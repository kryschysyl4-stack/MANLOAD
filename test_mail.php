<?php
// Send one test email and show exactly why it failed (if it did).
//   C:\xampp\php\php.exe tools\test_mail.php you@example.com
// Command line only - it cannot be opened from a browser.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/mailer.php';

$to = $argv[1] ?? '';
if (!valid_email($to)) { fwrite(STDERR, "Usage: php tools/test_mail.php you@example.com\n"); exit(2); }

echo "USE_SMTP : " . (USE_SMTP ? 'true' : 'false (SMTP_PASS not set in config/local.php)') . "\n";
echo "Server   : " . SMTP_HOST . ':' . SMTP_PORT . ' (' . SMTP_SECURE . ")\n";
echo "Login    : " . SMTP_USER . "  password: " . (strlen(str_replace(' ', '', SMTP_PASS)) === 16 ? '16 chars (looks like an App Password)' : 'NOT 16 chars - Gmail needs an App Password') . "\n";
echo "From     : " . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\n";
echo "To       : $to\n\n";

$ok = send_mail($to, 'MANLOAD test email ' . date('Y-m-d H:i:s'),
    mail_layout('TEST EMAIL', '<p>If you can read this, MANLOAD email sending works.</p>'));

echo $ok ? "RESULT: SENT - check the inbox (and Spam).\n" : "RESULT: NOT SENT - " . mail_last_error() . "\n";
echo "Log: storage/mail_log/mail_error.log\n";
exit($ok ? 0 : 1);
