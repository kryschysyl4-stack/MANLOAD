<?php
/**
 * Date carousel shared by Load Monitor, Load Plan and Boarding (via trip_picker.php).
 * Needs $day (Y-m-d). Links go to ?date=… on the current page.
 * Shows 3 days before and after $day with the number of trips on each, ‹ › arrows, a date picker and "Today".
 */
$_lm_today = date('Y-m-d');
$_lm_from  = date('Y-m-d', strtotime($day . ' -3 days'));
$_lm_to    = date('Y-m-d', strtotime($day . ' +3 days'));
$_lm_st = db()->prepare("SELECT departure_date, COUNT(*) FROM voyage
    WHERE archived = 0 AND manifest_status <> 'Cancelled' AND departure_date BETWEEN ? AND ? GROUP BY departure_date");
$_lm_st->bind_param('ss', $_lm_from, $_lm_to); $_lm_st->execute();
$_lm_count = array_column($_lm_st->get_result()->fetch_all(), 1, 0);
?>
<div class="lm-dates mb-3 no-print">
    <a class="lm-arrow" href="?date=<?= e(date('Y-m-d', strtotime($day . ' -1 day'))) ?>" aria-label="Previous day"><i class="bi bi-chevron-left"></i></a>
    <div class="lm-days">
    <?php for ($d = $_lm_from; $d <= $_lm_to; $d = date('Y-m-d', strtotime($d . ' +1 day'))): $n = (int)($_lm_count[$d] ?? 0); ?>
        <a class="lm-day<?= $d === $day ? ' on' : '' ?><?= $d < $_lm_today ? ' past' : '' ?>" href="?date=<?= e($d) ?>"<?= $d === $day ? ' aria-current="date"' : '' ?>>
            <span class="w"><?= $d === $_lm_today ? 'TODAY' : e(strtoupper(date('D', strtotime($d)))) ?></span>
            <span class="n"><?= e(date('M j', strtotime($d))) ?></span>
            <span class="c"><?= $n ?> trip<?= $n === 1 ? '' : 's' ?></span>
        </a>
    <?php endfor; ?>
    </div>
    <a class="lm-arrow" href="?date=<?= e(date('Y-m-d', strtotime($day . ' +1 day'))) ?>" aria-label="Next day"><i class="bi bi-chevron-right"></i></a>
    <form method="get" class="lm-pick">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($day) ?>" onchange="this.form.submit()" aria-label="Pick a date">
        <?php if ($day !== $_lm_today): ?><a class="btn btn-sm btn-outline-primary" href="?date=<?= e($_lm_today) ?>">Today</a><?php endif; ?>
    </form>
</div>
