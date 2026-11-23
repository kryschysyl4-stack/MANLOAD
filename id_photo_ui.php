<?php
/**
 * Staff UI for the ID photo a passenger attached online:
 *   id_photo_cell($p)  — thumbnail button + check status, for a passenger row / detail card
 *   id_photo_modal()   — the viewer (large photo next to the booking details, Verify / Reject); print once per page
 * $p is a passenger row with p.* (id_photo, id_check, id_checked_by, id_checked_at, …).
 */
require_once __DIR__ . '/../config/auth.php';

/** Name of the staff user who checked an ID (cached per request). */
function id_checker_name(?int $uid): string {
    static $cache = [];
    if (!$uid) return '';
    if (!isset($cache[$uid])) {
        $st = db()->prepare('SELECT full_name FROM user WHERE user_id=?');
        $st->bind_param('i', $uid);
        $st->execute();
        $cache[$uid] = (string)($st->get_result()->fetch_row()[0] ?? '');
    }
    return $cache[$uid];
}

/** Badge for the ID check status (or the missing photo of a discounted passenger). */
function id_check_badge(array $p): string {
    $disc = in_array($p['pax_category'] ?? '', DISCOUNTED_CATEGORIES, true);
    if (empty($p['id_photo'])) return $disc && ($p['booked_via'] ?? '') === 'Online' ? '<span class="badge bg-warning text-dark">no ID photo</span>' : '';
    $who = id_checker_name(isset($p['id_checked_by']) ? (int)$p['id_checked_by'] : null);
    $when = !empty($p['id_checked_at']) ? date('M j, g:i A', strtotime($p['id_checked_at'])) : '';
    $tip = e(trim("$who · $when", ' ·'));
    return match ($p['id_check'] ?? null) {
        'Verified' => '<span class="badge bg-success" title="' . $tip . '"><i class="bi bi-check-circle-fill me-1"></i>ID verified</span>',
        'Rejected' => '<span class="badge bg-danger" title="' . $tip . '"><i class="bi bi-x-circle-fill me-1"></i>ID rejected</span>',
        default    => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>verify ID</span>',
    };
}

/** Thumbnail that opens the viewer, plus the status badge. Empty string when there is nothing to show. */
function id_photo_cell(array $p, string $size = 'sm'): string {
    $badge = id_check_badge($p);
    if (empty($p['id_photo'])) return $badge;
    $pid = (int)$p['passenger_id'];
    $url = BASE_URL . '/modules/checkin/id_photo.php?pid=' . $pid;
    $dob = !empty($p['date_of_birth']) ? date('M j, Y', strtotime($p['date_of_birth'])) : '';
    $data = [
        'pid' => $pid, 'src' => $url, 'name' => pax_display_name($p), 'ticket' => $p['ticket_no'] ?? '',
        'age' => (string)(int)($p['age'] ?? 0) . ($dob ? " · born $dob" : '') . ' · ' . ($p['sex'] ?? ''),
        'cat' => $p['pax_category'] ?? '', 'idtype' => trim(($p['id_type'] ?? '') . ' ' . ($p['id_number'] ?? '')) ?: '—',
        'check' => $p['id_check'] ?? '', 'checked' => strip_tags(id_check_badge($p)) . (!empty($p['id_checked_by']) ? ' by ' . id_checker_name((int)$p['id_checked_by']) : ''),
    ];
    $attr = '';
    foreach ($data as $k => $v) $attr .= ' data-' . $k . '="' . e((string)$v) . '"';
    $w = $size === 'lg' ? 160 : 56; $h = $size === 'lg' ? 100 : 36;
    return '<button type="button" class="idp-thumb"' . $attr . ' title="Check the ID photo">'
         . '<img src="' . e($url) . '" alt="ID photo of ' . e($data['name']) . '" loading="lazy" style="width:' . $w . 'px;height:' . $h . 'px">'
         . '<span><i class="bi bi-person-vcard"></i> ID photo</span></button> ' . $badge;
}

/** The viewer modal + its script. Print once, anywhere after id_photo_cell() is used. */
function id_photo_modal(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    $back = e($_SERVER['REQUEST_URI'] ?? '');
    $csrf = csrf_field();
    $can  = is_role(...ROLE_CHECKIN);
    $act  = BASE_URL . '/modules/checkin/id_verify.php';
    ob_start(); ?>
<style>
  .idp-thumb { display:inline-flex; align-items:center; gap:.4rem; padding:2px 8px 2px 2px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#1e3a8a; font-size:.75rem; font-weight:600; vertical-align:middle; }
  .idp-thumb:hover { border-color:#2563eb; background:#eff6ff; }
  .idp-thumb img { object-fit:cover; border-radius:6px; background:#f1f5f9; }
  #idpModal .idp-img { width:100%; max-height:70vh; object-fit:contain; background:#0f172a; border-radius:8px; cursor:zoom-in; }
  #idpModal .idp-img.zoom { max-height:none; cursor:zoom-out; }
  #idpModal dt { font-size:.7rem; text-transform:uppercase; color:#64748b; font-weight:700; margin-top:.6rem; }
  #idpModal dd { margin:0; font-weight:600; }
</style>
<div class="modal fade" id="idpModal" tabindex="-1" aria-labelledby="idpTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="idpTitle"><i class="bi bi-person-vcard me-2"></i>Check ID photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-8"><img class="idp-img" id="idpImg" alt="ID photo"><div class="small text-muted mt-1">Click the photo to zoom. <a id="idpOpen" target="_blank" rel="noopener">Open full size</a></div></div>
          <div class="col-lg-4">
            <div class="small text-muted">Compare the ID with the booking:</div>
            <dl class="mb-3">
              <dt>Name on booking</dt><dd id="idpName"></dd>
              <dt>Age / birthday / sex</dt><dd id="idpAge"></dd>
              <dt>Fare type</dt><dd id="idpCat"></dd>
              <dt>ID type / number given</dt><dd id="idpIdtype"></dd>
              <dt>Ticket</dt><dd id="idpTicket"></dd>
              <dt>Status</dt><dd id="idpStatus"></dd>
            </dl>
            <?php if ($can): ?>
            <form method="post" action="<?= e($act) ?>" class="d-grid gap-2" id="idpForm">
              <?= $csrf ?>
              <input type="hidden" name="pid" id="idpPid"><input type="hidden" name="back" value="<?= $back ?>">
              <button class="btn btn-success fw-bold" name="decision" value="Verified"><i class="bi bi-check-circle me-1"></i>ID matches — Verified</button>
              <button class="btn btn-outline-danger" name="decision" value="Rejected"><i class="bi bi-x-circle me-1"></i>Reject (not valid / does not match)</button>
              <button class="btn btn-link btn-sm text-muted" name="decision" value="Clear" id="idpClear">Clear the check</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', e => {
  const b = e.target.closest('.idp-thumb');
  if (!b) return;
  const d = b.dataset, $ = id => document.getElementById(id), img = $('idpImg');
  img.classList.remove('zoom'); img.src = d.src; $('idpOpen').href = d.src;
  $('idpName').textContent = d.name; $('idpAge').textContent = d.age; $('idpCat').textContent = d.cat;
  $('idpIdtype').textContent = d.idtype; $('idpTicket').textContent = d.ticket; $('idpStatus').textContent = d.checked;
  if ($('idpPid')) { $('idpPid').value = d.pid; $('idpClear').hidden = !d.check; }
  bootstrap.Modal.getOrCreateInstance($('idpModal')).show();
});
document.getElementById('idpImg').addEventListener('click', e => e.target.classList.toggle('zoom'));
</script>
<?php
    return ob_get_clean();
}
