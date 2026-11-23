<?php
require_once __DIR__ . '/config/auth.php';

session_start_once();

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ── Failed sign-in counter ────────────────────────────────────────────────
// Counted per username + IP in `login_throttle`. LOGIN_MAX_FAILS misses in a row
// lock that pair for 1 min, then 2, 4, 8 … up to 15 min on repeat lockouts.
const LOGIN_MAX_FAILS  = 5;
const LOGIN_FAIL_RESET = 900;     // misses older than 15 min are forgotten
const LOGIN_LOCK_MAX   = 900;

function login_throttle_key(string $username): string {
    return substr(mb_strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 120);
}

/** Current state: fails, lockouts, seconds left on the lock (times come from MySQL NOW()). */
function login_throttle_get(mysqli $db, string $key): array {
    $db->query('CREATE TABLE IF NOT EXISTS login_throttle (
        throttle_key varchar(120) NOT NULL, fails tinyint(3) unsigned NOT NULL DEFAULT 0,
        lockouts tinyint(3) unsigned NOT NULL DEFAULT 0, locked_until datetime DEFAULT NULL,
        updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (throttle_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $stmt = $db->prepare(
        'SELECT fails, lockouts,
                GREATEST(COALESCE(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0), 0) AS lock_left,
                TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age
         FROM login_throttle WHERE throttle_key = ?'
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) return ['fails' => 0, 'lockouts' => 0, 'lock_left' => 0];
    if ($r['lock_left'] == 0 && $r['age'] > LOGIN_FAIL_RESET) $r['fails'] = 0;
    if ($r['age'] > 86400) $r['lockouts'] = 0;
    return ['fails' => (int)$r['fails'], 'lockouts' => (int)$r['lockouts'], 'lock_left' => (int)$r['lock_left']];
}

/** Record one miss. Returns [attempts left, lock seconds (0 = not locked)]. */
function login_throttle_fail(mysqli $db, string $key, array $t): array {
    $fails = $t['fails'] + 1; $lockouts = $t['lockouts']; $lock = 0;
    if ($fails >= LOGIN_MAX_FAILS) {
        $lock = min(60 * (2 ** min($lockouts, 4)), LOGIN_LOCK_MAX);
        $lockouts++; $fails = 0;
    }
    $stmt = $db->prepare(
        'INSERT INTO login_throttle (throttle_key, fails, lockouts, locked_until)
         VALUES (?, ?, ?, IF(? > 0, NOW() + INTERVAL ? SECOND, NULL))
         ON DUPLICATE KEY UPDATE fails = VALUES(fails), lockouts = VALUES(lockouts),
                                 locked_until = VALUES(locked_until), updated_at = NOW()'
    );
    $stmt->bind_param('siiii', $key, $fails, $lockouts, $lock, $lock);
    $stmt->execute();
    $stmt->close();
    return [$lock ? 0 : LOGIN_MAX_FAILS - $fails, $lock];
}

$error     = '';
$warn      = false;   // amber style: few attempts left
$lock_secs = 0;       // > 0: form is locked, show countdown

// Keep the countdown on screen if the page is reloaded during a lock
if (!empty($_SESSION['login_lock_until']) && $_SESSION['login_lock_until'] > time()) {
    $lock_secs = $_SESSION['login_lock_until'] - time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim(str_param($_POST['username'] ?? ''));
    $password = trim(str_param($_POST['password'] ?? ''));

    if ($username && $password) {
        $db        = db();
        $tkey      = login_throttle_key($username);
        $t         = login_throttle_get($db, $tkey);
        $lock_secs = $t['lock_left'];
        $row       = null;

        if (!$lock_secs) {
            $stmt = $db->prepare(
                'SELECT user_id, username, password_hash, full_name, role, port_id, is_active
                 FROM user WHERE username = ? LIMIT 1'
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($lock_secs) {
            $error = 'Too many failed attempts. Sign-in is locked.';
        } elseif ($row && $row['is_active'] && password_verify($password, $row['password_hash'])) {
            $stmt = $db->prepare('DELETE FROM login_throttle WHERE throttle_key = ?');
            $stmt->bind_param('s', $tkey);
            $stmt->execute();
            $stmt->close();
            unset($_SESSION['login_lock_until']);

            session_regenerate_id(true);
            $_SESSION['user_id']   = $row['user_id'];
            $_SESSION['username']  = $row['username'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['role']      = $row['role'];
            $_SESSION['port_id']   = $row['port_id'];

            // Update last_login
            $db->query('UPDATE user SET last_login = NOW() WHERE user_id = ' . (int)$row['user_id']);
            $_SESSION['checked_at'] = time();
            csrf_token();

            audit('Login', 'user', $row['user_id']);
            $dest = BASE_URL . '/dashboard.php';
            header('Location: ' . $dest);
            exit;
        } else {
            [$left, $lock_secs] = login_throttle_fail($db, $tkey, $t);
            if ($lock_secs) {
                $error = 'Too many failed attempts. Sign-in is locked.';
                audit('Login locked: ' . substr($username, 0, 50), 'user', (int)($row['user_id'] ?? 0));
            } else {
                $error = 'Invalid username or password. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left before sign-in is locked.';
                $warn  = $left <= 2;
            }
        }
        if ($lock_secs) $_SESSION['login_lock_until'] = time() + $lock_secs;
    } else {
        $error = 'Please enter username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — MANLOAD</title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/img/logo-round.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        :root { --navy:#071a33; --navy-2:#0b2e5c; --sea:#0e6ba8; --sky:#38bdf8; --accent:#f5a623; --ink:#0f172a; --muted:#64748b; }
        * { box-sizing:border-box; }
        html, body { height:100%; }
        body { margin:0; font-family:system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f1f5f9; color:var(--ink); }
        .lg { display:flex; min-height:100vh; }

        /* ── Left: ocean panel ── */
        .lg-sea { position:relative; flex:1 1 100%; min-height:100vh; overflow:hidden; color:#fff;
                  background:radial-gradient(1200px 600px at 20% 10%, #1d4f91 0%, transparent 60%),
                             linear-gradient(160deg, var(--navy) 0%, var(--navy-2) 55%, var(--sea) 100%);
                  display:flex; flex-direction:column; justify-content:center; padding:3rem clamp(2rem, 6vw, 5rem) 12rem; }
        .lg-sea::before { content:""; position:absolute; inset:0; background-image:radial-gradient(rgba(255,255,255,.18) 1px, transparent 1.5px);
                          background-size:28px 28px; mask-image:linear-gradient(180deg, #000 0%, transparent 65%); opacity:.5; }
        .lg-brand { position:relative; display:flex; align-items:center; gap:1rem; margin-bottom:2.2rem; }
        .lg-brand img { width:76px; height:76px; border-radius:50%; background:#fff; border:4px solid var(--accent);
                        box-shadow:0 0 0 8px rgba(245,166,35,.15), 0 10px 30px rgba(0,0,0,.35); }
        .lg-brand .n { font-weight:900; font-size:1.9rem; letter-spacing:.04em; line-height:1; }
        .lg-brand .s { font-size:.72rem; font-weight:700; letter-spacing:.32em; color:var(--accent); margin-top:.35rem; }
        .lg-h { position:relative; font-weight:900; font-size:clamp(2.2rem, 4.2vw, 3.6rem); line-height:1.05; margin:0 0 1rem; }
        .lg-h span { background:linear-gradient(90deg, var(--sky), #a5f3fc); -webkit-background-clip:text; background-clip:text; color:transparent; }
        .lg-stats { position:relative; display:flex; gap:2rem; margin-top:2rem; color:#cbd5e1; font-size:.85rem; }
        .lg-stats b { display:block; color:#fff; font-size:1.35rem; }

        /* waves + ferry */
        .lg-waves { position:absolute; left:0; right:0; bottom:0; height:190px; }
        .lg-waves svg { position:absolute; bottom:0; width:200%; height:100%; }
        .w1 { animation:drift 14s linear infinite; opacity:.35; }
        .w2 { animation:drift 9s linear infinite reverse; opacity:.55; bottom:-10px !important; }
        .w3 { animation:drift 6s linear infinite; z-index:3; }
        @keyframes drift { from { transform:translateX(0); } to { transform:translateX(-50%); } }
        .lg-waves .lg-ship { position:absolute; z-index:2; bottom:50px; width:240px; height:auto; animation:sail 28s linear infinite, bob 3s ease-in-out infinite; }
        @keyframes sail { from { left:-260px; } to { left:calc(100% + 20px); } }   /* enters from the left edge, leaves past the right edge */
        @keyframes bob { 0%,100% { transform:translateY(0) rotate(-1deg); } 50% { transform:translateY(-6px) rotate(1deg); } }

        /* ── Top-right "Log In" button ── */
        .lg-top { position:absolute; top:0; right:0; z-index:5; padding:1.25rem clamp(1.25rem, 4vw, 3rem); }
        .lg-open { display:inline-flex; align-items:center; gap:.5rem; height:46px; padding:0 1.4rem; border-radius:999px; border:2px solid var(--accent);
                   background:var(--accent); color:var(--navy); font-weight:800; letter-spacing:.04em; box-shadow:0 8px 22px rgba(0,0,0,.25); transition:transform .1s, background .15s; }
        .lg-open:hover { background:#ffb83d; transform:translateY(-1px); }
        .lg-open:focus-visible { outline:3px solid #fff; outline-offset:3px; }

        /* ── Sign-in panel: slides in from the right when "Log In" is clicked ── */
        .lg-backdrop { position:fixed; inset:0; background:rgba(2,6,23,.55); z-index:20; opacity:0; visibility:hidden; transition:opacity .25s, visibility 0s .25s; }
        .lg-form { position:fixed; top:0; right:0; bottom:0; width:min(480px, 100%); z-index:30; background:#f1f5f9; overflow-y:auto;
                   display:flex; align-items:center; justify-content:center; padding:2.5rem 1.5rem; box-shadow:-12px 0 40px rgba(0,0,0,.3);
                   transform:translateX(100%); visibility:hidden; transition:transform .3s ease, visibility 0s .3s; }
        body.login-open .lg-form { transform:none; visibility:visible; transition:transform .3s ease; }
        body.login-open .lg-backdrop { opacity:1; visibility:visible; transition:opacity .25s; }
        body.login-open .lg-top { visibility:hidden; }
        .lg-close { position:absolute; top:1rem; right:1rem; width:40px; height:40px; border:0; border-radius:50%; background:#e2e8f0; color:#334155; font-size:1rem; }
        .lg-close:hover { background:#cbd5e1; }
        .lg-card { width:100%; max-width:420px; }
        .lg-card h1 { font-weight:800; font-size:2rem; margin:0; color:var(--navy); }
        .lg-card .sub { color:var(--muted); margin:.4rem 0 2rem; }
        .lg-field { position:relative; margin-bottom:1.1rem; }
        .lg-field label { display:block; font-size:.8rem; font-weight:700; letter-spacing:.04em; color:#334155; margin-bottom:.4rem; text-transform:uppercase; }
        .lg-field .ic { position:absolute; left:1rem; bottom:.95rem; color:#94a3b8; font-size:1.1rem; pointer-events:none; }
        .lg-field input { width:100%; height:54px; border:2px solid #e2e8f0; border-radius:14px; padding:0 3rem 0 2.9rem; font-size:1.05rem; background:#fff; transition:border-color .15s, box-shadow .15s; }
        .lg-field input:focus { outline:none; border-color:var(--sea); box-shadow:0 0 0 4px rgba(14,107,168,.15); }
        .lg-field input:focus + .ic, .lg-field:focus-within .ic { color:var(--sea); }
        .lg-eye { position:absolute; right:.6rem; bottom:.55rem; border:0; background:none; color:#94a3b8; font-size:1.2rem; padding:.3rem .5rem; border-radius:8px; }
        .lg-eye:hover { color:var(--sea); }
        .lg-caps { display:none; font-size:.8rem; color:#b45309; margin:-.6rem 0 .8rem; }
        .lg-btn { width:100%; height:54px; border:0; border-radius:14px; color:#fff; font-weight:800; font-size:1.05rem; letter-spacing:.03em;
                  background:linear-gradient(90deg, var(--navy-2), var(--sea)); box-shadow:0 10px 24px rgba(14,107,168,.35); transition:transform .1s, box-shadow .15s; margin-top:.6rem; }
        .lg-btn:hover { box-shadow:0 14px 30px rgba(14,107,168,.45); transform:translateY(-1px); }
        .lg-btn:active { transform:translateY(0); }
        .lg-err { display:flex; gap:.6rem; align-items:center; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:12px; padding:.75rem 1rem; margin-bottom:1.2rem; font-size:.95rem; animation:shake .35s; }
        @keyframes shake { 25% { transform:translateX(-6px); } 75% { transform:translateX(6px); } }
        .lg-err.warn { background:#fffbeb; color:#b45309; border-color:#fde68a; }
        .lg-lock { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:12px; padding:1rem; margin-bottom:1.2rem; text-align:center; }
        .lg-lock .t { font-size:2.2rem; font-weight:900; font-variant-numeric:tabular-nums; line-height:1.1; margin:.3rem 0; }
        .lg-lock small { color:#7f1d1d; }
        .lg-lock.done { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
        form[aria-disabled="true"] { opacity:.55; pointer-events:none; }
        .lg-foot { text-align:center; color:#94a3b8; font-size:.75rem; margin-top:2rem; }

        /* loading screen while signing in: semi-transparent logo spinning in the middle */
        .lg-loader { position:fixed; inset:0; z-index:100; display:grid; place-items:center; background:rgba(241,245,249,.55); backdrop-filter:blur(2px);
                     opacity:0; visibility:hidden; transition:opacity .2s; }
        .lg-loader .anchor-spin { width:84px; height:84px; color:var(--navy-2); opacity:.6; animation:lgspin 1.1s linear infinite; }
        body.lg-loading .lg-loader { opacity:1; visibility:visible; }
        @keyframes lgspin { to { transform:rotate(360deg); } }

        @media (max-width: 991.98px) {
            .lg { flex-direction:column; }
            .lg-sea { flex:1 1 auto; padding:6rem 1.5rem 9rem; }
            .lg-top { padding:1rem; } .lg-open { height:42px; padding:0 1.1rem; }
            .lg-h { font-size:2rem; } .lg-stats { display:none; }
            .lg-brand { margin-bottom:1.2rem; } .lg-brand img { width:60px; height:60px; }
            .lg-waves { height:110px; } .lg-waves .lg-ship { width:150px; bottom:28px; }
            .lg-form { padding:4rem 1.25rem 3rem; align-items:flex-start; }
        }
        @media (prefers-reduced-motion: reduce) { .w1, .w2, .w3, .lg-waves .lg-ship, .lg-err { animation:none; } .lg-waves .lg-ship { left:60%; } .lg-form, .lg-backdrop { transition:none !important; } }
    </style>
</head>
<body<?= ($error || $lock_secs > 0) ? ' class="login-open"' : '' ?>>
<div class="lg-loader" role="status" aria-live="polite"><svg class="anchor-spin" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="10" r="5"/><path d="M32 15v42M20 24h24M10 38c2 11 11 19 22 19s20-8 22-19M10 38l-4 5M10 38l6 3M54 38l4 5M54 38l-6 3"/></svg><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Signing in…</span></div>
<main class="lg">

    <div class="lg-top">
        <button type="button" class="lg-open" id="lgOpen" aria-controls="lgPanel" aria-expanded="<?= ($error || $lock_secs > 0) ? 'true' : 'false' ?>">
            <i class="bi bi-box-arrow-in-right"></i>LOG IN
        </button>
    </div>

    <!-- ═══════════ OCEAN PANEL ═══════════ -->
    <section class="lg-sea" aria-label="MANLOAD">
        <div class="lg-brand">
            <img src="<?= BASE_URL ?>/assets/img/logo-round.png" alt="MANLOAD logo">
            <div><div class="n">MANLOAD</div><div class="s">SEA PORT OPERATIONS</div></div>
        </div>
        <h2 class="lg-h">Every passenger counted.<br><span>Every voyage balanced.</span></h2>
        <div class="lg-stats">
            <div><b><i class="bi bi-people"></i></b>Live manifests</div>
            <div><b><i class="bi bi-speedometer2"></i></b>Load monitoring</div>
            <div><b><i class="bi bi-qr-code-scan"></i></b>QR boarding</div>
        </div>

        <div class="lg-waves" aria-hidden="true">
            <svg class="lg-ship" viewBox="0 0 160 80">
                <path d="M10 52 L150 52 L132 74 L26 74 Z" fill="#ffffff"/>
                <path d="M10 52 L150 52 L147 57 L13 57 Z" fill="#f5a623"/>
                <rect x="40" y="32" width="84" height="20" rx="4" fill="#ffffff"/>
                <rect x="58" y="16" width="52" height="17" rx="4" fill="#ffffff"/>
                <g fill="#0b2e5c"><rect x="46" y="38" width="8" height="6" rx="2"/><rect x="58" y="38" width="8" height="6" rx="2"/><rect x="70" y="38" width="8" height="6" rx="2"/><rect x="82" y="38" width="8" height="6" rx="2"/><rect x="94" y="38" width="8" height="6" rx="2"/><rect x="106" y="38" width="8" height="6" rx="2"/>
                   <rect x="64" y="21" width="8" height="6" rx="2"/><rect x="76" y="21" width="8" height="6" rx="2"/><rect x="88" y="21" width="8" height="6" rx="2"/></g>
                <rect x="94" y="4" width="8" height="13" fill="#f5a623"/>
            </svg>
            <svg class="w1" viewBox="0 0 1440 190" preserveAspectRatio="none"><path fill="#38bdf8" d="M0 90 C180 50 360 130 540 90 S900 50 1080 90 S1260 130 1440 90 L1440 190 L0 190 Z M1440 90 C1620 50 1800 130 1980 90 S2340 50 2520 90 S2700 130 2880 90 L2880 190 L1440 190 Z"/></svg>
            <svg class="w2" viewBox="0 0 1440 190" preserveAspectRatio="none"><path fill="#0e6ba8" d="M0 110 C240 80 480 140 720 110 S1200 80 1440 110 L1440 190 L0 190 Z M1440 110 C1680 80 1920 140 2160 110 S2640 80 2880 110 L2880 190 L1440 190 Z"/></svg>
            <svg class="w3" viewBox="0 0 1440 190" preserveAspectRatio="none"><path fill="#071a33" d="M0 140 C200 120 400 160 600 140 S1000 120 1200 140 S1360 150 1440 140 L1440 190 L0 190 Z M1440 140 C1640 120 1840 160 2040 140 S2440 120 2640 140 S2800 150 2880 140 L2880 190 L1440 190 Z"/></svg>
        </div>
    </section>

    <!-- ═══════════ SIGN IN ═══════════ -->
    <div class="lg-backdrop" id="lgBackdrop"></div>
    <section class="lg-form" id="lgPanel" role="dialog" aria-modal="true" aria-labelledby="lgTitle">
        <button type="button" class="lg-close" id="lgClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        <div class="lg-card">
            <h1 id="lgTitle">Welcome aboard <span aria-hidden="true">⚓</span></h1>
            <p class="sub">Sign in with your staff account to continue.</p>

            <?php if ($lock_secs > 0): ?>
            <div class="lg-lock" id="lgLock" role="alert" data-secs="<?= (int)$lock_secs ?>">
                <div><i class="bi bi-shield-lock-fill me-1"></i><b>Too many failed attempts</b></div>
                <div class="t" id="lgLockT"><?= sprintf('%d:%02d', intdiv($lock_secs, 60), $lock_secs % 60) ?></div>
                <small id="lgLockMsg">Sign-in is locked after <?= LOGIN_MAX_FAILS ?> wrong passwords. Please wait before trying again.</small>
            </div>
            <?php elseif ($error): ?>
            <div class="lg-err<?= $warn ? ' warn' : '' ?>" role="alert"><i class="bi bi-<?= $warn ? 'exclamation-triangle-fill' : 'exclamation-octagon-fill' ?>"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off" id="lgForm"<?= $lock_secs > 0 ? ' aria-disabled="true"' : '' ?>>
                <?= csrf_field() ?>
                <div class="lg-field">
                    <label for="lgUser">Username</label>
                    <input type="text" id="lgUser" name="username" placeholder="Enter your username"
                           value="<?= htmlspecialchars(str_param($_POST['username'] ?? '')) ?>" required autocapitalize="off" spellcheck="false">
                    <i class="bi bi-person-fill ic"></i>
                </div>
                <div class="lg-field">
                    <label for="lgPass">Password</label>
                    <input type="password" id="lgPass" name="password" placeholder="Enter your password" required>
                    <i class="bi bi-lock-fill ic"></i>
                    <button type="button" class="lg-eye" id="lgEye" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
                </div>
                <div class="lg-caps" id="lgCaps" role="status"><i class="bi bi-capslock-fill me-1"></i>Caps Lock is on</div>
                <button type="submit" class="lg-btn"<?= $lock_secs > 0 ? ' disabled' : '' ?>><i class="bi bi-box-arrow-in-right me-2"></i>SIGN IN</button>
            </form>


            <p class="lg-foot">MANLOAD v<?= APP_VERSION ?> · Vessel Manifest &amp; Load Control System</p>
        </div>
    </section>
</main>
<script>
(() => {
    // "Log In" button opens the sign-in panel; ✕ / backdrop / Esc closes it
    const openBtn = document.getElementById('lgOpen'), user = document.getElementById('lgUser');
    const setOpen = open => {
        document.body.classList.toggle('login-open', open);
        openBtn.setAttribute('aria-expanded', open);
        if (open) setTimeout(() => (user.value ? document.getElementById('lgPass') : user).focus(), 50);
        else openBtn.focus();
    };
    openBtn.addEventListener('click', () => setOpen(true));
    document.getElementById('lgClose').addEventListener('click', () => setOpen(false));
    document.getElementById('lgBackdrop').addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.body.classList.contains('login-open')) setOpen(false); });
    if (location.hash === '#login') setOpen(true);
    else if (document.body.classList.contains('login-open')) (user.value ? document.getElementById('lgPass') : user).focus();

    // Spinning logo while the sign-in request is on its way
    document.getElementById('lgForm').addEventListener('submit', () => document.body.classList.add('lg-loading'));
    window.addEventListener('pageshow', () => document.body.classList.remove('lg-loading'));

    const pass = document.getElementById('lgPass'), eye = document.getElementById('lgEye'), caps = document.getElementById('lgCaps');
    eye.addEventListener('click', () => {
        const show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eye.setAttribute('aria-pressed', show); eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        eye.innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
        pass.focus();
    });
    const capsCheck = e => { caps.style.display = e.getModifierState && e.getModifierState('CapsLock') ? 'block' : 'none'; };
    pass.addEventListener('keyup', capsCheck); pass.addEventListener('keydown', capsCheck);
    pass.addEventListener('blur', () => caps.style.display = 'none');

    // Lockout countdown: re-enable the form when it reaches 0:00
    const lock = document.getElementById('lgLock');
    if (lock) {
        const form = document.getElementById('lgForm'), t = document.getElementById('lgLockT');
        const end = Date.now() + parseInt(lock.dataset.secs, 10) * 1000;
        const tick = () => {
            const s = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            t.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            if (s > 0) return setTimeout(tick, 250);
            lock.classList.add('done');
            document.getElementById('lgLockMsg').textContent = 'You may try signing in again.';
            form.removeAttribute('aria-disabled');
            form.querySelector('button[type=submit]').disabled = false;
            pass.focus();
        };
        tick();
    }
})();
</script>
</body>
</html>
