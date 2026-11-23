<?php
/**
 * Check-in, boarding and payment-collection logic shared by:
 *   modules/checkin/*, modules/baggage, modules/payments/*, modules/admin/ticketing.php,
 *   modules/admin/boarding.php
 * Keep the business rules HERE so every page checks in / boards / collects the same way.
 * Requires config/auth.php to be loaded first (audit(), db(), helpers).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/wallet.php';

/** Who may mark a passenger as BOARDED (at the gangway, by ticket number). */
const CHECKIN_BOARD_ROLES = ['Admin', 'Passage', 'Coast Guard'];

/** Passenger statuses that occupy a seat. */
const SEAT_STATUSES = ['Booked', 'Checked-in', 'Boarded'];

// ═════════════════════════════════════════════════════════════════════════
// Seats
// ═════════════════════════════════════════════════════════════════════════

/**
 * Next seat for a passenger, ignoring seats of No-show / Cancelled passengers.
 * Vessels with a seat map (seats per accommodation): the balanced seat of $class (see includes/seats.php).
 * Other vessels: the lowest free seat number 1..max_passengers.
 * $exclude_pid ignores that passenger's own seat. Returns null when no seat is left.
 */
function next_free_seat(int $vid, int $exclude_pid = 0, ?string $class = null): ?string {
    if ($class !== null && ($picked = pick_balanced_seats($vid, $class, 1, $exclude_pid)) !== null) return $picked[0] ?? null;
    $db = db();
    $st = $db->prepare("SELECT vsl.max_passengers FROM voyage v JOIN vessel vsl ON vsl.vessel_id=v.vessel_id WHERE v.voyage_id=?");
    $st->bind_param('i', $vid); $st->execute();
    $max = (int)($st->get_result()->fetch_row()[0] ?? 0);
    $st = $db->prepare("SELECT seat_no FROM passenger WHERE voyage_id=? AND passenger_id<>?
                        AND status IN ('Booked','Checked-in','Boarded') AND seat_no IS NOT NULL AND seat_no<>''");
    $st->bind_param('ii', $vid, $exclude_pid); $st->execute();
    $used = [];
    foreach ($st->get_result()->fetch_all() as $r) {
        if (ctype_digit((string)$r[0])) $used[(int)$r[0]] = true;
    }
    for ($i = 1; $i <= $max; $i++) {
        if (!isset($used[$i])) return (string)$i;
    }
    return null;
}

/** Is this seat already used by another active passenger of the voyage? */
function seat_taken(int $vid, string $seat, int $exclude_pid = 0): bool {
    $st = db()->prepare("SELECT 1 FROM passenger WHERE voyage_id=? AND seat_no=? AND passenger_id<>?
                         AND status IN ('Booked','Checked-in','Boarded') LIMIT 1");
    $st->bind_param('isi', $vid, $seat, $exclude_pid); $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

// ═════════════════════════════════════════════════════════════════════════
// Passenger lookups
// ═════════════════════════════════════════════════════════════════════════

const PAX_FULL_SQL = "SELECT p.*, b.baggage_id, b.actual_weight, b.allowance_kg, b.excess_kg, b.fee_rate,
        b.discount_pct AS bag_discount_pct, b.excess_fee, b.is_paid AS bag_paid, b.tag_no,
        v.voyage_no, v.route_id, v.departure_date, v.departure_time, v.manifest_status, v.vessel_id, v.ready_to_board, v.archived,
        vsl.vessel_name, vsl.maintenance, vsl.maintenance_since, vsl.maintenance_until, vsl.maintenance_note,
        po.port_code AS orig, po.port_name AS orig_name, pd.port_code AS dest, pd.port_name AS dest_name
    FROM passenger p
    LEFT JOIN baggage b ON b.passenger_id = p.passenger_id
    JOIN voyage v   ON v.voyage_id  = p.voyage_id
    JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
    JOIN route r    ON r.route_id   = v.route_id
    JOIN port po    ON po.port_id   = r.origin_port_id
    JOIN port pd    ON pd.port_id   = r.destination_port_id";

function pax_full_by(string $col, $value): ?array {
    $col = pick($col, ['p.passenger_id', 'p.ticket_no']);
    $st  = db()->prepare(PAX_FULL_SQL . " WHERE $col = ? LIMIT 1");
    if ($col === 'p.passenger_id') { $v = (int)$value; $st->bind_param('i', $v); }
    else { $v = (string)$value; $st->bind_param('s', $v); }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) return null;
    $row['under_maintenance'] = vessel_under_maintenance($row, $row['departure_date']);
    return $row;
}

function pax_display_name(array $p): string {
    return strtoupper($p['last_name']) . ', ' . $p['first_name']
         . (!empty($p['middle_name']) ? ' ' . strtoupper(mb_substr($p['middle_name'], 0, 1)) . '.' : '');
}

/**
 * QR text on the printed boarding pass: who the passenger is (so a person can be
 * identified in an emergency with any phone, even offline) + the signed verify
 * link the gangway scanner reads. One line: a USB scanner types a newline as Enter.
 * No ID number or address is put in the QR.
 */
function boarding_pass_qr_data(array $p): string {
    $dep = strtotime($p['departure_date'] . ' ' . $p['departure_time']);
    $parts = [
        APP_NAME . ' BOARDING PASS',
        pax_display_name($p),
        (int)$p['age'] . ' yrs ' . $p['sex'] . ', ' . $p['nationality'],
        'Ticket ' . $p['ticket_no'] . ' Seat ' . ($p['seat_no'] ?: '-'),
        $p['vessel_name'] . ' ' . $p['orig'] . '-' . $p['dest'] . ' ' . date('M d Y g:i A', $dep),
    ];
    if (!empty($p['emergency_contact'])) $parts[] = 'Emergency contact: ' . $p['emergency_contact'];
    $parts[] = 'Verify: ' . ticket_qr_data($p);
    return implode(' | ', $parts);
}

/**
 * State of a ticket:  valid | expired | booked | boarded | cancelled
 */
function pax_ticket_state(array $p): string {
    if (in_array($p['status'], ['Cancelled', 'No-show'], true)) return 'cancelled';
    if ($p['status'] === 'Boarded') return 'boarded';
    if (!empty($p['ticket_valid_until']) && strtotime($p['ticket_valid_until']) < time()) return 'expired';
    if ($p['status'] === 'Booked') return 'booked';
    return 'valid';
}

/** Why this passenger cannot be boarded now (null = can board). $p from pax_full_by(). */
function board_block_reason(array $p): ?string {
    $state = pax_ticket_state($p);
    if ($state === 'boarded')   return 'Passenger is already boarded.';
    if ($state === 'cancelled') return 'Ticket is ' . $p['status'] . '.';
    if ($state === 'expired')   return 'Ticket has expired (valid until ' . date('M d, Y g:i A', strtotime($p['ticket_valid_until'])) . ').';
    if ($state === 'booked')    return 'Passenger is not yet checked in. Proceed to the check-in counter.';
    if (!empty($p['under_maintenance'])) return 'Vessel is under maintenance — boarding not allowed.';
    if (in_array($p['manifest_status'], ['Departed', 'Cancelled'], true))
        return 'Voyage is ' . $p['manifest_status'] . ' — boarding not allowed.';
    // Boarding opens only after the Coast Guard confirms the vessel is READY TO BOARD
    if (empty($p['ready_to_board']))
        return 'Not yet cleared — wait for the Coast Guard to confirm READY TO BOARD.';
    return null;
}

/**
 * Check a scanned QR / typed ticket number at the gate or on the verify page.
 * $expect_vid: the voyage being boarded (0 = any).
 * Returns ['state'=>valid|invalid|forged|expired|wrong_voyage|booked|boarded|cancelled|blocked,
 *          'p'=>?array, 'msg'=>string, 'can_board'=>bool, 'from_qr'=>bool]
 */
function ticket_gate_check(string $input, int $expect_vid = 0): array {
    $in = parse_ticket_input($input);
    $out = ['state' => 'invalid', 'p' => null, 'msg' => '', 'can_board' => false, 'from_qr' => $in['from_qr']];
    if ($in['ticket_no'] === '') { $out['msg'] = 'Scan the QR code or type the ticket number.'; return $out; }
    if ($in['from_qr'] && !$in['sig_ok']) { $out['state'] = 'forged'; $out['msg'] = 'INVALID QR — this code was not issued by MANLOAD. Check the passenger\'s ID and ticket.'; return $out; }
    $p = pax_full_by('p.ticket_no', $in['ticket_no']);
    if (!$p) { $out['msg'] = 'Ticket ' . $in['ticket_no'] . ' not found.'; return $out; }
    $out['p'] = $p;
    $state = pax_ticket_state($p);
    if ($in['qr_expired'] && in_array($state, ['valid', 'booked'], true)) $state = 'expired';
    if ($expect_vid && (int)$p['voyage_id'] !== $expect_vid && !in_array($state, ['cancelled'], true)) {
        $out['state'] = 'wrong_voyage';
        $out['msg'] = 'WRONG VOYAGE — this ticket is for ' . $p['orig'] . '→' . $p['dest'] . ' '
                    . date('M d g:i A', strtotime($p['departure_date'] . ' ' . $p['departure_time'])) . '.';
        return $out;
    }
    $out['state'] = $state;
    $vu = $p['ticket_valid_until'] ? date('M d, Y g:i A', strtotime($p['ticket_valid_until'])) : '—';
    switch ($state) {
        case 'expired':   $out['msg'] = 'EXPIRED — this ticket/QR was valid until ' . $vu . '.'; break;
        case 'cancelled': $out['msg'] = 'NOT VALID — ticket is ' . $p['status'] . '.'; break;
        case 'boarded':   $out['msg'] = 'ALREADY BOARDED' . ($p['boarded_at'] ? ' at ' . date('g:i A', strtotime($p['boarded_at'])) : '') . '.'; break;
        case 'booked':    $out['msg'] = 'NOT CHECKED IN — send the passenger to the check-in counter.'; break;
        default:
            $block = board_block_reason($p);
            if ($block) { $out['state'] = 'blocked'; $out['msg'] = $block; }
            else { $out['can_board'] = true; $out['msg'] = 'VALID — valid until ' . $vu . '. Check the passenger\'s ID.'; }
    }
    return $out;
}

/**
 * Confirm (verify) the pending online payment of a booking: issue the ORs for the fares,
 * mark the booking verified and e-mail the passenger.
 * Returns ['ok'=>bool, 'msg'=>string, 'payment_ids'=>int[]]
 */
function verify_online_payment(string $ref, ?int $uid): array {   // $uid null = confirmed automatically by the online checkout
    $db = db();
    $pay_ids = [];
    $db->begin_transaction();
    try {
        $st = $db->prepare("SELECT passenger_id, status, fare, fare_paid, online_pay_method, online_pay_ref FROM passenger
                            WHERE booking_ref=? AND online_pay_status='pending' ORDER BY passenger_id FOR UPDATE");
        $st->bind_param('s', $ref); $st->execute();
        $paxs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$paxs) throw new RuntimeException('No pending online payment for ' . $ref . ' (already processed?).');
        $method = in_array($paxs[0]['online_pay_method'], array_diff(ALL_PAY_METHODS, ['Cash']), true) ? $paxs[0]['online_pay_method'] : 'GCash';
        $pref   = trim((string)$paxs[0]['online_pay_ref']) ?: ('ONLINE-' . $ref);
        if (($used = pay_ref_used_elsewhere($method, $pref, $ref)))
            throw new RuntimeException("Reference $pref was already used ($used). Reject this payment or ask the passenger for the correct reference.");
        foreach ($paxs as $p) {
            if ((float)$p['fare'] > 0 && !$p['fare_paid'] && in_array($p['status'], SEAT_STATUSES, true)) {
                $r = collect_locked('fare', (int)$p['passenger_id'], $method, $pref, $uid, true);
                if (!$r['ok']) throw new RuntimeException($r['msg']);
                $pay_ids[] = $r['payment_id'];
            }
        }
        $st = $db->prepare("UPDATE passenger SET online_pay_status='verified' WHERE booking_ref=? AND online_pay_status='pending'");
        $st->bind_param('s', $ref); $st->execute();
        // Vehicle freight is NOT part of the online payment — it is paid at the port
        // (the passenger's e-ticket says so), so it stays in Payments > Collect.
        audit('Online payment verified ' . $ref, 'passenger', (int)$paxs[0]['passenger_id'], ['online_pay_status' => 'pending'],
              ['online_pay_status' => 'verified', 'method' => $method, 'reference_no' => $pref, 'receipts' => count($pay_ids)]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'payment_ids' => [], 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Verification failed: database error.'];
    }
    // Payment confirmation with the OR numbers (falls back to the short notice if nothing was collected)
    $pay_ids ? send_payment_receipt($pay_ids) : send_payment_result($ref, true);
    return ['ok' => true, 'payment_ids' => $pay_ids, 'msg' => 'Payment for ' . $ref . ' verified — ' . count($pay_ids) . ' OR(s) issued. Passenger notified by email.'];
}

/** Plate of the vehicle this passenger drives (the driver rides free), or null. */
function pax_driver_of(int $pid): ?string {
    $st = db()->prepare('SELECT plate_no FROM vehicle WHERE driver_passenger_id=? LIMIT 1');
    $st->bind_param('i', $pid); $st->execute();
    return $st->get_result()->fetch_row()[0] ?? null;
}

// ═════════════════════════════════════════════════════════════════════════
// Baggage
// ═════════════════════════════════════════════════════════════════════════

/** Insert or update the passenger's baggage row (one per passenger). */
function save_baggage(int $pid, array $voyage, string $class, string $category, float $kg): void {
    $allow = baggage_allowance($voyage, $class);
    $rate  = (float)$voyage['excess_rate_per_kg'];
    $disc  = in_array($category, DISCOUNTED_CATEGORIES, true) ? DISCOUNT_PCT : 0;
    $vid   = (int)$voyage['voyage_id'];
    $tag   = $kg > 0 ? 'BG-' . str_pad((string)$pid, 6, '0', STR_PAD_LEFT) : null;
    $st = db()->prepare("INSERT INTO baggage (passenger_id, voyage_id, actual_weight, allowance_kg, fee_rate, discount_pct, tag_no)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE actual_weight=VALUES(actual_weight), allowance_kg=VALUES(allowance_kg),
                           fee_rate=VALUES(fee_rate), discount_pct=VALUES(discount_pct), tag_no=COALESCE(tag_no, VALUES(tag_no))");
    $st->bind_param('iidddis', $pid, $vid, $kg, $allow, $rate, $disc, $tag);
    $st->execute();
}

// ═════════════════════════════════════════════════════════════════════════
// Check-in
// ═════════════════════════════════════════════════════════════════════════

/** Lock the voyage row (call right after begin_transaction) and return the fresh voyage. */
function lock_voyage(int $vid): ?array {
    $st = db()->prepare('SELECT voyage_id FROM voyage WHERE voyage_id=? FOR UPDATE');
    $st->bind_param('i', $vid); $st->execute();
    if (!$st->get_result()->fetch_row()) return null;
    return get_voyage($vid);
}

/**
 * Validate the walk-in passenger form. Returns [clean_data, errors[]].
 */
function validate_pax_input(array $in, array $voyage, bool $need_id = true): array {
    $err = [];
    $d = [
        'last_name'   => trim((string)($in['last_name'] ?? '')),
        'first_name'  => trim((string)($in['first_name'] ?? '')),
        'middle_name' => trim((string)($in['middle_name'] ?? '')),
        'age'         => (int)($in['age'] ?? -1),
        'sex'         => pick($in['sex'] ?? '', PAX_SEXES),
        'nationality' => trim((string)($in['nationality'] ?? '')) ?: 'Filipino',
        'id_type'     => trim((string)($in['id_type'] ?? '')),
        'id_number'   => trim((string)($in['id_number'] ?? '')),
        'pax_category'=> pick($in['pax_category'] ?? '', PAX_CATEGORIES),
        'class'       => (string)($in['class'] ?? ''),
        'contact_no'  => trim((string)($in['contact_no'] ?? '')),
        'email'       => trim((string)($in['email'] ?? '')),
        'emergency_contact' => trim((string)($in['emergency_contact'] ?? '')),
        'remarks'     => trim((string)($in['remarks'] ?? '')),
        'seat_no'     => strtoupper(trim((string)($in['seat_no'] ?? ''))),
        'address'     => trim((string)($in['address'] ?? '')),
    ];
    if ($d['last_name'] === '' || $d['first_name'] === '') $err[] = 'Last name and first name are required.';
    if (mb_strlen($d['last_name']) > 50 || mb_strlen($d['first_name']) > 50 || mb_strlen($d['middle_name']) > 50) $err[] = 'Name is too long.';
    if ($d['age'] < 0 || $d['age'] > 120) $err[] = 'Enter a valid age (0–120).';
    if ($d['pax_category'] === 'Infant' && $d['age'] >= 2) $err[] = 'Infant category is for passengers under 2 years old.';
    if ($d['pax_category'] === 'Senior' && $d['age'] < 60) $err[] = 'Senior citizen discount requires age 60 or above.';
    if ($d['pax_category'] === 'Minor' && $d['age'] >= 18) $err[] = 'Minor category is for passengers under 18.';
    $fares = voyage_fares((int)$voyage['voyage_id']);
    if (!isset($fares[$d['class']])) $err[] = 'Select a class that is offered on this trip.';
    if ($d['id_type'] !== '' && !in_array($d['id_type'], ID_TYPES, true)) $err[] = 'Invalid ID type.';
    if ($need_id && $d['pax_category'] !== 'Infant' && $d['pax_category'] !== 'Minor' && ($d['id_type'] === '' || $d['id_number'] === ''))
        $err[] = 'ID type and ID number are required.';
    if (mb_strlen($d['id_number']) > 30) $err[] = 'ID number is too long.';
    if ($d['email'] !== '' && !valid_email($d['email'])) $err[] = 'Email address is not valid.';
    if (mb_strlen($d['email']) > 100) $err[] = 'Email address is too long.';
    if ($d['contact_no'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $d['contact_no'])) $err[] = 'Contact number is not valid.';
    if (mb_strlen($d['emergency_contact']) > 100) $err[] = 'Emergency contact is too long.';
    if (mb_strlen($d['remarks']) > 255) $err[] = 'Print remarks is too long (max 255 characters).';
    if ($d['seat_no'] !== '' && !seat_fits($voyage, $d['class'], $d['seat_no'])) $err[] = 'Seat ' . $d['seat_no'] . ' is not ' . (preg_match('/^[AEIOU]/', $d['class']) ? 'an ' : 'a ') . $d['class'] . ' seat.';
    foreach (['middle_name', 'id_type', 'id_number', 'contact_no', 'email', 'emergency_contact', 'address', 'remarks'] as $k)
        if ($d[$k] === '') $d[$k] = null;
    return [$d, $err];
}

/**
 * Insert one passenger row (inside a transaction, voyage already locked).
 * $status: 'Booked' or 'Checked-in'. Returns passenger_id.
 * $auto_seat = false: a blank seat stays blank (Booked only — the seat is given at check-in).
 */
function insert_passenger(array $d, array $voyage, string $status, string $booking_ref, int $uid, bool $auto_seat = true): int {
    $f      = compute_fare((int)$voyage['route_id'], $d['class'], $d['pax_category']);
    $vid    = (int)$voyage['voyage_id'];
    $ticket = next_number('TK');
    $exp    = ticket_expiry($voyage);
    $seat   = $d['seat_no'] ?? '';
    if ($seat !== '') {
        if (seat_taken($vid, $seat)) throw new RuntimeException("Seat $seat was just taken — please choose another seat.");
    } elseif ($auto_seat || $status !== 'Booked') {
        $seat = next_free_seat($vid, 0, $d['class']);
        if ($seat === null || $seat === '') throw new RuntimeException('No free ' . $d['class'] . ' seat left on this voyage.');
    } else {
        $seat = null;
    }
    if ($r = class_capacity_error($vid, $d['class'])) throw new RuntimeException($r);
    $checkin_by = $status === 'Checked-in' ? $uid : null;
    $st = db()->prepare("INSERT INTO passenger (voyage_id, ticket_no, booking_ref, booked_via, last_name, first_name, middle_name,
            age, sex, nationality, id_type, id_number, pax_category, seat_no, class, address, contact_no, email,
            emergency_contact, remarks, base_fare, fare, fare_paid, status, ticket_valid_until, checkin_time, checkin_by, booked_by)
        VALUES (?,?,?, 'Counter', ?,?,?, ?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,0, ?,?, IF(?='Checked-in', NOW(), NULL), ?, ?)");
    $base = $f['base']; $fare = $f['fare'];
    $remarks = $d['remarks'] ?? null;
    $types = 'iss' . 'sss' . 'i' . str_repeat('s', 10) . 'ssdd' . 'ss' . 's' . 'i' . 'i';
    $st->bind_param($types,
        $vid, $ticket, $booking_ref, $d['last_name'], $d['first_name'], $d['middle_name'],
        $d['age'], $d['sex'], $d['nationality'], $d['id_type'], $d['id_number'], $d['pax_category'], $seat, $d['class'],
        $d['address'], $d['contact_no'], $d['email'], $d['emergency_contact'], $remarks, $base, $fare,
        $status, $exp, $status, $checkin_by, $uid);   // booked_by = who issued the ticket
    $st->execute();
    $pid = (int)db()->insert_id;
    link_passenger_profile($pid);
    return $pid;
}

/**
 * Walk-in check-in at the counter (new passenger, status Checked-in).
 * $send_pass=false: the caller e-mails the boarding pass itself (e.g. after collecting the fare).
 * Returns ['ok'=>bool, 'msg'=>string, 'pid'=>int, 'booking_ref'=>string, 'errors'=>[]]
 */
function checkin_walkin(int $vid, array $input, float $bag_kg, int $uid, bool $send_pass = true, ?array $pay = null): array {
    $db = db();
    $v0 = get_voyage($vid);
    if (!$v0) return ['ok' => false, 'msg' => 'Voyage not found.'];
    [$d, $err] = validate_pax_input($input, $v0);
    if ($bag_kg < 0 || $bag_kg > 500) $err[] = 'Enter a valid baggage weight (0–500 kg).';
    if ($err) return ['ok' => false, 'msg' => implode(' ', $err), 'errors' => $err];

    $ref = new_booking_ref();
    $db->begin_transaction();
    try {
        $v = lock_voyage($vid);
        if ($r = voyage_closed_reason($v)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }
        if ($r = capacity_error($vid, 1, $bag_kg)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }
        $pid = insert_passenger($d, $v, 'Checked-in', $ref, $uid);
        save_baggage($pid, $v, $d['class'], $d['pax_category'], $bag_kg);
        // No unpaid bills at check-in: the fare and any excess baggage are collected in the same step
        $pay_ids = []; $paid = 0.0;
        foreach ($pay ? checkin_dues($pid, []) : [] as $due) {
            $r = collect_locked($due['kind'], $due['id'], (string)($pay['method'] ?? 'Cash'), $pay['ref'] ?? null, $uid);
            if (!$r['ok']) { $db->rollback(); return ['ok' => false, 'msg' => 'Not checked in — payment failed: ' . $r['msg']]; }
            $pay_ids[] = $r['payment_id']; $paid += $r['amount'];
        }
        if ($dues = checkin_dues($pid, [])) {
            $db->rollback();
            return ['ok' => false, 'msg' => 'Not checked in — collect the fare' . (count($dues) > 1 ? ' and excess baggage' : '') . ' first ('
                . peso(array_sum(array_column($dues, 'due'))) . ').'];
        }
        audit('Walk-in check-in', 'passenger', $pid, [], ['voyage_id' => $vid, 'name' => $d['last_name'] . ', ' . $d['first_name'],
            'class' => $d['class'], 'category' => $d['pax_category'], 'baggage_kg' => $bag_kg]
            + ($pay_ids ? ['collected' => $paid, 'receipts' => count($pay_ids)] : []));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Check-in failed: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'database error.')];
    }
    if ($pay_ids) send_payment_receipt($pay_ids);   // payment confirmation for what was collected at the counter
    // Walk-in is checked in right away: e-mail the boarding pass (backup copy) if we have an address
    $mailed = false;
    if ($d['email'] && $send_pass) { try { $mailed = send_boarding_pass($pid)['ok']; } catch (Throwable $e) { $mailed = false; } }
    return ['ok' => true, 'pid' => $pid, 'booking_ref' => $ref, 'mailed' => $mailed, 'email' => $d['email'], 'payment_ids' => $pay_ids,
            'msg' => 'Checked in ' . $d['last_name'] . ', ' . $d['first_name'] . '.'
                   . ($pay_ids ? ' Collected ' . peso($paid) . ' — ' . count($pay_ids) . ' OR(s).' : '')];
}

/**
 * Vehicles that travel with this passenger's booking (or that this passenger drives). Locks the rows
 * when called inside a transaction. Each row: vehicle_id, plate_no, zone_id, freight_amount, is_paid, kg, type_name.
 */
function checkin_booking_vehicles(array $p, bool $lock = false): array {
    $sql = 'SELECT vh.vehicle_id, vh.plate_no, vh.zone_id, vh.freight_amount, vh.is_paid, vt.type_name,
                   COALESCE(vh.declared_weight_kg, vt.weight_kg) AS kg
            FROM vehicle vh JOIN vehicle_type vt ON vt.type_id = vh.vehicle_type_id
            WHERE vh.voyage_id = ? AND ' . ($p['booking_ref'] ? 'vh.booking_ref = ?' : 'vh.driver_passenger_id = ?')
         . ' ORDER BY vh.vehicle_id' . ($lock ? ' FOR UPDATE' : '');
    $st = db()->prepare($sql);
    $vid = (int)$p['voyage_id'];
    if ($p['booking_ref']) { $ref = (string)$p['booking_ref']; $st->bind_param('is', $vid, $ref); }
    else { $pid = (int)$p['passenger_id']; $st->bind_param('ii', $vid, $pid); }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * What must be settled before this passenger may check in (inside a transaction, after the baggage is saved):
 * unpaid fare, unpaid excess baggage, unpaid freight of the booking's vehicle(s).
 * Returns list of ['kind', 'id', 'label', 'due'].
 */
function checkin_dues(int $pid, array $vehicles): array {
    $items = [];
    foreach ([['fare', $pid], ['excess', $pid]] as [$kind, $id]) {
        $it = pay_item_lock($kind, $id);
        if (($it['due'] ?? 0) > 0 && !$it['paid_flag']) $items[] = ['kind' => $kind, 'id' => $id, 'label' => $it['label'], 'due' => $it['due']];
    }
    foreach ($vehicles as $vh) {
        $it = pay_item_lock('vehicle', (int)$vh['vehicle_id']);
        if (($it['due'] ?? 0) > 0 && !$it['paid_flag']) $items[] = ['kind' => 'vehicle', 'id' => (int)$vh['vehicle_id'], 'label' => $it['label'], 'due' => $it['due']];
    }
    return $items;
}

/**
 * Confirm an online/counter booking at the port (Booked -> Checked-in).
 * Uses the PASSENGER's voyage — never a voyage id from the form.
 * Check-in only goes through when, in one transaction:
 *   1. the baggage is weighed (saved),
 *   2. nothing is unpaid — fare, excess baggage and the booking's vehicle freight
 *      ($pay = ['method'=>..., 'ref'=>...] collects whatever is still due right here; one OR per item),
 *   3. every vehicle of the booking has a load-zone slot (assigned now if it has none yet).
 * $email: optional email to add when the booking has none.
 * Returns ['ok', 'msg', 'pid', 'booking_ref', 'mailed', 'payment_ids', 'paid']
 */
/** Is $seat a real seat of this voyage's vessel? (seat map code, or 1..max_passengers without a map) */
function seat_exists(array $voyage, string $seat): bool {
    $L = seat_layout((int)$voyage['vessel_id']);
    if ($L) return isset($L['seats'][$seat]);
    return ctype_digit($seat) && (int)$seat >= 1 && (int)$seat <= (int)$voyage['max_passengers'];
}

/** Voyage departs after today — its bookings can be confirmed (paid) but not checked in yet. */
function is_advance_voyage(array $v): bool {
    return $v['departure_date'] > date('Y-m-d');
}

/**
 * Seat for a Booked passenger (inside a transaction): the picked seat (must exist, fit the class and be free),
 * else the booked seat, else the balanced seat. Returns [seat, error].
 */
function booked_seat_pick(array $v, array $p, string $want_seat): array {
    $vid = (int)$v['voyage_id']; $pid = (int)$p['passenger_id'];
    $want_seat = strtoupper(trim($want_seat));
    if ($want_seat !== '') {
        $why = !seat_exists($v, $want_seat) ? "Seat $want_seat does not exist on this vessel."
             : (!seat_fits($v, $p['class'], $want_seat) ? "Seat $want_seat is not in the {$p['class']} section."
             : (seat_taken($vid, $want_seat, $pid) ? "Seat $want_seat was just taken by another passenger — choose another seat." : null));
        return $why ? [null, $why] : [$want_seat, null];
    }
    $seat = (string)($p['seat_no'] ?? '');
    if ($seat === '' || seat_taken($vid, $seat, $pid) || !seat_fits($v, $p['class'], $seat)) $seat = next_free_seat($vid, $pid, $p['class']);
    return $seat === null ? [null, 'No free seat left on this voyage.'] : [$seat, null];
}

/**
 * Confirm an advance booking at the counter (status stays Booked): collect the fare and the booking's
 * vehicle freight, lock in the seat. Baggage is weighed (and excess charged) later, at check-in.
 * Returns ['ok', 'msg', 'pid', 'payment_ids', 'paid']
 */
function confirm_booking(int $pid, int $uid, string $email = '', ?array $pay = null, string $want_seat = ''): array {
    $db = db();
    $email = trim($email);
    if ($email !== '' && !valid_email($email)) return ['ok' => false, 'msg' => 'Email address is not valid.'];

    $st = $db->prepare('SELECT voyage_id FROM passenger WHERE passenger_id=?');
    $st->bind_param('i', $pid); $st->execute();
    $vid = (int)($st->get_result()->fetch_row()[0] ?? 0);
    if (!$vid) return ['ok' => false, 'msg' => 'Passenger not found.'];

    $db->begin_transaction();
    try {
        $v = lock_voyage($vid);
        $st = $db->prepare('SELECT * FROM passenger WHERE passenger_id=? FOR UPDATE');
        $st->bind_param('i', $pid); $st->execute();
        $p = $st->get_result()->fetch_assoc();
        if (!$p || (int)$p['voyage_id'] !== $vid) { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger not found.']; }
        if ($p['status'] !== 'Booked') { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger is already ' . $p['status'] . '.']; }
        if ($r = voyage_closed_reason($v)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }
        if ($p['online_pay_status'] === 'pending' && (float)$p['fare'] > 0 && !$p['fare_paid']) {
            $db->rollback();
            return ['ok' => false, 'msg' => 'The online payment (' . $p['online_pay_method'] . ' ref ' . $p['online_pay_ref'] . ') is still waiting for verification. Verify it first (Payments › Online Payments).'];
        }
        [$seat, $why] = booked_seat_pick($v, $p, $want_seat);
        if ($why) { $db->rollback(); return ['ok' => false, 'msg' => $why]; }

        // Fare + vehicle freight only — excess baggage is not known until check-in
        $vehicles = checkin_booking_vehicles($p, true);
        $only_booking = fn() => array_values(array_filter(checkin_dues($pid, $vehicles), fn($d) => $d['kind'] !== 'excess'));
        $dues = $only_booking();
        $pay_ids = []; $paid = 0.0;
        if ($dues && $pay) {
            foreach ($dues as $d) {
                $r = collect_locked($d['kind'], $d['id'], (string)($pay['method'] ?? 'Cash'), $pay['ref'] ?? null, $uid);
                if (!$r['ok']) { $db->rollback(); return ['ok' => false, 'msg' => 'Booking not confirmed — payment failed: ' . $r['msg']]; }
                $pay_ids[] = $r['payment_id']; $paid += $r['amount'];
            }
            $dues = $only_booking();
        }
        if ($dues) {
            $db->rollback();
            return ['ok' => false, 'msg' => 'Booking not confirmed — unpaid ' . peso(array_sum(array_column($dues, 'due'))) . ': '
                . implode('; ', array_map(fn($d) => $d['label'] . ' ' . peso($d['due']), $dues)) . '. Collect the balance first.'];
        }

        $ref = $p['booking_ref'] ?: new_booking_ref();
        $new_email = ($email !== '' && empty($p['email'])) ? $email : $p['email'];
        $st = $db->prepare('UPDATE passenger SET seat_no=?, booking_ref=?, email=? WHERE passenger_id=?');
        $st->bind_param('sssi', $seat, $ref, $new_email, $pid);
        $st->execute();
        audit('Booking confirmed', 'passenger', $pid, ['seat_no' => $p['seat_no']],
              ['seat_no' => $seat] + ($pay_ids ? ['collected' => $paid, 'receipts' => count($pay_ids)] : []));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Confirm booking failed: database error.'];
    }
    if ($pay_ids) send_payment_receipt($pay_ids);
    return ['ok' => true, 'pid' => $pid, 'payment_ids' => $pay_ids, 'paid' => $paid,
            'msg' => 'Booking confirmed for ' . $p['last_name'] . ', ' . $p['first_name'] . ' — seat ' . $seat . '.'
                   . ($pay_ids ? ' Collected ' . peso($paid) . ' — ' . count($pay_ids) . ' OR(s).' : '')
                   . ' Check-in (baggage weigh-in) on ' . date('M d, Y', strtotime($v['departure_date'])) . '.'];
}

/**
 * Check in a Booked passenger. $want_seat: the seat the counter picked on the seat map ('' = keep the
 * booked seat, or the balanced seat when there is none). A picked seat must be free and of the passenger's class.
 */
function checkin_confirm_booked(int $pid, float $bag_kg, int $uid, string $email = '', ?array $pay = null, string $want_seat = ''): array {
    $db = db();
    if ($bag_kg < 0 || $bag_kg > 500) return ['ok' => false, 'msg' => 'Enter a valid baggage weight (0–500 kg).'];
    $email = trim($email);
    if ($email !== '' && !valid_email($email)) return ['ok' => false, 'msg' => 'Email address is not valid.'];

    $st = $db->prepare('SELECT voyage_id FROM passenger WHERE passenger_id=?');
    $st->bind_param('i', $pid); $st->execute();
    $vid = (int)($st->get_result()->fetch_row()[0] ?? 0);
    if (!$vid) return ['ok' => false, 'msg' => 'Passenger not found.'];

    $db->begin_transaction();
    try {
        $v = lock_voyage($vid);
        $st = $db->prepare('SELECT * FROM passenger WHERE passenger_id=? FOR UPDATE');
        $st->bind_param('i', $pid); $st->execute();
        $p = $st->get_result()->fetch_assoc();
        if (!$p || (int)$p['voyage_id'] !== $vid) { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger not found.']; }
        if ($p['status'] !== 'Booked') { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger is already ' . $p['status'] . '.']; }
        if ($r = voyage_closed_reason($v)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }
        // Early check-in (before the departure day) only once the fare and vehicle freight are already paid
        if (is_advance_voyage($v)) {
            $unpaid = array_filter(checkin_dues($pid, checkin_booking_vehicles($p, true)), fn($d) => $d['kind'] !== 'excess');
            if ($unpaid || ($p['online_pay_status'] === 'pending' && (float)$p['fare'] > 0 && !$p['fare_paid'])) {
                $db->rollback();
                return ['ok' => false, 'msg' => 'Early check-in (trip on ' . date('M d, Y', strtotime($v['departure_date']))
                    . ') needs the bills paid first — ' . ($unpaid ? implode('; ', array_map(fn($d) => $d['label'] . ' ' . peso($d['due']), $unpaid)) : 'online payment still pending') . '.'];
            }
        }
        // Seat is already counted (Booked) — only the weight is new.
        if ($r = capacity_error($vid, 0, PAX_AVG_WEIGHT_KG + $bag_kg)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }

        [$seat, $why] = booked_seat_pick($v, $p, $want_seat);
        if ($why) { $db->rollback(); return ['ok' => false, 'msg' => $why]; }
        $exp   = $p['ticket_valid_until'] ?: ticket_expiry($v);
        $ref   = $p['booking_ref'] ?: new_booking_ref();
        $new_email = ($email !== '' && empty($p['email'])) ? $email : $p['email'];

        // 1. Weigh the baggage (excess fee is computed by the database from this)
        save_baggage($pid, $v, $p['class'], $p['pax_category'], $bag_kg);

        // 2. No unpaid bills
        if ($p['online_pay_status'] === 'pending' && (float)$p['fare'] > 0 && !$p['fare_paid']) {
            $db->rollback();
            return ['ok' => false, 'msg' => 'The online payment (' . $p['online_pay_method'] . ' ref ' . $p['online_pay_ref'] . ') is still waiting for verification. Verify it first (Payments › Online Payments).'];
        }
        $vehicles = checkin_booking_vehicles($p, true);
        $dues = checkin_dues($pid, $vehicles);
        $pay_ids = []; $paid = 0.0;
        // Passenger account with auto-pay on: excess baggage and other fees come out of the wallet first
        foreach ($dues ? wallet_autopay_locked($dues, $uid) : [] as $r) { $pay_ids[] = $r['payment_id']; $paid += $r['amount']; }
        if ($pay_ids) $dues = checkin_dues($pid, $vehicles);
        if ($dues && $pay) {
            foreach ($dues as $d) {
                $r = collect_locked($d['kind'], $d['id'], (string)($pay['method'] ?? 'Cash'), $pay['ref'] ?? null, $uid);
                if (!$r['ok']) { $db->rollback(); return ['ok' => false, 'msg' => 'Not checked in — payment failed: ' . $r['msg']]; }
                $pay_ids[] = $r['payment_id']; $paid += $r['amount'];
            }
            $dues = checkin_dues($pid, $vehicles);
        }
        if ($dues) {
            $db->rollback();
            $total = array_sum(array_column($dues, 'due'));
            return ['ok' => false, 'msg' => 'Not checked in — unpaid ' . peso($total) . ': '
                . implode('; ', array_map(fn($d) => $d['label'] . ' ' . peso($d['due']), $dues)) . '. Collect the balance first.'];
        }

        // 3. Secure a load-zone slot for every vehicle of the booking
        $zones = null; $slots = [];
        foreach ($vehicles as $vh) {
            if ($vh['zone_id']) continue;
            $zones ??= voyage_zones($vid);
            if (!$zones) break;                         // vessel has no load zones set up — nothing to assign
            $zid = pick_zone($zones, 'vehicle', (float)$vh['kg']);
            if ($zid === null) { $db->rollback(); return ['ok' => false, 'msg' => 'Not checked in — no vehicle slot left for ' . $vh['plate_no'] . ' (' . $vh['type_name'] . ') on this voyage.']; }
            $st = $db->prepare('UPDATE vehicle SET zone_id=? WHERE vehicle_id=?');
            $vhid = (int)$vh['vehicle_id'];
            $st->bind_param('ii', $zid, $vhid); $st->execute();
            $zones = voyage_zones($vid);                // refresh counts for the next vehicle
            $slots[] = $vh['plate_no'];
        }

        $st = $db->prepare("UPDATE passenger SET status='Checked-in', checkin_time=NOW(), checkin_by=?, seat_no=?,
                               ticket_valid_until=?, booking_ref=?, email=? WHERE passenger_id=?");
        $st->bind_param('issssi', $uid, $seat, $exp, $ref, $new_email, $pid);
        $st->execute();
        audit('Booking checked in', 'passenger', $pid, ['status' => 'Booked'],
              ['status' => 'Checked-in', 'seat_no' => $seat, 'baggage_kg' => $bag_kg]
              + ($pay_ids ? ['collected' => $paid, 'receipts' => count($pay_ids)] : [])
              + ($slots ? ['vehicle_slot' => implode(', ', $slots)] : []));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Check-in failed: database error.'];
    }
    if ($pay_ids) send_payment_receipt($pay_ids);   // payment confirmation for what was collected at check-in
    // Checked in: e-mail a copy of the boarding pass as a backup in case the printed one is lost
    $mailed = false;
    if ($new_email && valid_email($new_email)) { try { $mailed = send_boarding_pass($pid)['ok']; } catch (Throwable $e) { $mailed = false; } }
    return ['ok' => true, 'pid' => $pid, 'booking_ref' => $ref, 'mailed' => $mailed, 'payment_ids' => $pay_ids, 'paid' => $paid,
            'msg' => 'Checked in ' . $p['last_name'] . ', ' . $p['first_name'] . ' — seat ' . $seat . '.'
                   . ($pay_ids ? ' Collected ' . peso($paid) . ' — ' . count($pay_ids) . ' OR(s).' : '')
                   . ($slots ? ' Vehicle slot secured: ' . implode(', ', $slots) . '.' : '')];
}

// ═════════════════════════════════════════════════════════════════════════
// Boarding
// ═════════════════════════════════════════════════════════════════════════

function board_passenger(int $pid, int $uid): array {
    $db = db();
    $db->begin_transaction();
    try {
        $st = $db->prepare('SELECT passenger_id FROM passenger WHERE passenger_id=? FOR UPDATE');
        $st->bind_param('i', $pid); $st->execute();
        if (!$st->get_result()->fetch_row()) { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger not found.']; }
        $p = pax_full_by('p.passenger_id', $pid);
        if ($r = board_block_reason($p)) { $db->rollback(); return ['ok' => false, 'msg' => $r]; }
        $st = $db->prepare("UPDATE passenger SET status='Boarded', boarded_at=NOW(), boarded_by=? WHERE passenger_id=? AND status='Checked-in'");
        $st->bind_param('ii', $uid, $pid); $st->execute();
        if ($st->affected_rows !== 1) { $db->rollback(); return ['ok' => false, 'msg' => 'Passenger could not be boarded.']; }
        audit('Passenger boarded', 'passenger', $pid, ['status' => 'Checked-in'], ['status' => 'Boarded']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Boarding failed: database error.'];
    }
    return ['ok' => true, 'msg' => pax_display_name($p) . ' is now BOARDED.'];
}

// ═════════════════════════════════════════════════════════════════════════
// Payments (one OR per item). Amounts ALWAYS come from the database.
//   kind: fare (passenger_id) | excess (passenger_id) | cargo (cargo_id) | vehicle (vehicle_id)
// ═════════════════════════════════════════════════════════════════════════

const PAY_KINDS = ['fare', 'excess', 'cargo', 'vehicle'];

/** Sum of valid (not voided) payments already made for an item. */
function paid_sum(string $kind, int $id): float {
    [$col, $types] = match ($kind) {
        'fare'    => ['passenger_id', "'Ticket Fare'"],
        'excess'  => ['passenger_id', "'Excess Baggage'"],
        'cargo'   => ['cargo_id', "'Cargo Freight','Hazardous Surcharge'"],
        'vehicle' => ['vehicle_id', "'Vehicle Freight'"],
    };
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payment WHERE $col=? AND is_voided=0 AND fee_type IN ($types)");
    $st->bind_param('i', $id); $st->execute();
    return (float)$st->get_result()->fetch_row()[0];
}

/**
 * Lock an item and work out what is due. Must run inside a transaction.
 * Returns ['error'=>?string, 'voyage_id', 'fee_type', 'due', 'label', 'col', 'id']
 */
function pay_item_lock(string $kind, int $id): array {
    $db = db();
    $out = ['error' => null, 'kind' => $kind, 'id' => $id];
    switch ($kind) {
        case 'fare':
            $st = $db->prepare("SELECT passenger_id, voyage_id, fare AS amount, fare_paid AS paid, status, online_pay_status,
                    CONCAT(last_name, ', ', first_name, ' (', ticket_no, ')') AS label FROM passenger WHERE passenger_id=? FOR UPDATE");
            $out += ['fee_type' => 'Ticket Fare', 'col' => 'passenger_id'];
            break;
        case 'excess':
            $st = $db->prepare("SELECT b.passenger_id, b.voyage_id, b.excess_fee AS amount, b.is_paid AS paid, p.status, 'none' AS online_pay_status,
                    CONCAT('Excess baggage ', b.excess_kg, ' kg — ', p.last_name, ', ', p.first_name) AS label
                    FROM baggage b JOIN passenger p ON p.passenger_id=b.passenger_id WHERE b.passenger_id=? FOR UPDATE");
            $out += ['fee_type' => 'Excess Baggage', 'col' => 'passenger_id'];
            break;
        case 'cargo':
            $st = $db->prepare("SELECT cargo_id, voyage_id, freight_amount AS amount, is_paid AS paid, 'ok' AS status, 'none' AS online_pay_status,
                    CONCAT('Waybill ', waybill_no, ' — ', shipper_name) AS label, cargo_type FROM cargo WHERE cargo_id=? FOR UPDATE");
            $out += ['fee_type' => 'Cargo Freight', 'col' => 'cargo_id'];
            break;
        case 'vehicle':
            $st = $db->prepare("SELECT vehicle_id, voyage_id, freight_amount AS amount, is_paid AS paid, 'ok' AS status, 'none' AS online_pay_status,
                    CONCAT('Vehicle ', plate_no, ' — ', owner_name) AS label FROM vehicle WHERE vehicle_id=? FOR UPDATE");
            $out += ['fee_type' => 'Vehicle Freight', 'col' => 'vehicle_id'];
            break;
        default:
            $out['error'] = 'Unknown item.'; return $out;
    }
    $st->bind_param('i', $id); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) { $out['error'] = 'Item not found.'; return $out; }
    $out['voyage_id'] = (int)$row['voyage_id'];
    $out['label']     = $row['label'];
    $out['amount']    = (float)$row['amount'];
    $out['paid_flag'] = (int)$row['paid'];
    $out['due']       = round(max(0, (float)$row['amount'] - paid_sum($kind, $id)), 2);
    if ($kind === 'fare' && !in_array($row['status'], SEAT_STATUSES, true)) $out['error'] = 'Passenger is ' . $row['status'] . '.';
    elseif ($kind === 'excess' && !in_array($row['status'], SEAT_STATUSES, true)) $out['error'] = 'Passenger is ' . $row['status'] . '.';
    elseif ($kind === 'fare' && $row['online_pay_status'] === 'pending') $out['error'] = 'An online payment for this ticket is waiting for verification. Verify or reject it first (Payments › Online Payments).';
    elseif ($out['paid_flag']) $out['error'] = 'Already paid.';
    elseif ($out['due'] <= 0) $out['error'] = 'Nothing to collect for this item.';
    return $out;
}

/**
 * Record a payment for a locked item (inside a transaction). Returns payment row info.
 * $allow_online_pending: used by verify_online.php for fares that are pending verification.
 */
function collect_locked(string $kind, int $id, string $method, ?string $ref, ?int $uid, bool $allow_online_pending = false): array {
    if (!in_array($method, ALL_PAY_METHODS, true)) return ['ok' => false, 'msg' => 'Choose a valid payment method (Cash, GCash, Maya, Bank Transfer or Wallet).'];
    $ref = $ref !== null ? trim($ref) : null;
    if ($ref === '' || $method === 'Wallet') $ref = null;   // a wallet payment's reference is its wallet transaction no.
    if (!in_array($method, ['Cash', 'Wallet'], true) && !$ref) return ['ok' => false, 'msg' => 'Reference number is required for ' . $method . ' payments.'];
    if ($ref !== null && mb_strlen($ref) > 100) return ['ok' => false, 'msg' => 'Reference number is too long.'];
    $it = pay_item_lock($kind, $id);
    if ($it['error'] && !($allow_online_pending && str_starts_with($it['error'], 'An online payment'))) {
        return ['ok' => false, 'msg' => ($it['label'] ?? 'Item') . ': ' . $it['error']];
    }
    if ($it['due'] <= 0) return ['ok' => false, 'msg' => 'Nothing to collect.'];
    $aid = pay_item_account($kind, $id);
    $wt  = null;
    if ($method === 'Wallet') {   // take the credits first — a short balance stops the payment
        if (!$aid) return ['ok' => false, 'msg' => $it['label'] . ': not linked to a passenger account with wallet credits.'];
        try {
            $wt = wallet_post_locked($aid, 'Payment', -$it['due'], ['user_id' => $uid, 'note' => $it['fee_type'] . ' — ' . $it['label'],
                'passenger_id' => in_array($kind, ['fare', 'excess'], true) ? $id : null, 'voyage_id' => $it['voyage_id'], 'fee_type' => $it['fee_type']]);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'msg' => $it['label'] . ': ' . $e->getMessage()];
        }
        $ref = $wt['txn_no'];
    }
    $or  = next_number('OR');
    $pax = in_array($kind, ['fare', 'excess'], true) ? $id : null;
    $cg  = $kind === 'cargo' ? $id : null;
    $vh  = $kind === 'vehicle' ? $id : null;
    $st  = db()->prepare('INSERT INTO payment (or_no, passenger_id, cargo_id, vehicle_id, cashier_id, voyage_id, fee_type, amount, pay_method, reference_no)
                          VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('siiiiisdss', $or, $pax, $cg, $vh, $uid, $it['voyage_id'], $it['fee_type'], $it['due'], $method, $ref);
    $st->execute();
    $pay_id = (int)db()->insert_id;
    set_paid_flag($kind, $id, 1);
    audit('Payment collected ' . $or, 'payment', $pay_id, [],
          ['fee_type' => $it['fee_type'], 'amount' => $it['due'], 'method' => $method, 'reference_no' => $ref, 'item' => $it['label']]);
    $bref = null;
    if ($pax) { $st = db()->prepare('SELECT booking_ref FROM passenger WHERE passenger_id=?'); $st->bind_param('i', $pax); $st->execute(); $bref = $st->get_result()->fetch_row()[0] ?? null; }
    ledger_post('Payment', $it['due'], ['method' => $method, 'fee_type' => $it['fee_type'], 'reference_no' => $or, 'account_id' => $aid,
        'passenger_id' => $pax, 'payment_id' => $pay_id, 'voyage_id' => $it['voyage_id'], 'booking_ref' => $bref,
        'description' => $it['label'] . ($wt ? ' — paid from wallet ' . $wt['txn_no'] : ''), 'user_id' => $uid]);
    if ($wt) wallet_txn_set_payment($wt['txn_id'], $pay_id);
    if ($aid && $kind === 'fare') points_award_locked($aid, $pay_id, $it['due'], $or);   // reward points for fares
    return ['ok' => true, 'payment_id' => $pay_id, 'or_no' => $or, 'amount' => $it['due'], 'label' => $it['label']];
}

function set_paid_flag(string $kind, int $id, int $flag): void {
    $sql = match ($kind) {
        'fare'    => 'UPDATE passenger SET fare_paid=? WHERE passenger_id=?',
        'excess'  => 'UPDATE baggage SET is_paid=? WHERE passenger_id=?',
        'cargo'   => 'UPDATE cargo SET is_paid=? WHERE cargo_id=?',
        'vehicle' => 'UPDATE vehicle SET is_paid=? WHERE vehicle_id=?',
    };
    $st = db()->prepare($sql);
    $st->bind_param('ii', $flag, $id); $st->execute();
}

/** Collect one item in its own transaction. */
function collect_item(string $kind, int $id, string $method, ?string $ref, int $uid): array {
    if (!in_array($kind, PAY_KINDS, true)) return ['ok' => false, 'msg' => 'Unknown item.'];
    $db = db();
    $db->begin_transaction();
    try {
        $r = collect_locked($kind, $id, $method, $ref, $uid);
        if (!$r['ok']) { $db->rollback(); return $r; }
        $db->commit();
        send_payment_receipt([$r['payment_id']]);
        return $r;
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Payment failed: database error.'];
    }
}

/** Collect several items (e.g. all fares of a booking) in ONE transaction — one OR each. */
function collect_many(array $items, string $method, ?string $ref, int $uid): array {
    $db = db();
    $db->begin_transaction();
    try {
        $ids = [];
        foreach ($items as [$kind, $id]) {
            $r = collect_locked($kind, (int)$id, $method, $ref, $uid);
            if (!$r['ok']) { $db->rollback(); return $r; }
            $ids[] = $r['payment_id'];
        }
        if (!$ids) { $db->rollback(); return ['ok' => false, 'msg' => 'Nothing to collect.']; }
        $db->commit();
        send_payment_receipt($ids);
        return ['ok' => true, 'payment_ids' => $ids];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Payment failed: database error.'];
    }
}

/** Void a payment (Admin). Resets the paid flag when nothing valid covers the item anymore. */
function void_payment(int $payment_id, string $reason, int $uid): array {
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'msg' => 'A reason is required to void a receipt.'];
    $db = db();
    $db->begin_transaction();
    try {
        $st = $db->prepare('SELECT * FROM payment WHERE payment_id=? FOR UPDATE');
        $st->bind_param('i', $payment_id); $st->execute();
        $pay = $st->get_result()->fetch_assoc();
        if (!$pay) { $db->rollback(); return ['ok' => false, 'msg' => 'Receipt not found.']; }
        if ($pay['is_voided']) { $db->rollback(); return ['ok' => false, 'msg' => 'Receipt ' . $pay['or_no'] . ' is already voided.']; }
        $st = $db->prepare('UPDATE payment SET is_voided=1, voided_by=?, void_reason=? WHERE payment_id=?');
        $st->bind_param('isi', $uid, $reason, $payment_id); $st->execute();

        [$kind, $id] = match ($pay['fee_type']) {
            'Ticket Fare'     => ['fare', (int)$pay['passenger_id']],
            'Excess Baggage'  => ['excess', (int)$pay['passenger_id']],
            'Cargo Freight', 'Hazardous Surcharge' => ['cargo', (int)$pay['cargo_id']],
            'Vehicle Freight' => ['vehicle', (int)$pay['vehicle_id']],
            default           => [null, 0],
        };
        if ($kind && $id) {
            $remaining = paid_sum($kind, $id);   // valid payments left (this one is voided now)
            if ($remaining <= 0) set_paid_flag($kind, $id, 0);
            elseif ($kind === 'excess') {
                $st = $db->prepare('SELECT excess_fee FROM baggage WHERE passenger_id=?');
                $st->bind_param('i', $id); $st->execute();
                if ($remaining + 0.001 < (float)($st->get_result()->fetch_row()[0] ?? 0)) set_paid_flag('excess', $id, 0);
            }
        }
        // Ledger, wallet refund (credits go back to the account) and the points earned with this receipt
        $st = $db->prepare('SELECT booking_ref FROM passenger WHERE passenger_id=?');
        $ppid = (int)$pay['passenger_id']; $st->bind_param('i', $ppid); $st->execute();
        $bref = $st->get_result()->fetch_row()[0] ?? null;
        ledger_post('Payment Void', -(float)$pay['amount'], ['method' => $pay['pay_method'], 'fee_type' => $pay['fee_type'], 'reference_no' => $pay['or_no'],
            'passenger_id' => $pay['passenger_id'], 'payment_id' => $payment_id, 'voyage_id' => $pay['voyage_id'], 'booking_ref' => $bref,
            'description' => 'Voided: ' . $reason, 'user_id' => $uid]);
        if ($pay['pay_method'] === 'Wallet') {
            $st = $db->prepare("SELECT account_id FROM wallet_txn WHERE payment_id=? AND txn_type='Payment' LIMIT 1");
            $st->bind_param('i', $payment_id); $st->execute();
            if ($waid = (int)($st->get_result()->fetch_row()[0] ?? 0))
                wallet_post_locked($waid, 'Refund', (float)$pay['amount'], ['payment_id' => $payment_id, 'user_id' => $uid, 'booking_ref' => $bref,
                    'passenger_id' => $pay['passenger_id'], 'voyage_id' => $pay['voyage_id'], 'fee_type' => $pay['fee_type'],
                    'note' => 'Refund of ' . $pay['or_no'] . ': ' . $reason]);
        }
        points_reverse_locked($payment_id, $pay['or_no']);
        audit('Payment voided ' . $pay['or_no'], 'payment', $payment_id,
              ['amount' => $pay['amount'], 'fee_type' => $pay['fee_type']], ['is_voided' => 1, 'reason' => $reason]);
        $db->commit();
        return ['ok' => true, 'msg' => 'Receipt ' . $pay['or_no'] . ' voided.'];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => 'Void failed: database error.'];
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Small UI helpers
// ═════════════════════════════════════════════════════════════════════════

/** Voyage header card (info, seats left, load bar). */
function voyage_header_card(array $L): string {
    $v = $L['voyage'];
    $dep = date('D, M d Y · g:i A', strtotime($v['departure_date'] . ' ' . $v['departure_time']));
    $badge = $v['under_maintenance'] ? maintenance_badge() : status_badge($v['manifest_status']);
    $lvl = level_color($L['level']);
    return '<div class="card card-manload mb-3"><div class="card-body py-2">'
        . '<div class="row g-2 align-items-center">'
        . '<div class="col-md-5"><div class="fw-bold">' . e($v['vessel_name']) . ' ' . $badge . '</div>'
        . '<div class="small text-muted">' . e($v['orig_name']) . ' <i class="bi bi-arrow-right"></i> ' . e($v['dest_name'])
        . ' · ' . e($dep) . ' · ' . e($v['voyage_no']) . '</div></div>'
        . '<div class="col-6 col-md-2 text-center"><div class="fs-5 fw-bold text-' . ($L['seats_left'] > 0 ? 'success' : 'danger') . '">' . (int)$L['seats_left'] . '</div>'
        . '<div class="small text-muted">seats left of ' . (int)$L['max_pax'] . '</div></div>'
        . '<div class="col-6 col-md-2 text-center"><div class="fs-5 fw-bold">' . (int)$L['onboard'] . '</div>'
        . '<div class="small text-muted">checked-in / boarded</div></div>'
        . '<div class="col-md-3"><div class="d-flex justify-content-between small"><span>Load</span><span class="fw-semibold text-' . $lvl . '">'
        . e(kg($L['total_kg'])) . ' / ' . e(kg($L['max_kg'])) . ' (' . e($L['load_pct']) . '%)</span></div>'
        . progress_bar((float)$L['load_pct'], 'Load ' . $L['load_pct'] . '%') . '</div>'
        . '</div></div></div>';
}

/** Voyage picker: carousel of departure dates; clicking a date drops down that day's voyages (picking one submits). */
function voyage_select_form(int $vid, array $options, string $extra_hidden = ''): string {
    if ($vid && !array_filter($options, fn($o) => (int)$o['voyage_id'] === $vid) && ($cur = get_voyage($vid))) $options[] = $cur;
    $days = []; $sel = null;
    foreach ($options as $o) {
        $days[$o['departure_date']][] = $o;
        if ((int)$o['voyage_id'] === $vid) $sel = $o;
    }
    ksort($days);
    $today = date('Y-m-d'); $tmrw = date('Y-m-d', strtotime('+1 day'));
    $h = '<form method="get" class="vcar mb-3">' . $extra_hidden
       . '<div class="vcar-head"><i class="bi bi-water me-1"></i>Voyage'
       . ($sel ? ' <span class="vcar-sel">' . e(voyage_label($sel) . ' · ' . $sel['manifest_status']) . '</span>' : ' <span class="text-muted">— pick a date</span>') . '</div>'
       . '<button type="button" class="vcar-nav vcar-prev" aria-label="Earlier dates"><i class="bi bi-chevron-left"></i></button>'
       . '<div class="vcar-track">';
    if (!$days) $h .= '<div class="vcar-empty">No upcoming voyages</div>';
    $menus = '';
    foreach ($days as $d => $list) {
        $on  = $sel && $sel['departure_date'] === $d;
        $tag = $d === $today ? 'Today' : ($d === $tmrw ? 'Tomorrow' : date('D', strtotime($d)));
        $n   = count($list);
        $h .= '<button type="button" class="vcar-day' . ($on ? ' active' : '') . '" data-day="' . e($d) . '" aria-expanded="false">'
            . '<span class="vcar-dow">' . e($tag) . '</span>'
            . '<span class="vcar-dnum">' . e(date('M j', strtotime($d))) . '</span>'
            . '<span class="vcar-cnt">' . $n . ' trip' . ($n > 1 ? 's' : '') . ' <i class="bi bi-caret-down-fill"></i></span></button>';
        $menus .= '<div class="vcar-menu" data-day="' . e($d) . '" hidden><div class="vcar-menu-head">' . e(date('l, M j, Y', strtotime($d))) . '</div>';
        foreach ($list as $o) {
            $st = $o['manifest_status'];
            $menus .= '<button type="submit" name="vid" value="' . (int)$o['voyage_id'] . '" class="vcar-opt' . ((int)$o['voyage_id'] === $vid ? ' active' : '') . '">'
                . '<span class="vcar-time">' . e(date('g:i A', strtotime($o['departure_time']))) . '</span>'
                . '<span class="vcar-info"><b>' . e($o['orig'] . ' → ' . $o['dest']) . '</b><br>' . e($o['vessel_name']) . '</span>'
                . '<span class="vcar-st vcar-st-' . e(strtolower(str_replace(' ', '-', $st))) . '">' . e($st) . '</span></button>';
        }
        $menus .= '</div>';
    }
    $h .= '</div><button type="button" class="vcar-nav vcar-next" aria-label="Later dates"><i class="bi bi-chevron-right"></i></button>' . $menus . '</form>';
    static $js = false;
    if (!$js) {
        $js = true;
        $h .= "<script>document.querySelectorAll('.vcar').forEach(f => {
            const t = f.querySelector('.vcar-track'), a = t.querySelector('.vcar-day.active');
            if (a) t.scrollLeft = a.offsetLeft - t.offsetLeft - (t.clientWidth - a.offsetWidth) / 2;
            const close = () => {
                f.querySelectorAll('.vcar-menu').forEach(m => m.hidden = true);
                f.querySelectorAll('.vcar-day').forEach(b => { b.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); });
            };
            f.querySelectorAll('.vcar-day').forEach(b => b.addEventListener('click', e => {
                e.stopPropagation();
                const m = f.querySelector('.vcar-menu[data-day=\"' + b.dataset.day + '\"]'), was = !m.hidden;
                close(); if (was) return;
                m.hidden = false; b.classList.add('open'); b.setAttribute('aria-expanded', 'true');
                const fr = f.getBoundingClientRect(), br = b.getBoundingClientRect();
                m.style.top  = (br.bottom - fr.top + 4) + 'px';
                m.style.left = Math.max(0, Math.min(br.left - fr.left, fr.width - m.offsetWidth)) + 'px';
            }));
            document.addEventListener('click', e => { if (!e.target.closest('.vcar-menu')) close(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
            t.addEventListener('scroll', close);
            f.querySelector('.vcar-prev').onclick = () => t.scrollBy({left: -t.clientWidth * .8, behavior: 'smooth'});
            f.querySelector('.vcar-next').onclick = () => t.scrollBy({left:  t.clientWidth * .8, behavior: 'smooth'});
        });</script>";
    }
    return $h;
}

// ═════════════════════════════════════════════════════════════════════════
// E-mail a copy of the boarding pass (backup in case the printed one is lost)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Send the passenger's boarding pass to their e-mail:
 *   - the pass in the e-mail body, with the QR code as an inline picture
 *   - the same pass as a PNG picture attachment (save it to the phone's gallery)
 * Only for passengers who are Checked-in or Boarded.
 * $to: send to this address instead of the saved one (optional).
 * Returns ['ok'=>bool, 'sent'=>bool (false = only saved to storage/mail_log), 'to'=>string, 'msg'=>string]
 */
function send_boarding_pass(int $pid, string $to = ''): array {
    require_once __DIR__ . '/boarding_pass_image.php';
    $p = pax_full_by('p.passenger_id', $pid);
    if (!$p) return ['ok' => false, 'sent' => false, 'to' => '', 'msg' => 'Passenger not found.'];
    $to = trim($to) !== '' ? trim($to) : (string)$p['email'];
    if (!valid_email($to)) return ['ok' => false, 'sent' => false, 'to' => '', 'msg' => 'No valid e-mail address for this passenger.'];
    if (!in_array($p['status'], ['Checked-in', 'Boarded'], true))
        return ['ok' => false, 'sent' => false, 'to' => $to, 'msg' => 'The boarding pass is issued after check-in (passenger is ' . $p['status'] . ').'];

    $qr_text = boarding_pass_qr_data($p);
    $qr_png  = qr_png($qr_text, 5);
    $pass    = boarding_pass_png($p, $qr_text);
    $dep     = strtotime($p['departure_date'] . ' ' . $p['departure_time']);
    $valid   = $p['ticket_valid_until'] ? date('M d, Y g:i A', strtotime($p['ticket_valid_until'])) : '—';
    $paytxt  = (float)$p['fare'] <= 0 ? 'FREE' : ($p['fare_paid'] ? 'PAID' : ($p['online_pay_status'] === 'pending' ? 'Online payment — verifying' : 'UNPAID'));
    $cid     = 'qr-' . preg_replace('/[^A-Za-z0-9-]/', '', $p['ticket_no']) . '@manload';

    $cell = fn(string $l, string $v) => '<td style="padding:6px 8px 6px 0;vertical-align:top;width:33%;">'
        . '<div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">' . e($l) . '</div>'
        . '<div style="font-size:14px;font-weight:700;color:#0f172a;">' . e($v) . '</div></td>';

    $content = '<p style="font-size:16px;margin:0 0 6px;"><strong>Thank you for booking with MANLOAD!</strong></p>'
        . '<p style="margin:0 0 16px;">Hi ' . e($p['first_name']) . ', you are checked in. Here is a <strong>copy of your boarding pass</strong>. '
        . 'Keep this e-mail as a backup — if your printed ticket gets lost, show the QR code below and a valid ID at the gangway.</p>'

        . '<table width="100%" cellpadding="0" cellspacing="0" style="border:2px dashed #cbd5e1;border-radius:10px;">'
        . '<tr><td style="background:#0f2d6b;color:#fff;padding:12px 16px;border-radius:8px 8px 0 0;">'
        .   '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        .   '<td style="color:#fff;"><div style="font-size:18px;font-weight:900;letter-spacing:2px;">BOARDING PASS</div>'
        .   '<div style="font-size:11px;opacity:.85;">PASSENGER\'S COPY · E-MAIL BACKUP</div></td>'
        .   '<td align="right" style="color:#fff;"><div style="font-size:10px;opacity:.85;">TICKET NO.</div>'
        .   '<div style="font-size:16px;font-weight:700;font-family:Consolas,monospace;">' . e($p['ticket_no']) . '</div></td>'
        .   '</tr></table></td></tr>'
        . '<tr><td style="padding:14px 16px;">'
        .   '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        .   '<td style="vertical-align:top;"><div style="font-size:30px;font-weight:900;color:#0f172a;line-height:1.1;">' . e($p['orig']) . ' &rarr; ' . e($p['dest']) . '</div>'
        .   '<div style="font-size:12px;color:#64748b;">' . e($p['orig_name']) . ' → ' . e($p['dest_name']) . '</div></td>'
        .   '<td align="right" style="vertical-align:top;"><div style="font-size:10px;color:#64748b;">SEAT</div>'
        .   '<div style="font-size:30px;font-weight:900;color:#0f172a;line-height:1.1;">' . e($p['seat_no'] ?: '—') . '</div></td>'
        .   '</tr></table>'
        .   '<div style="font-size:17px;font-weight:700;color:#1d4ed8;margin:10px 0 6px;">' . e(pax_display_name($p)) . '</div>'
        .   '<table width="100%" cellpadding="0" cellspacing="0">'
        .   '<tr>' . $cell('Vessel', $p['vessel_name']) . $cell('Date', date('D, M d Y', $dep)) . $cell('Departure', date('g:i A', $dep)) . '</tr>'
        .   '<tr>' . $cell('Class', $p['class']) . $cell('Category', $p['pax_category']) . $cell('Fare', peso($p['fare']) . ' · ' . $paytxt) . '</tr>'
        .   '<tr>' . $cell('Baggage', !empty($p['baggage_id']) ? kg($p['actual_weight'], 1) . ' / ' . kg($p['allowance_kg']) : '—')
        .            $cell('Bag tag', $p['tag_no'] ?: '—') . $cell('Booking ref', $p['booking_ref'] ?: '—') . '</tr>'
        .   '</table>'
        .   ($qr_png
              ? '<div style="text-align:center;margin-top:12px;"><img src="cid:' . e($cid) . '" width="260" height="260" alt="Boarding pass QR code" style="display:inline-block;border:1px solid #e2e8f0;">'
                . '<div style="font-size:12px;color:#64748b;">QR valid until <strong style="color:#0f172a;">' . e($valid) . '</strong></div></div>'
              : '<p style="text-align:center;">Ticket no. <strong>' . e($p['ticket_no']) . '</strong> — valid until ' . e($valid) . '</p>')
        . '</td></tr></table>'

        . '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;font-size:12px;color:#78350f;margin-top:16px;">'
        . 'Boarding closes 15 minutes before departure. This ticket and its QR code expire on <strong>' . e($valid) . '</strong>.'
        . ($pass ? ' The boarding pass is also attached as a picture — save it to your phone so you can show it even without internet.' : '')
        . '</div>'
        . ($p['booking_ref'] ? '<p style="text-align:center;margin:20px 0 0;"><a href="' . e(booking_link($p['booking_ref'])) . '" style="color:#1d4ed8;">View my booking online</a></p>' : '');

    $inline = $qr_png ? [$cid => ['data' => $qr_png, 'type' => 'image/png', 'name' => 'qr-' . $p['ticket_no'] . '.png']] : [];
    $attach = $pass ? [['name' => 'Boarding-Pass-' . $p['ticket_no'] . '.png', 'type' => 'image/png', 'data' => $pass]] : [];

    $sent = send_mail($to, 'Your boarding pass — ' . $p['ticket_no'] . ' (' . $p['orig'] . '→' . $p['dest'] . ', ' . date('M d', $dep) . ')',
                      mail_layout('BOARDING PASS — BACKUP COPY', $content), $inline, $attach);
    audit('Boarding pass e-mailed', 'passenger', $pid, [], ['to' => $to, 'sent' => $sent]);
    return ['ok' => true, 'sent' => $sent, 'to' => $to,
            'msg' => $sent ? 'Boarding pass sent to ' . $to . '.' : 'Boarding pass saved to storage/mail_log (SMTP is off) for ' . $to . '.'];
}
