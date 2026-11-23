# Bundled libraries (no Composer / internet needed)

| Folder | What | License |
|---|---|---|
| `chillerlan/QRCode` | chillerlan/php-qrcode 5.0.3 — draws QR codes as PNG on the server (for e-mails) | MIT / Apache-2.0 |
| `chillerlan/Settings` | chillerlan/php-settings-container 2.1.6 (needed by php-qrcode) | MIT |
| `fonts/` | DejaVu Sans fonts — text on the e-mailed boarding-pass image | Bitstream Vera / free (see LICENSE-DejaVu.txt) |

`autoload.php` loads the classes. Needs PHP 7.4+ with the **gd** and **mbstring** extensions
(both are enabled in XAMPP by default). This folder is blocked from the web by `.htaccess`.
