<?php
/**
 * Draws the PASSENGER'S COPY of the boarding pass as a PNG picture (with the QR code).
 * It is attached to the "boarding pass backup" e-mail so the passenger can save it to
 * the phone's gallery — if the printed ticket is lost, the gangway staff can scan the
 * QR straight from the phone screen.
 *
 * Needs PHP GD with FreeType (on in XAMPP). Returns null when it is not available.
 * $p = a row from pax_full_by().
 */
require_once __DIR__ . '/functions.php';

function boarding_pass_png(array $p, string $qr_text): ?string {
    if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) return null;
    $qr = qr_png($qr_text, 5);
    if (!$qr) return null;

    $F  = __DIR__ . '/../lib/fonts/';
    $R  = $F . 'DejaVuSans.ttf';
    $B  = $F . 'DejaVuSans-Bold.ttf';
    $M  = $F . 'DejaVuSansMono-Bold.ttf';
    if (!is_file($R) || !is_file($B) || !is_file($M)) return null;

    $W = 1240; $H = 640;
    $im = imagecreatetruecolor($W, $H);
    imageantialias($im, true);
    $c = fn(string $hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $white = $c('#ffffff'); $navy = $c('#0f2d6b'); $blue = $c('#1d4ed8'); $ink = $c('#0f172a');
    $grey = $c('#64748b'); $line = $c('#cbd5e1'); $amber = $c('#fef3c7'); $brown = $c('#78350f');
    $red = $c('#dc2626');
    imagefilledrectangle($im, 0, 0, $W, $H, $white);

    // text helper: draws $s at (x, baseline y), shrinking the font until it fits $maxw
    $txt = function (string $s, float $size, int $x, int $y, $col, string $font, int $maxw = 0, bool $right = false) use ($im) {
        while (true) {
            $bb = imagettfbbox($size, 0, $font, $s);
            $w  = $bb[2] - $bb[0];
            if (!$maxw || $w <= $maxw || $size <= 9) break;
            $size -= 1;
        }
        imagettftext($im, $size, 0, $right ? $x - $w : $x, $y, $col, $font, $s);
        return $w;
    };

    $dep    = strtotime($p['departure_date'] . ' ' . $p['departure_time']);
    $valid  = $p['ticket_valid_until'] ? date('M d, Y g:i A', strtotime($p['ticket_valid_until'])) : '—';
    $paytxt = (float)$p['fare'] <= 0 ? 'FREE' : ($p['fare_paid'] ? 'PAID' : ($p['online_pay_status'] === 'pending' ? 'VERIFYING' : 'UNPAID'));
    $state  = function_exists('pax_ticket_state') ? pax_ticket_state($p) : 'valid';

    // ── header band ───────────────────────────────────────────────
    imagefilledrectangle($im, 0, 0, $W, 104, $navy);
    $txt('MANLOAD', 34, 40, 56, $white, $B);
    $txt('BOARDING PASS  ·  PASSENGER\'S COPY  ·  E-MAIL BACKUP', 13, 42, 86, $white, $R);
    $txt('TICKET NO.', 12, $W - 40, 40, $white, $R, 0, true);
    $txt($p['ticket_no'], 26, $W - 40, 80, $white, $M, 0, true);

    // ── left: trip details ────────────────────────────────────────
    $x0 = 40; $colw = 250;
    $txt($p['orig'] . '  →  ' . $p['dest'], 50, $x0, 186, $ink, $B, 600);
    $txt($p['orig_name'] . ' → ' . $p['dest_name'], 15, $x0, 218, $grey, $R, 620);
    $txt('SEAT', 12, 840, 140, $grey, $R, 0, true);
    $txt($p['seat_no'] ?: '—', 46, 840, 196, $ink, $B, 180, true);

    $txt(function_exists('pax_display_name') ? pax_display_name($p) : strtoupper($p['last_name']) . ', ' . $p['first_name'], 26, $x0, 270, $blue, $B, 800);

    $cells = [
        ['VESSEL',    $p['vessel_name']],
        ['DATE',      date('D, M d Y', $dep)],
        ['DEPARTURE', date('g:i A', $dep)],
        ['CLASS',     $p['class']],
        ['CATEGORY',  $p['pax_category']],
        ['FARE',      peso($p['fare']) . ' · ' . $paytxt],
        ['BAGGAGE',   !empty($p['baggage_id']) ? kg($p['actual_weight'], 1) . ' / ' . kg($p['allowance_kg']) : '—'],
        ['BAG TAG',   $p['tag_no'] ?: '—'],
        ['BOOKING REF', $p['booking_ref'] ?: '—'],
    ];
    foreach ($cells as $i => [$lbl, $val]) {
        $x = $x0 + ($i % 3) * ($colw + 30);
        $y = 322 + intdiv($i, 3) * 70;
        $txt($lbl, 11, $x, $y, $grey, $R);
        $txt((string)$val, 18, $x, $y + 30, $ink, $B, $colw);
    }

    // ── right: QR code ────────────────────────────────────────────
    $q = imagecreatefromstring($qr);
    $qs = 330; $qx = $W - 40 - $qs; $qy = 120;
    imagefilledrectangle($im, $qx - 2, $qy - 2, $qx + $qs + 1, $qy + $qs + 1, $line);
    imagecopyresampled($im, $q, $qx, $qy, 0, 0, $qs, $qs, imagesx($q), imagesy($q));
    imagedestroy($q);
    $cx = $qx + intdiv($qs, 2);
    $w1 = imagettfbbox(13, 0, $R, 'QR valid until'); $txt('QR valid until', 13, $cx - intdiv($w1[2] - $w1[0], 2), $qy + $qs + 30, $grey, $R);
    $w2 = imagettfbbox(17, 0, $B, $valid);           $txt($valid, 17, $cx - intdiv($w2[2] - $w2[0], 2), $qy + $qs + 58, $ink, $B);

    // ── footer note ───────────────────────────────────────────────
    imagefilledrectangle($im, 0, $H - 64, $W, $H, $amber);
    imagefilledrectangle($im, 0, $H - 64, 8, $H, $c('#f59e0b'));
    $txt('Backup copy. If your printed ticket is lost, show this QR code and a valid ID at the gangway.', 14, 30, $H - 38, $brown, $B, $W - 60);
    $txt('Boarding closes 15 minutes before departure. This ticket and QR code expire on ' . $valid . '.', 12, 30, $H - 16, $brown, $R, $W - 60);

    // dashed outline (looks like a cut-out ticket)
    imagesetstyle($im, array_merge(array_fill(0, 10, $line), array_fill(0, 6, IMG_COLOR_TRANSPARENT)));
    imagerectangle($im, 1, 1, $W - 2, $H - 2, IMG_COLOR_STYLED);

    // EXPIRED / CANCELLED stamp
    $stamp = match ($state) { 'expired' => 'EXPIRED', 'cancelled' => strtoupper($p['status']), default => '' };
    if ($stamp) {
        imagettftext($im, 64, 14, 300, 470, $red, $B, $stamp);
    }

    ob_start(); imagepng($im, null, 6); imagedestroy($im);
    return ob_get_clean() ?: null;
}
