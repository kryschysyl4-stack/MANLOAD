<?php
/**
 * MANLOAD monthly batch (command line only).
 *
 *   php cron/monthly_archive.php                    archive the oldest eligible month
 *   php cron/monthly_archive.php 2026-05            archive a specific month
 *   php cron/monthly_archive.php --all              archive every eligible month
 *   php cron/monthly_archive.php --purge            ... and delete the detail rows after export
 *   php cron/monthly_archive.php --schedule-next    also create next month's voyages from the template
 *   php cron/monthly_archive.php --user=1           record this user as the exporter (default: none = scheduled task)
 *
 * Exit code 0 = OK, 1 = a step failed. See cron/README.txt for scheduling.
 */
if (PHP_SAPI !== 'cli' || php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/archive.php';

$month = null; $purge = false; $all = false; $schedule_next = false; $user_id = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--purge') $purge = true;
    elseif ($arg === '--all') $all = true;
    elseif ($arg === '--schedule-next') $schedule_next = true;
    elseif (preg_match('/^--user=(\d+)$/', $arg, $m)) $user_id = (int)$m[1];
    elseif (archive_valid_month($arg)) $month = $arg;
    elseif ($arg === '--help' || $arg === '-h') { echo "MANLOAD monthly batch\n"
        . "  php cron/monthly_archive.php                  archive the oldest eligible month\n"
        . "  php cron/monthly_archive.php YYYY-MM          archive a specific month\n"
        . "  --purge          also delete that month's detail rows after the ZIP is saved\n"
        . "  --schedule-next  create next month's voyages from the schedule template\n";
        exit(0); }
    else { fwrite(STDERR, "Unknown argument: $arg (use YYYY-MM, --all, --purge, --schedule-next, --user=ID)\n"); exit(1); }
}

function out(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }

// One run at a time
if (!is_dir(ARCHIVE_DIR)) @mkdir(ARCHIVE_DIR, 0775, true);
$lock = fopen(rtrim(ARCHIVE_DIR, '/\\') . '/.monthly_archive.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { out('Another archive run is in progress. Exiting.'); exit(1); }

$ok = true;
out('MANLOAD monthly batch started. Months before ' . archive_cutoff_date() . ' may be archived.'
    . ($purge ? ' Purge: ON.' : ' Purge: off.'));

if ($month) {
    $months = [$month];
} else {
    $months = array_column(archive_pending_months(), 'month');
    if (!$all) $months = array_slice($months, 0, 1);
}
if (!$months) out('Nothing to archive: every eligible month is already archived.');

foreach ($months as $ym) {
    $r = archive_month($ym, $user_id, $purge);
    out(($r['ok'] ? 'OK    ' : 'FAIL  ') . $r['message']);
    if (!$r['ok']) $ok = false;
}

if ($schedule_next) {
    $first = date('Y-m-01', strtotime('first day of next month'));
    $dates = [];
    for ($d = $first; $d <= date('Y-m-t', strtotime($first)); $d = date('Y-m-d', strtotime("$d +1 day"))) $dates[] = $d;
    $res = generate_schedule($dates, $user_id);
    audit('Generate schedule ' . date('F Y', strtotime($first)) . ' (scheduled task)', 'voyage', 0, [], $res);
    out('Schedule ' . date('F Y', strtotime($first)) . ": {$res['created']} voyage(s) created, {$res['existing']} already existed, "
        . "{$res['maintenance']} skipped (vessel under maintenance).");
}

flock($lock, LOCK_UN);
out($ok ? 'Done.' : 'Finished with errors.');
exit($ok ? 0 : 1);
