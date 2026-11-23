<?php
/**
 * MANLOAD shared helpers. Loaded by config/auth.php (used by staff AND
 * public pages). Keep business rules HERE so every
 * page computes fares, loads and capacity the same way.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings.php';

// ═════════════════════════════════════════════════════════════════════════
// Output / security
// ═════════════════════════════════════════════════════════════════════════

/** HTML-escape anything (null-safe). Use for EVERY value printed into HTML. */
function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Escape for use inside a JavaScript string in an HTML attribute or <script>. */
function js($v): string {
    return json_encode((string)($v ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

function peso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

function kg($amount, int $dec = 0): string {
    return number_format((float)$amount, $dec) . ' kg';
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

/** Hidden input to put inside every <form method="post">. (manload.js also adds it automatically.) */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Stops the request if the POST has no valid CSRF token. */
function csrf_verify(): void {
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Session expired. Please reload the page.']);
        } else {
            echo '<p style="font-family:sans-serif;padding:2rem">Your session expired or the form was submitted twice. '
               . '<a href="javascript:history.back()">Go back</a> and try again.</p>';
        }
        exit;
    }
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

/** Absolute URL for links that go into emails. */
function abs_url(string $path): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';   // host with an SSL proxy in front
    // Use the address the browser used (e.g. 192.168.1.5 on the port LAN) so phones
    // scanning a printed QR reach this computer; only safe host characters allowed.
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        if ($port && $port !== 80 && $port !== 443) $host .= ':' . $port;
    }
    return ($https ? 'https' : 'http') . '://' . $host . BASE_URL . $path;
}

function valid_email($email): bool {
    return is_string($email) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && !preg_match('/[\r\n]/', $email);
}

/**
 * A request value ($_GET / $_POST) as a string. A tampered URL like ?date[]=x
 * sends an array — that becomes '' instead of a warning or a crash.
 */
function str_param($v): string {
    return is_scalar($v) ? (string)$v : '';
}

/** Pick a value from a whitelist, falling back to the first entry. */
function pick($value, array $allowed): string {
    return is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
}

const PAX_CATEGORIES = ['Adult', 'Student', 'Minor', 'Senior', 'Infant', 'PWD'];
// Who each passenger type is for (shown next to the price)
const PAX_CATEGORY_HINTS = ['Adult' => '18–59 yrs', 'Student' => 'with school ID', 'Minor' => '2–17 yrs',
                            'Senior' => '60+ with ID', 'Infant' => 'under 2 yrs', 'PWD' => 'with PWD ID'];
const PAX_CLASSES    = ['Economy', 'Tourist', 'Business'];   // accommodations (no Cabin)
const RORO_SEATS     = ['Economy' => 270, 'Tourist' => 0, 'Business' => 0];   // every RORO: 270 Economy seats only
const PAX_SEXES      = ['Male', 'Female'];
const CARGO_TYPES    = ['General', 'Perishable', 'Hazardous', 'Livestock', 'Fragile', 'Oversized'];
const ID_TYPES       = ['PhilSys National ID', "Driver's License", 'Passport', 'UMID', 'SSS ID', 'PRC ID',
                        "Voter's ID", 'Postal ID', 'Senior Citizen ID', 'PWD ID', 'Student ID', 'Birth Certificate', 'Other'];
const PAY_METHODS    = ['Cash', 'GCash', 'Maya', 'Bank Transfer'];                     // taken at the counter
const ONLINE_PAY_METHODS = ['GCash', 'Maya', 'Card'];                                     // paid online through the PayMongo checkout (mock-up)
const ALL_PAY_METHODS    = ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Card', 'Wallet'];   // Wallet = passenger account credits (includes/wallet.php)

// ═════════════════════════════════════════════════════════════════════════
// Document numbers (atomic — safe with several cashiers at once)
// ═════════════════════════════════════════════════════════════════════════

/**
 * next_number('OR')        -> OR-2026-000001
 * next_number('WB')        -> WB-2026-000001
 * next_number('TK', 5)     -> TK-2026-00001
 */
function next_number(string $prefix, int $pad = 6): string {
    $db  = db();
    $key = $prefix . '-' . date('Y');
    $st  = $db->prepare('INSERT INTO doc_counter (counter_key, last_value) VALUES (?, LAST_INSERT_ID(1))
                         ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)');
    $st->bind_param('s', $key);
    $st->execute();
    $st->close();
    $n = (int)$db->query('SELECT LAST_INSERT_ID()')->fetch_row()[0];
    return $key . '-' . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

/** Short booking reference customers can type, e.g. MNL-7K3QX9 */
function new_booking_ref(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $ref = 'MNL-';
        for ($i = 0; $i < 6; $i++) $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $st = db()->prepare('SELECT 1 FROM passenger WHERE booking_ref=? UNION SELECT 1 FROM vehicle WHERE booking_ref=?');
        $st->bind_param('ss', $ref, $ref);
        $st->execute();
        $exists = $st->get_result()->fetch_row();
        $st->close();
    } while ($exists);
    return $ref;
}

// ═════════════════════════════════════════════════════════════════════════
// Voyages
// ═════════════════════════════════════════════════════════════════════════

/** Standard SELECT for a voyage with vessel, route and ports. Append WHERE ... */
const VOYAGE_SQL = "SELECT v.*, vsl.vessel_name, vsl.vessel_type, vsl.max_passengers, vsl.max_load_kg,
        vsl.max_vehicles, vsl.maintenance, vsl.maintenance_note, vsl.maintenance_since, vsl.maintenance_until,
        vsl.marina_no, vsl.operator, vsl.is_active AS vessel_active,
        r.route_code, r.est_travel_hours, r.baggage_allowance_eco, r.baggage_allowance_bus,
        r.baggage_allowance_cab, r.excess_rate_per_kg, r.freight_rate_per_kg, r.hazardous_surcharge,
        po.port_code AS orig, po.port_name AS orig_name, pd.port_code AS dest, pd.port_name AS dest_name
    FROM voyage v
    JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
    JOIN route r    ON r.route_id    = v.route_id
    JOIN port po    ON po.port_id    = r.origin_port_id
    JOIN port pd    ON pd.port_id    = r.destination_port_id";

function get_voyage(int $vid): ?array {
    if ($vid <= 0) return null;
    $st = db()->prepare(VOYAGE_SQL . ' WHERE v.voyage_id = ?');
    $st->bind_param('i', $vid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) $row['under_maintenance'] = vessel_under_maintenance($row, $row['departure_date']);
    return $row ?: null;
}

/**
 * Voyage the staff member is working on: ?vid=  > session > next open voyage.
 * Pages call this instead of repeating the "pick active voyage" logic.
 */
function active_voyage_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $vid = (int)($_GET['vid'] ?? $_POST['vid'] ?? 0);
    if ($vid > 0) { $_SESSION['active_voyage_id'] = $vid; return $vid; }
    if (!empty($_SESSION['active_voyage_id'])) {
        $vid = (int)$_SESSION['active_voyage_id'];
        $st = db()->prepare("SELECT 1 FROM voyage WHERE voyage_id=? AND archived=0");
        $st->bind_param('i', $vid);
        $st->execute();
        if ($st->get_result()->fetch_row()) return $vid;
    }
    $row = db()->query("SELECT voyage_id FROM voyage
        WHERE manifest_status IN ('Open','In Progress','Locked') AND archived=0
          AND TIMESTAMP(departure_date, departure_time) >= NOW() - INTERVAL 6 HOUR
        ORDER BY departure_date, departure_time LIMIT 1")->fetch_row();
    $vid = $row ? (int)$row[0] : 0;
    if ($vid) $_SESSION['active_voyage_id'] = $vid;
    return $vid;
}

/** Voyages staff can pick from (today onward, plus recent). For <select> menus. */
function voyage_options(bool $include_locked = true): array {
    $st = $include_locked ? "'Open','In Progress','Locked'" : "'Open','In Progress'";
    return db()->query(VOYAGE_SQL . " WHERE v.manifest_status IN ($st) AND v.archived=0
        AND v.departure_date >= CURDATE() - INTERVAL 1 DAY
        ORDER BY v.departure_date, v.departure_time LIMIT 200")->fetch_all(MYSQLI_ASSOC);
}

function voyage_label(array $v): string {
    return date('M d', strtotime($v['departure_date'])) . ' ' . date('g:i A', strtotime($v['departure_time']))
         . ' · ' . $v['orig'] . '→' . $v['dest'] . ' · ' . $v['vessel_name'];
}

/** Check-in / cargo / vehicle entry allowed? Returns null if OK, else the reason. */
function voyage_closed_reason(?array $v): ?string {
    if (!$v) return 'Voyage not found.';
    if (!empty($v['under_maintenance'])) return 'Vessel is UNDER MAINTENANCE — this voyage cannot depart.';
    if ($v['manifest_status'] === 'Cancelled') return 'This voyage was cancelled.';
    if (!in_array($v['manifest_status'], ['Open', 'In Progress'], true))
        return 'Manifest is ' . $v['manifest_status'] . ' — no more changes allowed.';
    return null;
}

/** Is the vessel under maintenance on $date? */
function vessel_under_maintenance(array $vessel, ?string $date = null): bool {
    if (empty($vessel['maintenance'])) return false;
    $date  = $date ?: date('Y-m-d');
    $since = $vessel['maintenance_since'] ?? null;
    $until = $vessel['maintenance_until'] ?? null;
    if ($since && $date < $since) return false;
    if ($until && $date > $until) return false;
    return true;
}

function status_badge(string $status): string {
    $cls = match ($status) {
        'Open'        => 'bg-success',
        'In Progress' => 'bg-primary',
        'Locked'      => 'bg-warning text-dark',
        'Submitted'   => 'bg-info text-dark',
        'Departed'    => 'bg-secondary',
        'Cancelled'   => 'bg-danger',
        'Booked'      => 'bg-info text-dark',
        'Checked-in'  => 'bg-primary',
        'Boarded'     => 'bg-success',
        'No-show'     => 'bg-secondary',
        default       => 'bg-light text-dark',
    };
    return '<span class="badge ' . $cls . '">' . e($status) . '</span>';
}

function maintenance_badge(): string {
    return '<span class="badge bg-danger"><i class="bi bi-tools me-1"></i>Under Maintenance</span>';
}

// ═════════════════════════════════════════════════════════════════════════
// Fares
// ═════════════════════════════════════════════════════════════════════════

/** ['Economy' => ['label'=>..., 'amount'=>560.0], ...] for a route */
function route_fares(int $route_id): array {
    static $cache = [];
    if (isset($cache[$route_id])) return $cache[$route_id];
    $st = db()->prepare("SELECT class, label, amount FROM route_fare WHERE route_id=?
                         ORDER BY FIELD(class,'Economy','Tourist','Business')");
    $st->bind_param('i', $route_id);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r)
        $out[$r['class']] = ['label' => $r['label'], 'amount' => (float)$r['amount']];
    return $cache[$route_id] = $out;
}

/**
 * Fare actually charged. Returns ['base'=>, 'discount_pct'=>, 'discount'=>, 'fare'=>]
 * The server ALWAYS recomputes this — never trust a fare sent by the browser.
 */
function compute_fare(int $route_id, string $class, string $category): array {
    $fares = route_fares($route_id);
    $base  = $fares[$class]['amount'] ?? ($fares['Economy']['amount'] ?? 0.0);
    if ($category === 'Infant') {
        return ['base' => $base, 'discount_pct' => 100, 'discount' => $base - INFANT_FARE, 'fare' => (float)INFANT_FARE];
    }
    $pct  = category_discount_pct($category);
    $disc = round($base * $pct / 100, 2);
    return ['base' => $base, 'discount_pct' => $pct, 'discount' => $disc, 'fare' => round($base - $disc, 2)];
}

/** Fare discount % of a passenger type (Infant pays the flat INFANT_FARE instead). */
function category_discount_pct(string $category): float {
    if ($category === 'Minor') return (float)MINOR_DISCOUNT_PCT;
    return in_array($category, DISCOUNTED_CATEGORIES, true) ? (float)DISCOUNT_PCT : 0.0;
}

/** Discount % per passenger type, for the fare previews in the browser. */
function category_discounts(): array {
    $out = [];
    foreach (PAX_CATEGORIES as $c) if ($c !== 'Infant') $out[$c] = category_discount_pct($c);
    return $out;
}

/** Price of every passenger type per class: ['Economy' => ['Adult' => 560.0, ...], ...] */
function fare_matrix(int $route_id): array {
    $out = [];
    foreach (route_fares($route_id) as $cls => $f)
        foreach (PAX_CATEGORIES as $c) $out[$cls][$c] = compute_fare($route_id, $cls, $c)['fare'];
    return $out;
}

function baggage_allowance(array $voyage_or_route, string $class): float {
    return (float)match ($class) {
        'Business' => $voyage_or_route['baggage_allowance_bus'],
        default    => $voyage_or_route['baggage_allowance_eco'],   // Economy + Tourist
    };
}

// ═════════════════════════════════════════════════════════════════════════
// Load control  (ONE formula used everywhere)
//   passengers on board = Checked-in + Boarded   × PAX_AVG_WEIGHT_KG
//   + baggage of those passengers
//   + cargo gross weight
//   + vehicles (declared weight, else vehicle type weight)
// ═════════════════════════════════════════════════════════════════════════

function voyage_load(int $vid): array {
    $db = db();
    $v  = get_voyage($vid);
    if (!$v) return [];

    $st = $db->prepare("SELECT
            SUM(status IN ('Checked-in','Boarded')) AS onboard,
            SUM(status = 'Boarded')                 AS boarded,
            SUM(status = 'Checked-in')              AS checked_in,
            SUM(status = 'Booked')                  AS booked
        FROM passenger WHERE voyage_id=?");
    $st->bind_param('i', $vid); $st->execute();
    $p = $st->get_result()->fetch_assoc();

    $st = $db->prepare("SELECT COALESCE(SUM(b.actual_weight),0), COALESCE(SUM(b.excess_kg > 0 AND b.is_paid = 0),0)
        FROM baggage b JOIN passenger p ON p.passenger_id=b.passenger_id
        WHERE b.voyage_id=? AND p.status IN ('Checked-in','Boarded')");
    $st->bind_param('i', $vid); $st->execute();
    [$bag_kg, $unpaid_excess] = $st->get_result()->fetch_row();

    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(gross_weight),0), COALESCE(SUM(cargo_type='Hazardous'),0)
        FROM cargo WHERE voyage_id=?");
    $st->bind_param('i', $vid); $st->execute();
    [$cargo_n, $cargo_kg, $hazard_n] = $st->get_result()->fetch_row();

    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(COALESCE(vh.declared_weight_kg, vt.weight_kg)),0)
        FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id WHERE vh.voyage_id=?");
    $st->bind_param('i', $vid); $st->execute();
    [$veh_n, $veh_kg] = $st->get_result()->fetch_row();

    $onboard = (int)$p['onboard'];
    $pax_kg  = $onboard * PAX_AVG_WEIGHT_KG;
    $total   = $pax_kg + (float)$bag_kg + (float)$cargo_kg + (float)$veh_kg;
    $max     = (float)$v['max_load_kg'];
    $maxpax  = (int)$v['max_passengers'];
    $seats_taken = $onboard + (int)$p['booked'];

    $load_pct = $max > 0 ? round($total / $max * 100, 1) : 0;
    $pax_pct  = $maxpax > 0 ? round($onboard / $maxpax * 100, 1) : 0;

    return [
        'voyage'         => $v,
        'onboard'        => $onboard,            // checked-in + boarded
        'boarded'        => (int)$p['boarded'],
        'checked_in'     => (int)$p['checked_in'],
        'booked'         => (int)$p['booked'],   // online, not yet at port
        'seats_taken'    => $seats_taken,
        'seats_left'     => max(0, $maxpax - $seats_taken),
        'max_pax'        => $maxpax,
        'pax_pct'        => $pax_pct,
        'pax_kg'         => $pax_kg,
        'baggage_kg'     => (float)$bag_kg,
        'cargo_count'    => (int)$cargo_n,
        'cargo_kg'       => (float)$cargo_kg,
        'hazard_count'   => (int)$hazard_n,
        'vehicle_count'  => (int)$veh_n,
        'vehicle_kg'     => (float)$veh_kg,
        'max_vehicles'   => (int)$v['max_vehicles'],
        'vehicles_left'  => max(0, (int)$v['max_vehicles'] - (int)$veh_n),
        'total_kg'       => $total,
        'max_kg'         => $max,
        'remaining_kg'   => max(0, $max - $total),
        'load_pct'       => $load_pct,
        'level'          => load_level(max($load_pct, $pax_pct)),
        'unpaid_excess'  => (int)$unpaid_excess,
        'pax_ok'         => $onboard <= $maxpax,
        'load_ok'        => $total <= $max,
    ];
}

/** 'ok' | 'warn' | 'critical' | 'over' */
function load_level(float $pct): string {
    if ($pct > LOAD_MAX_PCT) return 'over';
    if ($pct >= LOAD_CRITICAL_PCT) return 'critical';
    if ($pct >= LOAD_WARN_PCT) return 'warn';
    return 'ok';
}

function level_color(string $level): string {
    return match ($level) { 'over', 'critical' => 'danger', 'warn' => 'warning', default => 'success' };
}

/** Bootstrap progress bar for a percentage. */
function progress_bar(float $pct, string $label = '', string $height = '10px'): string {
    $c = level_color(load_level($pct));
    $w = min(100, max(0, $pct));
    return '<div class="progress" style="height:' . $height . '" title="' . e($label ?: $pct . '%') . '">'
         . '<div class="progress-bar bg-' . $c . '" style="width:' . $w . '%"></div></div>';
}

/**
 * Would adding this exceed the vessel's limits? Returns null when OK,
 * otherwise a message to show the user (and the insert must be refused).
 */
function capacity_error(int $vid, int $add_pax = 0, float $add_kg = 0, int $add_vehicles = 0): ?string {
    $L = voyage_load($vid);
    if (!$L) return 'Voyage not found.';
    if ($add_pax > 0 && $L['seats_taken'] + $add_pax > $L['max_pax'])
        return "Passenger capacity reached ({$L['max_pax']} max, {$L['seats_taken']} already booked/checked-in).";
    if ($add_vehicles > 0 && $L['vehicle_count'] + $add_vehicles > $L['max_vehicles'])
        return $L['max_vehicles'] === 0
            ? 'This vessel does not carry vehicles.'
            : "Vehicle slots full ({$L['max_vehicles']} max).";
    $new_total = $L['total_kg'] + $add_kg + $add_pax * PAX_AVG_WEIGHT_KG;
    if ($new_total > $L['max_kg'])
        return 'Allowable load exceeded: ' . kg($new_total) . ' would be over the vessel limit of '
             . kg($L['max_kg']) . ' (only ' . kg($L['remaining_kg']) . ' left).';
    return null;
}

// ═════════════════════════════════════════════════════════════════════════
// Seats per accommodation (class). Optional: a class without a row in
// vessel_class_seat simply shares the vessel's total passenger capacity.
// ═════════════════════════════════════════════════════════════════════════

/** ['Business' => 99, ...] — only the classes the Admin gave a seat count. */
function vessel_class_seats(int $vessel_id): array {
    $st = db()->prepare('SELECT class, capacity FROM vessel_class_seat WHERE vessel_id=?');
    $st->bind_param('i', $vessel_id); $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all() as [$c, $n]) $out[$c] = (int)$n;
    return $out;
}

/**
 * Fares of the classes a voyage offers: its route fares, minus classes its vessel
 * has 0 seats for (e.g. a RORO with Economy seats only).
 */
function voyage_fares(int $vid): array {
    $v = get_voyage($vid);
    if (!$v) return [];
    $caps = vessel_class_seats((int)$v['vessel_id']);
    return array_filter(route_fares((int)$v['route_id']), fn($c) => !isset($caps[$c]) || $caps[$c] > 0, ARRAY_FILTER_USE_KEY);
}

/**
 * Accommodations of a voyage (classes with a fare on its route):
 * [class => ['label','fare','capacity'(null = shared),'booked','remaining']]
 */
function voyage_accommodations(int $vid): array {
    $v = get_voyage($vid);
    if (!$v) return [];
    $caps = vessel_class_seats((int)$v['vessel_id']);
    $st = db()->prepare("SELECT class, COUNT(*) FROM passenger WHERE voyage_id=? AND status IN ('Booked','Checked-in','Boarded') GROUP BY class");
    $st->bind_param('i', $vid); $st->execute();
    $booked = array_column($st->get_result()->fetch_all(), 1, 0);
    $left = max(0, (int)$v['max_passengers'] - array_sum($booked));
    // Classes without their own seat count share only the seats no class has claimed
    $shared_booked = 0;
    foreach ($booked as $cls => $n) if (!isset($caps[$cls])) $shared_booked += (int)$n;
    $shared_left = max(0, min($left, (int)$v['max_passengers'] - array_sum($caps) - $shared_booked));
    $out = [];
    foreach (voyage_fares($vid) as $cls => $f) {
        $b   = (int)($booked[$cls] ?? 0);
        $cap = $caps[$cls] ?? null;
        $out[$cls] = ['label' => $f['label'], 'fare' => $f['amount'], 'capacity' => $cap, 'booked' => $b,
                      'remaining' => $cap === null ? $shared_left : max(0, min($cap - $b, $left))];
    }
    return $out;
}

/** null when $add more passengers fit in $class on the voyage, else the reason. */
function class_capacity_error(int $vid, string $class, int $add = 1): ?string {
    $acc = voyage_accommodations($vid);
    if (!isset($acc[$class])) return "$class is not offered on this trip.";
    if ($acc[$class]['remaining'] < $add)
        return $acc[$class]['label'] . ' has only ' . $acc[$class]['remaining'] . ' seat(s) left on this trip.';
    return null;
}

// ═════════════════════════════════════════════════════════════════════════
// Load zones (A1 / A2 / B1 ...) — loads are sorted BEFORE the Coast Guard
// inspection so the inspector only has to verify.
// ═════════════════════════════════════════════════════════════════════════

/** Zones of the voyage's vessel with current kg / vehicle usage. */
function voyage_zones(int $vid): array {
    $st = db()->prepare("SELECT z.*,
            COALESCE((SELECT SUM(COALESCE(vh.declared_weight_kg, vt.weight_kg)) FROM vehicle vh
                      JOIN vehicle_type vt ON vt.type_id = vh.vehicle_type_id
                      WHERE vh.voyage_id = v.voyage_id AND vh.zone_id = z.zone_id), 0) AS vehicle_kg,
            (SELECT COUNT(*) FROM vehicle vh WHERE vh.voyage_id = v.voyage_id AND vh.zone_id = z.zone_id) AS vehicle_count,
            COALESCE((SELECT SUM(c.gross_weight) FROM cargo c WHERE c.voyage_id = v.voyage_id AND c.zone_id = z.zone_id), 0) AS cargo_kg,
            (SELECT COUNT(*) FROM cargo c WHERE c.voyage_id = v.voyage_id AND c.zone_id = z.zone_id) AS cargo_count,
            (SELECT COUNT(*) FROM cargo c WHERE c.voyage_id = v.voyage_id AND c.zone_id = z.zone_id AND c.cargo_type='Hazardous') AS hazard_count
        FROM voyage v JOIN vessel_zone z ON z.vessel_id = v.vessel_id
        WHERE v.voyage_id = ? ORDER BY z.sort_order, z.zone_code");
    $st->bind_param('i', $vid);
    $st->execute();
    $zones = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($zones as &$z) {
        $z['used_kg'] = (float)$z['vehicle_kg'] + (float)$z['cargo_kg'];
        $z['pct']     = $z['max_kg'] > 0 ? round($z['used_kg'] / $z['max_kg'] * 100, 1) : 0;
        $z['over']    = ($z['max_kg'] > 0 && $z['used_kg'] > $z['max_kg'])
                     || ($z['max_vehicles'] > 0 && $z['vehicle_count'] > $z['max_vehicles'])
                     || ($z['hazard_count'] > 0 && !$z['allows_hazard']);
    }
    return $zones;
}

/**
 * Pick the best zone for one item. Vehicles go to Vehicle/Mixed zones,
 * heaviest vehicles to the first (heavy) lane; cargo to Cargo/Mixed zones;
 * hazardous cargo only to zones that allow it. Returns zone_id or null.
 */
function pick_zone(array $zones, string $kind, float $kg, bool $hazard = false): ?int {
    $candidates = [];
    foreach ($zones as $z) {
        $ok_use = $kind === 'vehicle' ? in_array($z['zone_use'], ['Vehicle', 'Mixed'], true)
                                      : in_array($z['zone_use'], ['Cargo', 'Mixed'], true);
        if (!$ok_use) continue;
        if ($hazard && !$z['allows_hazard']) continue;
        if ($kind === 'vehicle' && $z['max_vehicles'] > 0 && $z['vehicle_count'] >= $z['max_vehicles']) continue;
        if ($z['max_kg'] - $z['used_kg'] < $kg) continue;
        $candidates[] = $z;
    }
    if (!$candidates) return null;
    if ($kind === 'vehicle') {
        // Heavy vehicles (>= 5 t) take the first lane (A1 = heavy lane);
        // lighter vehicles fill the later lanes first to keep A1 free for trucks.
        if ($kg < 5000) $candidates = array_reverse($candidates);
        return (int)$candidates[0]['zone_id'];
    }
    // Cargo: the zone with the most room left (balances the holds)
    usort($candidates, fn($a, $b) => ($b['max_kg'] - $b['used_kg']) <=> ($a['max_kg'] - $a['used_kg']));
    return (int)$candidates[0]['zone_id'];
}

/**
 * Sort all loads of a voyage into zones. $only_unassigned=false re-sorts
 * everything (heaviest first). Returns ['assigned'=>n, 'unplaced'=>[labels]].
 */
function auto_assign_zones(int $vid, bool $only_unassigned = true): array {
    $db = db();
    if (!$only_unassigned) {
        $db->query("UPDATE vehicle SET zone_id=NULL WHERE voyage_id=" . (int)$vid);
        $db->query("UPDATE cargo   SET zone_id=NULL WHERE voyage_id=" . (int)$vid);
    }
    $zones = voyage_zones($vid);
    $index = [];
    foreach ($zones as $i => $z) $index[$z['zone_id']] = $i;
    $assigned = 0; $unplaced = [];

    $items = [];
    $st = $db->prepare("SELECT vh.vehicle_id AS id, vh.plate_no AS label, COALESCE(vh.declared_weight_kg, vt.weight_kg) AS kg
        FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id
        WHERE vh.voyage_id=? AND vh.zone_id IS NULL");
    $st->bind_param('i', $vid); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $items[] = $r + ['kind' => 'vehicle', 'hazard' => false];
    $st = $db->prepare("SELECT cargo_id AS id, waybill_no AS label, gross_weight AS kg, cargo_type='Hazardous' AS hazard
        FROM cargo WHERE voyage_id=? AND zone_id IS NULL");
    $st->bind_param('i', $vid); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $items[] = $r + ['kind' => 'cargo'];

    usort($items, fn($a, $b) => $b['kg'] <=> $a['kg']);   // heaviest first

    foreach ($items as $it) {
        $zid = pick_zone($zones, $it['kind'], (float)$it['kg'], (bool)$it['hazard']);
        if ($zid === null) { $unplaced[] = $it['label']; continue; }
        $table = $it['kind'] === 'vehicle' ? 'vehicle' : 'cargo';
        $pk    = $it['kind'] === 'vehicle' ? 'vehicle_id' : 'cargo_id';
        $u = $db->prepare("UPDATE $table SET zone_id=? WHERE $pk=?");
        $u->bind_param('ii', $zid, $it['id']); $u->execute();
        $i = $index[$zid];
        $zones[$i]['used_kg'] += (float)$it['kg'];
        if ($it['kind'] === 'vehicle') $zones[$i]['vehicle_count']++;
        $assigned++;
    }
    return ['assigned' => $assigned, 'unplaced' => $unplaced];
}

// ═════════════════════════════════════════════════════════════════════════
// Trip availability (public booking page + dashboards)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Upcoming trips with seats and vehicle slots left.
 * $filters: from (Y-m-d), to (Y-m-d), origin (port_id), dest (port_id), route_id
 */
function available_trips(array $filters = []): array {
    $db    = db();
    $from  = $filters['from'] ?? date('Y-m-d');
    $to    = $filters['to']   ?? date('Y-m-d', strtotime('+' . BOOKING_DAYS_AHEAD . ' days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $from;
    if ($to < $from) [$from, $to] = [$to, $from];
    $where = ["v.departure_date BETWEEN ? AND ?", "v.archived = 0", "vsl.is_active = 1",
              "v.manifest_status <> 'Cancelled'"];
    $types = 'ss'; $args = [$from, $to];
    foreach (['origin' => 'r.origin_port_id', 'dest' => 'r.destination_port_id', 'route_id' => 'v.route_id'] as $k => $col) {
        if (!empty($filters[$k])) { $where[] = "$col = ?"; $types .= 'i'; $args[] = (int)$filters[$k]; }
    }
    $sql = "SELECT v.voyage_id, v.voyage_no, v.route_id, v.departure_date, v.departure_time, v.manifest_status, v.public_remarks,
                vsl.vessel_id, vsl.vessel_name, vsl.vessel_type, vsl.max_passengers, vsl.max_vehicles, vsl.max_load_kg,
                vsl.maintenance, vsl.maintenance_note, vsl.maintenance_since, vsl.maintenance_until,
                r.route_code, r.est_travel_hours,
                po.port_code AS orig, po.port_name AS orig_name, pd.port_code AS dest, pd.port_name AS dest_name,
                (SELECT COUNT(*) FROM passenger p WHERE p.voyage_id=v.voyage_id
                    AND p.status IN ('Booked','Checked-in','Boarded')) AS seats_taken,
                (SELECT COUNT(*) FROM vehicle vh WHERE vh.voyage_id=v.voyage_id) AS vehicles_taken,
                (SELECT MIN(amount) FROM route_fare f WHERE f.route_id=v.route_id) AS fare_from
            FROM voyage v
            JOIN vessel vsl ON vsl.vessel_id = v.vessel_id
            JOIN route r    ON r.route_id    = v.route_id
            JOIN port po    ON po.port_id    = r.origin_port_id
            JOIN port pd    ON pd.port_id    = r.destination_port_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.departure_date, v.departure_time";
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $cutoff = time() + BOOKING_CUTOFF_MINUTES * 60;
    foreach ($rows as &$t) {
        $t['under_maintenance'] = vessel_under_maintenance($t, $t['departure_date']);
        $t['seats_left']    = max(0, (int)$t['max_passengers'] - (int)$t['seats_taken']);
        $t['vehicles_left'] = max(0, (int)$t['max_vehicles'] - (int)$t['vehicles_taken']);
        $dep = strtotime($t['departure_date'] . ' ' . $t['departure_time']);
        $t['departed']  = $dep < time();
        $t['bookable']  = !$t['under_maintenance'] && $dep > $cutoff && $t['seats_left'] > 0
                       && in_array($t['manifest_status'], ['Open', 'In Progress'], true);
        $t['availability'] = match (true) {
            $t['under_maintenance']                        => 'Under Maintenance',
            $t['departed'] || !in_array($t['manifest_status'], ['Open', 'In Progress'], true) => 'Closed',
            $dep <= $cutoff                                => 'Booking closed',
            $t['seats_left'] === 0                         => 'Fully booked',
            default                                        => 'Available',
        };
    }
    return $rows;
}

// ═════════════════════════════════════════════════════════════════════════
// Ticket validity + QR codes (the QR expires together with the ticket)
// ═════════════════════════════════════════════════════════════════════════

/** When a ticket stops being valid: scheduled departure + TICKET_GRACE_HOURS. */
function ticket_expiry(array $voyage): string {
    $dep = strtotime($voyage['departure_date'] . ' ' . $voyage['departure_time']);
    return date('Y-m-d H:i:s', $dep + TICKET_GRACE_HOURS * 3600);
}

/**
 * Text stored in a ticket's QR code: a link to the verify page with
 *   <ticket no>.<expiry unix time>.<signature>
 * The signature (HMAC with APP_SECRET) means nobody can make or edit a QR
 * by hand, and the expiry inside it means an old QR stops working.
 */
function ticket_qr_data(array $p): string {
    $exp = strtotime($p['ticket_valid_until'] ?? '') ?: 0;
    $tok = $p['ticket_no'] . '.' . $exp . '.' . ticket_qr_sig($p['ticket_no'], $exp);
    return abs_url('/modules/checkin/verify.php?t=' . rawurlencode($tok));
}

function ticket_qr_sig(string $ticket_no, int $exp): string {
    return substr(hash_hmac('sha256', 'TICKET|' . $ticket_no . '|' . $exp, APP_SECRET), 0, 12);
}

/**
 * Read what a scanner / camera / keyboard gave us. Accepts the QR link,
 * the bare token, or a typed ticket number.
 * Returns ['ticket_no'=>string, 'from_qr'=>bool, 'sig_ok'=>bool, 'exp'=>?int, 'qr_expired'=>bool]
 */
function parse_ticket_input(string $text): array {
    $text = trim($text);
    // The signed code anywhere in what was read — the full link, the bare code, or text from a scanner
    // that sent it in CAPITALS (Caps Lock / uppercase mode: "?T=TK-…") — case does not matter.
    if (preg_match('/(?<![A-Z0-9])([A-Z]{2}-\d{4}-\d{3,8})\.(\d{1,12})\.([0-9a-f]{12})(?![0-9a-f])/i', rawurldecode($text), $m)) {
        $tn = strtoupper($m[1]); $exp = (int)$m[2];
        $ok = hash_equals(ticket_qr_sig($tn, $exp), strtolower($m[3]));
        return ['ticket_no' => $tn, 'from_qr' => true, 'sig_ok' => $ok, 'exp' => $exp, 'qr_expired' => $ok && $exp > 0 && $exp < time()];
    }
    return ['ticket_no' => strtoupper($text), 'from_qr' => false, 'sig_ok' => false, 'exp' => null, 'qr_expired' => false];
}

/** Draw a QR code (works offline: library is in assets/vendor). */
function qr_canvas(string $data, int $size = 140, string $id = ''): string {
    static $lib = false;
    $id  = $id ?: 'qr' . substr(md5($data . random_int(0, PHP_INT_MAX)), 0, 10);
    $out = '';
    if (!$lib) { $out .= '<script src="' . e(BASE_URL) . '/assets/vendor/qrious/qrious.min.js"></script>'; $lib = true; }
    return $out . '<canvas id="' . e($id) . '" width="' . $size . '" height="' . $size . '" class="qr-canvas"></canvas>'
         . '<script>new QRious({element:document.getElementById(' . js($id) . '),value:' . js($data) . ',size:' . $size . ',level:"M"});</script>';
}

/**
 * Draw a QR code on the SERVER as PNG bytes (for e-mails: mail apps cannot run
 * JavaScript, so qr_canvas() does not work there). Uses the bundled
 * chillerlan/php-qrcode in lib/ and PHP's GD extension (on in XAMPP by default).
 * Returns null when GD is not available.
 */
function qr_png(string $data, int $scale = 5): ?string {
    if (!extension_loaded('gd')) return null;
    require_once __DIR__ . '/../lib/autoload.php';
    try {
        $opt = new \chillerlan\QRCode\QROptions([
            'outputType'    => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
            'eccLevel'      => \chillerlan\QRCode\Common\EccLevel::M,
            'scale'         => max(2, min(12, $scale)),
            'quietzoneSize' => 4,
            'outputBase64'  => false,
        ]);
        return (new \chillerlan\QRCode\QRCode($opt))->render($data);
    } catch (Throwable $e) {
        return null;
    }
}

/** When a departure clearance certificate stops being valid. */
function clearance_expiry(array $voyage): string {
    $dep = strtotime($voyage['departure_date'] . ' ' . $voyage['departure_time']);
    return date('Y-m-d H:i:s', $dep + CLEARANCE_VALID_HOURS * 3600);
}

/** Private link to a booking's e-tickets (can't be guessed from the ref alone). */
function booking_link(string $booking_ref): string {
    return abs_url('/modules/booking/confirm.php?ref=' . urlencode($booking_ref) . '&k=' . booking_key($booking_ref));
}

function booking_key(string $booking_ref): string {
    return substr(hash_hmac('sha256', 'booking|' . $booking_ref, APP_SECRET), 0, 16);
}

// ═════════════════════════════════════════════════════════════════════════
// Coast Guard verification — automatic checks for "Ready to Board"
// ═════════════════════════════════════════════════════════════════════════

/** key => ['ok'=>bool, 'detail'=>string] for every system-verified check. */
function clearance_auto_checks(int $vid): array {
    $vid = (int)$vid;   // integer only — safe to place in the queries below
    $L = voyage_load($vid);
    if (!$L) return [];
    $v = $L['voyage'];
    $zones = voyage_zones($vid);
    $unzoned = (int)db()->query("SELECT
        (SELECT COUNT(*) FROM vehicle WHERE voyage_id=$vid AND zone_id IS NULL) +
        (SELECT COUNT(*) FROM cargo   WHERE voyage_id=$vid AND zone_id IS NULL)")->fetch_row()[0];
    $over_zones = array_column(array_filter($zones, fn($z) => $z['over']), 'zone_code');
    $hazard_bad = array_filter($zones, fn($z) => $z['hazard_count'] > 0 && !$z['allows_hazard']);
    $unzoned_hazard = (int)db()->query("SELECT COUNT(*) FROM cargo WHERE voyage_id=$vid AND cargo_type='Hazardous' AND zone_id IS NULL")->fetch_row()[0];
    $incomplete = (int)db()->query("SELECT COUNT(*) FROM passenger WHERE voyage_id=$vid
        AND status IN ('Checked-in','Boarded') AND (last_name='' OR first_name='' OR age IS NULL)")->fetch_row()[0];
    $has_loads = $L['vehicle_count'] + $L['cargo_count'] > 0;

    return [
        'manifest' => ['ok' => $incomplete === 0,
            'detail' => $L['onboard'] . ' passenger(s) on manifest' . ($incomplete ? ", $incomplete with missing details" : ', all complete')],
        'pax_capacity' => ['ok' => $L['pax_ok'],
            'detail' => $L['onboard'] . ' / ' . $L['max_pax'] . ' passengers (' . $L['pax_pct'] . '%)'],
        'load_capacity' => ['ok' => $L['load_ok'],
            'detail' => kg($L['total_kg']) . ' / ' . kg($L['max_kg']) . ' (' . $L['load_pct'] . '%)'],
        'zones' => ['ok' => $unzoned === 0 && !$over_zones,
            'detail' => !$has_loads ? 'No vehicles or cargo on this voyage'
                : ($unzoned ? "$unzoned load(s) not yet assigned to a zone" : '')
                  . ($over_zones ? ' Over limit: ' . implode(', ', $over_zones) : '')
                  . (!$unzoned && !$over_zones ? 'All loads sorted into zones' : '')],
        'locked' => ['ok' => in_array($v['manifest_status'], ['Locked', 'Submitted', 'Departed'], true),
            'detail' => 'Manifest status: ' . $v['manifest_status']],
        'crew' => ['ok' => (int)$v['total_crew'] > 0,
            'detail' => (int)$v['total_crew'] > 0 ? (int)$v['total_crew'] . ' crew on the crew list'
                : 'Enter the number of crew on the Manifest page first'],
        'maintenance' => ['ok' => !$v['under_maintenance'],
            'detail' => $v['under_maintenance'] ? 'Vessel is under maintenance' : 'Vessel operational'],
        'hazard' => ['ok' => !$hazard_bad && $unzoned_hazard === 0,
            'detail' => $L['hazard_count'] ? $L['hazard_count'] . ' hazardous item(s) declared' : 'No hazardous cargo'],
    ];
}

// ═════════════════════════════════════════════════════════════════════════
// Schedule generation (Voyages page + monthly CLI script)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Create voyages from schedule_template for each date in $dates (Y-m-d).
 * Skips vessels under maintenance on that date and trips that already exist.
 * Returns ['created'=>n, 'existing'=>n, 'maintenance'=>n].
 */
function generate_schedule(array $dates, ?int $user_id = null): array {
    $db = db();
    $tpl = $db->query("SELECT t.*, r.route_code, vsl.maintenance, vsl.maintenance_since, vsl.maintenance_until
        FROM schedule_template t
        JOIN route r    ON r.route_id = t.route_id AND r.is_active = 1
        JOIN vessel vsl ON vsl.vessel_id = t.vessel_id AND vsl.is_active = 1
        WHERE t.is_active = 1 ORDER BY t.departure_time")->fetch_all(MYSQLI_ASSOC);
    $res = ['created' => 0, 'existing' => 0, 'maintenance' => 0];
    $ins = $db->prepare("INSERT IGNORE INTO voyage (voyage_no, vessel_id, route_id, departure_date, departure_time, created_by)
                         VALUES (?,?,?,?,?,?)");
    foreach ($dates as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        foreach ($tpl as $t) {
            if (!in_array(date('N', strtotime($d)), explode(',', (string)$t['run_days']), true)) continue;   // template not running that weekday
            if (vessel_under_maintenance($t, $d)) { $res['maintenance']++; continue; }
            $vno = $t['route_code'] . '-' . str_replace('-', '', $d) . '-' . str_replace(':', '', substr($t['departure_time'], 0, 5)) . '-' . $t['vessel_id'];
            $ins->bind_param('siissi', $vno, $t['vessel_id'], $t['route_id'], $d, $t['departure_time'], $user_id);
            $ins->execute();
            $ins->affected_rows > 0 ? $res['created']++ : $res['existing']++;
        }
    }
    return $res;
}

// ═════════════════════════════════════════════════════════════════════════
// Online payment references — the same GCash / Maya / bank reference number
// cannot be used for two different bookings (prevents re-using one receipt).
// ═════════════════════════════════════════════════════════════════════════

function normalize_pay_ref(string $ref): string {
    return strtoupper(preg_replace('/[\s\-]+/', '', $ref));
}

/**
 * Where else is this reference number already used? Returns a short text
 * ("booking MNL-XXXXXX" / "OR OR-2026-000012") or null when it is free.
 * $own_booking_ref: the booking being paid (its own rows are ignored).
 */
function pay_ref_used_elsewhere(string $method, string $ref, ?string $own_booking_ref = null): ?string {
    $norm = normalize_pay_ref($ref);
    if ($norm === '') return null;
    $own  = $own_booking_ref ?? '';
    $db   = db();
    $st = $db->prepare("SELECT booking_ref FROM passenger
        WHERE online_pay_method = ? AND online_pay_status IN ('pending','verified')
          AND REPLACE(REPLACE(UPPER(online_pay_ref),' ',''),'-','') = ?
          AND COALESCE(booking_ref,'') <> ? LIMIT 1");
    $st->bind_param('sss', $method, $norm, $own); $st->execute();
    if ($r = $st->get_result()->fetch_row()) return 'booking ' . $r[0];
    $st = $db->prepare("SELECT pay.or_no FROM payment pay LEFT JOIN passenger p ON p.passenger_id = pay.passenger_id
        WHERE pay.is_voided = 0 AND pay.pay_method = ?
          AND REPLACE(REPLACE(UPPER(pay.reference_no),' ',''),'-','') = ?
          AND (p.booking_ref IS NULL OR p.booking_ref <> ?) LIMIT 1");
    $st->bind_param('sss', $method, $norm, $own); $st->execute();
    if ($r = $st->get_result()->fetch_row()) return 'official receipt ' . $r[0];
    return null;
}

// ═════════════════════════════════════════════════════════════════════════
// Passenger profiles (one row per real person; tickets link via profile_id)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Find or create the profile of a ticket's passenger, refresh it with the
 * ticket's latest details and link the ticket. Returns profile_id.
 * Match: same ID type + number, else same name + sex + mobile, else + e-mail.
 */
function link_passenger_profile(int $pid): int {
    $db = db();
    $st = $db->prepare('SELECT * FROM passenger WHERE passenger_id = ?');
    $st->bind_param('i', $pid); $st->execute();
    $p = $st->get_result()->fetch_assoc();
    if (!$p) return 0;
    if ($p['profile_id']) return (int)$p['profile_id'];

    $name = "LOWER(TRIM(last_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
             AND LOWER(TRIM(COALESCE(middle_name,''))) = LOWER(TRIM(?)) AND sex = ?";
    $mid  = (string)$p['middle_name'];
    $prof = 0;
    if ($p['id_type'] && $p['id_number']) {
        $st = $db->prepare("SELECT profile_id FROM passenger_profile WHERE id_type = ?
            AND REPLACE(REPLACE(UPPER(id_number),' ',''),'-','') = REPLACE(REPLACE(UPPER(?),' ',''),'-','') LIMIT 1");
        $st->bind_param('ss', $p['id_type'], $p['id_number']); $st->execute();
        $prof = (int)($st->get_result()->fetch_row()[0] ?? 0);
    }
    foreach (['contact_no', 'email'] as $k) {
        if ($prof || !$p[$k]) continue;
        $st = $db->prepare("SELECT profile_id FROM passenger_profile WHERE $name AND LOWER($k) = LOWER(?) LIMIT 1");
        $st->bind_param('sssss', $p['last_name'], $p['first_name'], $mid, $p['sex'], $p[$k]); $st->execute();
        $prof = (int)($st->get_result()->fetch_row()[0] ?? 0);
    }

    $when = $p['booked_at'] ?? date('Y-m-d H:i:s');
    if (!$prof) {
        $st = $db->prepare('INSERT INTO passenger_profile (last_name, first_name, middle_name, sex, age, nationality, pax_category,
                id_type, id_number, contact_no, email, address, emergency_contact)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->bind_param('ssssissssssss', $p['last_name'], $p['first_name'], $p['middle_name'], $p['sex'], $p['age'],
            $p['nationality'], $p['pax_category'], $p['id_type'], $p['id_number'], $p['contact_no'], $p['email'],
            $p['address'], $p['emergency_contact']);
        $st->execute();
        $prof = (int)$db->insert_id;
    } else {
        // Newer ticket details win; empty fields keep what the profile already has
        $st = $db->prepare('UPDATE passenger_profile SET age = ?, nationality = COALESCE(?, nationality), pax_category = ?,
                id_type = COALESCE(?, id_type), id_number = COALESCE(?, id_number), contact_no = COALESCE(?, contact_no),
                email = COALESCE(?, email), address = COALESCE(?, address), emergency_contact = COALESCE(?, emergency_contact)
            WHERE profile_id = ? AND (last_trip_at IS NULL OR last_trip_at <= ?)');
        $st->bind_param('issssssssis', $p['age'], $p['nationality'], $p['pax_category'], $p['id_type'], $p['id_number'],
            $p['contact_no'], $p['email'], $p['address'], $p['emergency_contact'], $prof, $when);
        $st->execute();
    }
    $st = $db->prepare('UPDATE passenger SET profile_id = ? WHERE passenger_id = ?');
    $st->bind_param('ii', $prof, $pid); $st->execute();
    $st = $db->prepare('UPDATE passenger_profile SET trips = trips + 1,
            last_trip_at = GREATEST(COALESCE(last_trip_at, ?), ?) WHERE profile_id = ?');
    $st->bind_param('ssi', $when, $when, $prof); $st->execute();
    return $prof;
}
