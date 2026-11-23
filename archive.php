<?php
/**
 * MANLOAD — monthly archive ("batch file") + daily history helpers.
 *
 * Used by modules/admin/archive.php (web) and cron/monthly_archive.php (CLI).
 * Requires config/auth.php to be loaded first (db(), audit(), helpers).
 *
 *  archive_month('2026-05', $user_id, $purge)
 *     1. exports every voyage that departed in that month (plus its passengers,
 *        baggage, cargo, vehicles, payments, clearances) to
 *        storage/archives/MANLOAD-2026-05.zip  (CSV files + README.txt)
 *     2. stores a summary in archive_batch / archive_voyage and the passenger
 *        names in archive_passenger (so manifests stay searchable forever)
 *     3. marks the voyages archived=1
 *     4. $purge=true (Admin only): deletes the detail rows of those voyages
 *        (payment -> baggage -> vehicle -> cargo -> passenger). Voyage rows and
 *        departure_clearance rows are kept.
 */

const ARCHIVE_FILE_RE = '/^MANLOAD-\d{4}-(0[1-9]|1[0-2])\.zip$/';

// ─────────────────────────────────────────────────────────────────────────
// CSV helpers (formula-injection safe)
// ─────────────────────────────────────────────────────────────────────────

/** Neutralise spreadsheet formulas: =, +, -, @, tab, CR at the start of a text cell. */
function csv_safe($v): string {
    $s = (string)($v ?? '');
    if ($s !== '' && !is_numeric($s) && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    return $s;
}

/** Build a CSV document (string) from a header and rows. */
function csv_string(array $header, array $rows): string {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows ñ / ₱ correctly
    fputcsv($fh, array_map('csv_safe', $header), ',', '"', '');
    foreach ($rows as $r) fputcsv($fh, array_map('csv_safe', array_values($r)), ',', '"', '');
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out;
}

/** Send a CSV download to the browser (formula-injection safe). */
function csv_download(string $filename, array $header, array $rows): void {
    $filename = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo csv_string($header, $rows);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Months / files
// ─────────────────────────────────────────────────────────────────────────

function archive_valid_month(?string $ym): bool {
    return is_string($ym) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) === 1;
}

/** Voyages departing BEFORE this date may be archived (keeps ARCHIVE_KEEP_MONTHS live). */
function archive_cutoff_date(): string {
    $d = new DateTime('first day of this month');
    $d->modify('-' . (int)ARCHIVE_KEEP_MONTHS . ' months');
    return $d->format('Y-m-01');
}

/** [first_day, last_day] of 'YYYY-MM' */
function archive_month_bounds(string $ym): array {
    $first = $ym . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}

/** null if the month may be archived, otherwise the reason. */
function archive_month_error(string $ym): ?string {
    if (!archive_valid_month($ym)) return 'Invalid month. Use YYYY-MM.';
    [, $last] = archive_month_bounds($ym);
    if ($last >= date('Y-m-d')) return "$ym is not over yet.";
    if ($last >= archive_cutoff_date())
        return "$ym is too recent. The last " . ARCHIVE_KEEP_MONTHS . ' full month(s) stay live (archive months before '
             . date('F Y', strtotime(archive_cutoff_date())) . ').';
    return null;
}

function archive_file_name(string $ym): string { return 'MANLOAD-' . $ym . '.zip'; }

function archive_file_path(string $name): ?string {
    if (!preg_match(ARCHIVE_FILE_RE, $name)) return null;
    return rtrim(ARCHIVE_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
}

/** Months that still have non-archived voyages and are old enough: [['month'=>, 'voyages'=>], ...] oldest first. */
function archive_pending_months(): array {
    $cut = archive_cutoff_date();
    $st = db()->prepare("SELECT DATE_FORMAT(departure_date,'%Y-%m') AS month, COUNT(*) AS voyages
        FROM voyage WHERE departure_date < ? AND archived = 0
        GROUP BY month ORDER BY month");
    $st->bind_param('s', $cut);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function archive_batch_by_month(string $ym): ?array {
    $st = db()->prepare('SELECT * FROM archive_batch WHERE batch_month = ?');
    $st->bind_param('s', $ym);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

// ─────────────────────────────────────────────────────────────────────────
// Data collection (shared by monthly batch and single-voyage export)
// ─────────────────────────────────────────────────────────────────────────

function archive_in_list(array $vids): string {
    $vids = array_values(array_unique(array_map('intval', $vids)));
    return $vids ? implode(',', $vids) : '0';
}

/**
 * Per-voyage totals from the live tables (few grouped queries, no N+1).
 * Returns [voyage_id => [onboard, booked, total_pax_rows, vehicles, vehicle_kg, cargo_items, cargo_kg,
 *                        baggage_kg, total_load_kg, revenue, cert_no]]
 */
function archive_voyage_stats(array $vids): array {
    $db = db();
    $in = archive_in_list($vids);
    $out = [];
    foreach ($vids as $vid) {
        $out[(int)$vid] = ['onboard' => 0, 'booked' => 0, 'pax_rows' => 0, 'vehicles' => 0, 'vehicle_kg' => 0.0,
            'cargo_items' => 0, 'cargo_kg' => 0.0, 'baggage_kg' => 0.0, 'total_load_kg' => 0.0,
            'revenue' => 0.0, 'cert_no' => null];
    }
    foreach ($db->query("SELECT voyage_id, SUM(status IN ('Checked-in','Boarded')) AS onboard,
            SUM(status='Booked') AS booked, COUNT(*) AS n FROM passenger WHERE voyage_id IN ($in) GROUP BY voyage_id") as $r) {
        $out[(int)$r['voyage_id']]['onboard']  = (int)$r['onboard'];
        $out[(int)$r['voyage_id']]['booked']   = (int)$r['booked'];
        $out[(int)$r['voyage_id']]['pax_rows'] = (int)$r['n'];
    }
    foreach ($db->query("SELECT b.voyage_id, SUM(b.actual_weight) AS kg FROM baggage b
            JOIN passenger p ON p.passenger_id=b.passenger_id
            WHERE b.voyage_id IN ($in) AND p.status IN ('Checked-in','Boarded') GROUP BY b.voyage_id") as $r) {
        $out[(int)$r['voyage_id']]['baggage_kg'] = (float)$r['kg'];
    }
    foreach ($db->query("SELECT voyage_id, COUNT(*) AS n, SUM(gross_weight) AS kg FROM cargo
            WHERE voyage_id IN ($in) GROUP BY voyage_id") as $r) {
        $out[(int)$r['voyage_id']]['cargo_items'] = (int)$r['n'];
        $out[(int)$r['voyage_id']]['cargo_kg']    = (float)$r['kg'];
    }
    foreach ($db->query("SELECT vh.voyage_id, COUNT(*) AS n, SUM(COALESCE(vh.declared_weight_kg, vt.weight_kg)) AS kg
            FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id
            WHERE vh.voyage_id IN ($in) GROUP BY vh.voyage_id") as $r) {
        $out[(int)$r['voyage_id']]['vehicles']   = (int)$r['n'];
        $out[(int)$r['voyage_id']]['vehicle_kg'] = (float)$r['kg'];
    }
    foreach ($db->query("SELECT voyage_id, SUM(amount) AS amt FROM payment
            WHERE voyage_id IN ($in) AND is_voided=0 GROUP BY voyage_id") as $r) {
        $out[(int)$r['voyage_id']]['revenue'] = (float)$r['amt'];
    }
    foreach ($db->query("SELECT voyage_id, cert_no FROM departure_clearance
            WHERE voyage_id IN ($in) AND is_cleared=1 ORDER BY issued_at") as $r) {
        $out[(int)$r['voyage_id']]['cert_no'] = $r['cert_no'];   // latest wins
    }
    foreach ($out as &$s) {
        $s['total_load_kg'] = $s['onboard'] * PAX_AVG_WEIGHT_KG + $s['baggage_kg'] + $s['cargo_kg'] + $s['vehicle_kg'];
    }
    return $out;
}

/**
 * All exportable tables for these voyages: ['voyages.csv' => [header, rows], ...]
 */
function archive_collect(array $vids): array {
    $db = db();
    $in = archive_in_list($vids);
    $stats = archive_voyage_stats($vids);

    $voy = $db->query("SELECT v.voyage_id, v.voyage_no, vsl.vessel_name, r.route_code, po.port_name AS origin,
            pd.port_name AS destination, v.departure_date, v.departure_time, v.manifest_status, v.ready_to_board,
            v.departed_at, vsl.max_passengers, vsl.max_load_kg
        FROM voyage v JOIN vessel vsl ON vsl.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        WHERE v.voyage_id IN ($in) ORDER BY v.departure_date, v.departure_time")->fetch_all(MYSQLI_ASSOC);
    $voy_rows = [];
    foreach ($voy as $v) {
        $s = $stats[(int)$v['voyage_id']];
        $voy_rows[] = [$v['voyage_id'], $v['voyage_no'], $v['vessel_name'], $v['route_code'], $v['origin'], $v['destination'],
            $v['departure_date'], substr($v['departure_time'], 0, 5), $v['manifest_status'], $v['ready_to_board'] ? 'Yes' : 'No',
            $v['departed_at'], $s['onboard'], $s['booked'], $v['max_passengers'], $s['vehicles'], $s['cargo_items'],
            number_format($s['cargo_kg'], 2, '.', ''), number_format($s['total_load_kg'], 2, '.', ''), $v['max_load_kg'],
            number_format($s['revenue'], 2, '.', ''), $s['cert_no']];
    }

    $pax = $db->query("SELECT p.passenger_id, p.voyage_id, v.voyage_no, p.ticket_no, p.booking_ref, p.booked_via,
            p.last_name, p.first_name, p.middle_name, p.age, p.sex, p.nationality, p.id_type, p.id_number,
            p.pax_category, p.class, p.seat_no, p.contact_no, p.email, p.address, p.emergency_contact,
            p.base_fare, p.fare, p.fare_paid, p.online_pay_method, p.online_pay_status, p.status,
            p.booked_at, p.checkin_time, p.boarded_at
        FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id
        WHERE p.voyage_id IN ($in) ORDER BY v.departure_date, v.departure_time, p.last_name, p.first_name")->fetch_all(MYSQLI_ASSOC);

    $bag = $db->query("SELECT b.baggage_id, b.voyage_id, p.ticket_no, p.last_name, p.first_name, b.tag_no,
            b.actual_weight, b.allowance_kg, b.excess_kg, b.fee_rate, b.discount_pct, b.excess_fee, b.is_paid
        FROM baggage b JOIN passenger p ON p.passenger_id=b.passenger_id
        WHERE b.voyage_id IN ($in) ORDER BY b.voyage_id, b.baggage_id")->fetch_all(MYSQLI_ASSOC);

    $cargo = $db->query("SELECT c.cargo_id, c.voyage_id, c.waybill_no, c.shipper_name, c.shipper_contact, c.shipper_email,
            c.consignee_name, c.consignee_address, c.cargo_description, c.quantity, c.gross_weight, c.declared_value,
            c.cargo_type, c.freight_amount, c.is_paid, z.zone_code, c.created_at
        FROM cargo c LEFT JOIN vessel_zone z ON z.zone_id=c.zone_id
        WHERE c.voyage_id IN ($in) ORDER BY c.voyage_id, c.cargo_id")->fetch_all(MYSQLI_ASSOC);

    $veh = $db->query("SELECT vh.vehicle_id, vh.voyage_id, vh.plate_no, vt.type_name, vh.make_model, vh.color,
            vh.owner_name, vh.owner_contact, vh.owner_email, vh.receiver_name,
            COALESCE(vh.declared_weight_kg, vt.weight_kg) AS weight_kg, vh.freight_amount, vh.is_paid,
            vh.booking_ref, z.zone_code, vh.created_at
        FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id
        LEFT JOIN vessel_zone z ON z.zone_id=vh.zone_id
        WHERE vh.voyage_id IN ($in) ORDER BY vh.voyage_id, vh.vehicle_id")->fetch_all(MYSQLI_ASSOC);

    $pay = $db->query("SELECT py.payment_id, py.voyage_id, py.or_no, py.fee_type, py.amount, py.pay_method, py.reference_no,
            py.paid_at, u.username AS cashier, p.ticket_no, c.waybill_no, vh.plate_no, py.is_voided, py.void_reason
        FROM payment py LEFT JOIN user u ON u.user_id=py.cashier_id
        LEFT JOIN passenger p ON p.passenger_id=py.passenger_id
        LEFT JOIN cargo c ON c.cargo_id=py.cargo_id
        LEFT JOIN vehicle vh ON vh.vehicle_id=py.vehicle_id
        WHERE py.voyage_id IN ($in) ORDER BY py.paid_at, py.payment_id")->fetch_all(MYSQLI_ASSOC);

    $clr = $db->query("SELECT d.clearance_id, d.voyage_id, d.cert_no, d.is_cleared, d.total_pax, d.total_crew,
            d.total_vehicles, d.total_load_kg, u.full_name AS issued_by, d.issued_at, d.notes
        FROM departure_clearance d LEFT JOIN user u ON u.user_id=d.issued_by
        WHERE d.voyage_id IN ($in) ORDER BY d.issued_at")->fetch_all(MYSQLI_ASSOC);

    $keys = fn(array $rows, array $fallback) => $rows ? array_keys($rows[0]) : $fallback;
    return [
        'voyages.csv' => [['voyage_id', 'voyage_no', 'vessel', 'route', 'origin', 'destination', 'departure_date',
            'departure_time', 'status', 'ready_to_board', 'departed_at', 'pax_onboard', 'pax_booked_only',
            'max_passengers', 'vehicles', 'cargo_items', 'cargo_kg', 'total_load_kg', 'max_load_kg', 'revenue', 'cert_no'], $voy_rows],
        'passengers.csv' => [$keys($pax, ['passenger_id', 'voyage_id', 'ticket_no', 'last_name', 'first_name']), $pax],
        'baggage.csv'    => [$keys($bag, ['baggage_id', 'voyage_id', 'ticket_no', 'actual_weight']), $bag],
        'cargo.csv'      => [$keys($cargo, ['cargo_id', 'voyage_id', 'waybill_no', 'gross_weight']), $cargo],
        'vehicles.csv'   => [$keys($veh, ['vehicle_id', 'voyage_id', 'plate_no', 'type_name']), $veh],
        'payments.csv'   => [$keys($pay, ['payment_id', 'voyage_id', 'or_no', 'fee_type', 'amount']), $pay],
        'clearances.csv' => [$keys($clr, ['clearance_id', 'voyage_id', 'cert_no']), $clr],
        '_stats'         => $stats,
        '_voyages'       => $voy,
        '_passengers'    => $pax,
    ];
}

/** Write a ZIP of the collected tables (atomic: temp file then rename). Returns bytes written. */
function archive_write_zip(string $path, array $data, string $readme): int {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create folder $dir");
    }
    $tmp = $path . '.tmp';
    if (file_exists($tmp)) unlink($tmp);
    $files = [];
    foreach ($data as $name => $t) {
        if ($name[0] === '_') continue;
        $files[$name] = csv_string($t[0], $t[1]);
    }
    $files['README.txt'] = $readme;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create ZIP file $tmp");
        }
        foreach ($files as $name => $body) $zip->addFromString($name, $body);
        $zip->close();
    } elseif (file_put_contents($tmp, zip_build($files)) === false) {
        throw new RuntimeException("Cannot create ZIP file $tmp");
    }
    if (!rename($tmp, $path)) throw new RuntimeException("Cannot write $path");
    clearstatcache(true, $path);
    return (int)filesize($path);
}

/**
 * Build a ZIP in memory without the zip extension (fallback for servers where
 * ZipArchive is missing). Files are deflated with zlib when available, else stored.
 * $files = ['name.csv' => 'contents', ...]
 */
function zip_build(array $files): string {
    $out = ''; $cdir = '';
    $t = getdate();
    $dtime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $ddate = (max($t['year'] - 1980, 0) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as $name => $body) {
        $body   = (string)$body;
        $name   = (string)$name;
        $crc    = crc32($body);
        $packed = function_exists('gzdeflate') ? gzdeflate($body, 6) : false;
        $method = $packed === false ? 0 : 8;
        $data   = $packed === false ? $body : $packed;
        $common = pack('vvvvVVVv', 0x0800, $method, $dtime, $ddate, $crc, strlen($data), strlen($body), strlen($name));
        $offset = strlen($out);
        $out  .= pack('Vv', 0x04034b50, 20) . $common . pack('v', 0) . $name . $data;
        $cdir .= pack('Vvv', 0x02014b50, 20, 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $name;
    }
    $n = count($files);
    return $out . $cdir . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cdir), strlen($out), 0);
}

// ─────────────────────────────────────────────────────────────────────────
// Monthly batch
// ─────────────────────────────────────────────────────────────────────────

/**
 * Archive (and optionally purge) one month. Safe to run again (idempotent).
 * Returns ['ok'=>bool, 'message'=>string, 'batch'=>array|null]
 */
function archive_month(string $ym, ?int $user_id, bool $purge = false): array {
    $db = db();
    if ($err = archive_month_error($ym)) return ['ok' => false, 'message' => $err, 'batch' => null];

    $existing = archive_batch_by_month($ym);
    if ($existing && $existing['purged_at']) {
        // Detail rows are gone: the ZIP + archive tables are now the only copy. Never overwrite them.
        return ['ok' => true, 'batch' => $existing,
            'message' => "$ym was already archived and purged on {$existing['purged_at']}. Nothing to do."];
    }

    [$first, $last] = archive_month_bounds($ym);
    $st = $db->prepare('SELECT voyage_id FROM voyage WHERE departure_date BETWEEN ? AND ? ORDER BY voyage_id');
    $st->bind_param('ss', $first, $last);
    $st->execute();
    $vids = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'voyage_id'));
    if (!$vids) return ['ok' => false, 'message' => "No voyages departed in $ym.", 'batch' => $existing];

    // 1) Export
    $data  = archive_collect($vids);
    $stats = $data['_stats'];
    $tot = ['voyages' => count($vids), 'passengers' => count($data['_passengers']), 'vehicles' => 0,
            'cargo_items' => 0, 'cargo_kg' => 0.0, 'revenue' => 0.0];
    foreach ($stats as $s) {
        $tot['vehicles'] += $s['vehicles']; $tot['cargo_items'] += $s['cargo_items'];
        $tot['cargo_kg'] += $s['cargo_kg']; $tot['revenue'] += $s['revenue'];
    }
    $file = archive_file_name($ym);
    $readme = "MANLOAD monthly archive — " . date('F Y', strtotime($first)) . "\r\n"
        . "Generated: " . date('Y-m-d H:i:s') . ' (' . APP_TIMEZONE . ")\r\n"
        . "Period:    $first to $last\r\n\r\n"
        . "Voyages:     {$tot['voyages']}\r\n"
        . "Passengers:  {$tot['passengers']}\r\n"
        . "Vehicles:    {$tot['vehicles']}\r\n"
        . "Cargo items: {$tot['cargo_items']} (" . number_format($tot['cargo_kg'], 2) . " kg)\r\n"
        . "Revenue:     PHP " . number_format($tot['revenue'], 2) . " (non-voided payments)\r\n\r\n"
        . "Files: voyages.csv, passengers.csv, baggage.csv, cargo.csv, vehicles.csv, payments.csv, clearances.csv\r\n"
        . "CSV files are UTF-8. Cells that start with = + - @ are prefixed with ' to prevent spreadsheet formulas.\r\n";
    try {
        $size = archive_write_zip(archive_file_path($file), $data, $readme);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'ZIP export failed: ' . $e->getMessage(), 'batch' => $existing];
    }

    // 2) Summary tables + archived flag
    $db->begin_transaction();
    try {
        $st = $db->prepare('INSERT INTO archive_batch (batch_month, file_name, file_size, voyages, passengers, vehicles,
                cargo_items, cargo_kg, revenue, exported_by, exported_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE file_name=VALUES(file_name), file_size=VALUES(file_size), voyages=VALUES(voyages),
                passengers=VALUES(passengers), vehicles=VALUES(vehicles), cargo_items=VALUES(cargo_items),
                cargo_kg=VALUES(cargo_kg), revenue=VALUES(revenue), exported_by=VALUES(exported_by), exported_at=NOW()');
        $st->bind_param('ssiiiiiddi', $ym, $file, $size, $tot['voyages'], $tot['passengers'], $tot['vehicles'],
            $tot['cargo_items'], $tot['cargo_kg'], $tot['revenue'], $user_id);
        $st->execute();
        $batch_id = (int)archive_batch_by_month($ym)['batch_id'];

        // Idempotent: replace this batch's rows
        $in = archive_in_list($vids);
        $st = $db->prepare('DELETE FROM archive_passenger WHERE batch_id=?');
        $st->bind_param('i', $batch_id); $st->execute();
        $db->query("DELETE FROM archive_passenger WHERE voyage_id IN ($in)");
        $st = $db->prepare('DELETE FROM archive_voyage WHERE batch_id=?');
        $st->bind_param('i', $batch_id); $st->execute();

        $insV = $db->prepare('REPLACE INTO archive_voyage (voyage_id, batch_id, voyage_no, vessel_name, route_code,
                departure_date, departure_time, manifest_status, total_pax, total_vehicles, cargo_kg, total_load_kg, revenue, cert_no)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($data['_voyages'] as $v) {
            $s = $stats[(int)$v['voyage_id']];
            $vid = (int)$v['voyage_id'];
            $insV->bind_param('iissssssiiddds', $vid, $batch_id, $v['voyage_no'], $v['vessel_name'], $v['route_code'],
                $v['departure_date'], $v['departure_time'], $v['manifest_status'], $s['onboard'], $s['vehicles'],
                $s['cargo_kg'], $s['total_load_kg'], $s['revenue'], $s['cert_no']);
            $insV->execute();
        }
        $insP = $db->prepare('INSERT INTO archive_passenger (batch_id, voyage_id, ticket_no, last_name, first_name, middle_name,
                age, sex, nationality, seat_no, class, pax_category, contact_no, email, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($data['_passengers'] as $p) {
            $pv = (int)$p['voyage_id']; $age = (int)$p['age'];
            $insP->bind_param('iissssissssssss', $batch_id, $pv, $p['ticket_no'], $p['last_name'], $p['first_name'],
                $p['middle_name'], $age, $p['sex'], $p['nationality'], $p['seat_no'], $p['class'], $p['pax_category'],
                $p['contact_no'], $p['email'], $p['status']);
            $insP->execute();
        }
        $db->query("UPDATE voyage SET archived=1, archived_at=COALESCE(archived_at, NOW()) WHERE voyage_id IN ($in)");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'message' => 'Archive failed (nothing changed in the database): ' . $e->getMessage(), 'batch' => $existing];
    }
    audit("Archive month $ym", 'archive_batch', $batch_id, [], $tot + ['file' => $file, 'size' => $size]);
    $msg = "Archived $ym: {$tot['voyages']} voyage(s), {$tot['passengers']} passenger(s), {$tot['vehicles']} vehicle(s), "
         . "{$tot['cargo_items']} cargo item(s), revenue ₱" . number_format($tot['revenue'], 2) . " → $file (" . number_format($size / 1024, 1) . ' KB).';

    // 3) Optional purge of detail rows
    if ($purge) {
        $r = archive_purge($ym, $user_id);
        $msg .= ' ' . $r['message'];
        if (!$r['ok']) return ['ok' => false, 'message' => $msg, 'batch' => archive_batch_by_month($ym)];
    }
    return ['ok' => true, 'message' => $msg, 'batch' => archive_batch_by_month($ym)];
}

/** Delete the detail rows of an already-exported month (FK-safe order, one transaction). */
function archive_purge(string $ym, ?int $user_id): array {
    $db = db();
    $b = archive_batch_by_month($ym);
    if (!$b) return ['ok' => false, 'message' => "Run the archive for $ym first."];
    if ($b['purged_at']) return ['ok' => true, 'message' => "$ym was already purged."];
    $path = archive_file_path((string)$b['file_name']);
    if (!$path || !is_file($path) || filesize($path) === 0) {
        return ['ok' => false, 'message' => "Purge refused: the ZIP file for $ym is missing. Re-run the archive first."];
    }
    [$first, $last] = archive_month_bounds($ym);
    $st = $db->prepare('SELECT voyage_id FROM voyage WHERE departure_date BETWEEN ? AND ? AND archived=1');
    $st->bind_param('ss', $first, $last);
    $st->execute();
    $vids = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'voyage_id');
    $in = archive_in_list($vids);
    $counts = [];
    require_once __DIR__ . '/id_photos.php';
    $photos = id_photo_names_for_voyages($db, $in);   // deleted only after the rows are gone
    $db->begin_transaction();
    try {
        foreach (['payment', 'baggage', 'vehicle', 'cargo', 'passenger'] as $t) {
            $db->query("DELETE FROM `$t` WHERE voyage_id IN ($in)");
            $counts[$t] = $db->affected_rows;
        }
        $st = $db->prepare('UPDATE archive_batch SET purged_at=NOW(), purged_by=? WHERE batch_id=?');
        $bid = (int)$b['batch_id'];
        $st->bind_param('ii', $user_id, $bid);
        $st->execute();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'message' => 'Purge failed, nothing was deleted: ' . $e->getMessage()];
    }
    if ($photos) $counts['id_photo'] = id_photo_delete($photos);
    audit("Purge archived month $ym", 'archive_batch', (int)$b['batch_id'], [], $counts);
    return ['ok' => true, 'message' => 'Purged detail rows: ' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)) . '.'];
}

// ─────────────────────────────────────────────────────────────────────────
// Daily history (works before AND after purge)
// ─────────────────────────────────────────────────────────────────────────

/** Voyages that departed on $date with their totals (live if not purged, else archive summary). */
function history_day_voyages(string $date): array {
    $db = db();
    $st = $db->prepare("SELECT v.voyage_id, v.voyage_no, v.departure_date, v.departure_time, v.manifest_status, v.archived,
            vsl.vessel_name, vsl.max_passengers, vsl.max_load_kg, r.route_code, po.port_code AS orig, pd.port_code AS dest,
            av.batch_id, av.total_pax AS a_pax, av.total_vehicles AS a_veh, av.cargo_kg AS a_cargo_kg,
            av.total_load_kg AS a_load, av.revenue AS a_revenue, av.cert_no AS a_cert, ab.purged_at
        FROM voyage v JOIN vessel vsl ON vsl.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        LEFT JOIN archive_voyage av ON av.voyage_id=v.voyage_id
        LEFT JOIN archive_batch ab ON ab.batch_id=av.batch_id
        WHERE v.departure_date=? ORDER BY v.departure_time, vsl.vessel_name");
    $st->bind_param('s', $date);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $live_ids = array_column(array_filter($rows, fn($r) => empty($r['purged_at'])), 'voyage_id');
    $stats = $live_ids ? archive_voyage_stats($live_ids) : [];
    foreach ($rows as &$r) {
        if (!empty($r['purged_at'])) {
            $r['source'] = 'archive';
            $r += ['pax' => (int)$r['a_pax'], 'vehicles' => (int)$r['a_veh'], 'cargo_kg' => (float)$r['a_cargo_kg'],
                   'load_kg' => (float)$r['a_load'], 'revenue' => (float)$r['a_revenue'], 'cert_no' => $r['a_cert']];
        } else {
            $s = $stats[(int)$r['voyage_id']];
            $r['source'] = 'live';
            $r += ['pax' => $s['onboard'], 'vehicles' => $s['vehicles'], 'cargo_kg' => $s['cargo_kg'],
                   'load_kg' => $s['total_load_kg'], 'revenue' => $s['revenue'], 'cert_no' => $s['cert_no']];
        }
    }
    unset($r);
    // Archive-only rows (voyage row deleted by hand) — still show them
    $st = $db->prepare("SELECT av.*, ab.purged_at FROM archive_voyage av JOIN archive_batch ab ON ab.batch_id=av.batch_id
        LEFT JOIN voyage v ON v.voyage_id=av.voyage_id WHERE av.departure_date=? AND v.voyage_id IS NULL");
    $st->bind_param('s', $date);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
        $rows[] = ['voyage_id' => $a['voyage_id'], 'voyage_no' => $a['voyage_no'], 'departure_date' => $a['departure_date'],
            'departure_time' => $a['departure_time'], 'manifest_status' => $a['manifest_status'], 'archived' => 1,
            'vessel_name' => $a['vessel_name'], 'max_passengers' => null, 'max_load_kg' => null, 'route_code' => $a['route_code'],
            'orig' => '', 'dest' => '', 'purged_at' => $a['purged_at'], 'source' => 'archive', 'pax' => (int)$a['total_pax'],
            'vehicles' => (int)$a['total_vehicles'], 'cargo_kg' => (float)$a['cargo_kg'], 'load_kg' => (float)$a['total_load_kg'],
            'revenue' => (float)$a['revenue'], 'cert_no' => $a['cert_no']];
    }
    return $rows;
}

/** Passenger list of one voyage: live rows, or archive_passenger once purged. */
function history_voyage_passengers(int $vid): array {
    $db = db();
    $st = $db->prepare("SELECT ticket_no, last_name, first_name, middle_name, age, sex, nationality, seat_no, class,
            pax_category, contact_no, email, status, 'live' AS source
        FROM passenger WHERE voyage_id=? ORDER BY last_name, first_name");
    $st->bind_param('i', $vid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($rows) return $rows;
    $st = $db->prepare("SELECT ticket_no, last_name, first_name, middle_name, age, sex, nationality, seat_no, class,
            pax_category, contact_no, email, status, 'archive' AS source
        FROM archive_passenger WHERE voyage_id=? ORDER BY last_name, first_name");
    $st->bind_param('i', $vid);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Search a passenger name in live + archived manifests (deduplicated, newest first). */
function history_search_passengers(string $q, int $limit = 100): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $db = db();
    $sql_live = "SELECT p.voyage_id, p.ticket_no, p.last_name, p.first_name, p.middle_name, p.age, p.sex, p.status,
            v.voyage_no, v.departure_date, v.departure_time, vsl.vessel_name, r.route_code, 'live' AS source
        FROM passenger p JOIN voyage v ON v.voyage_id=p.voyage_id JOIN vessel vsl ON vsl.vessel_id=v.vessel_id
        JOIN route r ON r.route_id=v.route_id
        WHERE CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) LIKE ? OR CONCAT(p.last_name, ', ', p.first_name) LIKE ?
           OR p.ticket_no LIKE ?
        ORDER BY v.departure_date DESC LIMIT $limit";
    $st = $db->prepare($sql_live);
    $st->bind_param('sss', $like, $like, $like);
    $st->execute();
    $live = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $sql_arc = "SELECT a.voyage_id, a.ticket_no, a.last_name, a.first_name, a.middle_name, a.age, a.sex, a.status,
            av.voyage_no, av.departure_date, av.departure_time, av.vessel_name, av.route_code, 'archive' AS source
        FROM archive_passenger a JOIN archive_voyage av ON av.voyage_id=a.voyage_id
        WHERE CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name) LIKE ? OR CONCAT(a.last_name, ', ', a.first_name) LIKE ?
           OR a.ticket_no LIKE ?
        ORDER BY av.departure_date DESC LIMIT $limit";
    $st = $db->prepare($sql_arc);
    $st->bind_param('sss', $like, $like, $like);
    $st->execute();
    $out = [];
    foreach (array_merge($live, $st->get_result()->fetch_all(MYSQLI_ASSOC)) as $r) {
        $k = $r['voyage_id'] . '|' . $r['ticket_no'];
        if (!isset($out[$k])) $out[$k] = $r;       // live wins over archive copy
    }
    usort($out, fn($a, $b) => [$b['departure_date'], $b['departure_time']] <=> [$a['departure_date'], $a['departure_time']]);
    return array_slice(array_values($out), 0, $limit);
}
