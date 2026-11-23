<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Roles ─────────────────────────────────────────────────────────────────
// These are the ONLY valid roles (they match the `user.role` ENUM).
//   Admin       – administration (setup, users, audit) + everything else (the Back Office role was merged into Admin, 2026-11)
//   Passage     – passage booking: counter booking, payments/OR, check-in, baggage, boarding
//   Coast Guard – PCG verification and departure clearance
const ROLES = ['Admin', 'Passage', 'Coast Guard'];

// Who may open which area. Use these with require_role(...).
const ROLE_CHECKIN   = ['Admin', 'Passage'];
const ROLE_TICKETING = ['Admin', 'Passage'];
const ROLE_PAYMENTS  = ['Admin', 'Passage'];
const ROLE_CARGO     = ['Admin'];
const ROLE_OPS       = ['Admin', 'Passage'];
const ROLE_LOADPLAN  = ['Admin', 'Coast Guard'];
const ROLE_MANIFEST  = ['Admin', 'Coast Guard', 'Passage'];   // Passage: view / print / CSV only
const ROLE_COASTGUARD = ['Admin', 'Coast Guard'];
const ROLE_REPORTS   = ['Admin'];

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // secure: on an https site the login cookie is never sent over plain http
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
        session_start();
    }
}

/**
 * Require a logged-in, still-active user. Also verifies the CSRF token on
 * every POST request, so individual pages don't need to.
 */
function require_login(): void {
    session_start_once();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Re-check the account every 60 s so deactivated users are logged out
    if (($_SESSION['checked_at'] ?? 0) < time() - 60) {
        $st = db()->prepare('SELECT is_active, role FROM user WHERE user_id=?');
        $st->bind_param('i', $_SESSION['user_id']);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        if (!$u || !$u['is_active']) {
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        $_SESSION['role'] = $u['role'];
        $_SESSION['checked_at'] = time();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/dashboard.php?error=access_denied');
        exit;
    }
}

function current_user(): array {
    return [
        'user_id'   => $_SESSION['user_id'] ?? null,
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role'] ?? '',
        'port_id'   => $_SESSION['port_id'] ?? null,
    ];
}

function is_role(string ...$roles): bool {
    session_start_once();
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

function audit(string $action, string $table = '', int $record_id = 0, array $old = [], array $new = []): void {
    $db = db();
    $user_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $old_j   = $old ? json_encode($old) : null;
    $new_j   = $new ? json_encode($new) : null;
    $stmt = $db->prepare(
        'INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississs', $user_id, $action, $table, $record_id, $old_j, $new_j, $ip);
    $stmt->execute();
    $stmt->close();
}
