<?php
/**
 * Live-server settings for MANLOAD.
 *
 * 1. Copy this file to config/local.php on the server (do NOT upload your
 *    local.php back to anyone — it holds passwords).
 * 2. Fill in the values below. Anything left out uses the XAMPP default.
 *
 * config/ is blocked from the browser by config/.htaccess.
 */

// Database (from your hosting control panel → MySQL Databases)
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

// Web folder — detected automatically ('' at the domain root, '/MANLOAD' in that
// subfolder). Only set it if links still point to the wrong place.
// define('BASE_URL', '');

// Long random text, private. Generate one with:  php -r "echo bin2hex(random_bytes(32));"
// Set it once before going live — changing it later breaks booking links / QR codes already sent.
define('APP_SECRET', 'paste-64-random-characters-here');

// Show full PHP errors on screen? Keep false on the live site.
define('APP_DEBUG', false);

// Real email (booking confirmations, e-tickets)
define('USE_SMTP', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');        // 'tls' (587) or 'ssl' (465)
define('SMTP_USER', 'your@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('MAIL_FROM', 'your@gmail.com');
define('MAIL_FROM_NAME', 'MANLOAD Sea Port');
define('MAIL_REPLY_TO', 'your@gmail.com');
// Gets a "New online booking" email whenever someone books on the website (commas for several)
define('NOTIFY_EMAIL', 'your@gmail.com');
