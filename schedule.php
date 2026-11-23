<?php
/**
 * Schedule templates (table schedule_template) for Admin › Voyages › Create Direct Trips:
 * a template = vessel + route + departure time + the weekdays it runs (run_days "1,…,7", 1 = Mon).
 * Trips are always created through trip_create() (includes/trips.php), so the same checks apply.
 * Requires config/auth.php and includes/trips.php.
 */

const SCHED_WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

/**
 * Templates (optionally of one route), with vessel, route, ports and travel time.
 * $kind: 'direct' = single templates, 'link' = templates that belong to a link pair, 'all'.
 */
function sched_templates(int $route_id = 0, bool $active_only = false, string $kind = 'direct'): array {
    $sql = "SELECT t.*, s.vessel_name, s.vessel_type, s.is_active AS vessel_active, r.route_code, r.est_travel_hours,
                   po.port_name AS orig_name, pd.port_name AS dest_name
        FROM schedule_template t JOIN vessel s ON s.vessel_id=t.vessel_id JOIN route r ON r.route_id=t.route_id
        JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        WHERE (? = 0 OR t.route_id = ?)" . ($active_only ? ' AND t.is_active = 1' : '')
        . ($kind === 'direct' ? ' AND t.link_group IS NULL' : ($kind === 'link' ? ' AND t.link_group IS NOT NULL' : '')) . "
        ORDER BY po.port_name, pd.port_name, t.departure_time, s.vessel_name";
    $st = db()->prepare($sql);
    $st->bind_param('ii', $route_id, $route_id); $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Weekday numbers from a form: [1..7] in order; empty → []. */
function sched_days_in($raw): array {
    $d = array_values(array_unique(array_intersect(array_map('intval', (array)$raw), array_keys(SCHED_WEEKDAYS))));
    sort($d);
    return $d;
}

/** "Daily", "Mon–Fri", "Weekends" or "Mon, Wed, Fri". */
function sched_days_label(string $run_days): string {
    $d = sched_days_in(explode(',', $run_days));
    if (count($d) === 7) return 'Daily';
    if ($d === [1, 2, 3, 4, 5]) return 'Mon–Fri';
    if ($d === [6, 7]) return 'Weekends';
    return $d ? implode(', ', array_map(fn($n) => SCHED_WEEKDAYS[$n], $d)) : '—';
}

function sched_runs_on(array $t, string $date): bool {
    return in_array(date('N', strtotime($date)), explode(',', (string)$t['run_days']), true);
}

/** "1 h 30 min" from est_travel_hours. */
function sched_duration(float $hours): array {
    $m = (int)round($hours * 60);
    return [intdiv($m, 60), $m % 60];
}

/**
 * Create the trips of these templates on these dates (only on each template's run days).
 * Returns ['made' => [msg…], 'skip' => [msg…]].
 */
function sched_create_trips(array $templates, array $dates, int $uid, ?string $remark = null): array {
    $made = []; $skip = [];
    foreach ($dates as $d) {
        foreach ($templates as $t) {
            if (!$t['is_active'] || !sched_runs_on($t, $d)) continue;
            $r = trip_create((int)$t['vessel_id'], (int)$t['route_id'], $d, substr($t['departure_time'], 0, 5), $uid, $remark);
            if ($r['ok']) $made[] = $r['msg']; else $skip[] = $r['msg'];
        }
    }
    return ['made' => $made, 'skip' => $skip];
}

/** Existing trips for these templates' vessel/route/time between two dates: ["vessel|route|date|HH:MM:SS" => voyage row]. */
function sched_existing(array $templates, string $from, string $to): array {
    if (!$templates) return [];
    $routes = implode(',', array_unique(array_map(fn($t) => (int)$t['route_id'], $templates)));
    $st = db()->prepare("SELECT voyage_id, vessel_id, route_id, departure_date, departure_time, manifest_status FROM voyage
        WHERE route_id IN ($routes) AND departure_date BETWEEN ? AND ? AND archived = 0");
    $st->bind_param('ss', $from, $to); $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $v)
        $out[$v['vessel_id'] . '|' . $v['route_id'] . '|' . $v['departure_date'] . '|' . $v['departure_time']] = $v;
    return $out;
}

/**
 * Link schedules (outbound + return template on the same vessel), optionally of one outbound route.
 * Returns [['out' => template, 'ret' => template|null], …] — the outbound is the template whose id = link_group.
 */
function sched_links(int $route_id = 0, bool $active_only = false): array {
    $all = sched_templates(0, $active_only, 'link');
    $by_group = [];
    foreach ($all as $t) $by_group[(int)$t['link_group']][(int)$t['template_id'] === (int)$t['link_group'] ? 'out' : 'ret'] = $t;
    $out = [];
    foreach ($by_group as $g) {
        if (empty($g['out']) || ($route_id && (int)$g['out']['route_id'] !== $route_id)) continue;
        $out[] = ['out' => $g['out'], 'ret' => $g['ret'] ?? null];
    }
    usort($out, fn($a, $b) => [$a['out']['orig_name'], $a['out']['departure_time']] <=> [$b['out']['orig_name'], $b['out']['departure_time']]);
    return $out;
}
