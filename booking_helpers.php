<?php
/**
 * Helpers shared by the public booking pages (modules/booking/*, modules/payments/online_pay.php).
 * Requires config/auth.php (functions.php) to be loaded first.
 */

/** Bootstrap for every public booking page. */
function bk_boot(): void {
    session_start_once();
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');   // the confirm link carries a private key
}

/**
 * Link for BOOK NOW / Passenger Booking / Vehicle w/ Passenger: straight to the login page unless a passenger
 * account is logged in (the login page then offers sign-up or "Continue as guest" and comes back here).
 */
function bk_book_url(string $page = 'find.php'): string {
    session_start_once();
    $to = BASE_URL . '/modules/booking/' . $page;
    return !empty($_SESSION['pax_acct_id']) ? $to : BASE_URL . '/modules/account/login.php?next=' . urlencode($to);
}

/** Valid Y-m-d date string? */
function bk_valid_date($d): bool {
    if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Active ports for the search form. */
function bk_ports(): array {
    return db()->query("SELECT port_id, port_code, port_name FROM port WHERE is_active=1 ORDER BY port_name")
               ->fetch_all(MYSQLI_ASSOC);
}

/**
 * Can the public book this voyage right now? null = yes, otherwise the reason.
 * $v comes from get_voyage().
 */
function bk_booking_closed_reason(?array $v): ?string {
    if (!$v) return 'This trip was not found.';
    if (!empty($v['archived'])) return 'This trip is no longer available.';
    if (empty($v['vessel_active'])) return 'This vessel is not in service.';
    if (!empty($v['under_maintenance'])) return 'The vessel is UNDER MAINTENANCE on this date. Please choose another trip.';
    if ($v['manifest_status'] === 'Cancelled') return 'This trip was cancelled.';
    if (!in_array($v['manifest_status'], ['Open', 'In Progress'], true)) return 'Booking for this trip is closed.';
    $dep = strtotime($v['departure_date'] . ' ' . $v['departure_time']);
    if ($dep <= time() + BOOKING_CUTOFF_MINUTES * 60)
        return 'Online booking closes ' . BOOKING_CUTOFF_MINUTES . ' minutes before departure. Please go to the port ticket counter.';
    if ($dep > strtotime('+' . BOOKING_DAYS_AHEAD . ' days'))
        return 'Online booking opens ' . BOOKING_DAYS_AHEAD . ' days before departure.';
    return null;
}

/** Simple per-session rate limit. Returns true when the limit is reached. */
function bk_rate_limited(string $key, int $max, int $window_sec): bool {
    session_start_once();
    $now  = time();
    $hits = array_values(array_filter($_SESSION['_rl'][$key] ?? [], fn($t) => $t > $now - $window_sec));
    $_SESSION['_rl'][$key] = $hits;
    return count($hits) >= $max;
}

function bk_rate_hit(string $key): void {
    session_start_once();
    $_SESSION['_rl'][$key][] = time();
}

/** Everything about a booking: ['voyage'=>, 'pax'=>[], 'vehicles'=>[]] or null. */
function bk_load_booking(string $ref): ?array {
    $db = db();
    $st = $db->prepare("SELECT * FROM passenger WHERE booking_ref=? ORDER BY passenger_id");
    $st->bind_param('s', $ref);
    $st->execute();
    $pax = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    if (!$pax) return null;
    $st = $db->prepare("SELECT vh.*, vt.type_name FROM vehicle vh
                        JOIN vehicle_type vt ON vt.type_id = vh.vehicle_type_id
                        WHERE vh.booking_ref=? ORDER BY vh.vehicle_id");
    $st->bind_param('s', $ref);
    $st->execute();
    $veh = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return ['voyage' => get_voyage((int)$pax[0]['voyage_id']), 'pax' => $pax, 'vehicles' => $veh];
}

/**
 * Payment state of a booking's passenger fares.
 * state: paid | pending | rejected | unpaid | free
 */
function bk_pay_state(array $pax): array {
    $total = 0.0; $due = 0.0; $pending = false; $rejected = false; $method = null; $ref = null;
    foreach ($pax as $p) {
        if ($p['status'] === 'Cancelled') continue;
        $total += (float)$p['fare'];
        if ((float)$p['fare'] <= 0 || $p['fare_paid']) continue;
        $due += (float)$p['fare'];
        if ($p['online_pay_status'] === 'pending')  { $pending = true; $method = $p['online_pay_method']; $ref = $p['online_pay_ref']; }
        if ($p['online_pay_status'] === 'rejected') { $rejected = true; }
    }
    $state = match (true) {
        $total <= 0 => 'free',
        $due <= 0   => 'paid',
        $pending    => 'pending',
        $rejected   => 'rejected',
        default     => 'unpaid',
    };
    return ['state' => $state, 'total' => $total, 'due' => $due, 'method' => $method, 'ref' => $ref];
}

/** Online payment methods offered through the PayMongo checkout (GCash / Maya / card, see includes/settings.php). */
function bk_online_methods(): array {
    return array_values(array_filter(ONLINE_PAY_METHODS, fn($m) => isset(ONLINE_PAY_ACCOUNTS[$m])));
}

/** Badge for a trip's availability (from available_trips()). */
function bk_avail_badge(array $t): string {
    if (!empty($t['under_maintenance'])) return maintenance_badge();
    return match ($t['availability']) {
        'Available'      => '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Available</span>',
        'Fully booked'   => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Fully booked</span>',
        'Booking closed' => '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Booking closed</span>',
        default          => '<span class="badge bg-secondary"><i class="bi bi-lock me-1"></i>' . e($t['availability']) . '</span>',
    };
}

function bk_vessel_icon(string $type): string {
    return match ($type) { 'Fast Craft' => 'lightning-charge', 'RORO' => 'truck', default => 'water' };
}

/** One trip card (public index + availability). */
/** $book_qs: extra query for the Book link, e.g. ['pax' => 3, 'vehicle' => 1] */
function bk_trip_card(array $t, bool $show_date = false, array $book_qs = []): string {
    $dep     = strtotime($t['departure_date'] . ' ' . $t['departure_time']);
    $maxpax  = (int)$t['max_passengers'];
    $pct     = $maxpax > 0 ? round((int)$t['seats_taken'] / $maxpax * 100, 1) : 100;
    $cls     = $t['under_maintenance'] ? 'trip-maint' : ($t['bookable'] ? '' : 'trip-disabled');
    ob_start(); ?>
<div class="trip-card <?= $cls ?> d-flex flex-column">
  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
    <div>
      <div class="trip-time"><?= e(date('g:i A', $dep)) ?></div>
      <?php if ($show_date): ?><div class="small text-muted"><?= e(date('D, M j', $dep)) ?></div><?php endif; ?>
    </div>
    <div class="text-end"><?= bk_avail_badge($t) ?></div>
  </div>
  <div class="trip-route fs-6"><?= e($t['orig']) ?> <i class="bi bi-arrow-right"></i> <?= e($t['dest']) ?></div>
  <div class="small text-muted mb-2"><?= e($t['orig_name']) ?> → <?= e($t['dest_name']) ?>
    <?php if ((float)$t['est_travel_hours'] > 0): ?> · ~<?= e(rtrim(rtrim(number_format((float)$t['est_travel_hours'], 1), '0'), '.')) ?> h<?php endif; ?></div>
  <div class="small mb-2"><i class="bi bi-<?= e(bk_vessel_icon($t['vessel_type'])) ?> me-1 text-primary"></i><strong><?= e($t['vessel_name']) ?></strong>
    <span class="badge text-bg-light border ms-1"><?= e($t['vessel_type']) ?></span></div>
  <?php if (!empty($t['public_remarks'])): ?>
    <div class="alert alert-info py-1 px-2 small mb-2"><i class="bi bi-megaphone me-1"></i><?= e($t['public_remarks']) ?></div>
  <?php endif; ?>
  <?php if ($t['under_maintenance']): ?>
    <div class="alert alert-danger py-2 px-2 small mb-2"><i class="bi bi-tools me-1"></i>Vessel under maintenance<?= $t['maintenance_note'] ? ': ' . e($t['maintenance_note']) : '' ?>.
      <?php if ($t['maintenance_until']): ?>Expected back <?= e(date('M j', strtotime($t['maintenance_until']))) ?>.<?php endif; ?></div>
  <?php endif; ?>
  <div class="row g-2 small mb-2">
    <div class="col-7">
      <div class="avail-label">Seats left</div>
      <div class="fw-bold <?= $t['seats_left'] <= 10 ? 'text-danger' : '' ?>"><?= (int)$t['seats_left'] ?> <span class="text-muted fw-normal">/ <?= $maxpax ?></span></div>
      <?= progress_bar($pct, $pct . '% booked', '6px') ?>
    </div>
    <div class="col-5">
      <div class="avail-label">Vehicles</div>
      <?php if ((int)$t['max_vehicles'] > 0): ?>
        <div class="fw-bold <?= $t['vehicles_left'] == 0 ? 'text-danger' : '' ?>"><i class="bi bi-car-front me-1"></i><?= (int)$t['vehicles_left'] ?> <span class="text-muted fw-normal">slot<?= $t['vehicles_left'] == 1 ? '' : 's' ?> left</span></div>
      <?php else: ?>
        <div class="text-muted">No vehicles</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
    <div><span class="avail-label d-block">Fare from</span><span class="fw-bold fs-5 text-primary"><?= $t['fare_from'] !== null ? e(peso($t['fare_from'])) : '—' ?></span></div>
    <?php if ($t['bookable']): ?>
      <a href="<?= e(BASE_URL) ?>/modules/booking/book.php?<?= e(http_build_query(['vid' => (int)$t['voyage_id']] + $book_qs)) ?>" class="btn btn-primary btn-lg px-4"><i class="bi bi-ticket-perforated me-1"></i>Book</a>
    <?php else: ?>
      <button type="button" class="btn btn-outline-secondary" disabled>Not available</button>
    <?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}
