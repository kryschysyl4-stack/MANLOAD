<?php
/**
 * Back-office tools of the Admin (Barkota style; the Back Office role was merged into Admin) — shared trip selector used by every page in modules/backoffice/:
 *   blue  banner = route        (CHANGE ROUTE)
 *   red   banner = date         (CHANGE DATE)
 *   teal  banner = time/vessel  (CHANGE TIME, optionally "All trips")
 * Requires config/auth.php and includes/checkin_helpers.php.
 */

const ROLE_BACKOFFICE = ['Admin'];

/**
 * Route / day / trip chosen in the query string.
 * $allow_all: the time banner may be "All trips of the day" (trip = 0).
 */
function bo_ctx(bool $allow_all = false): array {
    $db = db();
    $routes = $db->query("SELECT r.route_id, r.route_code, r.origin_port_id, r.destination_port_id,
            po.port_name AS orig_name, pd.port_name AS dest_name
        FROM route r JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        WHERE r.is_active=1 ORDER BY po.port_name, pd.port_name")->fetch_all(MYSQLI_ASSOC);
    $by_id = array_column($routes, null, 'route_id');
    $route_id = (int)($_GET['route'] ?? 0);
    if (!isset($by_id[$route_id])) {
        $route_id = 0;
        foreach ($routes as $r) if ((int)$r['origin_port_id'] === (int)($_SESSION['port_id'] ?? 0)) { $route_id = (int)$r['route_id']; break; }
        if (!$route_id && $routes) $route_id = (int)$routes[0]['route_id'];
    }
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', str_param($_GET['day'] ?? '')) ? $_GET['day'] : date('Y-m-d');
    $trips = $route_id ? available_trips(['from' => $day, 'to' => $day, 'route_id' => $route_id]) : [];
    $trip_id = (int)($_GET['trip'] ?? 0);
    if (!in_array($trip_id, array_map(fn($t) => (int)$t['voyage_id'], $trips), true)) {
        $trip_id = 0;
        if (!$allow_all || isset($_GET['trip'])) {     // default: the next trip of the day, else the first
            foreach ($trips as $t) if (!$t['departed']) { $trip_id = (int)$t['voyage_id']; break; }
            if (!$trip_id && $trips) $trip_id = (int)$trips[0]['voyage_id'];
        }
        if ($allow_all && ($_GET['trip'] ?? '') === 'all') $trip_id = 0;
    }
    return ['routes' => $routes, 'route' => $by_id[$route_id] ?? null, 'route_id' => $route_id, 'day' => $day,
            'trips' => $trips, 'trip_id' => $trip_id, 'trip' => $trip_id ? get_voyage($trip_id) : null, 'allow_all' => $allow_all];
}

/** Query string keeping the chosen route / day / trip (+ $extra). */
function bo_q(array $ctx, array $extra = []): string {
    return '?' . http_build_query(array_merge(['route' => $ctx['route_id'], 'day' => $ctx['day'],
        'trip' => $ctx['trip_id'] ?: ($ctx['allow_all'] ? 'all' : null)], $extra));
}

/** The three banners + the change-route modal. $keep: extra GET fields to keep (e.g. filters). */
function bo_trip_bar(array $ctx, array $keep = []): string {
    $r = $ctx['route']; $t = $ctx['trip'];
    ob_start(); ?>
<div class="row g-2 mb-3 bo-bar">
  <div class="col-lg-4"><div class="pos-banner pos-route h-100">
    <div class="pos-banner-main fs-5"><?= $r ? e($r['orig_name'] . ' to ' . $r['dest_name']) : 'No route' ?></div>
    <div class="pos-banner-bar"><button type="button" class="pos-link" data-bs-toggle="modal" data-bs-target="#boRouteModal"><i class="bi bi-geo-alt-fill me-1"></i>CHANGE ROUTE</button></div>
  </div></div>
  <div class="col-lg-4"><div class="pos-banner pos-date h-100">
    <div class="pos-banner-main fs-5"><?= e(date('l, F j, Y', strtotime($ctx['day']))) ?></div>
    <div class="pos-banner-bar">
      <form method="get" class="d-inline">
        <?php foreach (array_merge(['route' => $ctx['route_id']], $keep) as $k => $v): if ($v === null || $v === '' || $v === []) continue;
              foreach ((array)$v as $vv): ?><input type="hidden" name="<?= e(is_array($v) ? $k . '[]' : $k) ?>" value="<?= e((string)$vv) ?>"><?php endforeach; endforeach; ?>
        <?php if ($ctx['allow_all']): ?><input type="hidden" name="trip" value="all"><?php endif; ?>
        <span class="pos-date-wrap">
          <span class="pos-link"><i class="bi bi-calendar3 me-1"></i>CHANGE DATE</span>
          <input type="date" name="day" id="boDay" class="pos-date-input" value="<?= e($ctx['day']) ?>" onclick="try{this.showPicker()}catch(e){}" onchange="this.form.submit()" aria-label="Change date">
        </span>
      </form>
    </div>
  </div></div>
  <div class="col-lg-4"><div class="pos-banner bo-time h-100">
    <div class="pos-banner-main fs-5"><?= $t ? e(date('g:i A', strtotime($t['departure_time'])) . ' – ' . $t['vessel_name']) : ($ctx['trips'] ? 'All trips' : 'No trips') ?></div>
    <div class="pos-banner-bar">
      <div class="dropdown">
        <button type="button" class="pos-link dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-clock me-1"></i>CHANGE TIME</button>
        <ul class="dropdown-menu shadow">
          <?php if ($ctx['allow_all']): ?><li><a class="dropdown-item <?= !$t ? 'active' : '' ?>" href="<?= e(bo_q($ctx, array_merge($keep, ['trip' => 'all']))) ?>">All trips of the day</a></li><?php endif; ?>
          <?php if (!$ctx['trips']): ?><li><span class="dropdown-item-text small text-muted">No trips on this date</span></li><?php endif; ?>
          <?php foreach ($ctx['trips'] as $x): ?>
          <li><a class="dropdown-item d-flex gap-3 <?= (int)$x['voyage_id'] === $ctx['trip_id'] ? 'active' : '' ?>" href="<?= e(bo_q($ctx, array_merge($keep, ['trip' => (int)$x['voyage_id']]))) ?>">
            <strong style="width:70px"><?= e(date('g:i A', strtotime($x['departure_time']))) ?></strong><span class="flex-fill"><?= e($x['vessel_name']) ?></span>
            <span class="small"><?= e($x['manifest_status']) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div></div>
</div>
<div class="modal fade" id="boRouteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Change route</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="list-group list-group-flush">
      <?php foreach ($ctx['routes'] as $x): ?>
      <a class="list-group-item list-group-item-action d-flex <?= (int)$x['route_id'] === $ctx['route_id'] ? 'active' : '' ?>"
         href="<?= e('?' . http_build_query(array_merge($keep, ['route' => $x['route_id'], 'day' => $ctx['day']]))) ?>">
        <span><?= e($x['orig_name']) ?> → <?= e($x['dest_name']) ?></span><span class="ms-auto small opacity-75"><?= e($x['route_code']) ?></span></a>
      <?php endforeach; ?>
    </div>
  </div></div>
</div>
<?php
    return ob_get_clean();
}

/**
 * Move one ticket (Booked or No-show) to another trip on the same route: new balanced seat,
 * new validity, status back to Booked. Fare and payments stay (payments follow the trip).
 */
function bo_revalidate(int $pid, int $new_vid, int $uid): array {
    $db = db();
    $db->begin_transaction();
    try {
        $new = lock_voyage($new_vid);
        $p   = pax_full_by('p.passenger_id', $pid);
        if (!$p || !$new) throw new RuntimeException('Ticket or trip not found.');
        $name = pax_display_name($p);
        if (!in_array($p['status'], ['Booked', 'No-show'], true)) throw new RuntimeException("$name is {$p['status']}.");
        if ((int)$p['voyage_id'] === $new_vid) throw new RuntimeException("$name is already on that trip.");
        if ((int)$new['route_id'] !== (int)$p['route_id']) throw new RuntimeException("$name: the new trip must be on the same route.");
        if ($r = voyage_closed_reason($new)) throw new RuntimeException($r);
        if (strtotime($new['departure_date'] . ' ' . $new['departure_time']) <= time()) throw new RuntimeException('The new trip has already departed.');
        if (pax_driver_of($pid)) throw new RuntimeException("$name drives a vehicle — use Change date / time for the whole booking.");
        if ($r = class_capacity_error($new_vid, $p['class'], 1)) throw new RuntimeException($r);
        if ($r = capacity_error($new_vid, 1, 0)) throw new RuntimeException($r);
        $seat = next_free_seat($new_vid, $pid, $p['class']);
        if (!$seat) throw new RuntimeException('No free seat on the new trip.');
        $exp = ticket_expiry($new);
        $st = $db->prepare("UPDATE passenger SET voyage_id=?, seat_no=?, status='Booked', ticket_valid_until=? WHERE passenger_id=?");
        $st->bind_param('issi', $new_vid, $seat, $exp, $pid); $st->execute();
        foreach (['payment', 'baggage'] as $tbl) {
            $st = $db->prepare("UPDATE $tbl SET voyage_id=? WHERE passenger_id=?");
            $st->bind_param('ii', $new_vid, $pid); $st->execute();
        }
        audit('Revalidated ticket ' . $p['ticket_no'], 'passenger', $pid,
              ['voyage_id' => (int)$p['voyage_id'], 'status' => $p['status'], 'seat_no' => $p['seat_no']],
              ['voyage_id' => $new_vid, 'status' => 'Booked', 'seat_no' => $seat]);
        $db->commit();
        return ['ok' => true, 'msg' => "$name → seat $seat"];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Database error.'];
    }
}

/** Page title bar used by the back-office pages. */
function bo_title(string $icon, string $title): string {
    return '<div class="bo-title"><i class="bi bi-' . e($icon) . ' me-2"></i>' . e(strtoupper($title)) . '</div>';
}

/** Payment state of a passenger row (pax_full_by / p.*): paid | unpaid | verify | free */
function bo_pay_state(array $p): string {
    if ((float)$p['fare'] <= 0) return 'free';
    if ($p['fare_paid']) return 'paid';
    return $p['online_pay_status'] === 'pending' ? 'verify' : 'unpaid';
}

function bo_pay_badge(array $p): string {
    return match (bo_pay_state($p)) {
        'paid'   => '<span class="badge badge-paid">PAID</span>',
        'free'   => '<span class="badge text-bg-light border">FREE</span>',
        'verify' => '<span class="badge bg-warning text-dark">FOR VERIFICATION</span>',
        default  => '<span class="badge badge-unpaid">UNPAID</span>',
    };
}
