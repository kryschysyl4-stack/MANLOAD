<?php
require_once __DIR__ . '/app.php';   // loads config/local.php first, so its values win
// ── Mail Configuration ─────────────────────────────────────────────────────
// Set USE_SMTP=true and fill in SMTP_* to send real email.
// When USE_SMTP=false, emails are written to storage/mail_log/ for review.
// On the live server put these in config/local.php (they win over the values here).

defined('MAIL_FROM') || define('MAIL_FROM',      'noreply@manload.local');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'MANLOAD Sea Port');
defined('MAIL_REPLY_TO') || define('MAIL_REPLY_TO',  'info@manload.local');

defined('USE_SMTP') || define('USE_SMTP',      true);       // flip to true when SMTP is ready
defined('SMTP_HOST') || define('SMTP_HOST',     'smtp.gmail.com');
defined('SMTP_PORT') || define('SMTP_PORT',     587);
defined('SMTP_USER') || define('SMTP_USER',     'your@gmail.com');
defined('SMTP_PASS') || define('SMTP_PASS',     'your_app_password');
defined('SMTP_SECURE') || define('SMTP_SECURE',   'tls');       // 'tls' or 'ssl'

// Office inbox that gets a "New online booking" email for every booking made on the website.
// Several addresses: separate with commas. Empty = no notice.
defined('NOTIFY_EMAIL') || define('NOTIFY_EMAIL', '');
