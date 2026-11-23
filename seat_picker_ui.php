<?php
/**
 * Seat picker for check-in: the counter can choose any vacant seat of the passenger's accommodation.
 *   seat_pick_button($p, $input_name)  — button showing the seat + hidden input ('' = automatic balanced seat)
 *   seat_picker_modal($voyage, $pids)  — the seat map dialog; print once per page. $pids = passengers being
 *                                        checked in on this page (their own current seats count as free for them).
 * The server re-checks the seat (free + right class) in checkin_confirm_booked().
 */
require_once __DIR__ . '/seats.php';

function seat_pick_button(array $p, string $input_name): string {
    $pid = (int)$p['passenger_id'];
    $cur = (string)($p['seat_no'] ?? '');
    return '<input type="hidden" class="js-seat-in" name="' . e($input_name) . '" value="" data-pid="' . $pid . '">'
         . '<button type="button" class="btn btn-sm btn-outline-primary seat-pick" data-pid="' . $pid . '" data-cls="' . e($p['class']) . '"'
         . ' data-cur="' . e($cur) . '" data-name="' . e(pax_display_name($p)) . '" title="Choose the seat">'
         . '<i class="bi bi-grid-3x3-gap me-1"></i><span class="js-seat-lbl">' . ($cur !== '' ? 'Seat ' . e($cur) : 'Auto seat') . '</span></button>';
}

function seat_picker_modal(array $voyage, array $pids): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    $vid = (int)$voyage['voyage_id'];
    $L   = seat_layout((int)$voyage['vessel_id']);
    // occupied seats, except the passengers being checked in here (they may keep or change their own seat)
    $occ = seat_occupancy($vid);
    $mine = [];
    if ($pids) {
        $in = implode(',', array_map('intval', $pids));
        foreach (db()->query("SELECT passenger_id, seat_no FROM passenger WHERE passenger_id IN ($in) AND seat_no IS NOT NULL AND seat_no<>''")->fetch_all() as [$id, $s]) {
            unset($occ[$s]); $mine[(int)$id] = $s;
        }
    }
    $cfg = [
        'layout' => $L,                                   // null = vessel without a seat map: plain numbers 1..max
        'max'    => (int)$voyage['max_passengers'],
        'occ'    => (object)$occ,
        'cols'   => SEAT_COLS,
        'occUrl' => BASE_URL . '/modules/checkin/seat_occupancy.php?vid=' . $vid . '&mine=' . implode(',', array_map('intval', $pids)),
    ];
    ob_start(); ?>
<style>
  .seat-pick { white-space: nowrap; }
  .seat-pick.chosen { background: #f59e0b; border-color: #d97706; color: #fff; }
  .seat-cell.other, .seat-key.other { background: #fde68a; border-color: #f59e0b; color: #92400e; }
  .seat-numgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(44px, 1fr)); gap: 4px; padding: .8rem; background: #f8fafc; }
</style>
<div class="modal fade" id="seatPickModal" tabindex="-1" aria-labelledby="seatPickTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="seatPickTitle"><i class="bi bi-grid-3x3-gap me-2"></i>Choose seat — <span id="spName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="small mb-2 d-flex flex-wrap gap-2 align-items-center">
          <span class="seat-key">Vacant</span><span class="seat-key taken-f">Taken (Female)</span><span class="seat-key taken-m">Taken (Male)</span>
          <span class="seat-key other">Picked for another passenger here</span><span class="seat-key sel">This passenger</span>
        </div>
        <div id="spBody"></div>
        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i><span id="spHint"></span></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary me-auto" id="spAuto"><i class="bi bi-magic me-1"></i>Automatic (balanced)</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
  const SP = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let btn = null;   // the passenger's seat button being edited
  const inputOf = b => document.querySelector('.js-seat-in[data-pid="' + b.dataset.pid + '"]');
  // seat each passenger on this page will get: picked one, else the one already booked
  const seatOf = b => inputOf(b).value || b.dataset.cur || '';
  const pickedByOthers = () => {
    const m = {};
    document.querySelectorAll('.seat-pick').forEach(b => { if (b !== btn && seatOf(b)) m[seatOf(b)] = b.dataset.name; });
    return m;
  };
  function cell(code, cls) {
    const others = pickedByOthers(), taken = SP.occ[code], mine = seatOf(btn) === code;
    let c = '', dis = false, tip = code;
    if (mine) c = 'sel';
    else if (others[code]) { c = 'other'; dis = true; tip += ' — ' + others[code]; }
    else if (taken !== undefined) { c = taken === 'Female' ? 'taken-f' : 'taken-m'; dis = true; tip += ' — taken'; }
    return '<button type="button" class="seat-cell ' + c + '" data-seat="' + code + '"' + (dis ? ' disabled' : '') + ' title="' + esc(tip) + '">' + code + '</button>';
  }
  function render() {
    const cls = btn.dataset.cls;
    let html = '';
    if (SP.layout && SP.layout.classes[cls]) {
      const C = SP.layout.classes[cls];
      html = '<div class="seat-sec"><div class="seat-head"><span><i class="bi bi-record-circle me-1"></i>' + esc(cls) + '</span></div><div class="seat-grid"><div class="seat-bow">BOW</div>';
      for (let r = C.first_row; r <= C.last_row; r++) {
        html += '<div class="seat-col">';
        SP.cols.forEach(c => {
          if (c === 'D') html += '<div class="seat-aisle"></div>';
          html += SP.layout.seats[c + r] ? cell(c + r, cls) : '<div class="seat-cell seat-none"></div>';
        });
        html += '</div>';
      }
      html += '</div></div>';
      $('spHint').textContent = 'BOW (front) is on the left. A–C = left side, D–F = right side. Only ' + cls + ' seats are shown.';
    } else {
      html = '<div class="seat-sec"><div class="seat-head"><span>Seat numbers</span></div><div class="seat-numgrid">';
      for (let i = 1; i <= SP.max; i++) html += cell(String(i), cls);
      html += '</div></div>';
      $('spHint').textContent = 'This vessel has no seat map — seats are plain numbers.';
    }
    $('spBody').innerHTML = html;
  }
  function setSeat(b, code) {
    inputOf(b).value = code;
    const s = seatOf(b);
    b.querySelector('.js-seat-lbl').textContent = s ? 'Seat ' + s : 'Auto seat';
    b.classList.toggle('chosen', !!code);
  }
  // Taken seats change while the page is open (other counters, online bookings): reload them when the
  // map opens and every few seconds while it is open; a picked seat someone else took goes back to automatic.
  let occTimer = null;
  async function syncOcc() {
    try {
      const r = await fetch(SP.occUrl, {cache: 'no-store', credentials: 'same-origin'});
      if (!r.ok) return;
      SP.occ = (await r.json()).occ || {};
    } catch (e) { return; }
    const lost = [];
    document.querySelectorAll('.seat-pick').forEach(b => { const v = inputOf(b).value; if (v && SP.occ[v] !== undefined) { lost.push(v); setSeat(b, ''); } });
    if (lost.length) alert('Seat ' + lost.join(', ') + (lost.length > 1 ? ' were' : ' was') + ' just taken by another booking — please choose another seat (or leave it on Auto seat).');
    if (btn && $('seatPickModal').classList.contains('show')) render();
  }
  $('seatPickModal').addEventListener('shown.bs.modal', () => { clearInterval(occTimer); occTimer = setInterval(syncOcc, 8000); });
  $('seatPickModal').addEventListener('hidden.bs.modal', () => clearInterval(occTimer));
  document.addEventListener('click', e => {
    const b = e.target.closest('.seat-pick');
    if (!b) return;
    btn = b; $('spName').textContent = b.dataset.name; render();
    bootstrap.Modal.getOrCreateInstance($('seatPickModal')).show();
    syncOcc();
  });
  $('spBody').addEventListener('click', e => {
    const c = e.target.closest('.seat-cell[data-seat]');
    if (!c || c.disabled || !btn) return;
    setSeat(btn, c.dataset.seat);
    bootstrap.Modal.getOrCreateInstance($('seatPickModal')).hide();
  });
  $('spAuto').addEventListener('click', () => { if (btn) setSeat(btn, ''); bootstrap.Modal.getOrCreateInstance($('seatPickModal')).hide(); });
})();
</script>
<?php
    return ob_get_clean();
}
