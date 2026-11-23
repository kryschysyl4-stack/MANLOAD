<?php
/**
 * Makes MANLOAD_upload.zip (next to the MANLOAD folder) for Hostinger:
 * upload it to public_html, Extract, open the site — done.
 *
 *   C:\xampp\php\php.exe -d extension=zip tools\build_upload.php [path\to\hosting-local.php]
 *
 * - Refreshes sql/manload_data.sql from the XAMPP database, so the site starts with your data
 *   (the tables are imported automatically on the first visit, see config/db.php).
 * - The optional file is put in the zip as config/local.php (hosting DB login, APP_SECRET).
 *   Without it the site opens install.php once to ask for the DB login.
 * - Leaves out private / local-only files: passenger ID photos, mail logs, archives,
 *   this XAMPP's config/local.php and storage/db_ready.flag.
 */
if (PHP_SAPI !== 'cli') exit;

$root = realpath(__DIR__ . '/..');
$zipPath = dirname($root) . DIRECTORY_SEPARATOR . 'MANLOAD_upload.zip';
$hostLocal = $argv[1] ?? null;
if ($hostLocal !== null && !is_file($hostLocal)) exit("Not found: $hostLocal\n");

// 1. Fresh copy of the XAMPP database
$dump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
if (is_file($dump)) {
    $out = $root . '/sql/manload_data.sql';
    exec('"' . $dump . '" -uroot --skip-comments --single-transaction --skip-triggers --default-character-set=utf8mb4 manload > "' . $out . '.new"', $o, $rc);
    if ($rc !== 0 || filesize($out . '.new') < 1000) exit("mysqldump failed — is MySQL running in XAMPP?\n");
    rename($out . '.new', $out);
    echo "Database copied to sql/manload_data.sql\n";
}

// 2. Zip
$skip = [
    '#^tools(/|$)#', '#^config/local\.php$#', '#^storage/db_(ready|schema_[a-z0-9_]+|profiles_linked)\.flag$#', '#^storage/imports(/|$)#',
    '#^storage/(id_photos|mail_log|archives)/(?!\.gitkeep$|\.htaccess$|tmp/?$).+#',
    '#^assets/uploads/.+#', '#(^|/)\.(git|vscode|idea)(/|$)#',
];
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) exit("Cannot write $zipPath\n");
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$n = 0;
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    foreach ($skip as $re) if (preg_match($re, $rel)) continue 2;
    if ($f->isDir()) { $zip->addEmptyDir($rel); continue; }
    $zip->addFile($f->getPathname(), $rel);
    $n++;
}
// Empty folders the app writes to
foreach (['storage/archives', 'storage/id_photos/tmp', 'storage/mail_log', 'assets/uploads'] as $d) $zip->addEmptyDir($d);
if ($hostLocal !== null) { $zip->addFile($hostLocal, 'config/local.php'); $n++; }
$zip->close();
echo "$n files -> $zipPath" . ($hostLocal ? " (with config/local.php)" : " (no config/local.php: install.php will ask for the DB login)") . "\n";
