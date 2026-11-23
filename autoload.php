<?php
/**
 * Tiny PSR-4 autoloader for the bundled libraries (no Composer needed on XAMPP).
 *   chillerlan/php-qrcode 5.0.3 (MIT/Apache-2.0)          -> lib/chillerlan/QRCode
 *   chillerlan/php-settings-container 2.1.6 (MIT)         -> lib/chillerlan/Settings
 * Used to draw QR codes as PNG on the server, for e-mails (mail apps cannot run JavaScript).
 */
spl_autoload_register(function (string $class): void {
    static $map = [
        'chillerlan\\QRCode\\'   => __DIR__ . '/chillerlan/QRCode/',
        'chillerlan\\Settings\\' => __DIR__ . '/chillerlan/Settings/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});
