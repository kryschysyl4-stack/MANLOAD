<?php
/**
 * Links every ticket that has no passenger profile yet (oldest first),
 * creating profiles as needed. Run once after sql/migrate_passenger_profile.sql:
 *
 *   C:\xampp\php\php.exe tools\backfill_profiles.php
 *
 * Safe to run again: tickets that already have a profile are skipped.
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$ids = db()->query('SELECT passenger_id FROM passenger WHERE profile_id IS NULL ORDER BY booked_at, passenger_id')
           ->fetch_all(MYSQLI_NUM);
db()->begin_transaction();
foreach ($ids as [$pid]) link_passenger_profile((int)$pid);
db()->commit();

[$tickets, $people] = db()->query('SELECT (SELECT COUNT(*) FROM passenger WHERE profile_id IS NOT NULL),
    (SELECT COUNT(*) FROM passenger_profile)')->fetch_row();
echo 'Linked ' . count($ids) . " ticket(s). Now $tickets ticket(s) belong to $people passenger profile(s).\n";
