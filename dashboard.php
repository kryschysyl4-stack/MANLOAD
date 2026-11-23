<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/flash.php';
require_login();
if (is_role('Admin')) {   // Admin starts on Dashboard › Trip Summary
    header('Location: ' . BASE_URL . '/modules/admin/sum_trips.php' . (isset($_GET['error']) ? '?error=' . urlencode(str_param($_GET['error'])) : '')); exit;
}
if (is_role('Coast Guard')) {   // Coast Guard inspectors start on Inspection (vessels to verify)
    header('Location: ' . BASE_URL . '/modules/coastguard/index.php' . (isset($_GET['error']) ? '?error=' . urlencode(str_param($_GET['error'])) : '')); exit;
}
if (is_role('Passage')) {   // Passage staff work on the booking counter screen (Barkota style)
    header('Location: ' . BASE_URL . '/modules/admin/ticketing.php' . (isset($_GET['error']) ? '?error=' . urlencode(str_param($_GET['error'])) : '')); exit;
}

$page_title = 'Dashboard';
$db   = db();
$me   = current_user();
$role = $me['role'];
$B    = BASE_URL;
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ── Trips today & tomorrow (every role) ──────────────────────────────────
$trips = available_trips(['from' => $today, 'to' => $tomorrow]);
$loads = [];
foreach ($trips as $t) $loads[$t['voyage_id']] = voyage_load((int)$t['voyage_id']);   // ≤ ~40 trips
$by_day = [$today => [], $tomorrow => []];
foreach ($trips as $t) $by_day[$t['departure_date']][] = $t;
$routes_on_board = [];
foreach ($trips as $t) $routes_on_board[$t['route_code']] = $t['orig'] . ' → ' . $t['dest'];
ksort($routes_on_board);

// ── Vessels + maintenance ────────────────────────────────────────────────
$vessels = $db->query("SELECT * FROM vessel WHERE is_active=1 ORDER BY vessel_name")->fetch_all(MYSQLI_ASSOC);
$trips_per_vessel = [];
foreach ($by_day[$today] as $t) $trips_per_vessel[$t['vessel_id']] = ($trips_per_vessel[$t['vessel_id']] ?? 0) + 1;
$maint_today = array_filter($vessels, fn($v) => vessel_under_maintenance($v, $today));

// ── KPIs ─────────────────────────────────────────────────────────────────
$kpi = [];
$kpi[] = ['Voyages today', count($by_day[$today]), 'calendar-event', 'blue',
          count($by_day[$tomorrow]) . ' tomorrow'];
$onboard_today = (int)$db->query("SELECT COUNT(*) FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id
    WHERE v.departure_date=CURDATE() AND p.status IN ('Checked-in','Boarded')")->fetch_row()[0];
$booked_today = (int)$db->query("SELECT COUNT(*) FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id
    WHERE v.departure_date=CURDATE() AND p.status='Booked'")->fetch_row()[0];
$kpi[] = ['Passengers on board today', $onboard_today, 'people-fill', 'green', $booked_today . ' booked, not yet checked-in'];

if (is_role(...ROLE_CHECKIN)) {
    $waiting = (int)$db->query("SELECT COUNT(*) FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id
        WHERE p.status='Booked' AND v.departure_date>=CURDATE() AND v.manifest_status IN ('Open','In Progress')")->fetch_row()[0];
    $kpi[] = ['Waiting for check-in', $waiting, 'person-check', $waiting ? 'amber' : 'green', 'online bookings', "$B/modules/checkin/index.php"];
}
if (is_role(...ROLE_PAYMENTS)) {
    $pending_pay = (int)$db->query("SELECT COUNT(DISTINCT COALESCE(p.booking_ref, p.passenger_id)) FROM passenger p
        JOIN voyage v ON v.voyage_id=p.voyage_id WHERE p.online_pay_status='pending' AND v.archived=0")->fetch_row()[0];
    $kpi[] = ['Online payments to verify', $pending_pay, 'phone', $pending_pay ? 'red' : 'green', 'PayMongo — needs a check', "$B/modules/payments/verify_online.php"];
}
$collections = [];
if (is_role(...ROLE_PAYMENTS) || is_role(...ROLE_REPORTS)) {
    $collections = $db->query("SELECT pay_method, COUNT(*) AS n, SUM(amount) AS amt FROM payment
        WHERE paid_at >= CURDATE() AND paid_at < CURDATE() + INTERVAL 1 DAY AND is_voided=0
        GROUP BY pay_method ORDER BY amt DESC")->fetch_all(MYSQLI_ASSOC);
    $coll_total = array_sum(array_column($collections, 'amt'));
    $kpi[] = ['Collections today', peso($coll_total), 'cash-stack', 'purple', array_sum(array_column($collections, 'n')) . ' official receipt(s)'];
}
$unzoned_list = [];
if (is_role(...ROLE_CARGO)) {
    $unzoned_list = $db->query("SELECT v.voyage_id, v.departure_date, v.departure_time, vsl.vessel_name, r.route_code,
            (SELECT COUNT(*) FROM vehicle vh WHERE vh.voyage_id=v.voyage_id AND vh.zone_id IS NULL)
          + (SELECT COUNT(*) FROM cargo c WHERE c.voyage_id=v.voyage_id AND c.zone_id IS NULL) AS unzoned
        FROM voyage v JOIN vessel vsl ON vsl.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        WHERE v.departure_date >= CURDATE() AND v.archived=0 AND v.manifest_status NOT IN ('Departed','Cancelled')
        HAVING unzoned > 0 ORDER BY v.departure_date, v.departure_time LIMIT 15")->fetch_all(MYSQLI_ASSOC);
    $unz = array_sum(array_column($unzoned_list, 'unzoned'));
    $kpi[] = ['Loads without a zone', $unz, 'grid-3x2-gap', $unz ? 'amber' : 'green', 'vehicles + cargo', "$B/modules/load/plan.php"];
}
$awaiting_cg = [];
if (is_role(...ROLE_COASTGUARD) || is_role(...ROLE_MANIFEST)) {
    $awaiting_cg = $db->query("SELECT v.voyage_id, v.departure_date, v.departure_time, vsl.vessel_name, r.route_code, v.manifest_status
        FROM voyage v JOIN vessel vsl ON vsl.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        WHERE v.manifest_status IN ('Locked','Submitted') AND v.ready_to_board=0 AND v.archived=0
          AND v.departure_date >= CURDATE() - INTERVAL 1 DAY
        ORDER BY v.departure_date, v.departure_time LIMIT 15")->fetch_all(MYSQLI_ASSOC);
    $kpi[] = ['Awaiting Coast Guard', count($awaiting_cg), 'shield-check', $awaiting_cg ? 'red' : 'green', 'locked manifests to verify',
              is_role(...ROLE_COASTGUARD) ? "$B/modules/coastguard/index.php" : null];
}
$kpi[] = ['Vessels under maintenance', count($maint_today), 'tools', $maint_today ? 'red' : 'green', count($vessels) . ' active vessel(s)'];

// ── Quick links per role ────────────────────────────────────────────────
function trip_links(int $vid): array {
    $B = BASE_URL; $l = [];
    if (is_role(...ROLE_CHECKIN))    $l[] = ["$B/modules/checkin/index.php?vid=$vid", 'person-check', 'Check-in'];
    elseif (is_role(...ROLE_TICKETING)) $l[] = ["$B/modules/admin/ticketing.php?vid=$vid", 'ticket-perforated', 'Book'];
    if (is_role(...ROLE_CARGO) && !is_role('Admin')) $l[] = ["$B/modules/cargo/index.php?vid=$vid", 'box-seam', 'Cargo'];
    if (is_role(...ROLE_LOADPLAN))   $l[] = ["$B/modules/load/plan.php?vid=$vid", 'grid-3x2-gap', 'Load plan'];
    if (is_role(...ROLE_COASTGUARD)) $l[] = ["$B/modules/coastguard/clearance.php?vid=$vid", 'patch-check', 'Verify'];
    if (is_role(...ROLE_MANIFEST))   $l[] = ["$B/modules/manifest/index.php?vid=$vid", 'file-earmark-text', 'Manifest'];
    return $l;
}

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <?php show_flash(); ?>
  <?php if (($_GET['error'] ?? '') === 'access_denied'): ?>
    <div class="alert alert-danger alert-dismissible"><i class="bi bi-shield-lock me-1"></i>
      <strong>Access denied.</strong> Your role (<?= e($role) ?>) cannot open that page.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0"><?= e($greet) ?>, <?= e($me['full_name'] ?: $me['username']) ?></h4>
      <div class="text-muted small"><span class="badge bg-primary"><?= e($role) ?></span>
        · <?= e(date('l, F j, Y')) ?> · <span id="dashClock"><?= e(date('g:i A')) ?></span></div>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <a href="<?= e($B) ?>/modules/booking/availability.php?staff=1" class="btn btn-sm btn-outline-primary"><i class="bi bi-calendar2-week me-1"></i>All trips (<?= (int)BOOKING_DAYS_AHEAD ?> days)</a>
      <?php if (is_role(...ROLE_TICKETING)): ?><a href="<?= e($B) ?>/modules/admin/ticketing.php" class="btn btn-sm btn-primary"><i class="bi bi-ticket-perforated me-1"></i>Counter booking</a><?php endif; ?>
      <?php if (is_role('Admin')): ?><a href="<?= e($B) ?>/modules/admin/voyages.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-plus me-1"></i>Schedule</a><?php endif; ?>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="row g-2 mb-3">
    <?php foreach ($kpi as $k): ?>
    <div class="col-6 col-md-4 col-xl">
      <?php $tag = !empty($k[5]) ? 'a' : 'div'; ?>
      <<?= $tag ?> class="metric-card metric-<?= e($k[3]) ?> d-block h-100 text-decoration-none text-reset"<?= !empty($k[5]) ? ' href="' . e($k[5]) . '"' : '' ?>>
        <div class="d-flex justify-content-between align-items-start">
          <div class="metric-label"><?= e($k[0]) ?></div>
          <i class="bi bi-<?= e($k[2]) ?> text-muted fs-5"></i>
        </div>
        <div class="metric-value mt-1"><?= e($k[1]) ?></div>
        <div class="small text-muted mt-1"><?= e($k[4]) ?></div>
      </<?= $tag ?>>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <!-- Trips board -->
    <div class="col-xl-9">
      <div class="card card-manload">
        <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
          <h6 class="mb-0 fw-bold"><i class="bi bi-water text-primary me-1"></i>Trips today &amp; tomorrow</h6>
          <ul class="nav nav-pills nav-sm ms-2" role="tablist">
            <?php $i = 0; foreach ($by_day as $d => $list): ?>
            <li class="nav-item"><button class="nav-link py-1 px-3 <?= $i === 0 ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#day<?= $i ?>" type="button">
              <?= $d === $today ? 'Today' : 'Tomorrow' ?> <span class="badge bg-light text-dark ms-1"><?= count($list) ?></span></button></li>
            <?php $i++; endforeach; ?>
          </ul>
          <select id="routeFilter" class="form-select form-select-sm ms-auto" style="max-width:220px">
            <option value="">All routes</option>
            <?php foreach ($routes_on_board as $code => $lbl): ?><option value="<?= e($code) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
          </select>
          <div class="form-check form-switch small mb-0">
            <input class="form-check-input" type="checkbox" id="onlyBookable"><label class="form-check-label" for="onlyBookable">Seats available only</label>
          </div>
        </div>
        <div class="card-body tab-content">
          <?php $i = 0; foreach ($by_day as $d => $list): ?>
          <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="day<?= $i ?>">
            <?php if (!$list): ?>
              <div class="text-center text-muted py-5"><i class="bi bi-calendar-x fs-1 d-block mb-2"></i>No voyages scheduled for <?= e(date('M j', strtotime($d))) ?>.
                <?php if (is_role('Admin')): ?><br><a href="<?= e($B) ?>/modules/admin/voyages.php">Generate the schedule</a><?php endif; ?></div>
            <?php endif; ?>
            <div class="row g-2">
            <?php foreach ($list as $t):
                $L = $loads[$t['voyage_id']] ?? [];
                $v = $L['voyage'] ?? [];
                $seat_pct = $t['max_passengers'] > 0 ? round($t['seats_taken'] / $t['max_passengers'] * 100, 1) : 0;
                $cls = $t['under_maintenance'] ? 'trip-maint' : (in_array($t['availability'], ['Closed'], true) ? 'trip-disabled' : '');
                $avail_cls = match ($t['availability']) {
                    'Available' => 'text-success', 'Fully booked', 'Under Maintenance' => 'text-danger', default => 'text-secondary' };
            ?>
              <div class="col-md-6 col-xxl-4 trip-item" data-route="<?= e($t['route_code']) ?>" data-bookable="<?= $t['seats_left'] > 0 && !$t['under_maintenance'] ? 1 : 0 ?>">
                <div class="trip-card <?= $cls ?>">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <div class="trip-time"><?= e(date('g:i A', strtotime($t['departure_time']))) ?></div>
                      <div class="trip-route mt-1"><?= e($t['orig']) ?> <i class="bi bi-arrow-right"></i> <?= e($t['dest']) ?>
                        <span class="text-muted fw-normal small">· <?= e((float)$t['est_travel_hours']) ?> h</span></div>
                    </div>
                    <div class="text-end">
                      <?= $t['under_maintenance'] ? maintenance_badge() : status_badge($t['manifest_status']) ?>
                      <?php if (!empty($v['ready_to_board'])): ?><div class="mt-1"><span class="badge bg-success"><i class="bi bi-patch-check-fill me-1"></i>Ready to board</span></div><?php endif; ?>
                    </div>
                  </div>
                  <div class="small text-muted mt-1 text-truncate"><i class="bi bi-water me-1"></i><?= e($t['vessel_name']) ?> · <?= e($t['vessel_type']) ?></div>
                  <?php if ($t['under_maintenance']): ?>
                    <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= e($t['maintenance_note'] ?: 'Vessel cannot depart') ?>
                      <?= $t['maintenance_until'] ? ' · until ' . e(date('M j', strtotime($t['maintenance_until']))) : '' ?></div>
                  <?php endif; ?>

                  <div class="mt-2">
                    <div class="d-flex justify-content-between"><span class="avail-label">Seats left</span>
                      <span class="small fw-semibold"><?= (int)$t['seats_left'] ?> / <?= (int)$t['max_passengers'] ?></span></div>
                    <?= progress_bar($seat_pct, $t['seats_taken'] . ' taken', '6px') ?>
                  </div>
                  <div class="d-flex justify-content-between mt-2 small">
                    <span class="avail-label">Vehicle slots</span>
                    <?php if ((int)$t['max_vehicles'] === 0): ?><span class="text-muted"><i class="bi bi-slash-circle me-1"></i>No vehicles</span>
                    <?php else: ?><span class="fw-semibold <?= $t['vehicles_left'] ? '' : 'text-danger' ?>"><i class="bi bi-truck me-1"></i><?= (int)$t['vehicles_left'] ?> / <?= (int)$t['max_vehicles'] ?> left</span><?php endif; ?>
                  </div>
                  <?php if ($L): ?>
                  <div class="mt-2">
                    <div class="d-flex justify-content-between"><span class="avail-label">Load</span>
                      <span class="small"><span class="fw-semibold text-<?= e(level_color($L['level'])) ?>"><?= e($L['load_pct']) ?>%</span>
                      <span class="text-muted">· <?= e(kg($L['total_kg'])) ?> / <?= e(kg($L['max_kg'])) ?></span></span></div>
                    <?= progress_bar((float)$L['load_pct'], kg($L['total_kg']), '6px') ?>
                    <div class="small text-muted mt-1"><?= (int)$L['onboard'] ?> on board · <?= (int)$L['booked'] ?> booked<?= $L['cargo_count'] ? ' · ' . (int)$L['cargo_count'] . ' cargo' : '' ?></div>
                  </div>
                  <?php endif; ?>
                  <div class="d-flex flex-wrap align-items-center gap-1 mt-2 pt-2 border-top">
                    <span class="small fw-semibold <?= $avail_cls ?> me-auto"><?= e($t['availability']) ?></span>
                    <?php foreach (trip_links((int)$t['voyage_id']) as $lnk): ?>
                      <a href="<?= e($lnk[0]) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-<?= e($lnk[1]) ?> me-1"></i><?= e($lnk[2]) ?></a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
            <div class="text-center text-muted small py-3 d-none no-match">No trips match the filter.</div>
          </div>
          <?php $i++; endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Side panel -->
    <div class="col-xl-3">
      <div class="card card-manload mb-3">
        <div class="card-header bg-white fw-bold"><i class="bi bi-life-preserver text-primary me-1"></i>Vessel status</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($vessels as $vs): $um = vessel_under_maintenance($vs, $today); ?>
          <li class="list-group-item <?= $um ? 'bg-danger-subtle' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-semibold small text-truncate"><?= e($vs['vessel_name']) ?></span>
              <?php if ($um): ?><?= maintenance_badge() ?>
              <?php else: ?><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Operational</span><?php endif; ?>
            </div>
            <div class="small text-muted"><?= e($vs['vessel_type']) ?> · <?= (int)$vs['max_passengers'] ?> pax · <?= e(kg($vs['max_load_kg'])) ?>
              · <?= (int)$vs['max_vehicles'] ? (int)$vs['max_vehicles'] . ' veh' : 'no veh' ?> · <?= (int)($trips_per_vessel[$vs['vessel_id']] ?? 0) ?> trip(s) today</div>
            <?php if ($um): ?>
              <div class="small text-danger"><?= e($vs['maintenance_note'] ?: 'Cannot operate') ?>
                <?= $vs['maintenance_until'] ? '· until ' . e(date('M j, Y', strtotime($vs['maintenance_until']))) : '· until further notice' ?></div>
            <?php elseif ($vs['maintenance'] && $vs['maintenance_since'] > $today): ?>
              <div class="small text-warning-emphasis"><i class="bi bi-calendar-event me-1"></i>Maintenance from <?= e(date('M j', strtotime($vs['maintenance_since']))) ?></div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if (is_role('Admin')): ?><div class="card-footer bg-white small"><a href="<?= e($B) ?>/modules/admin/vessels.php">Manage vessels &amp; maintenance</a></div><?php endif; ?>
      </div>

      <?php if ($awaiting_cg): ?>
      <div class="card card-manload mb-3">
        <div class="card-header bg-white fw-bold"><i class="bi bi-shield-exclamation text-danger me-1"></i>Awaiting Coast Guard verification</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($awaiting_cg as $a): ?>
          <li class="list-group-item d-flex align-items-center gap-2 small">
            <div class="me-auto"><strong><?= e(date('M j g:i A', strtotime($a['departure_date'] . ' ' . $a['departure_time']))) ?></strong>
              <div class="text-muted"><?= e($a['route_code']) ?> · <?= e($a['vessel_name']) ?></div></div>
            <?php if (is_role(...ROLE_COASTGUARD)): ?><a class="btn btn-sm btn-danger py-0" href="<?= e($B) ?>/modules/coastguard/clearance.php?vid=<?= (int)$a['voyage_id'] ?>">Verify</a>
            <?php else: ?><?= status_badge($a['manifest_status']) ?><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($unzoned_list): ?>
      <div class="card card-manload mb-3">
        <div class="card-header bg-white fw-bold"><i class="bi bi-grid-3x2-gap text-warning me-1"></i>Loads to sort into zones</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($unzoned_list as $u): ?>
          <li class="list-group-item d-flex align-items-center gap-2 small">
            <div class="me-auto"><strong><?= e(date('M j g:i A', strtotime($u['departure_date'] . ' ' . $u['departure_time']))) ?></strong>
              <div class="text-muted"><?= e($u['route_code']) ?> · <?= e($u['vessel_name']) ?></div></div>
            <span class="badge bg-warning text-dark"><?= (int)$u['unzoned'] ?></span>
            <a class="btn btn-sm btn-outline-primary py-0" href="<?= e($B) ?>/modules/load/plan.php?vid=<?= (int)$u['voyage_id'] ?>">Plan</a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (is_role(...ROLE_PAYMENTS) || is_role(...ROLE_REPORTS)): ?>
      <div class="card card-manload mb-3">
        <div class="card-header bg-white fw-bold"><i class="bi bi-cash-coin text-success me-1"></i>Today's collections</div>
        <div class="card-body p-2">
          <?php if (!$collections): ?><p class="text-muted small text-center mb-0 py-2">No payments yet today.</p>
          <?php else: ?>
          <table class="table table-sm mb-0 small">
            <?php foreach ($collections as $c): ?>
            <tr><td><?= e($c['pay_method']) ?> <span class="text-muted">(<?= (int)$c['n'] ?>)</span></td><td class="text-end fw-semibold"><?= e(peso($c['amt'])) ?></td></tr>
            <?php endforeach; ?>
            <tr class="table-light"><th>Total</th><th class="text-end"><?= e(peso($coll_total)) ?></th></tr>
          </table>
          <?php endif; ?>
        </div>
        <?php if (is_role(...ROLE_REPORTS)): ?><div class="card-footer bg-white small"><a href="<?= e($B) ?>/modules/reports/index.php">Open reports</a></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<!-- /.main-content is closed by footer.php -->
<script>
(function () {
  const sel = document.getElementById('routeFilter'), only = document.getElementById('onlyBookable');
  function apply() {
    document.querySelectorAll('.tab-pane').forEach(p => {
      let shown = 0;
      p.querySelectorAll('.trip-item').forEach(el => {
        const ok = (!sel.value || el.dataset.route === sel.value) && (!only.checked || el.dataset.bookable === '1');
        el.classList.toggle('d-none', !ok); if (ok) shown++;
      });
      const nm = p.querySelector('.no-match');
      if (nm) nm.classList.toggle('d-none', shown > 0 || !p.querySelector('.trip-item'));
    });
  }
  sel.addEventListener('change', apply); only.addEventListener('change', apply);
  const clk = document.getElementById('dashClock');
  setInterval(() => { clk.textContent = new Date().toLocaleTimeString('en-PH', {hour: 'numeric', minute: '2-digit'}); }, 30000);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
