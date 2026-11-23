<?php
/**
 * Staff notifications (the bell at the bottom-right of every staff page).
 *   - check-in staff : online bookings waiting for check-in
 *   - cashiers       : online payments waiting for verification
 *   - Coast Guard    : manifests locked by port staff, waiting for inspection;
 *                      voyages the Admin marked Departed
 *   - Admin          : vessels the Coast Guard cleared READY TO BOARD, to mark Departed
 *   - Admin / Passage / Coast Guard: trips of today and tomorrow that are nearly full or full (seats, LOAD_WARN_PCT / LOAD_CRITICAL_PCT)
 * Used by includes/header.php (first load) and api/notifications.php (refresh every 20 s).
 * Every notification lasts 1 day (NOTIF_MAX_AGE) from the moment it happened, then leaves the bell by itself;
 * the pages themselves (check-in, online payments, inspection …) still list everything still to do.
 */
require_once __DIR__ . '/../config/auth.php';

const NOTIF_LIST_LIMIT = 100;   // items listed per group (the count is always the real total)
const NOTIF_MAX_AGE    = 'INTERVAL 1 DAY';   // SQL: how long a notification stays in the bell

// Voyages the Coast Guard must inspect: manifest locked, not yet cleared or departed.
// Same rule for the bell, the Inspection tab badge and "Needs your verification" (modules/coastguard/index.php).
const CG_WAIT_WHERE = "v.archived = 0 AND v.manifest_status IN ('Locked','Submitted') AND v.ready_to_board = 0
                       AND v.departed_at IS NULL AND v.departure_date >= CURDATE() - INTERVAL 2 DAY";

/** $bell = false: the Admin Notifications page — cleared voyages stay listed until they are marked Departed. */
function staff_notifications(bool $bell = true): array {
    $db  = db();
    $out = ['total' => 0, 'checkin' => null, 'payments' => null, 'coastguard' => null, 'departed' => null, 'cleared' => null, 'capacity' => null, 'time' => date('c')];

    if (is_role(...ROLE_CHECKIN)) {
        $where = "FROM passenger p
             JOIN voyage v   ON v.voyage_id  = p.voyage_id
             JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
             JOIN route r    ON r.route_id   = v.route_id
             JOIN port po    ON po.port_id   = r.origin_port_id
             JOIN port pd    ON pd.port_id   = r.destination_port_id
             WHERE v.manifest_status IN ('Open','In Progress') AND p.status = 'Booked'
               AND v.archived = 0 AND v.departure_date >= CURDATE() AND p.booked_at >= NOW() - " . NOTIF_MAX_AGE;
        $count = (int)$db->query("SELECT COUNT(*) $where")->fetch_row()[0];
        $items = $db->query(
            "SELECT p.passenger_id, p.last_name, p.first_name, p.ticket_no, p.booking_ref, p.booked_via, p.booked_at,
                    p.pax_category, p.class, p.seat_no, v.voyage_id, vsl.vessel_name,
                    po.port_code AS orig, pd.port_code AS dest, v.departure_date, v.departure_time
             $where
             ORDER BY p.booked_at DESC, p.passenger_id DESC
             LIMIT " . NOTIF_LIST_LIMIT
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($items as &$i) {
            $i['ago'] = notif_ago($i['booked_at']);
            $i['dep_text'] = date('M d · g:i A', strtotime($i['departure_date'] . ' ' . $i['departure_time']));
        }
        unset($i);
        $out['checkin'] = ['count' => $count, 'items' => $items];
        $out['total'] += $count;
    }

    if (is_role(...ROLE_PAYMENTS)) {
        $rows = $db->query(
            "SELECT p.booking_ref, MIN(p.last_name) AS last_name, MIN(p.first_name) AS first_name,
                    COUNT(*) AS pax, SUM(p.fare) AS amount, MIN(p.online_pay_method) AS method,
                    MAX(p.booked_at) AS at
             FROM passenger p JOIN voyage v ON v.voyage_id = p.voyage_id
             WHERE p.online_pay_status = 'pending' AND v.archived = 0
             GROUP BY p.booking_ref HAVING at >= NOW() - " . NOTIF_MAX_AGE . " ORDER BY at DESC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) { $r['amount_text'] = peso($r['amount']); $r['ago'] = notif_ago($r['at']); }
        unset($r);
        $out['payments'] = ['count' => count($rows), 'items' => array_slice($rows, 0, NOTIF_LIST_LIMIT)];
        $out['total'] += count($rows);
    }

    if (is_role('Coast Guard')) {
        $rows = $db->query(
            "SELECT v.voyage_id, v.voyage_no, v.locked_at, v.total_pax, v.total_load_kg, v.departure_date, v.departure_time,
                    vsl.vessel_name, po.port_code AS orig, pd.port_code AS dest, u.full_name AS locked_by_name
             FROM voyage v
             JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
             JOIN route r    ON r.route_id   = v.route_id
             JOIN port po    ON po.port_id   = r.origin_port_id
             JOIN port pd    ON pd.port_id   = r.destination_port_id
             LEFT JOIN user u ON u.user_id   = v.locked_by
             WHERE " . CG_WAIT_WHERE . " AND v.locked_at >= NOW() - " . NOTIF_MAX_AGE . "
             ORDER BY v.departure_date, v.departure_time"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['ago'] = notif_ago($r['locked_at']);
            $r['dep_text'] = date('M d · g:i A', strtotime($r['departure_date'] . ' ' . $r['departure_time']));
            $r['load_text'] = kg($r['total_load_kg']);
        }
        unset($r);
        $out['coastguard'] = ['count' => count($rows), 'items' => array_slice($rows, 0, NOTIF_LIST_LIMIT)];
        $out['total'] += count($rows);

        // Voyages that left port (marked Departed)
        $rows = $db->query(
            "SELECT v.voyage_id, v.departed_at, v.total_pax, v.departure_date, v.departure_time,
                    vsl.vessel_name, po.port_code AS orig, pd.port_code AS dest
             FROM voyage v
             JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
             JOIN route r    ON r.route_id   = v.route_id
             JOIN port po    ON po.port_id   = r.origin_port_id
             JOIN port pd    ON pd.port_id   = r.destination_port_id
             WHERE v.manifest_status = 'Departed' AND v.departed_at >= NOW() - " . NOTIF_MAX_AGE . "
             ORDER BY v.departed_at DESC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['ago'] = notif_ago($r['departed_at']);
            $r['dep_text'] = date('M d · g:i A', strtotime($r['departure_date'] . ' ' . $r['departure_time']));
            $r['left_text'] = date('g:i A', strtotime($r['departed_at']));
        }
        unset($r);
        $out['departed'] = ['count' => count($rows), 'items' => array_slice($rows, 0, NOTIF_LIST_LIMIT)];
        $out['total'] += count($rows);
    }

    if (is_role('Admin')) {   // the Admin marks them Departed (modules/admin/notifications.php)
        $rows = $db->query(
            "SELECT v.voyage_id, v.ready_at, v.total_pax, v.departure_date, v.departure_time,
                    vsl.vessel_name, po.port_code AS orig, pd.port_code AS dest, u.full_name AS ready_by_name
             FROM voyage v
             JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
             JOIN route r    ON r.route_id   = v.route_id
             JOIN port po    ON po.port_id   = r.origin_port_id
             JOIN port pd    ON pd.port_id   = r.destination_port_id
             LEFT JOIN user u ON u.user_id   = v.ready_by
             WHERE v.archived = 0 AND v.ready_to_board = 1 AND v.departed_at IS NULL
               AND v.manifest_status NOT IN ('Departed','Cancelled') AND v.departure_date >= CURDATE() - INTERVAL 1 DAY
               " . ($bell ? 'AND v.ready_at >= NOW() - ' . NOTIF_MAX_AGE : '') . "
             ORDER BY v.ready_at DESC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['ago'] = notif_ago($r['ready_at']);
            $r['dep_text'] = date('M d · g:i A', strtotime($r['departure_date'] . ' ' . $r['departure_time']));
        }
        unset($r);
        $out['cleared'] = ['count' => count($rows), 'items' => array_slice($rows, 0, NOTIF_LIST_LIMIT)];
        $out['total'] += count($rows);
    }

    if (is_role('Admin', 'Passage', 'Coast Guard')) {
        $cg = is_role('Coast Guard');   // no booking screen: open the trip's clearance page instead
        // Seats taken on trips leaving today / tomorrow that can still be sold; warn from LOAD_WARN_PCT up
        $rows = $db->query(
            "SELECT v.voyage_id, v.departure_date, v.departure_time, vsl.vessel_name, vsl.max_passengers,
                    po.port_code AS orig, pd.port_code AS dest,
                    (SELECT COUNT(*) FROM passenger p WHERE p.voyage_id = v.voyage_id
                        AND p.status IN ('Booked','Checked-in','Boarded')) AS taken
             FROM voyage v
             JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
             JOIN route r    ON r.route_id   = v.route_id
             JOIN port po    ON po.port_id   = r.origin_port_id
             JOIN port pd    ON pd.port_id   = r.destination_port_id
             WHERE v.archived = 0 AND v.manifest_status IN ('Open','In Progress','Locked')
               AND v.departure_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY
               AND TIMESTAMP(v.departure_date, v.departure_time) > NOW() AND vsl.max_passengers > 0
             HAVING taken * 100 >= vsl.max_passengers * " . (float)LOAD_WARN_PCT . "
             ORDER BY v.departure_date, v.departure_time"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $pct = round($r['taken'] / $r['max_passengers'] * 100);
            $r['pct']   = $pct;
            $r['left']  = max(0, (int)$r['max_passengers'] - (int)$r['taken']);
            $r['level'] = $r['left'] === 0 ? 'full' : ($pct >= LOAD_CRITICAL_PCT ? 'critical' : 'warn');   // a new level notifies again
            $r['dep_text'] = date('M d · g:i A', strtotime($r['departure_date'] . ' ' . $r['departure_time']));
            $r['link'] = ($cg ? '/modules/coastguard/clearance.php?vid=' : '/modules/admin/ticketing.php?trip=') . (int)$r['voyage_id'];
        }
        unset($r);
        $out['capacity'] = ['count' => count($rows), 'items' => array_slice($rows, 0, NOTIF_LIST_LIMIT)];
        $out['total'] += count($rows);
    }
    return $out;
}

/** "just now", "5 min ago", "3 h ago", "Sep 30" */
function notif_ago(?string $when): string {
    $t = $when ? strtotime($when) : false;
    if (!$t) return '';
    $d = time() - $t;
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    return date('M d', $t);
}
