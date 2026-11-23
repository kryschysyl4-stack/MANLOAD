<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/flash.php';

$user = current_user();

$_profile = db()->query(
    "SELECT u.full_name, u.username, u.role, u.last_login, u.created_at, p.port_name
     FROM user u LEFT JOIN port p ON p.port_id = u.port_id
     WHERE u.user_id = " . (int)($user['user_id'] ?? 0)
)->fetch_assoc();

// Notifications for the bottom-right bell (check-in: bookings to check in; cashiers: online payments to verify)
require_once __DIR__ . '/notifications.php';
$_notifs     = staff_notifications();
$_has_notifs = $_notifs['checkin'] !== null || $_notifs['payments'] !== null || $_notifs['coastguard'] !== null || $_notifs['departed'] !== null || $_notifs['cleared'] !== null || $_notifs['capacity'] !== null;

// Passage staff get the Barkota-style layout: top navigation, no sidebar
$_passage_ui    = is_role('Passage');
$_admin_ui      = is_role('Admin');
$_cg_ui         = is_role('Coast Guard');   // Coast Guard: navy/orange top tabs, no sidebar         // Barkota admin layout: top tabs + navy side menu (Voyages, Passage, …)
$_bo_on_pos     = str_ends_with(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/modules/admin/ticketing.php');

// Active voyage pill
$active_voyage = !empty($_SESSION['active_voyage_id']) ? get_voyage((int)$_SESSION['active_voyage_id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($page_title ?? APP_NAME) ?> — MANLOAD</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/manload.css?v=<?= filemtime(__DIR__ . '/../assets/css/manload.css') ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(BASE_URL) ?>">
</head>
<body class="manload-body<?= $_passage_ui ? ' passage-ui' : '' ?><?= $_admin_ui ? ' passage-ui backoffice-ui admin-ui' : '' ?><?= $_cg_ui ? ' passage-ui cg-ui' : '' ?><?= $_has_notifs ? ' has-notif-fab' : '' ?>" data-uid="<?= (int)($user['user_id'] ?? 0) ?>">
<!-- Loading screen: spinning anchor while a page loads (assets/js/manload.js shows/hides it) -->
<div class="page-loader" id="pageLoader" role="status" aria-live="polite"><svg class="anchor-spin" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="10" r="5"/><path d="M32 15v42M20 24h24M10 38c2 11 11 19 22 19s20-8 22-19M10 38l-4 5M10 38l6 3M54 38l4 5M54 38l-6 3"/></svg><span class="visually-hidden">Loading…</span></div>

<?php ob_start(); ?>
        <!-- Profile dropdown -->
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-light d-flex align-items-center gap-2"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle fs-5"></i>
                <span class="d-none d-md-inline">
                    <strong><?= e($user['full_name']) ?></strong>
                    <span class="badge <?= $_passage_ui ? 'bg-primary' : ($_admin_ui ? 'bg-dark' : 'bg-white text-primary') ?> ms-1"><?= e($user['role']) ?></span>
                </span>
                <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.7;"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow" style="width:280px;min-width:280px;">
                <!-- Profile header -->
                <div class="px-3 py-3 text-center border-bottom">
                    <div class="mb-2">
                        <span class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                              style="width:52px;height:52px;font-size:1.4rem;">
                            <i class="bi bi-person-fill"></i>
                        </span>
                    </div>
                    <div class="fw-bold"><?= e($_profile['full_name'] ?? $user['full_name']) ?></div>
                    <div class="small text-muted">@<?= e($_profile['username'] ?? $user['username']) ?></div>
                    <span class="badge bg-primary mt-1"><?= e($_profile['role'] ?? $user['role']) ?></span>
                </div>
                <!-- Details -->
                <div class="px-3 py-2 border-bottom">
                    <?php if (!empty($_profile['port_name'])): ?>
                    <div class="d-flex justify-content-between small py-1">
                        <span class="text-muted"><i class="bi bi-geo-alt me-1"></i>Port</span>
                        <span class="fw-semibold"><?= e($_profile['port_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between small py-1">
                        <span class="text-muted"><i class="bi bi-clock me-1"></i>Last Login</span>
                        <span><?= $_profile['last_login'] ? date('M d, Y H:i', strtotime($_profile['last_login'])) : '—' ?></span>
                    </div>
                    <div class="d-flex justify-content-between small py-1">
                        <span class="text-muted"><i class="bi bi-calendar me-1"></i>Account Created</span>
                        <span><?= $_profile['created_at'] ? date('M d, Y', strtotime($_profile['created_at'])) : '—' ?></span>
                    </div>
                </div>
                <!-- Actions -->
                <div class="px-2 py-1">
                    <a href="<?= BASE_URL ?>/profile.php" class="dropdown-item rounded small py-2">
                        <i class="bi bi-person-gear me-2 text-muted"></i>Profile, Email &amp; Password
                    </a>
                    <div class="dropdown-divider my-1"></div>
                    <button class="dropdown-item rounded small py-2 text-danger"
                            data-bs-toggle="modal" data-bs-target="#logoutModal">
                        <i class="bi bi-box-arrow-right me-2"></i>Log Out
                    </button>
                </div>
            </div>
        </div>
<?php $_profile_menu = ob_get_clean(); ?>

<?php if ($_admin_ui): $B = BASE_URL; $_cur = str_replace('\\', '/', $_SERVER['PHP_SELF']); $_on_bo = str_contains($_cur, '/modules/backoffice/'); ?>
<!-- ADMIN TOP BAR (Barkota admin style) -->
<nav class="navbar navbar-dark passage-nav bo-nav sticky-top py-1">
    <div class="container-fluid px-3 flex-nowrap justify-content-start">
        <?php if (!$_bo_on_pos): ?><button class="drawer-toggle d-lg-none" type="button" data-drawer-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list"></i></button><?php endif; ?>
        <a class="navbar-brand passage-brand d-flex align-items-center gap-2 me-3" href="<?= $B ?>/dashboard.php">
            <img src="<?= $B ?>/assets/img/logo-round.png" alt="" width="42" height="42" style="width:42px;height:42px;object-fit:cover">
            <span class="lh-1 d-none d-sm-inline"><span class="passage-brand-name">MANLOAD</span><br><span class="passage-brand-sub">ADMINISTRATION</span></span>
        </a>
        <div class="ms-auto align-items-center gap-3 <?= $_bo_on_pos ? 'd-flex' : 'd-none d-lg-flex' ?>">
            <?php if (!empty($_profile['port_name'])): ?><span class="small text-muted d-none d-xl-inline"><i class="bi bi-shop me-1"></i><?= e($_profile['port_name']) ?></span><?php endif; ?>
            <?= $_profile_menu ?>
        </div>
    </div>
</nav>

<?php elseif ($_cg_ui): $B = BASE_URL;
    $_cg_on = function (string ...$paths): string {
        $cur = str_replace('\\', '/', $_SERVER['PHP_SELF']);
        foreach ($paths as $p) if ($cur === BASE_URL . $p) return ' active';
        return '';
    };
    // Inspection tab: every locked manifest still waiting (the bell only shows those locked in the last day)
    $_cg_wait = (int)db()->query("SELECT COUNT(*) FROM voyage v WHERE " . CG_WAIT_WHERE)->fetch_row()[0];
?>
<!-- COAST GUARD TOP BAR -->
<nav class="navbar navbar-expand-lg cg-nav sticky-top py-1">
    <div class="container-fluid px-3 flex-nowrap justify-content-start">
        <button class="drawer-toggle d-lg-none" type="button" data-drawer-toggle aria-controls="cgNav" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <a class="navbar-brand d-flex align-items-center gap-2 me-3" href="<?= $B ?>/modules/coastguard/index.php">
            <span class="cg-badge"><i class="bi bi-shield-fill-check"></i></span>
            <span class="lh-1"><span class="cg-brand">MANLOAD</span><br><span class="cg-brand-sub">COAST GUARD</span></span>
        </a>
        <div class="collapse navbar-collapse nav-drawer" id="cgNav" data-drawer aria-label="Main menu">
            <div class="drawer-head d-lg-none"><span class="cg-badge" style="width:36px;height:36px;font-size:1rem"><i class="bi bi-shield-fill-check"></i></span><span class="lh-1"><b>MANLOAD</b><br><small>Coast Guard</small></span><button type="button" class="drawer-close ms-auto" data-drawer-close aria-label="Close menu"><i class="bi bi-x-lg"></i></button></div>
            <ul class="navbar-nav align-items-lg-center gap-lg-1">
                <li class="nav-item"><a class="nav-link cg-tab<?= $_cg_on('/modules/coastguard/index.php', '/modules/coastguard/clearance.php') ?>" href="<?= $B ?>/modules/coastguard/index.php"><i class="bi bi-clipboard-check me-1"></i>Inspection<?php if ($_cg_wait): ?> <span class="badge rounded-pill cg-count"><?= $_cg_wait ?></span><?php endif; ?></a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link cg-tab dropdown-toggle<?= $_cg_on('/modules/load/index.php', '/modules/load/plan.php') ?>" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-speedometer2 me-1"></i>Load Control</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item<?= $_cg_on('/modules/load/index.php') ?>" href="<?= $B ?>/modules/load/index.php"><i class="bi bi-speedometer2 me-2"></i>Load Monitor</a></li>
                        <li><a class="dropdown-item<?= $_cg_on('/modules/load/plan.php') ?>" href="<?= $B ?>/modules/load/plan.php"><i class="bi bi-grid-3x2-gap me-2"></i>Load Plan</a></li>
                    </ul>
                </li>
                <li class="nav-item"><a class="nav-link cg-tab<?= $_cg_on('/modules/manifest/index.php') ?>" href="<?= $B ?>/modules/manifest/index.php"><i class="bi bi-file-earmark-text me-1"></i>Manifest</a></li>
                <li class="nav-item"><a class="nav-link cg-tab<?= $_cg_on('/modules/admin/boarding.php') ?>" href="<?= $B ?>/modules/admin/boarding.php"><i class="bi bi-door-open me-1"></i>Boarding</a></li>
                <li class="nav-item ms-lg-2"><a class="nav-link cg-tab cg-emerg<?= $_cg_on('/modules/coastguard/emergency.php') ?>" href="<?= $B ?>/modules/coastguard/emergency.php"><i class="bi bi-exclamation-octagon-fill me-1"></i>Emergency Scan</a></li>
            </ul>
            <div class="ms-auto d-flex align-items-center gap-3 py-2 py-lg-0 cg-profile">
                <?php if (!empty($_profile['port_name'])): ?><span class="small d-none d-xl-inline"><i class="bi bi-geo-alt me-1"></i><?= e($_profile['port_name']) ?></span><?php endif; ?>
                <?= $_profile_menu ?>
            </div>
        </div>
    </div>
</nav>

<?php elseif ($_passage_ui): $B = BASE_URL; ?>
<!-- PASSAGE TOP BAR (the menu is in the side bar, includes/sidebar.php) -->
<nav class="navbar navbar-dark passage-nav sticky-top py-1">
    <div class="container-fluid px-3 flex-nowrap justify-content-start">
        <button class="drawer-toggle d-lg-none" type="button" data-drawer-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <a class="navbar-brand passage-brand d-flex align-items-center gap-2 me-3" href="<?= $B ?>/modules/admin/ticketing.php">
            <img src="<?= $B ?>/assets/img/logo-round.png" alt="" width="42" height="42" style="width:42px;height:42px;object-fit:cover">
            <span class="lh-1"><span class="passage-brand-name">MANLOAD</span><br><span class="passage-brand-sub">PASSAGE</span></span>
        </a>
        <div class="ms-auto align-items-center gap-3 d-none d-lg-flex">
            <?php if (!empty($_profile["port_name"])): ?><span class="small text-muted d-none d-xl-inline"><i class="bi bi-shop me-1"></i><?= e($_profile["port_name"]) ?> Booking Office</span><?php endif; ?>
            <?= $_profile_menu ?>
        </div>
    </div>
</nav>

<?php else: ?>
<!-- TOP BAR -->
<nav class="navbar navbar-dark bg-primary px-3 py-2 topbar">
    <button class="drawer-toggle d-lg-none" type="button" data-drawer-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list"></i></button>
    <a class="navbar-brand d-flex align-items-center gap-2 me-3" href="<?= BASE_URL ?>/dashboard.php">
        <img src="<?= BASE_URL ?>/assets/img/logo-round.png" alt="MANLOAD"
             style="height:38px;width:38px;object-fit:contain;border-radius:50%;background:#fff;padding:2px;">
        <span class="fw-bold fs-5">MANLOAD</span>
    </a>

    <?php if ($active_voyage): ?>
    <span class="badge bg-light text-primary px-3 py-2 voyage-pill d-none d-md-inline-flex align-items-center gap-1">
        <i class="bi bi-water me-1"></i><?= e($active_voyage['vessel_name']) ?> — <?= e($active_voyage['orig']) ?>
        <i class="bi bi-arrow-right"></i> <?= e($active_voyage['dest']) ?>
        · <?= date('M d g:i A', strtotime($active_voyage['departure_date'].' '.$active_voyage['departure_time'])) ?>
        <?= $active_voyage['under_maintenance'] ? maintenance_badge() : status_badge($active_voyage['manifest_status']) ?>
    </span>
    <?php endif; ?>

    <div class="ms-auto d-flex align-items-center gap-3">

        <?= $_profile_menu ?>
    </div>
</nav>

<?php endif; ?>

<!-- Logout confirmation modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold"><i class="bi bi-box-arrow-right me-2"></i>Confirm Logout</h6>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-person-circle text-danger" style="font-size:2.5rem;"></i>
                <div class="fw-semibold mt-2"><?= e($user['full_name']) ?></div>
                <div class="small text-muted"><?= e($user['role']) ?></div>
                <p class="mt-3 mb-0 small">Are you sure you want to log out?<br>Your session will be ended and activity will be recorded.</p>
            </div>
            <div class="modal-footer justify-content-center gap-2 py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i> Cancel
                </button>
                <form method="post" action="<?= BASE_URL ?>/logout.php" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm px-4 fw-semibold"><i class="bi bi-box-arrow-right me-1"></i> Log Out</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($_has_notifs): ?>
<!-- Notification centre: bell at the bottom-right with a count; click it to see all notifications -->
<div id="notifToasts" class="notif-toasts" aria-live="polite"></div>
<div id="notifPanel" class="notif-panel shadow-lg" role="dialog" aria-label="Notifications" hidden>
    <div class="notif-panel-head">
        <i class="bi bi-bell-fill"></i>
        <strong>Notifications</strong>
        <span class="badge rounded-pill bg-warning text-dark" id="notifPanelCount">0</span>
        <button type="button" class="btn btn-sm btn-link ms-auto text-decoration-none p-0 me-2" id="notifMarkRead">Mark all as read</button>
        <button type="button" class="btn-close btn-close-white" id="notifClose" aria-label="Close"></button>
    </div>
    <div class="notif-panel-body" id="notifList"></div>
</div>
<button type="button" id="notifFab" class="notif-fab" aria-haspopup="dialog" aria-expanded="false" title="Notifications">
    <i class="bi bi-bell-fill"></i>
    <span class="notif-fab-badge" id="notifBadge" hidden>0</span>
</button>
<script type="application/json" id="notifData"><?= json_encode($_notifs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>/assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>" defer></script>
<?php endif; ?>

<!-- WRAPPER -->
<div class="d-flex app-wrap" style="min-height: calc(100vh - 56px);">
<div class="sidebar-backdrop d-lg-none" data-drawer-close></div>
