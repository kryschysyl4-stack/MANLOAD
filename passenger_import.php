<?php
/**
 * Bulk passenger import (Admin): read a .csv / .xlsx passenger list, check every row
 * against the chosen voyage, then save the valid rows as Booked tickets (fare paid at the counter).
 * Page: modules/admin/import_passengers.php. Button + modal: passenger_import_button() / passenger_import_modal().
 *
 * Rules (same as the counter booking where the database allows):
 *   - first_name, last_name, gender and age (or birthdate) are required — the passenger table needs them;
 *   - Student / Senior / PWD need id_type + id_number (discount); class must be offered on the trip;
 *   - seat_number must exist on the vessel, fit the class, be free and not repeat in the file;
 *   - a passenger already on the voyage (same first + last name + age, or same ticket no.) is a duplicate:
 *     skipped, or its details updated (option);
 *   - capacity: booked + checked-in + boarded <= max passengers, class seat limits, max load kg
 *     (capacity_error); rows that do not fit block the import, or are skipped (option).
 */
require_once __DIR__ . '/checkin_helpers.php';

const PI_MAX_BYTES = 5 * 1024 * 1024;
const PI_MAX_ROWS  = 5000;
const PI_CHUNK     = 500;
const PI_DIR       = __DIR__ . '/../storage/imports';
const PI_COLS = [   // column => accepted header names (lower case, spaces / dots / dashes as "_")
    'first_name'  => ['first_name', 'firstname', 'first', 'given_name', 'given'],
    'last_name'   => ['last_name', 'lastname', 'last', 'surname', 'family_name'],
    'middle_name' => ['middle_name', 'middlename', 'middle', 'mi', 'middle_initial'],
    'gender'      => ['gender', 'sex'],
    'birthdate'   => ['birthdate', 'birth_date', 'date_of_birth', 'dob', 'birthday'],
    'age'         => ['age'],
    'nationality' => ['nationality', 'citizenship'],
    'contact_number' => ['contact_number', 'contact_no', 'contact', 'mobile', 'mobile_number', 'mobile_no', 'phone', 'phone_number', 'cellphone'],
    'email'       => ['email', 'email_address'],
    'address'     => ['address', 'home_address'],
    'passenger_type' => ['passenger_type', 'pax_type', 'type', 'category', 'pax_category'],
    'accommodation'  => ['accommodation', 'class'],
    'seat_number' => ['seat_number', 'seat_no', 'seat'],
    'ticket_number' => ['ticket_number', 'ticket_no', 'ticket'],
    'id_type'     => ['id_type'],
    'id_number'   => ['id_number', 'id_no'],
    'emergency_contact_name'   => ['emergency_contact_name', 'emergency_contact', 'emergency_name', 'contact_person'],
    'emergency_contact_number' => ['emergency_contact_number', 'emergency_number', 'emergency_contact_no'],
    'group'       => ['group', 'booking_group', 'group_no', 'booking'],
    'remarks'     => ['remarks', 'notes'],
];
const PI_DEFAULT_OPTS = ['over' => 'block', 'auto_seat' => 0, 'dup' => 'skip', 'alt_vid' => 0];

// ═════════════════════════════════════════════════════════════════════════
// Reading the file
// ═════════════════════════════════════════════════════════════════════════

/** Rows of the uploaded file keyed by PI_COLS (+ '_line'). Returns [rows, error]. */
function pi_read_file(string $path, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xls') return [[], 'Old Excel files (.xls) are not supported — in Excel use File › Save As › "Excel Workbook (.xlsx)" or "CSV UTF-8".'];
    if (!in_array($ext, ['csv', 'xlsx'], true)) return [[], 'Upload a .csv or .xlsx file. "' . $name . '" is not one.'];
    if (filesize($path) === 0) return [[], 'The file is empty.'];
    [$raw, $err] = $ext === 'xlsx' ? pi_read_xlsx($path) : pi_read_csv($path);
    if ($err) return [[], $err];
    $raw = array_values(array_filter($raw, fn($r) => array_filter($r, fn($c, $k) => $k !== '_line' && trim((string)$c) !== '', ARRAY_FILTER_USE_BOTH)));   // drop blank lines
    if (!$raw) return [[], 'The file is empty.'];

    $map = [];
    foreach ($raw[0] as $i => $h) {
        $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$h))), '_');
        foreach (PI_COLS as $col => $names) if (in_array($k, $names, true) && !in_array($col, $map, true)) { $map[$i] = $col; break; }
    }
    $missing = array_diff(['first_name', 'last_name'], $map);
    if ($missing) return [[], 'Column' . (count($missing) > 1 ? 's ' : ' ') . implode(' and ', $missing) . ' not found in the first row. Download the template and keep its header row.'];
    if (count($raw) < 2) return [[], 'The file has the header row but no passengers.'];
    if (count($raw) - 1 > PI_MAX_ROWS) return [[], 'Too many rows — at most ' . number_format(PI_MAX_ROWS) . ' passengers per file.'];

    $rows = [];
    foreach (array_slice($raw, 1, null, true) as $n => $r) {
        $row = array_fill_keys(array_keys(PI_COLS), '');
        foreach ($map as $i => $col) $row[$col] = trim(preg_replace('/\s+/u', ' ', (string)($r[$i] ?? '')));
        $row['_line'] = $r['_line'] ?? $n + 1;
        $rows[] = $row;
    }
    return [$rows, null];
}

function pi_read_csv(string $path): array {
    $raw = file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = strtok($raw, "\n") ?: '';
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts); $sep = array_key_first($counts);
    $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh);
    $rows = []; $line = 0;
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) { $line++; $r['_line'] = $line; $rows[] = $r; }
    return [$rows, null];
}

/** First worksheet of an .xlsx (ZipArchive + XML, no extra package). */
function pi_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) return [[], 'This server cannot read .xlsx files (PHP zip extension is off) — upload a CSV instead.'];
    $z = new ZipArchive();
    if ($z->open($path) !== true) return [[], 'The .xlsx file could not be opened — it may be damaged. Save it again, or upload a CSV.'];
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $load = function (string $name) use ($z) {
        $st = $z->statName($name);
        if (!$st || $st['size'] > 60 * 1024 * 1024) return null;
        $xml = $z->getFromName($name);
        return $xml === false ? null : simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    };
    $strings = [];
    if ($sx = $load('xl/sharedStrings.xml')) {
        foreach ($sx->children($ns)->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $node) $t .= (string)$node;
            $strings[] = $t;
        }
    }
    $sheet = 'xl/worksheets/sheet1.xml';
    if (($wb = $load('xl/workbook.xml')) && ($rels = $load('xl/_rels/workbook.xml.rels'))) {
        $first = $wb->xpath('//*[local-name()="sheet"]')[0] ?? null;
        $rid = $first ? (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel)
            if ((string)$rel['Id'] === $rid) { $t = ltrim((string)$rel['Target'], '/'); $sheet = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t; }
    }
    $ws = $load($sheet);
    $z->close();
    if (!$ws) return [[], 'No worksheet found in the .xlsx file.'];
    $rows = [];
    foreach ($ws->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $out = [];
        foreach ($row->xpath('*[local-name()="c"]') as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0; foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + ord($ch) - 64;
            $type = (string)$c['t'];
            $v = $type === 'inlineStr' ? implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]'))) : (string)($c->xpath('*[local-name()="v"]')[0] ?? '');
            if ($type === 's') $v = $strings[(int)$v] ?? '';
            elseif ($type === '' || $type === 'n') { if (is_numeric($v) && stripos($v, 'e') !== false) $v = sprintf('%.0f', (float)$v); }
            $out[$col - 1] = $v;
        }
        if (!$out) continue;
        $dense = array_fill(0, max(array_keys($out)) + 1, '');
        foreach ($out as $i => $v) $dense[$i] = $v;
        $dense['_line'] = (int)$row['r'] ?: count($rows) + 1;
        $rows[] = $dense;
    }
    return [$rows, null];
}

// ═════════════════════════════════════════════════════════════════════════
// Checking the rows
// ═════════════════════════════════════════════════════════════════════════

/** Age at the departure date from a birthdate (YYYY-MM-DD, MM/DD/YYYY, "Jan 5, 1990", Excel date number). */
function pi_age_from_birthdate(string $b, string $on): array {
    $b = trim($b);
    if (preg_match('/^\d{4,5}(\.0+)?$/', $b) && (int)$b > 1000 && (int)$b < 80000) $ts = strtotime('1899-12-30 +' . (int)$b . ' days');   // Excel serial
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $b, $m)) $ts = checkdate((int)$m[1], (int)$m[2], (int)$m[3]) ? mktime(0, 0, 0, (int)$m[1], (int)$m[2], (int)$m[3]) : false;
    elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $b, $m)) $ts = checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) : false;
    else $ts = strtotime($b);
    if (!$ts || $ts > strtotime($on)) return [null, 'Invalid birthdate "' . $b . '".'];
    $age = (int)(new DateTime(date('Y-m-d', $ts)))->diff(new DateTime($on))->y;
    return $age > 120 ? [null, 'Invalid birthdate "' . $b . '".'] : [$age, null];
}

/** File row -> the input validate_pax_input() expects, plus the row's own errors. */
function pi_to_input(array $r, array $v, array $fares): array {
    $err = [];
    $g = strtolower($r['gender']);
    $sex = match (true) { in_array($g, ['m', 'male', 'lalaki'], true) => 'Male', in_array($g, ['f', 'female', 'babae'], true) => 'Female', default => '' };
    if ($r['gender'] === '') $err[] = 'Gender is required (Male / Female).';
    elseif ($sex === '') $err[] = 'Gender "' . $r['gender'] . '" is not Male or Female.';

    $age = null;
    if ($r['birthdate'] !== '') { [$age, $e] = pi_age_from_birthdate($r['birthdate'], $v['departure_date']); if ($e) $err[] = $e; }
    elseif ($r['age'] !== '') { if (preg_match('/^\d{1,3}(\.0+)?$/', $r['age'])) $age = (int)$r['age']; else $err[] = 'Age "' . $r['age'] . '" is not a number.'; }
    else $err[] = 'Age or birthdate is required.';

    $t = strtolower(preg_replace('/[^a-z]/i', '', $r['passenger_type']));
    $cat = match (true) {
        $t === '' => $age === null ? 'Adult' : ($age < 2 ? 'Infant' : ($age < 18 ? 'Minor' : 'Adult')),
        in_array($t, ['adult', 'regular', 'fullfare'], true) => 'Adult',
        in_array($t, ['child', 'minor', 'kid', 'children'], true) => 'Minor',
        in_array($t, ['infant', 'baby'], true) => 'Infant',
        str_starts_with($t, 'senior') => 'Senior',
        in_array($t, ['pwd', 'disabled', 'personwithdisability'], true) => 'PWD',
        str_starts_with($t, 'student') => 'Student',
        default => null,
    };
    if ($cat === null) { $err[] = 'Passenger type "' . $r['passenger_type'] . '" is not adult, child, infant, senior, PWD or student.'; $cat = 'Adult'; }

    $class = '';
    $acc = strtolower($r['accommodation']);
    if ($acc === '') $class = isset($fares['Economy']) ? 'Economy' : (string)array_key_first($fares);
    else foreach ($fares as $cls => $f) if ($acc === strtolower($cls) || $acc === strtolower($f['label'])) $class = $cls;
    if ($class === '') $err[] = 'Accommodation "' . $r['accommodation'] . '" is not offered on this trip (' . implode(', ', array_keys($fares)) . ').';

    $idt = $r['id_type'];
    foreach (ID_TYPES as $x) if (strcasecmp($idt, $x) === 0) $idt = $x;
    if (in_array(strtolower($idt), ['philsys', 'philsys id', 'national id', 'philid'], true)) $idt = 'PhilSys National ID';
    $phone = preg_match('/^9\d{9}$/', $r['contact_number']) ? '0' . $r['contact_number'] : $r['contact_number'];   // Excel drops the leading 0
    $ephone = preg_match('/^9\d{9}$/', $r['emergency_contact_number']) ? '0' . $r['emergency_contact_number'] : $r['emergency_contact_number'];
    $in = ['last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'middle_name' => $r['middle_name'],
           'age' => $age ?? -1, 'sex' => $sex ?: 'Male', 'nationality' => $r['nationality'], 'id_type' => $idt, 'id_number' => $r['id_number'],
           'pax_category' => $cat, 'class' => $class ?: 'Economy', 'contact_no' => $phone, 'email' => $r['email'],
           'emergency_contact' => trim($r['emergency_contact_name'] . ' ' . $ephone), 'remarks' => $r['remarks'],
           'seat_no' => $r['seat_number'], 'address' => $r['address']];
    return [$in, $err];
}

/** Seats / weight room on the voyage right now. */
function pi_capacity(int $vid): array {
    $L = voyage_load($vid);
    $by_weight = PAX_AVG_WEIGHT_KG > 0 ? (int)floor(max(0, $L['max_kg'] - $L['total_kg']) / PAX_AVG_WEIGHT_KG) : PHP_INT_MAX;
    return ['max' => $L['max_pax'], 'taken' => $L['seats_taken'], 'seats_left' => $L['seats_left'],
            'available' => min($L['seats_left'], $by_weight), 'by_weight' => $by_weight, 'classes' => voyage_accommodations($vid)];
}

/**
 * Check every row against the voyage. Each row gets:
 *   status: new | update | skip (duplicate) | over (does not fit) | invalid;  errors[]; note; data (clean); match_pid; fare
 * Returns [rows, summary].
 */
function pi_check(array $rows, array $v, array $opt): array {
    $db  = db(); $vid = (int)$v['voyage_id'];
    $fares = voyage_fares($vid);
    $st = $db->prepare("SELECT passenger_id, LOWER(CONCAT(TRIM(first_name), '|', TRIM(last_name), '|', age)) AS k, ticket_no
        FROM passenger WHERE voyage_id=? AND status IN ('Booked','Checked-in','Boarded')");
    $st->bind_param('i', $vid); $st->execute();
    $by_key = []; $by_ticket = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $p) { $by_key[$p['k']] ??= (int)$p['passenger_id']; $by_ticket[strtoupper($p['ticket_no'])] = (int)$p['passenger_id']; }

    $seen_key = []; $seen_ticket = []; $seen_seat = [];
    foreach ($rows as &$r) {
        [$in, $err] = pi_to_input($r, $v, $fares);
        $seat_in = strtoupper(trim($in['seat_no']));
        if ($seat_in !== '' && !seat_exists($v, $seat_in)) { $err[] = "Seat $seat_in does not exist on this vessel."; $in['seat_no'] = ''; }
        [$d, $verr] = validate_pax_input($in, $v, false);
        // the age / class problems are already explained above in the file's own words
        $verr = array_filter($verr, fn($e) => !($err && str_starts_with($e, 'Enter a valid age')) && $e !== 'Select a class that is offered on this trip.');
        $err = array_values(array_unique(array_merge($err, $verr)));
        if (in_array($d['pax_category'], DISCOUNTED_CATEGORIES, true) && ($d['id_type'] === null || $d['id_number'] === null))
            $err[] = $d['pax_category'] . ' needs id_type and id_number (discount).';
        $r += ['status' => 'new', 'note' => '', 'match_pid' => 0, 'fare' => 0.0];

        $key = mb_strtolower($d['first_name'] . '|' . $d['last_name'] . '|' . $d['age']);
        $tk  = strtoupper($r['ticket_number']);
        if (!$err) {
            if (isset($seen_key[$key])) $err[] = 'Duplicate in the file (same name and age as row ' . $seen_key[$key] . ').';
            elseif ($tk !== '' && isset($seen_ticket[$tk])) $err[] = 'Duplicate in the file (same ticket no. as row ' . $seen_ticket[$tk] . ').';
        }
        $seen_key[$key] ??= $r['_line'];
        if ($tk !== '') $seen_ticket[$tk] ??= $r['_line'];

        if (!$err) {
            $pid = $tk !== '' && isset($by_ticket[$tk]) ? $by_ticket[$tk] : ($by_key[$key] ?? 0);
            if (!$pid && $tk !== '') {
                $st = $db->prepare('SELECT 1 FROM passenger WHERE ticket_no=?'); $st->bind_param('s', $tk); $st->execute();
                if ($st->get_result()->fetch_row()) $err[] = 'Ticket no. ' . $tk . ' belongs to another voyage.';
                else $r['note'] = 'A system ticket no. is given (file ticket no. ' . $tk . ' is not used).';
            }
            if ($pid) { $r['match_pid'] = $pid; $r['status'] = $opt['dup'] === 'update' ? 'update' : 'skip'; $r['note'] = 'Already on this voyage.'; }
        }
        if (!$err && $r['status'] === 'new' && $d['seat_no']) {
            $s = $d['seat_no'];
            if (!seat_fits($v, $d['class'], $s)) $err[] = "Seat $s is not a {$d['class']} seat.";
            elseif (seat_taken($vid, $s)) $err[] = "Seat $s is already taken.";
            elseif (isset($seen_seat[$s])) $err[] = "Seat $s is also in row {$seen_seat[$s]}.";
            else $seen_seat[$s] = $r['_line'];
        }
        if (!$err && $r['status'] === 'new' && !$d['seat_no']) $r['note'] = trim($r['note'] . ' ' . ($opt['auto_seat'] ? 'A free seat will be given.' : 'No seat yet — given at check-in.'));
        if ($err) $r['status'] = 'invalid';
        $r['errors'] = $err;
        $r['data']   = $d;
        $r['grp']    = $r['group'] !== '' ? 'g' . $r['group'] : 'r' . $r['_line'];
        if (!$err) $r['fare'] = compute_fare((int)$v['route_id'], $d['class'], $d['pax_category'])['fare'];
    }
    unset($r);

    // Capacity: new passengers in file order, a group (same "group" value) fits whole or not at all
    $cap = pi_capacity($vid);
    $groups = [];
    foreach ($rows as $i => $r) if ($r['status'] === 'new') $groups[$r['grp']][] = $i;
    $used = 0; $cls_used = [];
    foreach ($groups as $idx) {
        $need = count($idx); $need_cls = [];
        foreach ($idx as $i) $need_cls[$rows[$i]['data']['class']] = ($need_cls[$rows[$i]['data']['class']] ?? 0) + 1;
        $fits = $used + $need <= $cap['available'];
        foreach ($need_cls as $c => $n) if (($cls_used[$c] ?? 0) + $n > ($cap['classes'][$c]['remaining'] ?? 0)) $fits = false;
        if ($fits) { $used += $need; foreach ($need_cls as $c => $n) $cls_used[$c] = ($cls_used[$c] ?? 0) + $n; continue; }
        foreach ($idx as $i) { $rows[$i]['status'] = 'over'; $rows[$i]['errors'] = ['Over capacity — no seat left for ' . ($need > 1 ? "this group of $need" : 'this passenger') . '.']; }
    }
    $count = fn($s) => count(array_filter($rows, fn($r) => $r['status'] === $s));
    $sum = ['total' => count($rows), 'new' => $count('new'), 'update' => $count('update'), 'skip' => $count('skip'),
            'over' => $count('over'), 'invalid' => $count('invalid'), 'cap' => $cap,
            'fare' => array_sum(array_map(fn($r) => $r['status'] === 'new' ? $r['fare'] : 0, $rows))];
    $sum['valid']   = $sum['new'] + $sum['update'] + $sum['skip'] + $sum['over'];
    $sum['blocked'] = $sum['over'] > 0 && $opt['over'] === 'block';
    return [$rows, $sum];
}

/** Other open voyages on the same route (from today) the over-capacity passengers can be booked on, with seats left. */
function pi_alt_options(array $v): array {
    $st = db()->prepare("SELECT v.voyage_id, v.departure_date, v.departure_time, s.vessel_name, s.max_passengers, s.maintenance, s.maintenance_since, s.maintenance_until,
            (SELECT COUNT(*) FROM passenger p WHERE p.voyage_id=v.voyage_id AND p.status IN ('Booked','Checked-in','Boarded')) AS taken
        FROM voyage v JOIN vessel s ON s.vessel_id=v.vessel_id
        WHERE v.route_id=? AND v.voyage_id<>? AND v.archived=0 AND v.manifest_status IN ('Open','In Progress')
          AND (v.departure_date > CURDATE() OR (v.departure_date = CURDATE() AND v.departure_time > CURTIME()))
        ORDER BY v.departure_date, v.departure_time LIMIT 80");
    $rid = (int)$v['route_id']; $vid = (int)$v['voyage_id'];
    $st->bind_param('ii', $rid, $vid); $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $o['left'] = max(0, (int)$o['max_passengers'] - (int)$o['taken']);
        if ($o['left'] > 0 && !vessel_under_maintenance($o, $o['departure_date'])) $out[(int)$o['voyage_id']] = $o;
    }
    return $out;
}

/**
 * pi_check() + the "book on another voyage" option: when $opt['over'] = 'move', the rows that do not fit
 * are checked again against the voyage $opt['alt_vid'] (seat numbers from the file dropped — another vessel).
 * Those that fit there get status 'moved'; the others stay 'over' with the reason.
 * Returns [rows, summary, alt voyage or null, alt rows (checked against the alt voyage)].
 */
function pi_plan(array $raw, array $v, array $opt): array {
    $main = ['over' => $opt['over'] === 'move' ? 'limit' : $opt['over']] + $opt;
    [$rows, $sum] = pi_check($raw, $v, $main);
    $alt = null; $alt_rows = [];
    $sum['moved'] = 0; $sum['need_alt'] = false;
    if ($opt['over'] === 'move' && $sum['over']) {
        $choices = pi_alt_options($v);
        $alt = isset($choices[(int)($opt['alt_vid'] ?? 0)]) ? get_voyage((int)$opt['alt_vid']) : null;
        if ($alt && voyage_closed_reason($alt)) $alt = null;
        $sum['need_alt'] = !$alt;
        if ($alt) {
            $over_lines = array_flip(array_column(array_filter($rows, fn($r) => $r['status'] === 'over'), '_line'));
            $sub = array_values(array_map(fn($r) => ['seat_number' => ''] + $r, array_filter($raw, fn($r) => isset($over_lines[$r['_line']]))));
            [$alt_rows] = pi_check($sub, $alt, ['over' => 'limit', 'dup' => 'skip'] + $opt);
            $by_line = array_column($alt_rows, null, '_line');
            $label = voyage_label($alt);
            foreach ($rows as &$r) {
                if ($r['status'] !== 'over' || !isset($by_line[$r['_line']])) continue;
                $a = $by_line[$r['_line']];
                if ($a['status'] === 'new') {
                    $r['status'] = 'moved'; $r['errors'] = []; $r['fare'] = $a['fare']; $r['data'] = $a['data'];
                    $r['note'] = 'No room on this voyage — booked on ' . $label . ($r['seat_number'] !== '' ? ' (file seat not used)' : '') . '.';
                } else {
                    $r['errors'] = ['No room on this voyage, and not on ' . $label . ' either: '
                        . ($a['status'] === 'skip' ? 'already booked there.' : implode(' ', $a['errors']))];
                }
            }
            unset($r);
            $alt_rows = array_values(array_filter($alt_rows, fn($a) => $a['status'] === 'new'));
            $sum['moved'] = count($alt_rows);
            $sum['over'] -= $sum['moved'];
            $sum['fare'] += array_sum(array_column($alt_rows, 'fare'));
        }
    }
    $sum['blocked'] = $opt['over'] === 'block' && $sum['over'] > 0;
    return [$rows, $sum, $alt, $alt_rows];
}

// ═════════════════════════════════════════════════════════════════════════
// Saving
// ═════════════════════════════════════════════════════════════════════════

/**
 * Save the checked rows. New passengers go in, in chunks of PI_CHUNK rows, each chunk one transaction with the
 * voyage row locked and capacity re-checked per group (so two imports at once cannot overbook). Duplicates are
 * updated (personal details only — never class, seat or fare) when that option is on.
 * Returns ['imported','updated','skipped','failed','refs','problems' => [[line, name, status, reason]], 'error' => ?string].
 */
function pi_save(array $rows, array $v, array $opt, int $uid): array {
    @set_time_limit(600);
    $db = db(); $vid = (int)$v['voyage_id'];
    $res = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'refs' => [], 'problems' => [], 'error' => null];
    $name = fn($r) => trim($r['last_name'] . ', ' . $r['first_name'], ', ');
    $problem = function ($r, string $status, string $why) use (&$res, $name) { $res['problems'][] = [$r['_line'], $name($r), $status, $why]; };

    foreach ($rows as $r) {
        if ($r['status'] === 'invalid') { $res['failed']++; $problem($r, 'Failed', implode(' ', $r['errors'])); }
        elseif ($r['status'] === 'over') { $res['skipped']++; $problem($r, 'Skipped', implode(' ', $r['errors'])); }
        elseif ($r['status'] === 'skip') { $res['skipped']++; $problem($r, 'Skipped', 'Duplicate — already on this voyage.'); }
    }
    $groups = []; $updates = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'new') $groups[$r['grp']][] = $r;
        if ($r['status'] === 'update') $updates[] = $r;
    }
    $chunks = []; $cur = []; $n = 0;
    foreach ($groups as $g) { if ($cur && $n + count($g) > PI_CHUNK) { $chunks[] = $cur; $cur = []; $n = 0; } $cur[] = $g; $n += count($g); }
    if ($cur || $updates) $chunks[] = $cur;

    foreach ($chunks as $ci => $chunk) {
        $db->begin_transaction();
        $done = ['imported' => 0, 'updated' => 0, 'refs' => [], 'problems' => []];
        try {
            $lv = lock_voyage($vid);
            if ($why = voyage_closed_reason($lv)) throw new RuntimeException($why);
            if ($ci === 0 && $opt['over'] === 'block') {   // re-check the whole file under the lock
                if ($over = count(array_filter($rows, fn($r) => $r['status'] === 'over')))
                    throw new RuntimeException("Import blocked — $over passenger(s) do not fit. Nothing was saved.");
                $all = array_sum(array_map('count', $groups));
                if ($all > ($c = pi_capacity($vid))['available'])
                    throw new RuntimeException("Import blocked — $all new passenger(s) but only {$c['available']} seat(s) available now. Nothing was saved.");
            }
            if ($ci === 0) foreach ($updates as $r) {
                $d = $r['data'];
                $st = $db->prepare('UPDATE passenger SET middle_name=COALESCE(?, middle_name), age=?, sex=?, nationality=?,
                        id_type=COALESCE(?, id_type), id_number=COALESCE(?, id_number), contact_no=COALESCE(?, contact_no), email=COALESCE(?, email),
                        address=COALESCE(?, address), emergency_contact=COALESCE(?, emergency_contact) WHERE passenger_id=? AND voyage_id=?');
                $st->bind_param('sissssssssii', $d['middle_name'], $d['age'], $d['sex'], $d['nationality'], $d['id_type'], $d['id_number'],
                    $d['contact_no'], $d['email'], $d['address'], $d['emergency_contact'], $r['match_pid'], $vid);
                $st->execute();
                $done['updated']++;
            }
            foreach ($chunk as $g) {
                $size = count($g); $why = capacity_error($vid, $size);
                if (!$why) { $need = array_count_values(array_map(fn($r) => $r['data']['class'], $g)); foreach ($need as $c => $k) $why ??= class_capacity_error($vid, $c, $k); }
                if ($why) { foreach ($g as $r) $done['problems'][] = [$r, 'Skipped', 'Over capacity at save time — ' . $why]; continue; }
                $db->query('SAVEPOINT pi_group');
                try {
                    $ref = new_booking_ref();
                    foreach ($g as $r) {
                        $d = $r['data'];
                        if ($d['seat_no'] && seat_taken($vid, $d['seat_no'])) throw new RuntimeException('Seat ' . $d['seat_no'] . ' was taken meanwhile.');
                        insert_passenger($d, $lv, 'Booked', $ref, $uid, (bool)$opt['auto_seat']);
                    }
                    $db->query('RELEASE SAVEPOINT pi_group');
                    $done['imported'] += $size; $done['refs'][] = $ref;
                } catch (RuntimeException $e) {
                    $db->query('ROLLBACK TO SAVEPOINT pi_group');
                    foreach ($g as $r) $done['problems'][] = [$r, 'Failed', $e->getMessage()];
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            $res['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Server error — this part of the import was rolled back.';
            foreach (array_slice($chunks, $ci) as $rest) foreach ($rest as $g) foreach ($g as $r) { $res['failed']++; $problem($r, 'Failed', $res['error']); }
            if ($ci === 0) foreach ($updates as $r) { $res['failed']++; $problem($r, 'Failed', $res['error']); }
            break;
        }
        $res['imported'] += $done['imported']; $res['updated'] += $done['updated']; $res['refs'] = array_merge($res['refs'], $done['refs']);
        foreach ($done['problems'] as [$r, $s, $why]) { $s === 'Skipped' ? $res['skipped']++ : $res['failed']++; $problem($r, $s, $why); }
    }
    usort($res['problems'], fn($a, $b) => $a[0] <=> $b[0]);
    return $res;
}

/** CSV cell safe from spreadsheet formula injection. */
function pi_csv_cell($v): string {
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// ═════════════════════════════════════════════════════════════════════════
// Temporary files (storage/imports, denied to the browser)
// ═════════════════════════════════════════════════════════════════════════

function pi_store(string $token, string $kind, array $data): void {
    if (!is_dir(PI_DIR)) @mkdir(PI_DIR, 0775, true);
    file_put_contents(PI_DIR . "/$token.$kind.json", json_encode($data, JSON_UNESCAPED_UNICODE));
    foreach (glob(PI_DIR . '/*.json') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);   // older than a day
}
function pi_load(string $token, string $kind): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $token) || !is_file($f = PI_DIR . "/$token.$kind.json")) return null;
    return json_decode((string)file_get_contents($f), true) ?: null;
}
function pi_drop(string $token, string $kind): void {
    if (preg_match('/^[a-f0-9]{24}$/', $token)) @unlink(PI_DIR . "/$token.$kind.json");
}

// ═════════════════════════════════════════════════════════════════════════
// Button + modal
// ═════════════════════════════════════════════════════════════════════════

function passenger_import_button(string $class = 'btn btn-sm btn-success'): string {
    return '<button type="button" class="' . e($class) . '" data-bs-toggle="modal" data-bs-target="#paxImportModal">'
         . '<i class="bi bi-file-earmark-arrow-up me-1"></i>Import</button>';
}

/**
 * The upload modal. $fixed = the voyage (Manifest page); null = pick the date and voyage in the modal,
 * starting at $preselect. $back = page to return to (path under BASE_URL).
 */
function passenger_import_modal(?array $fixed, string $back, int $preselect = 0): string {
    $db = db();
    $sql = "SELECT v.voyage_id, v.departure_date, v.departure_time, v.manifest_status, r.route_code, s.vessel_name, s.max_passengers,
                   (SELECT COUNT(*) FROM passenger p WHERE p.voyage_id=v.voyage_id AND p.status IN ('Booked','Checked-in','Boarded')) AS taken
              FROM voyage v JOIN route r ON r.route_id=v.route_id JOIN vessel s ON s.vessel_id=v.vessel_id
             WHERE v.archived=0 AND v.manifest_status IN ('Open','In Progress') AND " . ($fixed ? 'v.voyage_id=' . (int)$fixed['voyage_id']
                 : 'v.departure_date >= CURDATE()') . " ORDER BY v.departure_date, v.departure_time LIMIT 400";
    $list = [];
    foreach ($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $o)
        $list[] = ['id' => (int)$o['voyage_id'], 'day' => $o['departure_date'], 'max' => (int)$o['max_passengers'], 'taken' => (int)$o['taken'],
                   'label' => date('g:i A', strtotime($o['departure_time'])) . ' · ' . $o['route_code'] . ' · ' . $o['vessel_name'] . ($o['manifest_status'] !== 'Open' ? ' · ' . $o['manifest_status'] : '')];
    $sel = $fixed ? (int)$fixed['voyage_id'] : $preselect;
    $days = array_values(array_unique(array_column($list, 'day')));
    $selday = ''; foreach ($list as $o) if ($o['id'] === $sel) $selday = $o['day'];
    $selday = $selday ?: ($days[0] ?? '');
    $B = BASE_URL;

    ob_start(); ?>
<div class="modal fade" id="paxImportModal" tabindex="-1" aria-labelledby="paxImportTitle">
  <div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" enctype="multipart/form-data" action="<?= $B ?>/modules/admin/import_passengers.php" class="modal-content" id="paxImportForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="paxImportTitle"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <?php if (!$list): ?>
      <div class="alert alert-warning mb-0">No open voyage to import into<?= $fixed ? ' — this manifest is ' . e($fixed['manifest_status']) : '' ?>.</div>
      <?php else: ?>
      <?php if ($fixed): ?>
      <input type="hidden" name="vid" value="<?= (int)$fixed['voyage_id'] ?>">
      <div class="mb-2"><div class="small text-muted">Voyage</div><div class="fw-semibold"><?= e(voyage_label($fixed)) ?></div></div>
      <?php else: ?>
      <div class="row g-2 mb-2">
        <div class="col-sm-4"><label class="form-label small mb-0" for="piDay">Departure date</label>
          <select id="piDay" class="form-select"><?php foreach ($days as $d): ?><option value="<?= e($d) ?>" <?= $d === $selday ? 'selected' : '' ?>><?= e(date('D, M j, Y', strtotime($d))) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-8"><label class="form-label small mb-0" for="piVoyage">Voyage (time · route · vessel)</label>
          <select name="vid" id="piVoyage" class="form-select" required></select></div>
      </div>
      <?php endif; ?>
      <div class="alert alert-light border py-2 small mb-3" id="piCap"></div>
      <label class="form-label small mb-0" for="piFile">Passenger list (.csv or .xlsx, max 5 MB)</label>
      <input type="file" name="file" id="piFile" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <div class="form-text"><a href="<?= $B ?>/modules/admin/import_passengers.php?template=1"><i class="bi bi-download me-1"></i>Download Template</a> — one passenger per row; keep the header row.
        Required: first_name, last_name, gender, age or birthdate.</div>
      <div class="row g-3 mt-1 small">
        <div class="col-md-5"><div class="fw-semibold mb-1">If the file has more passengers than available seats</div>
          <div class="form-check"><input class="form-check-input" type="radio" name="over" id="piOverBlock" value="block" checked><label class="form-check-label" for="piOverBlock">Block the import</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="over" id="piOverLimit" value="limit"><label class="form-check-label" for="piOverLimit">Import only up to the limit</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="over" id="piOverMove" value="move"><label class="form-check-label" for="piOverMove">Book the extra passengers on another voyage <span class="text-muted">(choose it in the preview)</span></label></div></div>
        <div class="col-md-4"><div class="fw-semibold mb-1">Passengers already on this voyage</div>
          <div class="form-check"><input class="form-check-input" type="radio" name="dup" id="piDupSkip" value="skip" checked><label class="form-check-label" for="piDupSkip">Skip them</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="dup" id="piDupUpd" value="update"><label class="form-check-label" for="piDupUpd">Update their details</label></div></div>
        <div class="col-md-3"><div class="fw-semibold mb-1">Seats</div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="auto_seat" id="piAuto" value="1"><label class="form-check-label" for="piAuto">Auto-assign a seat when seat_number is blank</label></div></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      <?php if ($list): ?><button class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Upload &amp; preview</button><?php endif; ?></div>
  </form></div>
</div>
<script>
(() => {
    const list = <?= json_encode($list, JSON_HEX_TAG | JSON_HEX_AMP) ?>, sel = <?= (int)$sel ?>;
    const day = document.getElementById('piDay'), vs = document.getElementById('piVoyage'), cap = document.getElementById('piCap');
    const show = id => {
        const o = list.find(x => x.id === +id);
        cap.innerHTML = o ? 'Capacity: <b>' + o.max + '</b> &nbsp;|&nbsp; Already booked: <b>' + o.taken + '</b> &nbsp;|&nbsp; Available: <b class="' + (o.max - o.taken > 0 ? 'text-success' : 'text-danger') + '">' + Math.max(0, o.max - o.taken) + '</b>'
                        + '<div class="text-muted">The exact room (class seats, max load) is checked in the preview.</div>' : 'Pick the voyage.';
    };
    if (vs) {
        const fill = () => {
            vs.innerHTML = '';
            list.filter(o => o.day === day.value).forEach(o => vs.add(new Option(o.label + ' — ' + Math.max(0, o.max - o.taken) + ' seats left', o.id, false, o.id === sel)));
            show(vs.value);
        };
        day.addEventListener('change', fill); vs.addEventListener('change', () => show(vs.value)); fill();
    } else show(sel);
    document.getElementById('paxImportForm').addEventListener('submit', e => {
        const f = document.getElementById('piFile').files[0];
        if (f && f.size > <?= PI_MAX_BYTES ?>) { e.preventDefault(); alert('The file is bigger than 5 MB.'); }
        else if (f && !/\.(csv|xlsx)$/i.test(f.name)) { e.preventDefault(); alert('Upload a .csv or .xlsx file.'); }
    });
})();
</script>
<?php
    return ob_get_clean();
}
