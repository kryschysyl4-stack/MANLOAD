<?php
require_once __DIR__ . '/app.php';

// XAMPP defaults — on the live server set these in config/local.php
define('DB_FROM_LOCAL', defined('DB_USER'));   // false = local.php has no database login
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'manload');

// Written once the tables are in the database, so later requests skip the check
const DB_READY_FLAG = __DIR__ . '/../storage/db_ready.flag';

/** Send the browser to the one-time setup page (install.php). */
function db_goto_install(): void {
    if (PHP_SAPI !== 'cli' && !headers_sent() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: ' . BASE_URL . '/install.php');
        exit;
    }
}

/**
 * First visit on a new server: the database exists (made in hPanel) but is empty.
 * Import sql/manload_data.sql (copy of the XAMPP data) or sql/manload.sql, and make
 * the storage folders — so uploading the files is the only setup step.
 */
function db_auto_setup(mysqli $conn): void {
    if (is_file(DB_READY_FLAG)) return;
    foreach (['storage/archives', 'storage/id_photos/tmp', 'storage/mail_log', 'assets/uploads'] as $dir) {
        $p = __DIR__ . '/../' . $dir;
        if (!is_dir($p)) @mkdir($p, 0775, true);
    }
    // Only one request imports; the others wait for it (up to 2 minutes)
    $conn->query("SELECT GET_LOCK('manload_auto_setup', 120)");
    try {
        if (!$conn->query("SHOW TABLES LIKE 'user'")->num_rows) {
            $file = is_file(__DIR__ . '/../sql/manload_data.sql') ? __DIR__ . '/../sql/manload_data.sql' : __DIR__ . '/../sql/manload.sql';
            $sql = (string)@file_get_contents($file);
            if (trim($sql) === '') throw new RuntimeException('sql/manload_data.sql or sql/manload.sql is missing — upload the sql folder.');
            @set_time_limit(300);
            $conn->multi_query($sql);
            do {
                if ($r = $conn->store_result()) $r->free();
            } while ($conn->more_results() && $conn->next_result());
            error_log('MANLOAD: database tables imported from ' . basename($file));
        }
        @file_put_contents(DB_READY_FLAG, date('c'));
    } finally {
        $conn->query("SELECT RELEASE_LOCK('manload_auto_setup')");
    }
}

// Written once every sql/migrate_*.sql is in this database / every old ticket has its passenger profile,
// so later requests skip the checks (not uploaded: tools/build_upload.php leaves storage/db_*.flag out)
const DB_SCHEMA_FLAG   = __DIR__ . '/../storage/db_schema_v6.flag';
const DB_BACKFILL_FLAG = __DIR__ . '/../storage/db_profiles_linked.flag';

/**
 * Database changes a server may have missed (a sql/migrate_*.sql file never imported there, e.g. the
 * hosting set up from an older copy). Each migration is applied only when its mark is missing.
 * Runs right after connecting — before any transaction, because a table change would silently commit
 * a transaction that is still open. Problems go to the PHP error log; the flag is then not written.
 */
function db_schema_upgrade(mysqli $conn): void {
    if (!is_file(DB_READY_FLAG)) return;
    if (!is_file(DB_SCHEMA_FLAG)) {
        $conn->query("SELECT GET_LOCK('manload_schema_upgrade', 120)");
        try {
            $has_table = fn(string $t): bool => (bool)$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'")->num_rows;
            $col = fn(string $t, string $c): ?array => $has_table($t)
                ? ($conn->query("SHOW COLUMNS FROM `$t` LIKE '" . $conn->real_escape_string($c) . "'")->fetch_assoc() ?: null) : null;
            // migration file => is it already in? (oldest first)
            $checks = [
                'migrate_roles_v2.sql'          => fn() => str_contains((string)($col('user', 'role')['Type'] ?? ''), "'Passage'"),
                'migrate_passage_v2.sql'        => fn() => $has_table('vessel_class_seat') && $col('passenger', 'booked_by') && $col('vehicle_type', 'with_driver'),
                'migrate_schedule_v2.sql'       => fn() => (bool)$col('schedule_template', 'link_group'),
                'migrate_payment_qr.sql'        => fn() => (bool)$col('payment_account', 'qr_payload'),
                'migrate_id_photo.sql'          => fn() => (bool)$col('passenger', 'id_checked_at'),
                'migrate_login_throttle.sql'    => fn() => $has_table('login_throttle'),
                'migrate_maya.sql'              => fn() => $has_table('payment_gateway') && $col('passenger', 'online_pay_checkout'),
                'migrate_passenger_profile.sql' => fn() => $has_table('passenger_profile') && $col('passenger', 'profile_id'),
                'migrate_cargo_rates.sql'       => fn() => $has_table('cargo_rate'),
                'migrate_roro_seats.sql'        => fn() => !$conn->query("SELECT 1 FROM vessel v
                        LEFT JOIN vessel_class_seat s ON s.vessel_id = v.vessel_id AND s.class = 'Economy'
                        WHERE v.vessel_type = 'RORO' AND (v.max_passengers <> 270 OR s.capacity IS NULL OR s.capacity <> 270
                           OR EXISTS (SELECT 1 FROM vessel_class_seat x WHERE x.vessel_id = v.vessel_id AND x.class <> 'Economy' AND x.capacity > 0))")->num_rows,
                'migrate_accounts_rewards.sql'  => fn() => $has_table('ledger_entry') && $has_table('passenger_account') && $col('passenger', 'voucher_discount')
                        && str_contains((string)($col('payment', 'pay_method')['Type'] ?? ''), "'Wallet'"),
                'migrate_roles_v3.sql'          => fn() => !str_contains((string)($col('user', 'role')['Type'] ?? ''), "'Back Office'"),
            ];
            $ok = true;
            foreach ($checks as $file => $is_in) {
                if ($is_in()) continue;
                if ($file === 'migrate_passenger_profile.sql') { db_add_passenger_profile($conn, $has_table, $col); continue; }   // may be half in
                $sql = (string)@file_get_contents(__DIR__ . '/../sql/' . $file);
                if (trim($sql) === '') { $ok = false; error_log("MANLOAD: sql/$file is missing — cannot apply it"); continue; }
                try {
                    $conn->multi_query($sql);
                    do { if ($r = $conn->store_result()) $r->free(); } while ($conn->more_results() && $conn->next_result());
                    error_log("MANLOAD: applied sql/$file (it was not in this database)");
                } catch (mysqli_sql_exception $e) {
                    $ok = false;
                    error_log("MANLOAD: sql/$file could not be applied: " . $e->getMessage() . ' — import it in phpMyAdmin.');
                }
            }
            if ($ok) @file_put_contents(DB_SCHEMA_FLAG, date('c'));
        } catch (mysqli_sql_exception $e) {
            error_log('MANLOAD schema upgrade failed: ' . $e->getMessage());
        } finally {
            $conn->query("SELECT RELEASE_LOCK('manload_schema_upgrade')");
        }
    }
    db_backfill_profiles($conn);
}

/**
 * Old tickets without a passenger profile (made before the profiles existed on this server) are linked
 * a few hundred per request — what tools/backfill_profiles.php does — until none is left.
 */
function db_backfill_profiles(mysqli $conn): void {
    static $busy = false;
    if ($busy || is_file(DB_BACKFILL_FLAG) || !is_file(DB_SCHEMA_FLAG) || !function_exists('link_passenger_profile')) return;
    $busy = true;
    $locked = false;
    try {
        $locked = (bool)$conn->query("SELECT GET_LOCK('manload_profile_backfill', 0)")->fetch_row()[0];
        if (!$locked) return;   // another request is on it
        $ids = $conn->query('SELECT passenger_id FROM passenger WHERE profile_id IS NULL ORDER BY booked_at, passenger_id LIMIT 300')->fetch_all(MYSQLI_NUM);
        if (!$ids) { @file_put_contents(DB_BACKFILL_FLAG, date('c')); return; }
        @set_time_limit(120);
        $conn->begin_transaction();
        foreach ($ids as [$pid]) link_passenger_profile((int)$pid);
        $conn->commit();
        error_log('MANLOAD: linked ' . count($ids) . ' old ticket(s) to passenger profiles');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $e2) { /* nothing open */ }
        error_log('MANLOAD profile backfill failed: ' . $e->getMessage());
    } finally {
        if ($locked) $conn->query("SELECT RELEASE_LOCK('manload_profile_backfill')");
        $busy = false;
    }
}

/** passenger_profile table + passenger.profile_id (sql/migrate_passenger_profile.sql), whichever part is missing. */
function db_add_passenger_profile(mysqli $conn, callable $has_table, callable $col): void {
    if (!$has_table('passenger_profile')) {
        $conn->query("CREATE TABLE IF NOT EXISTS `passenger_profile` (
            `profile_id` int(11) NOT NULL AUTO_INCREMENT, `last_name` varchar(50) NOT NULL, `first_name` varchar(50) NOT NULL,
            `middle_name` varchar(50) DEFAULT NULL, `sex` enum('Male','Female') NOT NULL, `age` int(11) DEFAULT NULL,
            `nationality` varchar(50) DEFAULT 'Filipino', `pax_category` enum('Adult','Student','Minor','Senior','Infant','PWD') NOT NULL DEFAULT 'Adult',
            `id_type` varchar(30) DEFAULT NULL, `id_number` varchar(30) DEFAULT NULL, `contact_no` varchar(20) DEFAULT NULL,
            `email` varchar(100) DEFAULT NULL, `address` text DEFAULT NULL, `emergency_contact` varchar(100) DEFAULT NULL,
            `trips` int(11) NOT NULL DEFAULT 0, `last_trip_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(), `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`profile_id`), KEY `name` (`last_name`,`first_name`), KEY `id_doc` (`id_type`,`id_number`),
            KEY `contact_no` (`contact_no`), KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        error_log('MANLOAD: created table passenger_profile (sql/migrate_passenger_profile.sql was not imported)');
    }
    if (!$col('passenger', 'profile_id')) {
        $conn->query("ALTER TABLE `passenger` ADD COLUMN `profile_id` int(11) DEFAULT NULL AFTER `booked_via`, ADD KEY `profile_id` (`profile_id`),
            ADD CONSTRAINT `pax_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `passenger_profile` (`profile_id`) ON DELETE SET NULL");
        error_log('MANLOAD: added passenger.profile_id');
    }
}

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        // Prepared config/local.php whose setup has not finished yet (tables not imported)
        if (defined('INSTALL_PENDING') && INSTALL_PENDING) db_goto_install();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        } catch (mysqli_sql_exception $e) {
            error_log('MANLOAD database connection failed: ' . $e->getMessage());
            // New server that was never set up, or a live local.php without a database login
            // (e.g. replaced by the XAMPP copy) → open the setup page to enter it
            $on_pc = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '');
            if (!is_file(__DIR__ . '/local.php') || (!DB_FROM_LOCAL && !$on_pc)) db_goto_install();
            http_response_code(500);
            if (!DB_FROM_LOCAL) die('Database connection failed: config/local.php has no database login (DB_HOST, DB_USER, DB_PASS, DB_NAME). '
                . 'It was probably replaced by the copy from your PC. Put the hosting database settings from hPanel back into it.');
            die('Database connection failed. Check the DB_* settings (config/local.php) and that the database user is added to the database in hPanel.');
        }
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+08:00'");
        // Same SQL rules as XAMPP's MariaDB. Hosting MySQL 8 turns on ONLY_FULL_GROUP_BY and
        // STRICT_TRANS_TABLES by default, which would reject queries that work here.
        $conn->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        db_auto_setup($conn);
        db_schema_upgrade($conn);
    }
    return $conn;
}
