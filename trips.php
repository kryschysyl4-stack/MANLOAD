<?php
/**
 * Trip (voyage) creation shared by the Admin › Voyages pages (Create Direct / Link Trips).
 * Same rules as Setup › Voyages & Schedule: future date, active vessel + route, vessel not under
 * maintenance, a vessel cannot have two trips at the same date and time.
 * Requires config/auth.php.
 */

/** Default voyage number, e.g. SUG-DPA-20261009-0600-1 */
function trip_default_no(string $route_code, string $date, string $time, int $vessel_id): string {
    return $route_code . '-' . str_replace('-', '', $date) . '-' . str_replace(':', '', substr($time, 0, 5)) . '-' . $vessel_id;
}

/** Create one trip. Returns ['ok'=>bool, 'msg'=>string, 'id'=>int]. */
function trip_create(int $vessel_id, int $route_id, string $date, string $time, int $uid, ?string $public_remarks = null): array {
    $db = db();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return ['ok' => false, 'msg' => 'Invalid date.', 'id' => 0];
    if (!preg_match('/^\d{2}:\d{2}$/', substr($time, 0, 5))) return ['ok' => false, 'msg' => 'Invalid time.', 'id' => 0];
    $time = substr($time, 0, 5) . ':00';
    if ($date < date('Y-m-d')) return ['ok' => false, 'msg' => date('M j', strtotime($date)) . ' is in the past.', 'id' => 0];
    $st = $db->prepare('SELECT * FROM vessel WHERE vessel_id=?'); $st->bind_param('i', $vessel_id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    $st = $db->prepare('SELECT * FROM route WHERE route_id=?'); $st->bind_param('i', $route_id); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$v || !$v['is_active']) return ['ok' => false, 'msg' => 'Choose an active vessel.', 'id' => 0];
    if (!$r || !$r['is_active']) return ['ok' => false, 'msg' => 'Choose an active route.', 'id' => 0];
    $when = date('M j g:i A', strtotime("$date $time"));
    if (vessel_under_maintenance($v, $date)) return ['ok' => false, 'msg' => "{$v['vessel_name']} is under maintenance on " . date('M j', strtotime($date)) . '.', 'id' => 0];
    $st = $db->prepare('SELECT voyage_no FROM voyage WHERE vessel_id=? AND departure_date=? AND departure_time=?');
    $st->bind_param('iss', $vessel_id, $date, $time); $st->execute();
    if ($dup = $st->get_result()->fetch_row()) return ['ok' => false, 'msg' => "{$v['vessel_name']} already has a trip on $when ({$dup[0]}).", 'id' => 0];
    $vno = trip_default_no($r['route_code'], $date, $time, $vessel_id);
    $pr  = $public_remarks !== null && trim($public_remarks) !== '' ? mb_substr(trim($public_remarks), 0, 255) : null;
    $st = $db->prepare('INSERT INTO voyage (voyage_no, vessel_id, route_id, departure_date, departure_time, created_by, public_remarks) VALUES (?,?,?,?,?,?,?)');
    $st->bind_param('siissis', $vno, $vessel_id, $route_id, $date, $time, $uid, $pr);
    $st->execute();
    $id = (int)$db->insert_id;
    audit('Create voyage', 'voyage', $id, [], ['voyage_no' => $vno, 'vessel_id' => $vessel_id, 'route_id' => $route_id, 'date' => $date, 'time' => $time]);
    return ['ok' => true, 'msg' => "$vno ($when)", 'id' => $id];
}

/** Dates from $from to $to (inclusive) whose weekday (1=Mon … 7=Sun) is in $days. Max 120 days. */
function trip_dates(string $from, string $to, array $days): array {
    $out = [];
    if (!strtotime($from) || !strtotime($to) || $to < $from) return $out;
    for ($d = $from, $i = 0; $d <= $to && $i < 120; $d = date('Y-m-d', strtotime("$d +1 day")), $i++)
        if (!$days || in_array((int)date('N', strtotime($d)), $days, true)) $out[] = $d;
    return $out;
}
