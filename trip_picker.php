<?php
/**
 * Trip picker: date carousel + that day's trips as buttons (Load Plan, Boarding, …).
 *   [$day, $trips, $vid, $v] = trip_pick($vid, $v);   // before any output
 *   trip_pick_render($day, $trips, $vid);              // where the picker goes
 * ?date= chooses the day (default: the selected trip's day, else today); ?vid= a trip on it.
 * A GET without a trip on that day selects the day's first trip.
 */

function trip_pick(int $vid, ?array $v): array {
    $day = str_param($_GET['date'] ?? '');
    $dt  = DateTime::createFromFormat('!Y-m-d', $day);
    if (!$dt || $dt->format('Y-m-d') !== $day) $day = $v ? $v['departure_date'] : date('Y-m-d');

    $st = db()->prepare("SELECT v.voyage_id, v.departure_time, v.manifest_status, v.ready_to_board, s.vessel_name, po.port_code o, pd.port_code d
        FROM voyage v JOIN vessel s ON s.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        WHERE v.archived=0 AND v.manifest_status<>'Cancelled' AND v.departure_date=? ORDER BY v.departure_time, s.vessel_name");
    $st->bind_param('s', $day); $st->execute();
    $trips = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!$v || $v['departure_date'] !== $day)) {
        $vid = $trips ? (int)$trips[0]['voyage_id'] : 0;
        $v   = $vid ? get_voyage($vid) : null;
        if ($vid) $_SESSION['active_voyage_id'] = $vid;
    }
    return [$day, $trips, $vid, $v];
}

/**
 * Date carousel where clicking a date drops down that day's voyages (Check-in).
 * Same 7-day window, arrows, date picker and "Today" as date_strip.php.
 */
function trip_pick_render_dropdown(string $day, int $vid): void {
    $today = date('Y-m-d');
    $from  = date('Y-m-d', strtotime($day . ' -3 days'));
    $to    = date('Y-m-d', strtotime($day . ' +3 days'));
    $st = db()->prepare("SELECT v.voyage_id, v.departure_date, v.departure_time, v.manifest_status, v.ready_to_board, s.vessel_name, po.port_code o, pd.port_code d
        FROM voyage v JOIN vessel s ON s.vessel_id=v.vessel_id JOIN route r ON r.route_id=v.route_id
        JOIN port po ON po.port_id=r.origin_port_id JOIN port pd ON pd.port_id=r.destination_port_id
        WHERE v.archived=0 AND v.manifest_status<>'Cancelled' AND v.departure_date BETWEEN ? AND ? ORDER BY v.departure_time, s.vessel_name");
    $st->bind_param('ss', $from, $to); $st->execute();
    $by_day = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $by_day[$t['departure_date']][] = $t;
    $pc = e(json_encode(['strategy' => 'fixed']));   // keep the menu from being clipped by the scrolling strip
    ?>
<div class="lm-dates mb-3 no-print">
    <a class="lm-arrow" href="?date=<?= e(date('Y-m-d', strtotime($day . ' -1 day'))) ?>" aria-label="Previous day"><i class="bi bi-chevron-left"></i></a>
    <div class="lm-days">
    <?php for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))): $trips = $by_day[$d] ?? []; $n = count($trips); ?>
        <div class="dropdown lm-day-dd">
            <button type="button" class="lm-day w-100<?= $d === $day ? ' on' : '' ?><?= $d < $today ? ' past' : '' ?>" data-bs-toggle="dropdown" data-bs-popper-config="<?= $pc ?>" aria-expanded="false"<?= $d === $day ? ' aria-current="date"' : '' ?>>
                <span class="w"><?= $d === $today ? 'TODAY' : e(strtoupper(date('D', strtotime($d)))) ?></span>
                <span class="n"><?= e(date('M j', strtotime($d))) ?></span>
                <span class="c"><?= $n ?> trip<?= $n === 1 ? '' : 's' ?> <i class="bi bi-chevron-down"></i></span>
            </button>
            <ul class="dropdown-menu shadow-sm">
                <li><h6 class="dropdown-header"><?= e(date('l, M j Y', strtotime($d))) ?></h6></li>
                <?php if (!$trips): ?><li><span class="dropdown-item-text small text-muted">No voyages on this day</span></li><?php endif; ?>
                <?php $last = null;
                foreach ($trips as $t): $on = (int)$t['voyage_id'] === $vid; $tm = date('g:i A', strtotime($t['departure_time'])); ?>
                <?php if ($tm !== $last): $last = $tm; ?>
                <li><div class="lm-dd-time"><i class="bi bi-clock me-1"></i><?= e($tm) ?></div></li>
                <?php endif; ?>
                <li><a class="dropdown-item small ps-4<?= $on ? ' active' : '' ?>" href="?date=<?= e($d) ?>&amp;vid=<?= (int)$t['voyage_id'] ?>">
                    <?= e($t['o'] . ' → ' . $t['d']) ?> · <?= e($t['vessel_name']) ?>
                    <span class="<?= $on ? '' : 'text-muted' ?>"> · <?= $t['ready_to_board'] ? 'Ready to board' : e($t['manifest_status']) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endfor; ?>
    </div>
    <a class="lm-arrow" href="?date=<?= e(date('Y-m-d', strtotime($day . ' +1 day'))) ?>" aria-label="Next day"><i class="bi bi-chevron-right"></i></a>
    <form method="get" class="lm-pick">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($day) ?>" onchange="this.form.submit()" aria-label="Pick a date">
        <?php if ($day !== $today): ?><a class="btn btn-sm btn-outline-primary" href="?date=<?= e($today) ?>">Today</a><?php endif; ?>
    </form>
</div>
    <?php
}

/**
 * Date row (last 7 days → next 14) with every day's trips as buttons underneath (Manifest, Boarding).
 * Tapping a date shows its trips at once (no reload, no dropdown); tapping a trip opens it (?vid=).
 */
function trip_pick_days(int $vid, ?array $v): void {
    $options = db()->query(VOYAGE_SQL . " WHERE v.archived=0 AND v.manifest_status <> 'Cancelled'
        AND v.departure_date BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() + INTERVAL 14 DAY
        ORDER BY v.departure_date, v.departure_time LIMIT 300")->fetch_all(MYSQLI_ASSOC);
    if ($v && !in_array($vid, array_map('intval', array_column($options, 'voyage_id')), true)) $options[] = $v;
    $days = [];
    foreach ($options as $o) $days[$o['departure_date']][] = $o;
    ksort($days);
    $today = date('Y-m-d'); $tmrw = date('Y-m-d', strtotime('+1 day'));
    $pick  = $v['departure_date'] ?? (isset($days[$today]) ? $today : (array_key_first($days) ?? ''));
    $tone  = ['Open' => 'success', 'In Progress' => 'primary', 'Locked' => 'warning', 'Submitted' => 'warning', 'Departed' => 'secondary'];
    ?>
<div class="mf-pick card card-manload mb-3 no-print">
    <div class="mf-days">
        <?php if (!$days): ?><span class="text-muted small p-2">No voyages</span><?php endif; ?>
        <?php foreach ($days as $d => $list): ?>
        <button type="button" class="mf-day<?= $d === $pick ? ' active' : '' ?>" data-day="<?= e($d) ?>">
            <span class="mf-dow"><?= e($d === $today ? 'Today' : ($d === $tmrw ? 'Tomorrow' : date('D', strtotime($d)))) ?></span>
            <span class="mf-dnum"><?= e(date('M j', strtotime($d))) ?></span>
            <span class="mf-cnt"><?= count($list) ?> trip<?= count($list) > 1 ? 's' : '' ?></span></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($days as $d => $list): ?>
    <div class="mf-trips" data-day="<?= e($d) ?>"<?= $d === $pick ? '' : ' hidden' ?>>
        <?php foreach ($list as $o): ?>
        <a class="mf-trip<?= (int)$o['voyage_id'] === $vid ? ' active' : '' ?>" href="?vid=<?= (int)$o['voyage_id'] ?>">
            <span class="mf-time"><?= e(date('g:i A', strtotime($o['departure_time']))) ?></span>
            <span class="mf-route"><?= e($o['orig'] . ' → ' . $o['dest']) ?></span>
            <span class="mf-vsl"><?= e($o['vessel_name']) ?></span>
            <span class="badge bg-<?= $o['ready_to_board'] ? 'success' : ($tone[$o['manifest_status']] ?? 'secondary') ?>"><?= $o['ready_to_board'] ? 'Ready' : e($o['manifest_status']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<script>
(() => {
    const pick = document.currentScript.previousElementSibling;
    pick.querySelectorAll('.mf-day').forEach(b => b.addEventListener('click', () => {
        pick.querySelectorAll('.mf-day').forEach(x => x.classList.toggle('active', x === b));
        pick.querySelectorAll('.mf-trips').forEach(t => t.hidden = t.dataset.day !== b.dataset.day);
    }));
    pick.querySelector('.mf-day.active')?.scrollIntoView({ block: 'nearest', inline: 'center' });
})();
</script>
    <?php
}

function trip_pick_render(string $day, array $trips, int $vid): void {
    include __DIR__ . '/date_strip.php';
    if (!$trips) {
        echo '<div class="alert alert-info no-print">No voyages on ' . e(date('l, M d Y', strtotime($day))) . '. Pick another day above.</div>';
        return;
    }
    echo '<div class="lp-trips mb-3 no-print">';
    foreach ($trips as $t) {
        $on = (int)$t['voyage_id'] === $vid;
        echo '<a class="lp-trip' . ($on ? ' on' : '') . '" href="?date=' . e($day) . '&amp;vid=' . (int)$t['voyage_id'] . '"' . ($on ? ' aria-current="true"' : '') . '>'
           . '<span class="t">' . e(date('g:i A', strtotime($t['departure_time']))) . '</span>'
           . '<span class="v">' . e($t['vessel_name']) . '</span>'
           . '<span class="r">' . e($t['o'] . ' → ' . $t['d']) . ' · ' . ($t['ready_to_board'] ? '<i class="bi bi-patch-check-fill"></i> Ready' : e($t['manifest_status'])) . '</span></a>';
    }
    echo '</div>';
}
