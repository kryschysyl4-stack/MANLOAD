<?php
/**
 * Passenger ID photos (online booking → staff ID verification).
 *
 * Files live in ID_PHOTO_DIR (storage/, denied by .htaccess). An upload first goes to
 * ID_PHOTO_DIR/tmp under a random name that is remembered in the session, so the photo
 * survives a form that comes back with errors. When the booking is saved the file is
 * moved up to ID_PHOTO_DIR and its name stored in passenger.id_photo.
 */
require_once __DIR__ . '/../config/app.php';

const ID_PHOTO_MAX_BYTES = 6 * 1024 * 1024;
const ID_PHOTO_TYPES     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const ID_PHOTO_NAME_RE   = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

/**
 * Save one uploaded file ($_FILES-style array) to the temp folder.
 * Returns [file name, null] on success, [null, error] on a bad file, [null, null] when nothing was uploaded.
 */
function id_photo_store_tmp(array $f): array {
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)($f['size'] ?? 0) > ID_PHOTO_MAX_BYTES)
        return [null, 'the ID photo is too large (max 6 MB).'];
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return [null, 'the ID photo could not be uploaded. Please try again.'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $info = @getimagesize($f['tmp_name']);
    if (!isset(ID_PHOTO_TYPES[$mime]) || !$info || $info[0] < 200 || $info[1] < 200)
        return [null, 'the ID photo must be a clear JPG, PNG or WEBP picture.'];

    $dir = ID_PHOTO_DIR . '/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return [null, 'the ID photo could not be saved. Please try again.'];
    id_photo_cleanup_tmp();
    $name = bin2hex(random_bytes(16)) . '.' . ID_PHOTO_TYPES[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return [null, 'the ID photo could not be saved. Please try again.'];
    $_SESSION['id_photo_tmp'][$name] = time();
    return [$name, null];
}

/** Is $name a temp photo uploaded in this session (and still on disk)? */
function id_photo_tmp_ok(string $name): bool {
    return preg_match(ID_PHOTO_NAME_RE, $name) && isset($_SESSION['id_photo_tmp'][$name]) && is_file(ID_PHOTO_DIR . "/tmp/$name");
}

/** Move a temp photo into the permanent folder once the booking is saved. */
function id_photo_commit(string $name): bool {
    if (!id_photo_tmp_ok($name)) return false;
    $ok = @rename(ID_PHOTO_DIR . "/tmp/$name", ID_PHOTO_DIR . "/$name");
    unset($_SESSION['id_photo_tmp'][$name]);
    if (!$ok) error_log("MANLOAD: could not move ID photo $name out of tmp");
    return $ok;
}

/** Full path of a saved ID photo, or null. */
function id_photo_path(?string $name): ?string {
    if (!$name || !preg_match(ID_PHOTO_NAME_RE, $name)) return null;
    $p = ID_PHOTO_DIR . "/$name";
    return is_file($p) ? $p : null;
}

/** Delete temp photos older than a day (abandoned booking forms). */
function id_photo_cleanup_tmp(): void {
    foreach (glob(ID_PHOTO_DIR . '/tmp/*') ?: [] as $f)
        if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
}

/** ID photo file names of these voyages' passengers (read before the rows are purged). */
function id_photo_names_for_voyages(mysqli $db, string $in_list): array {
    return array_column($db->query("SELECT id_photo FROM passenger WHERE id_photo IS NOT NULL AND voyage_id IN ($in_list)")->fetch_all(), 0);
}

/** Delete saved ID photos by file name; returns how many were removed. */
function id_photo_delete(array $names): int {
    $n = 0;
    foreach ($names as $name) if (($p = id_photo_path($name)) && @unlink($p)) $n++;
    return $n;
}
