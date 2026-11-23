<?php
// Only the link for the page you are on turns blue: we compare the full
// path (folder + file), not just the file name.
$_current_path = str_replace('\\', '/', $_SERVER['PHP_SELF']);

function nav_link(string $href, string $icon, string $label, ?int $badge = null): void {
    global $_current_path;
    $path   = parse_url($href, PHP_URL_PATH);
    $active = ($path === $_current_path) ? ' active' : '';
    $aria   = $active ? ' aria-current="page"' : '';
    $b = $badge ? '<span class="badge bg-danger rounded-pill ms-auto">' . $badge . '</span>' : '';
    echo '<a href="' . e($href) . '" class="nav-link d-flex align-items-center' . $active . '"' . $aria . '>'
       . '<i class="bi bi-' . e($icon) . ' me-2"></i><span>' . e($label) . '</span>' . $b . "</a>\n";
}

/** Phone-only drawer header: logo + close button (the menu slides in from the left below 992px). */
function sidebar_drawer_head(string $sub): void {
    echo '<div class="drawer-head d-lg-none">'
       . '<img src="' . e(BASE_URL) . '/assets/img/logo-round.png" alt="" width="36" height="36">'
       . '<span class="lh-1"><b>MANLOAD</b><br><small>' . e($sub) . '</small></span>'
       . '<button type="button" class="drawer-close ms-auto" data-drawer-close aria-label="Close menu"><i class="bi bi-x-lg"></i></button>'
       . "</div>
";
}

/** Phone-only drawer footer: the profile menu (hidden from the top bar on phones when a drawer exists). */
function sidebar_drawer_foot(): void {
    global $_profile_menu;
    if (!empty($_profile_menu)) echo '<div class="drawer-foot d-lg-none">' . $_profile_menu . "</div>
";
}

$_pending_online = 0;
if (is_role(...ROLE_PAYMENTS)) {
    $_pending_online = (int)db()->query("SELECT COUNT(DISTINCT COALESCE(p.booking_ref, p.passenger_id)) FROM passenger p
        JOIN voyage v ON v.voyage_id=p.voyage_id
        WHERE p.online_pay_status='pending' AND v.archived=0")->fetch_row()[0];
}
$B = BASE_URL;
if (!empty($_cg_ui)) return;   // Coast Guard uses top tabs (includes/header.php)
if (!empty($_passage_ui)) {     // Passage staff: the same navy side menu as the Admin, with their own items
    $_passage_menu = [
        ['Passage', 'ticket-perforated', "$B/modules/admin/ticketing.php"],
        ['Verify Ticket', 'shield-check', "$B/modules/checkin/scan.php"],
        ['Check-in', 'person-check', "$B/modules/checkin/index.php"],
        ['Cargo Waybill', 'box-seam', "$B/modules/cargo/index.php"],
    ];
    if (is_role(...ROLE_PAYMENTS)) $_passage_menu[] = ['Collect / OR', 'cash-stack', "$B/modules/payments/index.php"];
    if (is_role(...ROLE_PAYMENTS)) $_passage_menu[] = ['Passenger Wallet', 'wallet2', "$B/modules/admin/accounts.php"];
    $_passage_menu[] = ['Load Control', 'speedometer2', "$B/modules/load/index.php"];
    $_passage_menu[] = ['Report', 'bar-chart-line', [
        ['My Sales Report', 'receipt', "$B/modules/admin/my_report.php"],
        ['Daily Collection Report', 'journal-check', "$B/modules/payments/daily.php"],
        ['Vessel Manifest', 'file-earmark-text', "$B/modules/manifest/index.php"],
    ]];
}
if (!empty($_admin_ui) || !empty($_passage_menu)) {   // Admin (and Passage): navy menu, collapsible sections
    if (!empty($_bo_on_pos) && empty($_passage_menu)) return;   // Admin's "Passage Booking" tab: full-width booking screen
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $qmode = $_GET['mode'] ?? null;
    $qtab  = $_GET['tab'] ?? null;
    /** Is $href the page being shown (path + ?mode= / ?tab= when given)? */
    $is_on = function (string $href) use ($uri, $qmode, $qtab): bool {
        $p = parse_url($href);
        if (($p['path'] ?? '') !== $uri) return false;
        parse_str($p['query'] ?? '', $q);
        if (isset($q['tab'])) return $q['tab'] === ($qtab ?? 'history');
        return !isset($q['mode']) || $q['mode'] === ($qmode ?? 'direct');
    };
    $pending = (int)db()->query("SELECT COUNT(DISTINCT p.booking_ref) FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id
        WHERE p.online_pay_status='pending' AND p.fare_paid=0 AND v.archived=0")->fetch_row()[0];
    $menu = [
        ['Dashboard', 'speedometer2', [
            ['Trip Summary', 'signpost-2', "$B/modules/admin/sum_trips.php"],
            ['Sales Summary', 'graph-up-arrow', "$B/modules/admin/sum_sales.php"],
            ['Voyage Summary', 'water', "$B/modules/admin/sum_voyages.php"],
            ['Voyage Sales', 'cash-coin', "$B/modules/admin/voyage_sales.php"],
            ['System Ledger', 'journal-bookmark', "$B/modules/admin/ledger.php"],
        ]],
        ['Voyages', 'life-preserver', [
            ['Search / View Trips', 'search', "$B/modules/admin/trips.php"],
            ['Create Direct Trips', 'calendar-plus', "$B/modules/admin/trip_create.php?mode=direct"],
            ['Create Link Trips', 'calendar2-range', "$B/modules/admin/trip_create.php?mode=link"],
            ['Vessels Voyages', 'calendar-week', "$B/modules/admin/vessel_voyages.php"],
        ]],
        ['Notifications', 'bell', "$B/modules/admin/notifications.php"],
        ['Passage', 'people', [
            ['Passage Booking', 'ticket-perforated', "$B/modules/admin/ticketing.php"],
            ['Search Ticket', 'search', "$B/modules/backoffice/search.php"],
            ['Boarded Tickets', 'person-check', "$B/modules/backoffice/boarding.php"],
            ['Cot Assignment List', 'grid-3x3-gap', "$B/modules/backoffice/cots.php"],
            ['Bulk Revalidation', 'arrow-repeat', "$B/modules/backoffice/revalidate.php"],
            ['Bulk Receipt Issuance', 'receipt', "$B/modules/backoffice/receipts.php"],
            ['Bulk Confirm Reservation', 'check2-all', "$B/modules/backoffice/confirm.php"],
            ['Verify Ticket (scan)', 'shield-check', "$B/modules/checkin/scan.php"],
            ['Manifest', 'file-earmark-text', "$B/modules/manifest/index.php"],
        ]],
        ['Collect / OR', 'cash-stack', "$B/modules/payments/index.php"],
        ['Passengers & Rewards', 'person-badge', [
            ['Passenger Accounts', 'person-badge', "$B/modules/admin/accounts.php"],
            ['Vouchers & Rewards', 'ticket-perforated', "$B/modules/admin/vouchers.php"],
        ]],
        ['Load Control', 'speedometer', [
            ['Load Monitor', 'speedometer2', "$B/modules/load/index.php"],
            ['Load Plan', 'grid-3x2-gap', "$B/modules/load/plan.php"],
        ]],
        ['Commercial/Reselling', 'currency-exchange', [
            ['Fares', 'tags', "$B/modules/admin/routes.php"],
            ['Cargo Rates', 'box-seam', "$B/modules/admin/cargo_rates.php"],
            ['Reports', 'bar-chart-line', "$B/modules/reports/index.php"],
        ]],
        ['Shipping Routes', 'signpost-split', [
            ['Routes', 'signpost-split', "$B/modules/admin/routes.php?view=routes"],
            ['Ports', 'geo-alt', "$B/modules/admin/ports.php"],
        ]],
        ['Vessels', 'water', [
            ['Vessels & Seats', 'water', "$B/modules/admin/vessels.php"],
            ['Vehicle Types', 'car-front', "$B/modules/admin/vehicle_types.php"],
            ['PCG Checklist', 'list-check', "$B/modules/admin/clearance_docs.php"],
        ]],
        ['Company Setup', 'building-gear', [
            ['System Settings', 'sliders', "$B/modules/admin/settings.php"],
        ]],
        ['Archive', 'archive', [
            ['Daily History', 'calendar3', "$B/modules/admin/archive.php?tab=history"],
            ['Monthly Batches', 'file-zip', "$B/modules/admin/archive.php?tab=batches"],
            ['Export a Voyage', 'download', "$B/modules/admin/archive.php?tab=export"],
            ['Activity History', 'clock-history', "$B/modules/admin/audit.php"],
        ]],
        ['Administration', 'shield-lock', [
            ['Users', 'people', "$B/modules/admin/users.php"],
        ]],
    ];
    if (!empty($_passage_menu)) $menu = $_passage_menu;
    ?>
<nav class="sidebar adm-sidebar d-flex flex-column py-2" id="sidebar" aria-label="Main menu">
<?php sidebar_drawer_head(!empty($_passage_menu) ? 'Passage' : 'Administration'); ?>
<?php foreach ($menu as $i => [$label, $icon, $target]):
    if (is_string($target)): $on = $is_on($target); ?>
    <a href="<?= e($target) ?>" class="adm-item <?= $on ? 'active' : '' ?>"><i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
        <?php if ($label === 'Notifications' && $pending): ?><span class="badge bg-danger rounded-pill ms-auto"><?= $pending ?></span><?php endif; ?></a>
<?php else: $open = (bool)array_filter($target, fn($x) => $is_on($x[2])); ?>
    <button type="button" class="adm-item adm-group <?= $open ? 'open' : '' ?>" data-bs-toggle="collapse" data-bs-target="#admG<?= $i ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>">
        <i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span><i class="bi bi-chevron-right ms-auto adm-chev"></i></button>
    <div class="collapse <?= $open ? 'show' : '' ?>" id="admG<?= $i ?>">
        <?php foreach ($target as [$l2, $i2, $h2]): ?>
        <a href="<?= e($h2) ?>" class="adm-sub <?= $is_on($h2) ? 'active' : '' ?>"><i class="bi bi-<?= e($i2) ?>"></i><span><?= e($l2) ?></span></a>
        <?php endforeach; ?>
    </div>
<?php endif; endforeach; ?>
<?php sidebar_drawer_foot(); ?>
</nav>
<?php
    return;
}
?>
<nav class="sidebar d-flex flex-column py-3" id="sidebar" aria-label="Main menu">
<?php sidebar_drawer_head('Menu'); ?>

    <?php nav_link("$B/dashboard.php", 'house', 'Dashboard'); ?>
    <?php nav_link("$B/modules/booking/availability.php?staff=1", 'calendar2-week', 'Trips & Availability'); ?>

    <?php if (is_role(...ROLE_TICKETING)): ?>
    <p class="sidebar-section-label">Passengers</p>
    <?php nav_link("$B/modules/admin/ticketing.php", 'ticket-perforated', 'Counter Booking'); ?>
    <?php nav_link("$B/modules/admin/bookings.php", 'list-ul', 'Bookings'); ?>
    <?php nav_link("$B/modules/admin/rebook.php", 'calendar2-range', 'Change Date / Time'); ?>
    <?php nav_link("$B/modules/admin/my_report.php", 'receipt', 'My Sales Report'); ?>
    <?php if (is_role(...ROLE_CHECKIN)): ?>
    <?php nav_link("$B/modules/checkin/scan.php", 'shield-check', 'Verify Ticket (scan)'); ?>
    <?php nav_link("$B/modules/checkin/index.php", 'person-check', 'Check-in'); ?>
    <?php nav_link("$B/modules/baggage/index.php", 'luggage', 'Baggage'); ?>
    <?php nav_link("$B/modules/admin/boarding.php", 'door-open', 'Boarding'); ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (is_role(...ROLE_PAYMENTS)): ?>
    <p class="sidebar-section-label">Payments</p>
    <?php nav_link("$B/modules/payments/index.php", 'cash-stack', 'Collect / OR'); ?>
    <?php nav_link("$B/modules/payments/verify_online.php", 'phone', 'Online Payments', $_pending_online); ?>
    <?php endif; ?>

    <?php if (is_role(...ROLE_CARGO)): ?>
    <p class="sidebar-section-label">Cargo</p>
    <?php nav_link("$B/modules/cargo/index.php", 'box-seam', 'Cargo Waybills'); ?>
    <?php endif; ?>

    <?php if (is_role(...ROLE_LOADPLAN) || is_role(...ROLE_OPS)): ?>
    <p class="sidebar-section-label">Load Control</p>
    <?php if (is_role(...ROLE_LOADPLAN)) nav_link("$B/modules/load/plan.php", 'grid-3x2-gap', 'Load Plan (A1/A2)'); ?>
    <?php nav_link("$B/modules/load/index.php", 'speedometer2', 'Load Monitor'); ?>
    <?php if (is_role(...ROLE_MANIFEST)) nav_link("$B/modules/manifest/index.php", 'file-earmark-text', 'Manifest'); ?>
    <?php endif; ?>

    <?php if (is_role(...ROLE_COASTGUARD)): ?>
    <p class="sidebar-section-label">Coast Guard</p>
    <?php nav_link("$B/modules/coastguard/index.php", 'shield-check', 'Vessels to Verify'); ?>
    <?php nav_link("$B/modules/coastguard/clearance.php", 'patch-check', 'Ready to Board'); ?>
    <?php if (is_role('Coast Guard')): ?>
    <?php nav_link("$B/modules/admin/boarding.php", 'door-open', 'Boarding'); ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (is_role('Admin')): ?>
    <p class="sidebar-section-label">Passage tools</p>
    <?php nav_link("$B/modules/backoffice/search.php", 'search', 'Search Ticket'); ?>
    <?php nav_link("$B/modules/backoffice/cots.php", 'grid-3x3-gap', 'Cot Assignment List'); ?>
    <?php nav_link("$B/modules/backoffice/boarding.php", 'ticket-perforated', 'Boarded Tickets'); ?>
    <?php nav_link("$B/modules/backoffice/revalidate.php", 'arrow-repeat', 'Bulk Revalidation'); ?>
    <?php nav_link("$B/modules/backoffice/receipts.php", 'receipt', 'Bulk Receipt Issuance'); ?>
    <?php nav_link("$B/modules/backoffice/confirm.php", 'check2-all', 'Bulk Confirm Reservation'); ?>
    <?php endif; ?>

    <?php if (is_role(...ROLE_REPORTS)): ?>
    <p class="sidebar-section-label">Records</p>
    <?php nav_link("$B/modules/reports/index.php", 'bar-chart-line', 'Reports'); ?>
    <?php nav_link("$B/modules/admin/archive.php", 'archive', 'Archive & History'); ?>
    <?php endif; ?>

    <?php if (is_role('Admin')): ?>
    <p class="sidebar-section-label">Setup</p>
    <?php nav_link("$B/modules/admin/voyages.php", 'calendar-event', 'Voyages & Schedule'); ?>
    <?php nav_link("$B/modules/admin/vessels.php", 'water', 'Vessels & Zones'); ?>
    <?php nav_link("$B/modules/admin/routes.php", 'signpost-split', 'Routes & Fares'); ?>
    <?php nav_link("$B/modules/admin/ports.php", 'geo-alt', 'Ports'); ?>
    <?php nav_link("$B/modules/admin/vehicle_types.php", 'car-front', 'Vehicle Types'); ?>
    <?php nav_link("$B/modules/admin/clearance_docs.php", 'list-check', 'PCG Checklist'); ?>
    <?php nav_link("$B/modules/admin/settings.php", 'sliders', 'System Settings'); ?>
    <?php nav_link("$B/modules/admin/users.php", 'people', 'Users'); ?>
    <?php nav_link("$B/modules/admin/audit.php", 'journal-text', 'Audit Log'); ?>
    <?php endif; ?>
<?php sidebar_drawer_foot(); ?>
</nav>
