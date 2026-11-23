<?php
/**
 * Helpers shared by the Load Plan, Manifest, Coast Guard and public
 * documents pages. Requires includes/functions.php (loaded by config/auth.php).
 */
require_once __DIR__ . '/functions.php';

/** "DELA CRUZ, Juan Santos" */
function pax_full_name(array $p): string {
    $n = mb_strtoupper(trim((string)$p['last_name'])) . ', ' . trim((string)$p['first_name']);
    if (!empty($p['middle_name'])) $n .= ' ' . trim((string)$p['middle_name']);
    return $n;
}

/** May the current user sort / move / add / delete loads? */
function can_edit_loads(): bool {
    return function_exists('is_role') && is_role('Admin');
}

/**
 * All vehicles and cargo of a voyage as one list, heaviest first.
 * Each item: kind, id, ref, desc, type, kg, zone_id, hazard, is_paid, freight
 */
function voyage_load_items(int $vid): array {
    $db = db();
    $items = [];
    $st = $db->prepare("SELECT vh.vehicle_id, vh.plate_no, vh.make_model, vh.color, vh.owner_name, vh.zone_id,
            vh.is_paid, vh.freight_amount, vt.type_name, COALESCE(vh.declared_weight_kg, vt.weight_kg) AS kg
        FROM vehicle vh JOIN vehicle_type vt ON vt.type_id = vh.vehicle_type_id WHERE vh.voyage_id = ?");
    $st->bind_param('i', $vid);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'kind' => 'vehicle', 'id' => (int)$r['vehicle_id'], 'ref' => $r['plate_no'],
            'desc' => trim($r['type_name'] . ($r['make_model'] ? ' · ' . $r['make_model'] : '') . ($r['color'] ? ' (' . $r['color'] . ')' : '')),
            'type' => $r['type_name'], 'owner' => $r['owner_name'], 'kg' => (float)$r['kg'],
            'zone_id' => $r['zone_id'] !== null ? (int)$r['zone_id'] : null, 'hazard' => false,
            'is_paid' => (int)$r['is_paid'], 'freight' => (float)$r['freight_amount'], 'qty' => 1,
        ];
    }
    $st = $db->prepare("SELECT cargo_id, waybill_no, cargo_description, cargo_type, quantity, gross_weight, zone_id,
            is_paid, freight_amount, shipper_name, consignee_name
        FROM cargo WHERE voyage_id = ?");
    $st->bind_param('i', $vid);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'kind' => 'cargo', 'id' => (int)$r['cargo_id'], 'ref' => $r['waybill_no'],
            'desc' => $r['cargo_description'], 'type' => $r['cargo_type'], 'owner' => $r['shipper_name'],
            'consignee' => $r['consignee_name'], 'kg' => (float)$r['gross_weight'],
            'zone_id' => $r['zone_id'] !== null ? (int)$r['zone_id'] : null,
            'hazard' => $r['cargo_type'] === 'Hazardous', 'is_paid' => (int)$r['is_paid'],
            'freight' => (float)$r['freight_amount'], 'qty' => (int)$r['quantity'],
        ];
    }
    usort($items, fn($a, $b) => $b['kg'] <=> $a['kg']);
    return $items;
}

/** Can this item go into this zone (use / hazard only, not capacity)? */
function zone_accepts(array $z, string $kind, bool $hazard): bool {
    $ok_use = $kind === 'vehicle' ? in_array($z['zone_use'], ['Vehicle', 'Mixed'], true)
                                  : in_array($z['zone_use'], ['Cargo', 'Mixed'], true);
    return $ok_use && (!$hazard || (int)$z['allows_hazard'] === 1);
}

/**
 * Validate moving one load item to a zone. $zone_id = 0 means "unassign".
 * Returns null when OK, else the reason. Call inside the transaction.
 */
function zone_move_error(int $vid, string $kind, int $item_id, int $zone_id): ?string {
    $db = db();
    if ($kind === 'vehicle') {
        $st = $db->prepare("SELECT vh.zone_id, COALESCE(vh.declared_weight_kg, vt.weight_kg) AS kg, 0 AS hazard
            FROM vehicle vh JOIN vehicle_type vt ON vt.type_id = vh.vehicle_type_id
            WHERE vh.vehicle_id = ? AND vh.voyage_id = ? FOR UPDATE");
    } elseif ($kind === 'cargo') {
        $st = $db->prepare("SELECT zone_id, gross_weight AS kg, cargo_type = 'Hazardous' AS hazard
            FROM cargo WHERE cargo_id = ? AND voyage_id = ? FOR UPDATE");
    } else {
        return 'Unknown item.';
    }
    $st->bind_param('ii', $item_id, $vid);
    $st->execute();
    $it = $st->get_result()->fetch_assoc();
    if (!$it) return 'Item not found on this voyage.';
    if ($zone_id === 0) return null;
    if ((int)$it['zone_id'] === $zone_id) return 'The item is already in that zone.';

    $zone = null;
    foreach (voyage_zones($vid) as $z) if ((int)$z['zone_id'] === $zone_id) $zone = $z;
    if (!$zone) return 'That zone does not belong to this vessel.';
    if (!zone_accepts($zone, $kind, (bool)$it['hazard'])) {
        return $it['hazard'] && !$zone['allows_hazard']
            ? "Zone {$zone['zone_code']} does not allow hazardous cargo."
            : "Zone {$zone['zone_code']} is a {$zone['zone_use']} zone — it cannot take this " . ($kind === 'vehicle' ? 'vehicle' : 'cargo') . '.';
    }
    $kgv = (float)$it['kg'];
    if ($zone['max_kg'] > 0 && $zone['used_kg'] + $kgv > $zone['max_kg']) {
        return "Zone {$zone['zone_code']} would exceed its limit: " . kg($zone['used_kg'] + $kgv) . ' of ' . kg($zone['max_kg']) . ' allowed.';
    }
    if ($kind === 'vehicle' && $zone['max_vehicles'] > 0 && $zone['vehicle_count'] + 1 > $zone['max_vehicles']) {
        return "Zone {$zone['zone_code']} has no vehicle slot left ({$zone['max_vehicles']} max).";
    }
    return null;
}

/** Latest valid certificate of a voyage, or null. */
function latest_clearance(int $vid): ?array {
    $st = db()->prepare("SELECT dc.*, u.full_name AS issued_by_name FROM departure_clearance dc
        LEFT JOIN user u ON u.user_id = dc.issued_by
        WHERE dc.voyage_id = ? AND dc.is_cleared = 1 ORDER BY dc.clearance_id DESC LIMIT 1");
    $st->bind_param('i', $vid);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/**
 * The PCG checklist evaluated for a voyage.
 * $ticked = doc_ids of manual items the inspector ticked.
 * Returns ['rows'=>[...], 'all_ok'=>bool, 'missing'=>[labels]]
 */
function clearance_checklist(int $vid, array $ticked = []): array {
    $docs = db()->query("SELECT * FROM clearance_document WHERE is_active = 1 ORDER BY sort_order, doc_id")->fetch_all(MYSQLI_ASSOC);
    $auto = clearance_auto_checks($vid);
    $ticked = array_map('intval', $ticked);
    $rows = []; $missing = [];
    foreach ($docs as $d) {
        if ($d['is_auto'] !== null && $d['is_auto'] !== '') {
            $a = $auto[$d['is_auto']] ?? ['ok' => false, 'detail' => 'Unknown automatic check "' . $d['is_auto'] . '"'];
            $ok = (bool)$a['ok']; $detail = trim($a['detail']);
        } else {
            $ok = in_array((int)$d['doc_id'], $ticked, true); $detail = '';
        }
        $rows[] = ['doc_id' => (int)$d['doc_id'], 'label' => $d['label'], 'description' => $d['description'],
                   'required' => (int)$d['is_required'], 'auto' => $d['is_auto'], 'ok' => $ok, 'detail' => $detail];
        if ($d['is_required'] && !$ok) $missing[] = $d['label'];
    }
    return ['rows' => $rows, 'all_ok' => !$missing, 'missing' => $missing];
}

/** CSV cell safe against spreadsheet formula injection. */
function csv_safe($v): string {
    $v = (string)($v ?? '');
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) $v = "'" . $v;
    return $v;
}
