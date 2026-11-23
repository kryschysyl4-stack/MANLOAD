<?php
// Settings for THIS PC (XAMPP) only — tools/build_upload.php leaves this file out of the upload zip.
// Database settings are not set here, so the XAMPP defaults in config/db.php are used.

// Gmail sending. SMTP_PASS must be a Gmail *App Password* (16 letters), not your normal password:
// Google Account → Security → 2-Step Verification (turn on) → App passwords → create one for "MANLOAD".
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', 'kryschysyl4@gmail.com');
define('SMTP_PASS', 'fncz cdzz ijzj kvwu');
define('MAIL_FROM', 'kryschysyl4@gmail.com');
define('MAIL_FROM_NAME', 'MANLOAD Sea Port');
define('MAIL_REPLY_TO', 'kryschysyl4@gmail.com');
define('USE_SMTP', SMTP_PASS !== 'PASTE-APP-PASSWORD-HERE');   // stays off (mail_log only) until the password is filled in

// Gets a "New online booking" email for every booking made on the website
define('NOTIFY_EMAIL', 'kryschysyl4@gmail.com');
