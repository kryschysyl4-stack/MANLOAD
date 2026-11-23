<?php
/**
 * One-time setup for a new server: connects to the hosting database, imports the
 * tables and writes config/local.php. Locks itself as soon as config/local.php exists
 * (delete that file to run it again).
 */
require_once __DIR__ . '/config/app.php';

const INSTALL_LOCAL = __DIR__ . '/config/local.php';
const INSTALL_DUMPS = [
    'fresh' => ['file' => __DIR__ . '/sql/manload.sql',      'label' => 'Empty system — ports, routes, vessels and demo accounts only'],
    'data'  => ['file' => __DIR__ . '/sql/manload_data.sql', 'label' => 'My data — copy of the XAMPP database (sql/manload_data.sql)'],
];

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
header('X-Robots-Tag: noindex');

function in_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Run every statement of a .sql file. Throws on the first error. */
function install_import(mysqli $db, string $file): void {
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') throw new RuntimeException(basename($file) . ' is missing or empty — upload it to the sql folder.');
    $db->multi_query($sql);
    do {
        if ($r = $db->store_result()) $r->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}

// ── Server checks ─────────────────────────────────────────────────────────
$checks = [
    'PHP 8.0 or newer (this server: ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.0.0', '>='),
];
foreach (['mysqli', 'mbstring', 'gd', 'curl', 'zip', 'openssl'] as $ext) $checks["PHP extension: $ext"] = extension_loaded($ext);
foreach (['config', 'storage/archives', 'storage/id_photos', 'storage/mail_log', 'assets/uploads'] as $dir) {
    $p = __DIR__ . '/' . $dir;
    if (!is_dir($p)) @mkdir($p, 0775, true);
    $checks["Folder writable: $dir/"] = is_dir($p) && is_writable($p);
}
if (is_dir(__DIR__ . '/storage/id_photos') && !is_dir(__DIR__ . '/storage/id_photos/tmp')) @mkdir(__DIR__ . '/storage/id_photos/tmp', 0775, true);

// A prepared config/local.php may set INSTALL_PENDING: its values pre-fill the form and
// can still be corrected until setup finishes (the finished file has no INSTALL_PENDING).
$pending = defined('INSTALL_PENDING') && INSTALL_PENDING;
// config/local.php exists but has no database login (e.g. replaced by the XAMPP copy):
// ask for the login again and keep the file's other settings (mail etc.).
$repair = is_file(INSTALL_LOCAL) && !$pending && !defined('DB_USER');
$done = is_file(INSTALL_LOCAL) && !$pending && !$repair;
$error = ''; $config_text = ''; $result = '';
$f = ['host' => defined('DB_HOST') ? DB_HOST : 'localhost', 'name' => defined('DB_NAME') ? DB_NAME : '', 'user' => defined('DB_USER') ? DB_USER : '',
      'content' => $repair ? 'none' : (is_file(INSTALL_DUMPS['data']['file']) ? 'data' : 'fresh')];

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf'] ?? '';
    foreach (['host', 'name', 'user', 'content'] as $k) $f[$k] = trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $pass    = is_string($_POST['pass'] ?? null) ? $_POST['pass'] : '';
    if ($pass === '' && $pending && defined('DB_PASS')) $pass = DB_PASS;   // keep the prepared password
    $admin   = is_string($_POST['admin_pass'] ?? null) ? $_POST['admin_pass'] : '';
    $replace = !empty($_POST['replace']);

    if (!is_string($sent) || !hash_equals($_SESSION['install_csrf'], $sent)) {
        $error = 'The page expired. Please try again.';
    } elseif ($f['host'] === '' || $f['name'] === '' || $f['user'] === '') {
        $error = 'Fill in the database host, name and user.';
    } elseif (str_starts_with($f['name'], 'u123456789_') || str_starts_with($f['user'], 'u123456789_')) {
        $error = 'u123456789_ is only an example. Type YOUR database name and user exactly as hPanel shows them (your own number, e.g. u729224543_manload).';
    } elseif (!in_array($f['content'], ['fresh', 'data', 'none'], true)) {
        $error = 'Choose what to put in the database.';
    } elseif ($f['content'] === 'fresh' && mb_strlen($admin) < 8) {
        $error = 'Choose a new password of at least 8 characters for the accounts.';
    } else {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            try {
                $db = new mysqli($f['host'], $f['user'], $pass, $f['name']);
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1045) throw new RuntimeException('Wrong database password for ' . $f['user'] . ' (or this user is not added to ' . $f['name'] . '). '
                    . 'In hPanel → Databases → MySQL Databases: click ⋮ / Change password next to this user, set a new password, then type that new password here.');
                if ($e->getCode() === 1044) throw new RuntimeException('User ' . $f['user'] . ' is not allowed to use database ' . $f['name'] . '. '
                    . 'In hPanel → Databases → MySQL Databases, use the database name shown on the same row as this user.');
                throw new RuntimeException('Could not connect: ' . $e->getMessage()
                    . '. Check the names in hPanel → Databases (they include the u123…_ prefix) and that the user is added to this database.');
            }
            $db->set_charset('utf8mb4');
            $db->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
            $has_tables = (bool)$db->query("SHOW TABLES LIKE 'user'")->num_rows;

            if ($f['content'] === 'none') {
                if (!$has_tables) throw new RuntimeException('This database is empty. Choose “Empty system” or “My data” to import the tables.');
            } else {
                if ($has_tables && !$replace) throw new RuntimeException('This database already has MANLOAD tables. Tick “Replace them” to overwrite, or choose “Already imported”.');
                install_import($db, INSTALL_DUMPS[$f['content']]['file']);
                if ($f['content'] === 'fresh') {
                    $hash = password_hash($admin, PASSWORD_DEFAULT);
                    $st = $db->prepare('UPDATE user SET password_hash = ?');
                    $st->bind_param('s', $hash);
                    $st->execute();
                }
            }
            $accounts = (int)$db->query('SELECT COUNT(*) FROM user')->fetch_row()[0];

            $keep = $repair ? trim(preg_replace(['/^\s*<\?php\s*/', '/^.*define\(\s*[\'"]DB_(HOST|USER|PASS|NAME)[\'"].*$\R?/m'], '',
                                                (string)file_get_contents(INSTALL_LOCAL))) : '';
            $has  = fn(string $c): bool => (bool)preg_match('/define\(\s*[\'"]' . $c . '[\'"]/', $keep);
            $config_text = "<?php\n// Written by install.php on " . date('Y-m-d H:i') . ". Keep this file private.\n"
                . 'define(\'DB_HOST\', ' . var_export($f['host'], true) . ");\n"
                . 'define(\'DB_USER\', ' . var_export($f['user'], true) . ");\n"
                . 'define(\'DB_PASS\', ' . var_export($pass, true) . ");\n"
                . 'define(\'DB_NAME\', ' . var_export($f['name'], true) . ");\n\n"
                . ($has('APP_SECRET') ? '' : "// Protects booking links and QR codes — do not change after going live.\n"
                    . 'define(\'APP_SECRET\', ' . var_export(bin2hex(random_bytes(32)), true) . ");\n")
                . ($has('APP_DEBUG') ? '' : "define('APP_DEBUG', false);\n")
                . ($keep !== '' ? "\n// ── Kept from the previous config/local.php ──\n" . $keep . "\n"
                                : "\n// Real e-mail: see config/local.example.php (USE_SMTP, SMTP_*).\n");
            if (@file_put_contents(INSTALL_LOCAL, $config_text, LOCK_EX) !== false) {
                $done = true;
                $result = "Database connected ($accounts accounts)."
                    . ($f['content'] === 'fresh' ? ' Every account now uses the password you chose — change each one later in Profile.' : '');
                $config_text = '';
            } else {
                $error = 'Everything worked, but config/local.php could not be saved (folder not writable). '
                       . 'In File Manager create config/local.php and paste the text below, then open the site.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>MANLOAD Setup</title>
<style>
  :root { --navy:#0b2545; --blue:#1d74f5; --line:#d7dce4; --bg:#f4f6fa; --ok:#198754; --bad:#c62828; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; color:#1b2433; }
  .wrap { max-width:560px; margin:32px auto; padding:0 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:24px; margin-bottom:16px; }
  h1 { color:var(--navy); font-size:22px; margin:0 0 4px; }
  h2 { font-size:16px; margin:0 0 12px; color:var(--navy); }
  p.sub { margin:0 0 20px; color:#5b6575; }
  label { display:block; font-weight:600; margin:12px 0 4px; }
  input[type=text], input[type=password] { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px; font-size:15px; }
  .hint { font-size:13px; color:#6b7585; margin-top:3px; }
  .opt { display:flex; gap:8px; align-items:flex-start; font-weight:400; margin:8px 0; }
  .opt input { margin-top:4px; }
  button, .btn { display:inline-block; background:var(--blue); color:#fff; border:0; border-radius:6px; padding:11px 20px; font-size:15px; font-weight:600; cursor:pointer; text-decoration:none; margin-top:18px; }
  .msg { padding:12px 14px; border-radius:6px; margin-bottom:16px; }
  .msg.bad { background:#fdecea; color:var(--bad); }
  .msg.ok { background:#e7f5ee; color:var(--ok); }
  ul.checks { list-style:none; padding:0; margin:0; font-size:14px; }
  ul.checks li { padding:3px 0; }
  .y { color:var(--ok); font-weight:700; } .n { color:var(--bad); font-weight:700; }
  textarea { width:100%; height:200px; font:13px/1.4 ui-monospace,Consolas,monospace; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>MANLOAD Setup</h1>
    <p class="sub">Connect the system to your hosting database. This page works only once.</p>

<?php if ($done): ?>
    <?php if ($result): ?><div class="msg ok">✓ <?= in_e($result) ?></div><?php endif; ?>
    <p>Setup is complete<?= $result ? '' : ' (config/local.php already exists — delete it to run setup again)' ?>.</p>
    <a class="btn" href="<?= in_e(BASE_URL) ?>/login.php">Go to login</a>
<?php else: ?>
    <?php if ($repair && !$error): ?><div class="msg bad">config/local.php has no database login (it was probably replaced by the copy from your PC).
      Enter your hPanel database details below and choose “Already imported — just connect”. Your data is not touched; the file's other settings (e-mail) are kept.</div><?php endif; ?>
    <?php if ($error): ?><div class="msg bad"><?= in_e($error) ?></div><?php endif; ?>
    <?php if ($config_text): ?><textarea readonly><?= in_e($config_text) ?></textarea><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= in_e($_SESSION['install_csrf']) ?>">
      <h2>1. Database (hPanel → Databases → MySQL Databases)</h2>
      <label for="host">Host</label>
      <input type="text" id="host" name="host" value="<?= in_e($f['host']) ?>">
      <div class="hint">Usually <b>localhost</b>.</div>
      <label for="name">Database name</label>
      <input type="text" id="name" name="name" value="<?= in_e($f['name']) ?>" placeholder="example: u123456789_manload">
      <div class="hint">Your own name from hPanel — it starts with your account number.</div>
      <label for="user">Database user</label>
      <input type="text" id="user" name="user" value="<?= in_e($f['user']) ?>" placeholder="example: u123456789_admin">
      <label for="pass">Database password</label>
      <input type="password" id="pass" name="pass">
      <?php if ($pending && defined('DB_PASS')): ?><div class="hint">Already filled in — leave blank to keep it.</div><?php endif; ?>

      <h2 style="margin-top:24px">2. What to put in the database</h2>
      <?php foreach (INSTALL_DUMPS as $k => $d): if (!is_file($d['file'])) continue; ?>
        <label class="opt"><input type="radio" name="content" value="<?= $k ?>" <?= $f['content'] === $k ? 'checked' : '' ?>> <?= in_e($d['label']) ?></label>
      <?php endforeach; ?>
      <label class="opt"><input type="radio" name="content" value="none" <?= $f['content'] === 'none' ? 'checked' : '' ?>> Already imported with phpMyAdmin — just connect</label>
      <label class="opt"><input type="checkbox" name="replace" value="1"> Replace them if the database already has MANLOAD tables (deletes what is there)</label>

      <label for="admin_pass">New password for the accounts (Empty system only)</label>
      <input type="password" id="admin_pass" name="admin_pass" minlength="8">
      <div class="hint">The demo accounts (admin, passage1, coastguard1) all get this password instead of “password”.</div>

      <button type="submit">Set up</button>
    </form>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>Server check</h2>
    <ul class="checks">
      <?php foreach ($checks as $label => $ok): ?>
        <li><span class="<?= $ok ? 'y' : 'n' ?>"><?= $ok ? '✓' : '✗' ?></span> <?= in_e($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
</body>
</html>
