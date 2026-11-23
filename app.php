<?php
// Per-server settings (database, web folder, secret, mail, debug) go in
// config/local.php — copy config/local.example.php. Anything it defines
// wins over the XAMPP defaults below and in db.php / mail.php.
if (is_file(__DIR__ . '/local.php')) require_once __DIR__ . '/local.php';

/**
 * Web folder of this app, worked out from the running script, e.g. '/MANLOAD' on
 * XAMPP, '' when uploaded straight into public_html, '/ferry' in a subfolder.
 * So links and redirects never point at a folder that does not exist (host 404).
 */
function app_detect_base_url(): string {
    if (PHP_SAPI === 'cli') return '/MANLOAD';
    $root   = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $file   = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($root !== '' && $file !== '' && $script !== '' && stripos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root));            // e.g. /modules/booking/index.php
        if (strcasecmp(substr($script, -strlen($rel)), $rel) === 0) {
            return rtrim(substr($script, 0, -strlen($rel)), '/');
        }
    }
    return '/MANLOAD';
}
defined('BASE_URL') || define('BASE_URL', app_detect_base_url());   // config/local.php may set it by hand
define('APP_NAME', 'MANLOAD');
define('APP_VERSION', '2.0');
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ── Errors ────────────────────────────────────────────────────────────────
// Debug (full PHP errors on screen) only when the site is opened as
// http://localhost on this PC, unless config/local.php sets APP_DEBUG.
// Everyone else never sees file paths or SQL: they get a short message and
// the details go to the web server's error log.
defined('APP_DEBUG') || define('APP_DEBUG', PHP_SAPI === 'cli' || (
    in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '')
));
if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(function (Throwable $ex): void {
        $code = date('His') . '-' . substr(md5((string)$ex), 0, 4);
        error_log("MANLOAD error $code: $ex");
        if (!headers_sent()) http_response_code(500);
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
             || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($json) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => "Something went wrong (code $code)."]);
            return;
        }
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Something went wrong</title>'
           . '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center">'
           . '<h2 style="color:#0b2545">Something went wrong</h2>'
           . '<p>Please go back and try again. If it keeps happening, tell the port office this code: <b>' . $code . '</b></p>'
           . '<p><a href="javascript:history.back()">&larr; Go back</a></p></div>';
    });
}

// Secret used to protect booking links and forms. Set your own in
// config/local.php BEFORE going live (changing it later invalidates booking
// links and ticket QR codes already sent).
defined('APP_SECRET') || define('APP_SECRET', 'change-this-to-a-long-random-string-manload-2026');
if (!APP_DEBUG && APP_SECRET === 'change-this-to-a-long-random-string-manload-2026') {
    error_log('MANLOAD WARNING: APP_SECRET is still the default — set it in config/local.php');
}

// Business settings (discount, passenger weight, booking cutoff, ticket
// validity, online payment accounts ...) are stored in the DATABASE and
// edited by the Admin in Setup > System Settings. See includes/settings.php.

// Folder where the monthly batch (archive) ZIP files are saved
define('ARCHIVE_DIR', __DIR__ . '/../storage/archives');

// Passenger ID photos attached to online bookings — private (storage/ is not web-readable), staff view them via modules/checkin/id_photo.php
define('ID_PHOTO_DIR', __DIR__ . '/../storage/id_photos');
