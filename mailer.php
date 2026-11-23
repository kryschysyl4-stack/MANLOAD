<?php
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/functions.php';

/**
 * Send an HTML email.
 *  USE_SMTP = true  -> sends through the SMTP server in config/mail.php
 *  USE_SMTP = false -> saved to storage/mail_log/ (for demos without internet):
 *                      a readable .log line + the full message as an .eml file
 *                      (double-click it to open in Outlook / Thunderbird / Mail).
 *
 *  $inline : images shown inside the HTML, ['cid' => ['data'=>bytes, 'type'=>'image/png', 'name'=>'qr.png']]
 *            and referenced in the HTML as <img src="cid:cid">
 *  $attach : file attachments, [['name'=>'file.html', 'type'=>'text/html', 'data'=>bytes], ...]
 * Returns true when actually sent. On failure mail_last_error() says why, and the
 * reason is written to storage/mail_log/mail_error.log and the PHP error log.
 */
function send_mail(string $to, string $subject, string $html_body, array $inline = [], array $attach = []): bool {
    $GLOBALS['_mail_last_error'] = '';
    if (!valid_email($to)) { _mail_error($to, $subject, 'invalid recipient address'); return false; }
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $mime = _mime_message($to, $subject, $html_body, $inline, $attach);
    if (USE_SMTP) {
        $ok = _smtp_send($to, $mime);
        if ($ok) _mail_error($to, $subject, '', 'SENT');
        else     { _mail_error($to, $subject, mail_last_error()); _log_mail($to, '[NOT SENT] ' . $subject, $html_body, $mime); }
        return $ok;
    }
    _mail_error($to, $subject, 'USE_SMTP is off (SMTP_PASS not set in config/local.php) - saved to mail_log only');
    _log_mail($to, $subject, $html_body, $mime);
    return false;
}

/** Why the last send_mail() call failed ('' when it was sent). */
function mail_last_error(): string {
    return (string)($GLOBALS['_mail_last_error'] ?? '');
}

/** One line per send attempt in storage/mail_log/mail_error.log (+ PHP error log on failure). */
function _mail_error(string $to, string $subject, string $reason, string $status = 'FAILED'): void {
    if ($status === 'FAILED') {
        $GLOBALS['_mail_last_error'] = $reason;
        error_log("MANLOAD mail to $to FAILED: $reason");
    }
    $dir = __DIR__ . '/../storage/mail_log';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/mail_error.log',
        date('[Y-m-d H:i:s]') . " $status to=$to subject=\"$subject\"" . ($reason !== '' ? " reason=$reason" : '') . "\n",
        FILE_APPEND | LOCK_EX);
}

function _log_mail(string $to, string $subject, string $body, string $mime = ''): void {
    $dir = __DIR__ . '/../storage/mail_log';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $eml = '';
    if ($mime !== '') {
        $eml = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        @file_put_contents($dir . '/' . $eml, $mime, LOCK_EX);
    }
    $entry = date('[Y-m-d H:i:s]') . " TO: $to\nSUBJECT: $subject\n"
           . ($eml ? "FILE: storage/mail_log/$eml\n" : '')
           . trim(preg_replace('/\n\s*\n+/', "\n", strip_tags(str_replace(['<br>', '</tr>', '</div>'], "\n", $body))))
           . "\n" . str_repeat('-', 60) . "\n";
    @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
}

/** Build the full MIME message (headers + body, CRLF line endings). */
function _mime_message(string $to, string $subject, string $html, array $inline, array $attach): string {
    $b64  = fn(string $d): string => rtrim(chunk_split(base64_encode($d), 76, "\r\n"));
    $bnd  = fn(string $k): string => '=_' . $k . '_' . bin2hex(random_bytes(8));
    $name = fn(string $n): string => preg_replace('/[^A-Za-z0-9._-]/', '_', $n);

    // 1) the HTML (+ a plain-text alternative for mail apps that hide HTML)
    $text = trim(html_entity_decode(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|tr|h\d)>/i'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n\n", $text));
    $alt  = $bnd('alt');
    $part = "Content-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
          . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text) . "\r\n"
          . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n"
          . "--$alt--\r\n";

    // 2) inline images (QR codes) next to the HTML
    if ($inline) {
        $rel = $bnd('rel');
        $p = "Content-Type: multipart/related; boundary=\"$rel\"\r\n\r\n--$rel\r\n" . $part;
        foreach ($inline as $cid => $img) {
            $cid = preg_replace('/[^A-Za-z0-9._@-]/', '', (string)$cid);
            $p .= "--$rel\r\nContent-Type: " . ($img['type'] ?? 'image/png') . '; name="' . $name($img['name'] ?? "$cid.png") . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\n"
                . 'Content-Disposition: inline; filename="' . $name($img['name'] ?? "$cid.png") . "\"\r\n\r\n"
                . $b64($img['data']) . "\r\n";
        }
        $part = $p . "--$rel--\r\n";
    }

    // 3) attachments
    if ($attach) {
        $mix = $bnd('mix');
        $p = "Content-Type: multipart/mixed; boundary=\"$mix\"\r\n\r\n--$mix\r\n" . $part;
        foreach ($attach as $a) {
            $p .= "--$mix\r\nContent-Type: " . ($a['type'] ?? 'application/octet-stream') . '; name="' . $name($a['name']) . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $name($a['name']) . "\"\r\n\r\n"
                . $b64($a['data']) . "\r\n";
        }
        $part = $p . "--$mix--\r\n";
    }

    $domain = substr(strrchr(MAIL_FROM, '@'), 1) ?: 'manload.local';
    return 'Date: ' . date('r') . "\r\n"
         . 'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM . ">\r\n"
         . "To: <$to>\r\n"
         . 'Reply-To: ' . MAIL_REPLY_TO . "\r\n"
         . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
         . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
         . "MIME-Version: 1.0\r\n"
         . $part;
}

/** Minimal but correct SMTP client (AUTH LOGIN, STARTTLS or SSL). */
function _smtp_send(string $to, string $mime): bool {
    $host = (SMTP_SECURE === 'ssl' ? 'ssl://' : '') . SMTP_HOST;
    $sock = @stream_socket_client($host . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!$sock) {
        $GLOBALS['_mail_last_error'] = "cannot connect to $host:" . SMTP_PORT . " ($errno $errstr) - check host/port, internet, firewall";
        return false;
    }
    stream_set_timeout($sock, 15);

    // Read a full (possibly multi-line) reply; return its 3-digit code.
    // $last keeps the server's last reply line so a failure can report it.
    $last = '';
    $reply = function () use ($sock, &$last): int {
        $code = 0;
        $last = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $code = (int)substr($line, 0, 3);
            $last .= ($last === '' ? '' : ' | ') . trim($line);
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        if ($last === '') $last = '(no reply / timeout)';
        return $code;
    };
    $cmd = function (string $c, array $expect) use ($sock, $reply): bool {
        fwrite($sock, $c . "\r\n");
        return in_array($reply(), $expect, true);
    };

    try {
        $helo = preg_replace('/[^a-z0-9.\-]/i', '', gethostname() ?: 'localhost');
        if ($reply() !== 220) throw new RuntimeException('banner');
        if (!$cmd("EHLO $helo", [250])) throw new RuntimeException('ehlo');
        if (SMTP_SECURE === 'tls') {
            if (!$cmd('STARTTLS', [220])) throw new RuntimeException('starttls');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))
                throw new RuntimeException('tls');
            if (!$cmd("EHLO $helo", [250])) throw new RuntimeException('ehlo2');
        }
        if (!$cmd('AUTH LOGIN', [334]))                 throw new RuntimeException('auth');
        if (!$cmd(base64_encode(SMTP_USER), [334]))     throw new RuntimeException('user');
        // Gmail shows App Passwords as "abcd efgh ijkl mnop"; the spaces are not part of it
        if (!$cmd(base64_encode(str_replace(' ', '', SMTP_PASS)), [235])) throw new RuntimeException('pass');
        if (!$cmd('MAIL FROM:<' . MAIL_FROM . '>', [250])) throw new RuntimeException('from');
        if (!$cmd('RCPT TO:<' . $to . '>', [250, 251]))  throw new RuntimeException('rcpt');
        if (!$cmd('DATA', [354]))                        throw new RuntimeException('data');

        // dot-stuffing: a line starting with '.' must be sent as '..'
        $msg = preg_replace('/^\./m', '..', $mime);
        fwrite($sock, $msg . "\r\n.\r\n");
        $ok = $reply() === 250;
        if (!$ok) $GLOBALS['_mail_last_error'] = "SMTP step 'send' rejected: $last";
        $cmd('QUIT', [221]);
        fclose($sock);
        return $ok;
    } catch (Throwable $e) {
        // $last is the server's reply (never contains our password - only what the server said)
        $GLOBALS['_mail_last_error'] = "SMTP step '" . $e->getMessage() . "' failed: "
            . ($e->getMessage() === 'tls' ? 'TLS handshake failed (check php openssl / SMTP_SECURE)' : $last);
        @fclose($sock);
        return false;
    }
}

/** Branded email wrapper. $content is trusted HTML (escape values before!). */
function mail_layout(string $heading, string $content): string {
    $heading = e($heading);
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:30px 0;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;">
  <tr><td style="background:#0f2d6b;padding:22px 32px;color:#fff;">
    <div style="font-size:22px;font-weight:900;letter-spacing:2px;">MANLOAD</div>
    <div style="font-size:11px;opacity:.8;">Vessel Manifest &amp; Load Control System</div>
    <div style="font-size:13px;font-weight:700;margin-top:8px;letter-spacing:1px;">$heading</div>
  </td></tr>
  <tr><td style="padding:24px 32px;color:#0f172a;font-size:14px;line-height:1.6;">$content</td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;font-size:11px;color:#94a3b8;text-align:center;">
    Automated message from MANLOAD. Please do not reply.
  </td></tr>
</table></td></tr></table></body></html>
HTML;
}

/**
 * Email the booking confirmation (all passengers + vehicle of a booking ref)
 * to every distinct email on the booking.
 */
function send_booking_confirmation(string $booking_ref): void {
    $db = db();
    $st = $db->prepare("SELECT * FROM passenger WHERE booking_ref=? ORDER BY passenger_id");
    $st->bind_param('s', $booking_ref); $st->execute();
    $paxs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$paxs) return;
    $v = get_voyage((int)$paxs[0]['voyage_id']);

    $rows = ''; $total = 0; $emails = [];
    foreach ($paxs as $p) {
        $total += (float)$p['fare'];
        if (valid_email($p['email'])) $emails[strtolower($p['email'])] = $p['email'];
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;"><strong>' . e(strtoupper($p['last_name'] . ', ' . $p['first_name'])) . '</strong><br>'
               . '<span style="color:#64748b;font-size:12px;">' . e($p['ticket_no']) . ' · ' . e($p['class']) . ' · ' . e($p['pax_category']) . '</span></td>'
               . '<td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;">' . e(peso($p['fare'])) . '</td></tr>';
    }
    $st = $db->prepare("SELECT vh.*, vt.type_name FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id WHERE vh.booking_ref=?");
    $st->bind_param('s', $booking_ref); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $vh) {
        $total += (float)$vh['freight_amount'];
        if (valid_email($vh['owner_email'])) $emails[strtolower($vh['owner_email'])] = $vh['owner_email'];
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;"><strong>Vehicle ' . e($vh['plate_no']) . '</strong><br>'
               . '<span style="color:#64748b;font-size:12px;">' . e($vh['type_name']) . '</span></td>'
               . '<td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;">' . e(peso($vh['freight_amount'])) . '</td></tr>';
    }

    // Payment status of the whole booking (not just the first passenger)
    $due      = array_filter($paxs, fn($p) => (float)$p['fare'] > 0);
    $unpaid   = array_filter($due,  fn($p) => !$p['fare_paid']);
    $statuses = array_column($unpaid, 'online_pay_status');
    $pay_line = match (true) {
        !$due                              => '<span style="color:#15803d;font-weight:700;">No fare due</span>',
        !$unpaid                           => '<span style="color:#15803d;font-weight:700;">PAID</span>',
        in_array('pending', $statuses, true) => '<span style="color:#b45309;font-weight:700;">Online payment received — waiting for verification</span>',
        in_array('rejected', $statuses, true) => '<span style="color:#b91c1c;font-weight:700;">Online payment NOT verified — please pay again online or at the port</span>',
        default                            => '<span style="color:#b91c1c;font-weight:700;">UNPAID — pay online or at the port counter</span>',
    };
    $all_checked = !array_filter($paxs, fn($p) => $p['status'] === 'Booked');
    $dep  = date('D, d M Y', strtotime($v['departure_date'])) . ' · ' . date('g:i A', strtotime($v['departure_time']));
    $link = booking_link($booking_ref);
    $exp  = date('d M Y g:i A', strtotime($paxs[0]['ticket_valid_until'] ?? ticket_expiry($v)));

    // QR code of each e-ticket as a picture inside the e-mail (mail apps can't run JavaScript)
    $inline = []; $qrs = '';
    foreach ($paxs as $p) {
        if (in_array($p['status'], ['Cancelled', 'No-show'], true)) continue;
        $png = qr_png(ticket_qr_data($p), 4);
        if (!$png) continue;
        $cid = 'qr-' . preg_replace('/[^A-Za-z0-9-]/', '', $p['ticket_no']) . '@manload';
        $inline[$cid] = ['data' => $png, 'type' => 'image/png', 'name' => 'qr-' . $p['ticket_no'] . '.png'];
        $qrs .= '<td align="center" style="padding:6px;vertical-align:top;"><img src="cid:' . e($cid) . '" width="150" height="150" alt="QR ' . e($p['ticket_no']) . '" style="border:1px solid #e2e8f0;"><br>'
              . '<span style="font-size:11px;font-weight:700;">' . e(strtoupper($p['last_name']) . ', ' . $p['first_name']) . '</span><br>'
              . '<span style="font-size:11px;color:#64748b;font-family:Consolas,monospace;">' . e($p['ticket_no']) . '</span></td>';
        if (count($inline) % 3 === 0) $qrs .= '</tr><tr>';
    }
    $first = trim((string)$paxs[0]['first_name']);

    $content = '<div style="text-align:center;margin:0 0 18px;">'
        . '<div style="font-size:24px;font-weight:900;color:#15803d;">Thank you for booking!</div>'
        . '<div style="font-size:13px;color:#475569;">' . ($first !== '' ? 'Hi ' . e($first) . ', your' : 'Your') . ' trip is booked. Your booking details are below.</div></div>'
        . '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Booking reference</div>'
        . '<div style="font-size:26px;font-weight:900;color:#1e3a8a;letter-spacing:2px;">' . e($booking_ref) . '</div>'
        . '<div style="margin:14px 0;padding:12px 16px;background:#f8fafc;border-radius:8px;">'
        . '<strong>' . e($v['orig_name']) . ' → ' . e($v['dest_name']) . '</strong><br>'
        . e($dep) . '<br>' . e($v['vessel_name']) . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">' . $rows
        . '<tr><td style="padding:8px 0;"><strong>Total</strong></td><td style="text-align:right;"><strong>' . e(peso($total)) . '</strong></td></tr></table>'
        . '<p>Payment: ' . $pay_line . '</p>'
        . ($qrs ? '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-top:10px;">Your e-ticket QR code' . (count($inline) > 1 ? 's' : '') . '</div>'
                 . '<table cellpadding="0" cellspacing="0" align="center" style="margin:6px auto;"><tr>' . $qrs . '</tr></table>' : '')
        . '<p style="text-align:center;margin:22px 0;"><a href="' . e($link) . '" style="background:#1d4ed8;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;">Open my e-ticket &amp; QR code</a></p>'
        . '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;font-size:12px;color:#78350f;">'
        . 'Your ticket and QR code are valid until <strong>' . e($exp) . '</strong>. '
        . ($all_checked
            ? 'You are already checked in — give your ticket number and show a valid ID at the boarding gate (no printing needed).'
            : 'Give your booking reference or ticket number and show a valid ID at the check-in counter at least 1 hour before departure (no printing needed).')
        . '</div>';

    $content .= '<p style="text-align:center;margin:18px 0 0;color:#475569;">Thank you for choosing us — have a safe voyage!</p>';
    foreach ($emails as $to) send_mail($to, 'Thank you for booking! Confirmation ' . $booking_ref, mail_layout('BOOKING CONFIRMATION', $content), $inline);
}

/** Tell the office (NOTIFY_EMAIL) that a new booking was made on the website. */
function send_booking_notice(string $booking_ref): void {
    $to = array_filter(array_map('trim', explode(',', (string)NOTIFY_EMAIL)), 'valid_email');
    if (!$to) return;
    $db = db();
    $st = $db->prepare("SELECT * FROM passenger WHERE booking_ref=? ORDER BY passenger_id");
    $st->bind_param('s', $booking_ref); $st->execute();
    $paxs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$paxs) return;
    $v  = get_voyage((int)$paxs[0]['voyage_id']);
    $p0 = $paxs[0];

    $td = 'padding:5px 0;border-bottom:1px solid #eee;';
    $rows = ''; $total = 0;
    foreach ($paxs as $p) {
        $total += (float)$p['fare'];
        $rows .= '<tr><td style="' . $td . '"><strong>' . e(strtoupper($p['last_name'] . ', ' . $p['first_name'])) . '</strong><br>'
               . '<span style="color:#64748b;font-size:12px;">' . e($p['ticket_no']) . ' · ' . e($p['class']) . ' · ' . e($p['pax_category']) . '</span></td>'
               . '<td style="' . $td . 'text-align:right;">' . e(peso($p['fare'])) . '</td></tr>';
    }
    $st = $db->prepare("SELECT vh.plate_no, vh.freight_amount, vt.type_name FROM vehicle vh JOIN vehicle_type vt ON vt.type_id=vh.vehicle_type_id WHERE vh.booking_ref=?");
    $st->bind_param('s', $booking_ref); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $vh) {
        $total += (float)$vh['freight_amount'];
        $rows .= '<tr><td style="' . $td . '"><strong>Vehicle ' . e($vh['plate_no']) . '</strong><br><span style="color:#64748b;font-size:12px;">' . e($vh['type_name']) . '</span></td>'
               . '<td style="' . $td . 'text-align:right;">' . e(peso($vh['freight_amount'])) . '</td></tr>';
    }

    $dep  = date('D, d M Y', strtotime($v['departure_date'])) . ' · ' . date('g:i A', strtotime($v['departure_time']));
    $link = abs_url('/modules/admin/booking_view.php?ref=' . urlencode($booking_ref));
    $content = '<p style="margin:0 0 12px;">A new booking was made on the website on ' . e(date('d M Y g:i A', strtotime($p0['booked_at'] ?? 'now'))) . '.</p>'
        . '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Booking reference</div>'
        . '<div style="font-size:24px;font-weight:900;color:#1e3a8a;letter-spacing:2px;">' . e($booking_ref) . '</div>'
        . '<div style="margin:12px 0;padding:12px 16px;background:#f8fafc;border-radius:8px;">'
        . '<strong>' . e($v['orig_name']) . ' → ' . e($v['dest_name']) . '</strong><br>' . e($dep) . '<br>' . e($v['vessel_name']) . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">' . $rows
        . '<tr><td style="padding:8px 0;"><strong>Total (' . count($paxs) . ' passenger' . (count($paxs) > 1 ? 's' : '') . ')</strong></td>'
        . '<td style="text-align:right;"><strong>' . e(peso($total)) . '</strong></td></tr></table>'
        . '<p style="margin:12px 0 0;">Contact: ' . e((string)$p0['email']) . ($p0['contact_no'] ? ' · ' . e($p0['contact_no']) : '') . '</p>'
        . '<p style="text-align:center;margin:20px 0 0;"><a href="' . e($link) . '" style="background:#1d4ed8;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:700;">Open booking in MANLOAD</a></p>';

    $subject = 'New online booking ' . $booking_ref . ' — ' . $v['orig_name'] . ' → ' . $v['dest_name'] . ', ' . date('d M', strtotime($v['departure_date']));
    foreach ($to as $addr) send_mail($addr, $subject, mail_layout('NEW ONLINE BOOKING', $content));
}

/**
 * Payment confirmation (official receipt details) for just-collected payments —
 * counter, check-in and verified online payments. One email per payer address:
 * fare/excess -> passenger email, vehicle -> owner email, cargo -> shipper email.
 * Never throws: a mail problem must not undo or block a recorded payment.
 */
function send_payment_receipt(array $payment_ids): void {
    try {
        $ids = array_filter(array_map('intval', $payment_ids));
        if (!$ids) return;
        $rows = db()->query("SELECT pm.or_no, pm.fee_type, pm.amount, pm.pay_method, pm.reference_no, pm.paid_at, pm.voyage_id,
                    COALESCE(p.email, vh.owner_email, cg.shipper_email) AS email,
                    COALESCE(CONCAT(p.last_name, ', ', p.first_name), CONCAT('Vehicle ', vh.plate_no), CONCAT('Waybill ', cg.waybill_no)) AS item,
                    COALESCE(p.ticket_no, '') AS ticket_no, COALESCE(p.booking_ref, vh.booking_ref) AS booking_ref
                FROM payment pm
                LEFT JOIN passenger p ON p.passenger_id = pm.passenger_id
                LEFT JOIN vehicle  vh ON vh.vehicle_id  = pm.vehicle_id
                LEFT JOIN cargo    cg ON cg.cargo_id    = pm.cargo_id
                WHERE pm.payment_id IN (" . implode(',', $ids) . ") AND pm.is_voided = 0 ORDER BY pm.payment_id")->fetch_all(MYSQLI_ASSOC);

        $by_email = [];
        foreach ($rows as $r) if (valid_email((string)$r['email'])) $by_email[strtolower($r['email'])][] = $r;

        $td = 'padding:6px 0;border-bottom:1px solid #eee;';
        foreach ($by_email as $to => $list) {
            $v = get_voyage((int)$list[0]['voyage_id']);
            $total = 0; $lines = '';
            foreach ($list as $r) {
                $total += (float)$r['amount'];
                $lines .= '<tr><td style="' . $td . '"><strong>' . e($r['fee_type']) . '</strong> — ' . e(strtoupper($r['item'])) . '<br>'
                        . '<span style="color:#64748b;font-size:12px;">OR ' . e($r['or_no']) . ($r['ticket_no'] ? ' · ' . e($r['ticket_no']) : '') . '</span></td>'
                        . '<td style="' . $td . 'text-align:right;">' . e(peso($r['amount'])) . '</td></tr>';
            }
            $r0  = $list[0];
            $ref = (string)$r0['booking_ref'];
            $content = '<div style="text-align:center;margin:0 0 18px;">'
                . '<div style="font-size:24px;font-weight:900;color:#15803d;">Payment received</div>'
                . '<div style="font-size:13px;color:#475569;">Thank you! Here is the confirmation of your payment. Keep this email as your proof of payment.</div></div>'
                . ($v ? '<div style="margin:0 0 14px;padding:12px 16px;background:#f8fafc;border-radius:8px;">'
                      . '<strong>' . e($v['orig_name']) . ' → ' . e($v['dest_name']) . '</strong><br>'
                      . e(date('D, d M Y', strtotime($v['departure_date'])) . ' · ' . date('g:i A', strtotime($v['departure_time']))) . '<br>' . e($v['vessel_name']) . '</div>' : '')
                . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">' . $lines
                . '<tr><td style="padding:8px 0;"><strong>Total paid</strong></td><td style="text-align:right;"><strong>' . e(peso($total)) . '</strong></td></tr></table>'
                . '<p style="margin:12px 0 0;font-size:13px;">Paid via <strong>' . e($r0['pay_method']) . '</strong>'
                . ($r0['reference_no'] ? ' · Ref. ' . e($r0['reference_no']) : '')
                . ' · ' . e(date('d M Y g:i A', strtotime($r0['paid_at']))) . '</p>'
                . ($ref ? '<p style="text-align:center;margin:22px 0 0;"><a href="' . e(booking_link($ref)) . '" style="background:#1d4ed8;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;">View my booking</a></p>' : '');
            send_mail($to, 'Payment confirmation' . ($ref ? ' — ' . $ref : '') . ' (' . peso($total) . ')', mail_layout('PAYMENT CONFIRMATION', $content));
        }
    } catch (Throwable $e) {
        error_log('MANLOAD payment receipt email failed: ' . $e->getMessage());
    }
}

/** Tell the passenger their online payment was verified or rejected. */
function send_payment_result(string $booking_ref, bool $verified, string $reason = ''): void {
    $st = db()->prepare("SELECT email FROM passenger WHERE booking_ref=? AND email IS NOT NULL");
    $st->bind_param('s', $booking_ref); $st->execute();
    $emails = array_unique(array_map('strtolower', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'email')));
    $content = $verified
        ? '<p>Your online payment for booking <strong>' . e($booking_ref) . '</strong> has been <strong style="color:#15803d;">verified</strong>. Your e-ticket is now marked PAID.</p>'
        : '<p>We could not verify the online payment for booking <strong>' . e($booking_ref) . '</strong>.</p>'
          . ($reason ? '<p>Reason: ' . e($reason) . '</p>' : '')
          . '<p>You may submit a new reference number or pay at the port counter.</p>';
    $content .= '<p style="text-align:center;margin:22px 0;"><a href="' . e(booking_link($booking_ref)) . '" style="background:#1d4ed8;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;">View booking</a></p>';
    foreach ($emails as $to) send_mail($to, ($verified ? 'Payment verified — ' : 'Payment not verified — ') . $booking_ref,
        mail_layout($verified ? 'PAYMENT VERIFIED' : 'PAYMENT NOT VERIFIED', $content));
}
