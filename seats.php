<?php
/**
 * Seat map + balanced seating.
 *
 * Layout (vessels with seats per accommodation, see table vessel_class_seat):
 *   columns  A B C | aisle | D E F      A–C = left (port) side, D–F = right (starboard) side
 *   rows run from the bow: Business first, then Tourist, then Economy, so every seat code is
 *   unique on the vessel (e.g. Business A1…F17, Tourist A18…, Economy A59…).
 *   The last row of a class is filled from both sides (A, F, B, E, C, D) so the layout itself is even.
 *
 * Balance: a new passenger gets the free seat that keeps left/right and front/back
 * passenger counts closest to even (left/right counts double), filling from midship outward.
 * Vessels without seats per accommodation keep plain seat numbers 1..max_passengers.
 */

const SEAT_COLS          = ['A', 'B', 'C', 'D', 'E', 'F'];
const SEAT_CLASS_ORDER   = ['Business', 'Tourist', 'Economy'];   // bow → stern
const SEAT_PARTIAL_ORDER = ['A', 'F', 'B', 'E', 'C', 'D'];

/** Seat layout of a vessel, or null when it has no seats per accommodation. */
function seat_layout(int $vessel_id): ?array {
    static $cache = [];
    if (array_key_exists($vessel_id, $cache)) return $cache[$vessel_id];
    $caps = vessel_class_seats($vessel_id);
    if (!$caps) return $cache[$vessel_id] = null;
    $seats = []; $classes = []; $row = 0;
    foreach (SEAT_CLASS_ORDER as $cls) {
        $n = (int)($caps[$cls] ?? 0);
        if ($n <= 0) continue;
        $classes[$cls] = ['first_row' => $row + 1, 'seats' => []];
        $full = intdiv($n, 6); $rest = $n % 6;
        for ($i = 0; $i < $full + ($rest ? 1 : 0); $i++) {
            $row++;
            $cols = $i < $full ? SEAT_COLS : array_slice(SEAT_PARTIAL_ORDER, 0, $rest);
            foreach (SEAT_COLS as $k => $c) {
                if (!in_array($c, $cols, true)) continue;
                $seats[$c . $row] = ['class' => $cls, 'row' => $row, 'col' => $c, 'side' => $k < 3 ? -1 : 1];
                $classes[$cls]['seats'][] = $c . $row;
            }
        }
        $classes[$cls]['last_row'] = $row;
    }
    return $cache[$vessel_id] = ['seats' => $seats, 'classes' => $classes, 'rows' => $row, 'mid' => ($row + 1) / 2];
}

/** -1 front half, 1 back half, 0 midship row. */
function seat_fore_aft(array $layout, int $row): int {
    return $row < $layout['mid'] ? -1 : ($row > $layout['mid'] ? 1 : 0);
}

/** Seats in use on a voyage: [seat code => sex] (Booked, Checked-in, Boarded). */
function seat_occupancy(int $vid, int $exclude_pid = 0): array {
    $st = db()->prepare("SELECT seat_no, sex FROM passenger WHERE voyage_id=? AND passenger_id<>?
                         AND status IN ('Booked','Checked-in','Boarded') AND seat_no IS NOT NULL AND seat_no<>''");
    $st->bind_param('ii', $vid, $exclude_pid); $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all() as [$s, $sex]) $out[$s] = $sex;
    return $out;
}

/** Passenger counts per side: ['left','right','front','back']. */
function seat_balance(array $layout, array $occupied): array {
    $b = ['left' => 0, 'right' => 0, 'front' => 0, 'back' => 0];
    foreach (array_keys($occupied) as $code) {
        $s = $layout['seats'][$code] ?? null;
        if (!$s) continue;
        $b[$s['side'] < 0 ? 'left' : 'right']++;
        $fa = seat_fore_aft($layout, $s['row']);
        if ($fa) $b[$fa < 0 ? 'front' : 'back']++;
    }
    return $b;
}

/**
 * $n free seats of $class on the voyage that keep the vessel balanced, or null when the
 * vessel has no seat layout / the class has none. Fewer than $n are returned when the class is full.
 * $also_taken: seats to treat as used (e.g. already picked for the same booking).
 */
function pick_balanced_seats(int $vid, string $class, int $n, int $exclude_pid = 0, array $also_taken = []): ?array {
    $v = get_voyage($vid);
    $L = $v ? seat_layout((int)$v['vessel_id']) : null;
    if (!$L || !isset($L['classes'][$class])) return null;
    $occ = seat_occupancy($vid, $exclude_pid) + array_fill_keys($also_taken, '');
    $lr = 0; $fb = 0;
    foreach (array_keys($occ) as $code) {
        if (!isset($L['seats'][$code])) continue;
        $lr += $L['seats'][$code]['side'];
        $fb += seat_fore_aft($L, $L['seats'][$code]['row']);
    }
    $picked = [];
    for ($k = 0; $k < $n; $k++) {
        $best = null; $best_cost = INF;
        foreach ($L['classes'][$class]['seats'] as $code) {
            if (isset($occ[$code])) continue;
            $s    = $L['seats'][$code];
            $fa   = seat_fore_aft($L, $s['row']);
            $cost = abs($lr + $s['side']) * 2 + abs($fb + $fa)
                  + abs($s['row'] - $L['mid']) * 0.001                       // midship first
                  + abs(array_search($s['col'], SEAT_COLS) - 2.5) * 0.0001;   // aisle seats first
            if ($cost < $best_cost) { $best_cost = $cost; $best = $code; }
        }
        if ($best === null) break;
        $picked[] = $best; $occ[$best] = '';
        $lr += $L['seats'][$best]['side'];
        $fb += seat_fore_aft($L, $L['seats'][$best]['row']);
    }
    return $picked;
}

/** Can a $class passenger use $seat on this voyage's vessel? (always true without a layout) */
function seat_fits(array $voyage, string $class, string $seat): bool {
    $L = seat_layout((int)$voyage['vessel_id']);
    if (!$L) return true;
    return ($L['seats'][$seat]['class'] ?? null) === $class;
}
