<?php
/**
 * Passenger accounts, wallet (credits), reward points / tiers, vouchers and the system ledger.
 *
 *   passenger_account   – the passenger's own login on the public site (session key 'pax_acct_id',
 *                         never mixed with the staff login 'user_id')
 *   wallet_txn          – every wallet movement (cash-in, payment, refund, adjustment)
 *   points_txn          – reward points earned / redeemed / reversed
 *   voucher(+_redemption) – promo codes and personal reward vouchers
 *   ledger_entry        – one line for EVERY money / credit / discount / points movement
 *
 * Functions ending in _locked must run inside the caller's transaction.
 * Every movement also writes an audit_log row.
 * Requires config/auth.php (db(), audit(), functions.php).
 */
require_once __DIR__ . '/functions.php';

const ACCT_TIERS = [               // completed (boarded) trips → tier, points multiplier
    'Gold'   => ['trips' => 15, 'mult' => 1.5],
    'Silver' => ['trips' => 5,  'mult' => 1.25],
    'Member' => ['trips' => 0,  'mult' => 1.0],
];
const WALLET_CASHIN_METHODS = ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Card'];

// ═════════════════════════════════════════════════════════════════════════
// System ledger
// ═════════════════════════════════════════════════════════════════════════

/**
 * Write one ledger line. $o: points, method, fee_type, reference_no, account_id, passenger_id,
 * payment_id, wallet_txn_id, voyage_id, booking_ref, description, user_id (default: the staff user logged in).
 */
function ledger_post(string $type, float $amount, array $o = []): void {
    $uid = array_key_exists('user_id', $o) ? $o['user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $g = fn(string $k) => isset($o[$k]) && $o[$k] !== '' ? $o[$k] : null;
    $pts = (int)($o['points'] ?? 0);
    $method = $g('method'); $fee = $g('fee_type'); $ref = $g('reference_no'); $bref = $g('booking_ref');
    $desc = $g('description') !== null ? mb_substr((string)$o['description'], 0, 255) : null;
    $aid = $g('account_id'); $pid = $g('passenger_id'); $pay = $g('payment_id'); $wt = $g('wallet_txn_id'); $vid = $g('voyage_id');
    $st = db()->prepare('INSERT INTO ledger_entry (entry_type, amount, points, method, fee_type, reference_no, account_id, passenger_id,
            payment_id, wallet_txn_id, voyage_id, booking_ref, description, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('sdisssiiiiissi', $type, $amount, $pts, $method, $fee, $ref, $aid, $pid, $pay, $wt, $vid, $bref, $desc, $uid);
    $st->execute();
}

// ═════════════════════════════════════════════════════════════════════════
// Account session (public site)
// ═════════════════════════════════════════════════════════════════════════

function acct_id(): int {
    session_start_once();
    return (int)($_SESSION['pax_acct_id'] ?? 0);
}

/** The logged-in passenger account (fresh from the database), or null. */
function acct_current(): ?array {
    static $cache = [];
    $aid = acct_id();
    if (!$aid) return null;
    if (!array_key_exists($aid, $cache)) {
        $cache[$aid] = acct_get($aid);
        if (!$cache[$aid] || !$cache[$aid]['is_active']) { unset($_SESSION['pax_acct_id']); $cache[$aid] = null; }
    }
    return $cache[$aid];
}

function acct_get(int $aid): ?array {
    $st = db()->prepare('SELECT * FROM passenger_account WHERE account_id=?');
    $st->bind_param('i', $aid); $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/** Send visitors who are not logged in to the account login page. */
function acct_require(): array {
    $a = acct_current();
    if (!$a) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . BASE_URL . '/modules/account/login.php?next=' . urlencode($next));
        exit;
    }
    return $a;
}

/**
 * Booking pages (Book Now): log in / sign up first — or "Continue as guest" on the login page
 * (occasional travellers: no points, vouchers that need an account, or wallet). Returns the account or null (guest).
 */
function acct_require_for_booking(): ?array {
    if ($a = acct_current()) return $a;
    if (!empty($_SESSION['bk_guest'])) return null;
    if (function_exists('set_flash')) set_flash('info', 'Please log in, create a free account, or continue as a guest to book your trip.');
    return acct_require();
}

/** Last 10 digits of a PH mobile number (09171234567 / +639171234567 → 9171234567), '' when not a mobile number. */
function acct_mobile_key(string $s): string {
    $d = preg_replace('/\D/', '', $s);
    return strlen($d) >= 10 && strlen($d) <= 13 ? substr($d, -10) : '';
}

/** Accounts matching what was typed in "Email Address / Mobile Number". */
function acct_find_by_login(string $login): array {
    $login = trim($login);
    if (str_contains($login, '@')) {
        $e = strtolower($login);
        $st = db()->prepare('SELECT * FROM passenger_account WHERE email=?');
        $st->bind_param('s', $e);
    } elseif (($k = acct_mobile_key($login)) !== '') {
        $st = db()->prepare("SELECT * FROM passenger_account WHERE RIGHT(REPLACE(REPLACE(mobile,' ',''),'-',''), 10)=? ORDER BY last_login DESC");
        $st->bind_param('s', $k);
    } else {
        return [];
    }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

const ACCT_OTP_TTL = 600, ACCT_OTP_TRIES = 5;

/**
 * Send a 6-digit one-time code to the account's email ($mode 'login' = Login with OTP, 'reset' = Forgot Password).
 * Never says whether the account exists. Returns ['ok', 'msg', 'debug_code' (only on this PC when mail is not set up)].
 */
function acct_otp_start(string $login, string $mode): array {
    session_start_once();
    if (bk_rate_limited('acct_otp', 5, 3600)) return ['ok' => false, 'msg' => 'Too many codes requested. Please try again in an hour.'];
    if (!str_contains($login, '@') && acct_mobile_key($login) === '') return ['ok' => false, 'msg' => 'Enter your email address or mobile number.'];
    bk_rate_hit('acct_otp');
    $a = array_values(array_filter(acct_find_by_login($login), fn($r) => $r['is_active']))[0] ?? null;
    $code = (string)random_int(100000, 999999);
    $_SESSION['acct_otp'] = ['aid' => $a ? (int)$a['account_id'] : 0, 'hash' => password_hash($code, PASSWORD_DEFAULT),
                             'exp' => time() + ACCT_OTP_TTL, 'tries' => 0, 'mode' => $mode, 'login' => mb_substr($login, 0, 100)];
    // same message whether or not the account exists (no account fishing)
    $out = ['ok' => true, 'msg' => 'If an account matches, a 6-digit code was sent to its email address. It expires in 10 minutes.'];
    if ($a) {
        require_once __DIR__ . '/mailer.php';
        $what = $mode === 'reset' ? 'reset your password' : 'log in';
        $sent = send_mail($a['email'], $mode === 'reset' ? 'Your MANLOAD password reset code' : 'Your MANLOAD login code',
            '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0b2545">Hi ' . e($a['first_name']) . ',<br><br>Use this code to ' . $what . ':'
            . '<div style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:16px 0;color:#1d74f5">' . $code . '</div>'
            . 'It expires in 10 minutes. If you did not ask for it, ignore this email — your account is safe.<br><br>MANLOAD Ferry Booking</div>');
        audit('Passenger ' . ($mode === 'reset' ? 'password reset' : 'login') . ' code sent' . ($sent ? '' : ' (mail not sent)'), 'passenger_account', (int)$a['account_id']);
        if (!$sent && APP_DEBUG) $out['debug_code'] = $code;   // localhost only: mail is not set up on this PC
    }
    return $out;
}

/** Check the code typed. Returns the account (code used up) or ['error' => msg]. */
function acct_otp_verify(string $code, string $mode): array {
    session_start_once();
    $o = $_SESSION['acct_otp'] ?? null;
    if (!$o || $o['mode'] !== $mode || $o['exp'] < time()) { unset($_SESSION['acct_otp']); return ['error' => 'The code expired. Please ask for a new one.']; }
    if (++$_SESSION['acct_otp']['tries'] > ACCT_OTP_TRIES) { unset($_SESSION['acct_otp']); return ['error' => 'Too many wrong codes. Please ask for a new one.']; }
    if (!preg_match('/^\d{6}$/', $code) || !$o['aid'] || !password_verify($code, $o['hash'])) return ['error' => 'Wrong code. Check the email and try again.'];
    unset($_SESSION['acct_otp']);
    $a = acct_get((int)$o['aid']);
    return ($a && $a['is_active']) ? $a : ['error' => 'This account is disabled. Please contact the port ticket office.'];
}

function acct_login(array $a): void {
    session_start_once();
    session_regenerate_id(true);
    $_SESSION['pax_acct_id'] = (int)$a['account_id'];
    unset($_SESSION['bk_guest']);
    $st = db()->prepare('UPDATE passenger_account SET last_login=NOW() WHERE account_id=?');
    $aid = (int)$a['account_id'];
    $st->bind_param('i', $aid); $st->execute();
}

function acct_logout(): void {
    session_start_once();
    unset($_SESSION['pax_acct_id']);
    session_regenerate_id(true);
}

/** Only same-site paths are allowed as ?next= targets. */
function acct_safe_next(string $next): string {
    return (preg_match('#^/[^/\\\\]#', $next) && !str_contains($next, "\n")) ? $next : BASE_URL . '/modules/account/index.php';
}

/** Completed trips (boarded tickets) of an account. */
function acct_trips_done(int $aid): int {
    $st = db()->prepare("SELECT COUNT(*) FROM passenger WHERE account_id=? AND status='Boarded'");
    $st->bind_param('i', $aid); $st->execute();
    return (int)$st->get_result()->fetch_row()[0];
}

/** ['name', 'mult', 'trips', 'next' => next tier name|null, 'to_next' => trips still needed] */
function acct_tier(int $aid): array {
    $trips = acct_trips_done($aid);
    $out = ['name' => 'Member', 'mult' => 1.0, 'trips' => $trips, 'next' => null, 'to_next' => 0];
    $prev = null;
    foreach (ACCT_TIERS as $name => $t) {
        if ($trips >= $t['trips']) {
            $out['name'] = $name; $out['mult'] = $t['mult'];
            if ($prev) { $out['next'] = $prev; $out['to_next'] = ACCT_TIERS[$prev]['trips'] - $trips; }
            break;
        }
        $prev = $name;
    }
    return $out;
}

/** Account that pays for an item (fare / excess: the ticket's account; vehicle: an account on the same booking). */
function pay_item_account(string $kind, int $id): ?int {
    $db = db();
    if (in_array($kind, ['fare', 'excess'], true)) {
        $st = $db->prepare('SELECT account_id FROM passenger WHERE passenger_id=?');
    } elseif ($kind === 'vehicle') {
        $st = $db->prepare("SELECT p.account_id FROM vehicle vh JOIN passenger p ON p.booking_ref=vh.booking_ref AND p.voyage_id=vh.voyage_id
                            WHERE vh.vehicle_id=? AND vh.booking_ref IS NOT NULL AND p.account_id IS NOT NULL ORDER BY p.passenger_id LIMIT 1");
    } else {
        return null;
    }
    $st->bind_param('i', $id); $st->execute();
    $aid = $st->get_result()->fetch_row()[0] ?? null;
    return $aid ? (int)$aid : null;
}

// ═════════════════════════════════════════════════════════════════════════
// Wallet
// ═════════════════════════════════════════════════════════════════════════

/**
 * Move credits (inside a transaction). $amount: + adds, - takes. Never lets the balance go below zero
 * or above WALLET_MAX_BALANCE. $o: method, reference_no, payment_id, booking_ref, note, user_id, passenger_id,
 * voyage_id, fee_type. Returns ['txn_id', 'txn_no', 'balance']. Throws RuntimeException.
 */
function wallet_post_locked(int $aid, string $type, float $amount, array $o = []): array {
    $db = db();
    $amount = round($amount, 2);
    if (abs($amount) < 0.01) throw new RuntimeException('Amount must not be zero.');
    $st = $db->prepare('SELECT wallet_balance, is_active FROM passenger_account WHERE account_id=? FOR UPDATE');
    $st->bind_param('i', $aid); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException('Passenger account not found.');
    if (!$row['is_active'] && $amount < 0) throw new RuntimeException('This passenger account is disabled.');
    $bal = round((float)$row['wallet_balance'] + $amount, 2);
    if ($bal < 0) throw new RuntimeException('Not enough wallet credits (balance ' . peso($row['wallet_balance']) . ').');
    if ($amount > 0 && $type === 'Cash-in' && $bal > WALLET_MAX_BALANCE)
        throw new RuntimeException('The wallet balance may not go over ' . peso(WALLET_MAX_BALANCE) . '.');

    $no   = next_number('WL');
    $uid  = array_key_exists('user_id', $o) ? $o['user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $meth = $o['method'] ?? null; $ref = $o['reference_no'] ?? null; $pay = $o['payment_id'] ?? null;
    $bref = $o['booking_ref'] ?? null; $note = isset($o['note']) ? mb_substr((string)$o['note'], 0, 255) : null;
    $st = $db->prepare('INSERT INTO wallet_txn (txn_no, account_id, txn_type, amount, balance_after, method, reference_no, payment_id, booking_ref, note, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('sisddssissi', $no, $aid, $type, $amount, $bal, $meth, $ref, $pay, $bref, $note, $uid);
    $st->execute();
    $tid = (int)$db->insert_id;
    $st = $db->prepare('UPDATE passenger_account SET wallet_balance=? WHERE account_id=?');
    $st->bind_param('di', $bal, $aid); $st->execute();

    $ltype = ['Cash-in' => 'Wallet Cash-in', 'Payment' => 'Wallet Payment', 'Refund' => 'Wallet Refund', 'Adjustment' => 'Wallet Adjustment'][$type];
    ledger_post($ltype, $amount, ['method' => $meth, 'reference_no' => $no, 'account_id' => $aid, 'wallet_txn_id' => $tid,
        'payment_id' => $pay, 'booking_ref' => $bref, 'passenger_id' => $o['passenger_id'] ?? null, 'voyage_id' => $o['voyage_id'] ?? null,
        'fee_type' => $o['fee_type'] ?? null, 'description' => $note ?? $type, 'user_id' => $uid]);
    audit('Wallet ' . strtolower($type) . ' ' . $no, 'wallet_txn', $tid, ['balance' => (float)$row['wallet_balance']],
          ['account_id' => $aid, 'amount' => $amount, 'balance' => $bal, 'method' => $meth, 'reference_no' => $ref, 'note' => $note]);
    return ['txn_id' => $tid, 'txn_no' => $no, 'balance' => $bal];
}

/** Link the OR to the wallet movement that paid it (collect_locked inserts the payment after the debit). */
function wallet_txn_set_payment(int $txn_id, int $payment_id): void {
    $st = db()->prepare('UPDATE wallet_txn SET payment_id=? WHERE txn_id=?');
    $st->bind_param('ii', $payment_id, $txn_id); $st->execute();
    $st = db()->prepare('UPDATE ledger_entry SET payment_id=? WHERE wallet_txn_id=?');
    $st->bind_param('ii', $payment_id, $txn_id); $st->execute();
}

/** Cash-in (own transaction): at the counter ($uid = cashier) or online ($uid null). */
function wallet_cashin(int $aid, float $amount, string $method, ?string $ref, ?int $uid, string $note = ''): array {
    $amount = round($amount, 2);
    if (!in_array($method, WALLET_CASHIN_METHODS, true)) return ['ok' => false, 'msg' => 'Choose a valid cash-in method.'];
    if ($amount < WALLET_MIN_CASHIN) return ['ok' => false, 'msg' => 'The smallest cash-in is ' . peso(WALLET_MIN_CASHIN) . '.'];
    $ref = $ref !== null ? trim($ref) : null;
    if ($method !== 'Cash' && !$ref) return ['ok' => false, 'msg' => 'Reference number is required for ' . $method . '.'];
    if ($ref !== null && $ref !== '' && $uid !== null) {   // the same e-wallet/bank reference may not be credited twice
        $st = db()->prepare("SELECT txn_no FROM wallet_txn WHERE txn_type='Cash-in' AND method=? AND reference_no=? LIMIT 1");
        $st->bind_param('ss', $method, $ref); $st->execute();
        if ($dup = $st->get_result()->fetch_row()) return ['ok' => false, 'msg' => "Reference $ref was already credited ({$dup[0]})."];
    }
    $db = db();
    $db->begin_transaction();
    try {
        $r = wallet_post_locked($aid, 'Cash-in', $amount, ['method' => $method, 'reference_no' => $ref ?: null, 'user_id' => $uid,
            'note' => $note !== '' ? $note : ($uid ? 'Cash-in at the counter' : 'Online cash-in')]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Cash-in failed: database error.'];
    }
    return ['ok' => true, 'msg' => 'Cash-in ' . peso($amount) . ' (' . $r['txn_no'] . '). New balance ' . peso($r['balance']) . '.'] + $r;
}

/** Admin correction (own transaction), reason required. */
function wallet_adjust(int $aid, float $amount, string $reason, int $uid): array {
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'msg' => 'A reason is required.'];
    $db = db();
    $db->begin_transaction();
    try {
        $r = wallet_post_locked($aid, 'Adjustment', $amount, ['note' => 'Adjustment: ' . $reason, 'user_id' => $uid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Adjustment failed: database error.'];
    }
    return ['ok' => true, 'msg' => 'Wallet adjusted ' . peso($amount) . ' (' . $r['txn_no'] . '). Balance ' . peso($r['balance']) . '.'] + $r;
}

/**
 * Inside a check-in transaction: pay what is due from the passenger's wallet when the account has
 * auto-pay on and enough credits. $dues = checkin_dues() rows. Returns the payment rows collected.
 */
function wallet_autopay_locked(array $dues, ?int $uid): array {
    $out = [];
    foreach ($dues as $d) {
        $aid = pay_item_account($d['kind'], (int)$d['id']);
        if (!$aid) continue;
        $st = db()->prepare('SELECT wallet_balance, auto_pay, is_active FROM passenger_account WHERE account_id=? FOR UPDATE');
        $st->bind_param('i', $aid); $st->execute();
        $a = $st->get_result()->fetch_assoc();
        if (!$a || !$a['auto_pay'] || !$a['is_active'] || (float)$a['wallet_balance'] + 0.001 < (float)$d['due']) continue;
        $r = collect_locked($d['kind'], (int)$d['id'], 'Wallet', null, $uid);
        if ($r['ok']) $out[] = $r;
    }
    return $out;
}

/** Pay every unpaid fare of a booking from the account's wallet (own transaction). */
function wallet_pay_booking(int $aid, string $ref): array {
    $db = db();
    $st = $db->prepare("SELECT passenger_id FROM passenger WHERE booking_ref=? AND account_id=? AND fare > 0 AND fare_paid=0
                        AND online_pay_status <> 'pending' AND status IN ('Booked','Checked-in','Boarded') ORDER BY passenger_id");
    $st->bind_param('si', $ref, $aid); $st->execute();
    $pids = array_map(fn($r) => (int)$r[0], $st->get_result()->fetch_all());
    if (!$pids) return ['ok' => false, 'msg' => 'Nothing to pay on this booking.'];
    $ids = [];
    $db->begin_transaction();
    try {
        foreach ($pids as $pid) {
            $r = collect_locked('fare', $pid, 'Wallet', null, null);
            if (!$r['ok']) throw new RuntimeException($r['msg']);
            $ids[] = $r['payment_id'];
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Wallet payment failed: database error.'];
    }
    try { send_payment_receipt($ids); } catch (Throwable $e) { error_log('MANLOAD wallet receipt mail: ' . $e->getMessage()); }
    return ['ok' => true, 'payment_ids' => $ids, 'msg' => 'Paid ' . count($ids) . ' ticket(s) of ' . $ref . ' with your wallet credits.'];
}

/** Wallet of the passenger account a ticket belongs to, or null (not linked / account disabled). */
function pax_wallet(int $pid): ?array {
    $st = db()->prepare('SELECT a.account_id, a.account_no, a.wallet_balance FROM passenger p
                         JOIN passenger_account a ON a.account_id=p.account_id WHERE p.passenger_id=? AND a.is_active=1');
    $st->bind_param('i', $pid); $st->execute();
    $w = $st->get_result()->fetch_assoc();
    return $w ? ['aid' => (int)$w['account_id'], 'account_no' => $w['account_no'], 'balance' => (float)$w['wallet_balance']] : null;
}

/** <option>s for a counter payment-method select: PAY_METHODS + "Wallet" when the ticket has a linked account. */
function pay_method_options(?array $wallet, bool $prefer_wallet = false): string {
    $h = '';
    foreach (PAY_METHODS as $m) $h .= '<option' . (!$prefer_wallet && $m === 'Cash' ? ' selected' : '') . '>' . e($m) . '</option>';
    if ($wallet) $h .= '<option value="Wallet"' . ($prefer_wallet ? ' selected' : '') . ' data-balance="' . e((string)$wallet['balance']) . '">'
                    . e('E-wallet (' . peso($wallet['balance']) . ')') . '</option>';
    return $h;
}

/** Passenger pays the excess baggage fee of one of their own tickets from the wallet (public account page). */
function wallet_pay_excess(int $aid, int $pid): array {
    $st = db()->prepare('SELECT 1 FROM passenger p JOIN baggage b ON b.passenger_id=p.passenger_id
                         WHERE p.passenger_id=? AND p.account_id=? AND b.excess_fee > 0 AND b.is_paid=0');
    $st->bind_param('ii', $pid, $aid); $st->execute();
    if (!$st->get_result()->fetch_row()) return ['ok' => false, 'msg' => 'No unpaid excess baggage fee on this ticket.'];
    $db = db();
    $db->begin_transaction();
    try {
        $r = collect_locked('excess', $pid, 'Wallet', null, null);
        if (!$r['ok']) throw new RuntimeException($r['msg']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Wallet payment failed: database error.'];
    }
    try { send_payment_receipt([$r['payment_id']]); } catch (Throwable $e) { error_log('MANLOAD wallet receipt mail: ' . $e->getMessage()); }
    return ['ok' => true, 'payment_id' => $r['payment_id'], 'msg' => 'Excess baggage fee ' . peso($r['amount']) . ' paid from your wallet — receipt ' . $r['or_no'] . '.'];
}

// ═════════════════════════════════════════════════════════════════════════
// Reward points
// ═════════════════════════════════════════════════════════════════════════

/** Add / take points (inside a transaction). The balance never goes below zero. */
function points_post_locked(int $aid, int $pts, string $reason, array $o = []): int {
    if ($pts === 0) return 0;
    $db = db();
    $st = $db->prepare('SELECT points_balance FROM passenger_account WHERE account_id=? FOR UPDATE');
    $st->bind_param('i', $aid); $st->execute();
    $cur = (int)($st->get_result()->fetch_row()[0] ?? 0);
    if ($pts < 0 && $reason === 'Redeemed' && $cur + $pts < 0) throw new RuntimeException('Not enough points.');
    $pts = max($pts, -$cur);   // reversals stop at zero
    if ($pts === 0) return 0;
    $bal = $cur + $pts;
    $pay = $o['payment_id'] ?? null; $vch = $o['voucher_id'] ?? null; $note = $o['note'] ?? null;
    $st = $db->prepare('INSERT INTO points_txn (account_id, points, balance_after, reason, payment_id, voucher_id, note) VALUES (?,?,?,?,?,?,?)');
    $st->bind_param('iiisiis', $aid, $pts, $bal, $reason, $pay, $vch, $note); $st->execute();
    $life = in_array($reason, ['Earned', 'Bonus'], true) ? $pts : 0;
    $st = $db->prepare('UPDATE passenger_account SET points_balance=?, lifetime_points=lifetime_points+? WHERE account_id=?');
    $st->bind_param('iii', $bal, $life, $aid); $st->execute();
    $ltype = $reason === 'Redeemed' ? 'Points Redeemed' : ($pts < 0 ? 'Points Reversed' : 'Points Earned');
    ledger_post($ltype, 0, ['points' => $pts, 'account_id' => $aid, 'payment_id' => $pay, 'description' => $note ?? $reason]);
    audit('Points ' . strtolower($reason) . ' ' . ($pts > 0 ? '+' : '') . $pts, 'passenger_account', $aid, ['points' => $cur], ['points' => $bal, 'note' => $note]);
    return $pts;
}

/** Points for a fare OR just issued (inside the collect transaction). */
function points_award_locked(int $aid, int $payment_id, float $amount, string $or_no): int {
    $tier = acct_tier($aid);
    $pts = (int)floor($amount / max(1, POINTS_PESO_PER_POINT) * $tier['mult']);
    return $pts > 0 ? points_post_locked($aid, $pts, 'Earned', ['payment_id' => $payment_id, 'note' => "Fare $or_no ({$tier['name']} x{$tier['mult']})"]) : 0;
}

/** Take back the points earned with a receipt that is being voided (inside the void transaction). */
function points_reverse_locked(int $payment_id, string $or_no): void {
    $st = db()->prepare("SELECT account_id, SUM(points) FROM points_txn WHERE payment_id=? GROUP BY account_id");
    $st->bind_param('i', $payment_id); $st->execute();
    foreach ($st->get_result()->fetch_all() as [$aid, $net]) {
        if ((int)$net > 0) points_post_locked((int)$aid, -(int)$net, 'Reversed', ['payment_id' => $payment_id, 'note' => "Receipt $or_no voided"]);
    }
}

/** Trade points for a personal voucher (own transaction). */
function points_redeem(int $aid, int $blocks = 1): array {
    $blocks = max(1, min(20, $blocks));
    $need = $blocks * (int)POINTS_REDEEM_BLOCK;
    $value = $blocks * (float)POINTS_REDEEM_VALUE;
    $db = db();
    $db->begin_transaction();
    try {
        $vid = voucher_issue_locked(['source' => 'Points', 'description' => peso($value) . ' off — redeemed ' . $need . ' points',
            'discount_type' => 'Fixed', 'discount_value' => $value, 'account_id' => $aid, 'valid_until' => date('Y-m-d', strtotime('+6 months'))]);
        points_post_locked($aid, -$need, 'Redeemed', ['voucher_id' => $vid, 'note' => 'Redeemed for voucher ' . voucher_code($vid)]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not redeem: database error.'];
    }
    return ['ok' => true, 'msg' => "Redeemed $need points for a " . peso($value) . ' voucher: ' . voucher_code($vid) . '.'];
}

// ═════════════════════════════════════════════════════════════════════════
// Vouchers
// ═════════════════════════════════════════════════════════════════════════

function voucher_code(int $vid): string {
    $st = db()->prepare('SELECT code FROM voucher WHERE voucher_id=?');
    $st->bind_param('i', $vid); $st->execute();
    return (string)($st->get_result()->fetch_row()[0] ?? '');
}

function voucher_new_code(string $prefix): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = $prefix . '-';
        for ($i = 0; $i < 6; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $st = db()->prepare('SELECT 1 FROM voucher WHERE code=?');
        $st->bind_param('s', $c); $st->execute();
    } while ($st->get_result()->fetch_row());
    return $c;
}

/** Create a voucher (inside a transaction or alone). Returns voucher_id. */
function voucher_issue_locked(array $v): int {
    $prefix = ['Promo' => 'PROMO', 'Welcome' => 'WELCOME', 'Points' => 'PTS', 'Loyalty' => 'LOYAL'][$v['source'] ?? 'Promo'] ?? 'PROMO';
    $code  = strtoupper(trim((string)($v['code'] ?? ''))) ?: voucher_new_code($prefix);
    $src   = $v['source'] ?? 'Promo';
    $desc  = mb_substr((string)$v['description'], 0, 150);
    $type  = ($v['discount_type'] ?? 'Percent') === 'Fixed' ? 'Fixed' : 'Percent';
    $val   = (float)$v['discount_value'];
    $max   = isset($v['max_discount']) && $v['max_discount'] !== '' ? (float)$v['max_discount'] : null;
    $min   = (float)($v['min_amount'] ?? 0);
    $from  = $v['valid_from'] ?? null ?: null;
    $until = $v['valid_until'] ?? null ?: null;
    $lim   = isset($v['usage_limit']) && $v['usage_limit'] !== '' ? (int)$v['usage_limit'] : null;
    $per   = max(1, (int)($v['per_account_limit'] ?? 1));
    $req   = !empty($v['requires_account']) || !empty($v['account_id']) ? 1 : 0;
    $aid   = !empty($v['account_id']) ? (int)$v['account_id'] : null;
    if ($aid) $lim = 1;   // a personal reward is used once
    $uid   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $st = db()->prepare('INSERT INTO voucher (code, description, source, discount_type, discount_value, max_discount, min_amount,
            valid_from, valid_until, usage_limit, per_account_limit, requires_account, account_id, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('ssssdddssiiiii', $code, $desc, $src, $type, $val, $max, $min, $from, $until, $lim, $per, $req, $aid, $uid);
    $st->execute();
    $id = (int)db()->insert_id;
    audit('Voucher issued ' . $code, 'voucher', $id, [], ['source' => $src, 'type' => $type, 'value' => $val, 'account_id' => $aid]);
    return $id;
}

/** Peso discount a voucher gives on $fare_total. */
function voucher_discount(array $v, float $fare_total): float {
    $d = $v['discount_type'] === 'Fixed' ? (float)$v['discount_value'] : $fare_total * (float)$v['discount_value'] / 100;
    if ($v['max_discount'] !== null) $d = min($d, (float)$v['max_discount']);
    return round(max(0, min($d, $fare_total)), 2);
}

/**
 * Can $code be used by account $aid (0 = guest) on a booking with $fare_total? $lock: FOR UPDATE (inside a transaction).
 * Returns [voucher|null, error|null, discount].
 */
function voucher_check(string $code, int $aid, float $fare_total, bool $lock = false): array {
    $code = strtoupper(trim($code));
    if ($code === '') return [null, null, 0.0];
    if (!preg_match('/^[A-Z0-9\-]{3,30}$/', $code)) return [null, 'Voucher code not found.', 0.0];
    $st = db()->prepare('SELECT * FROM voucher WHERE code=?' . ($lock ? ' FOR UPDATE' : ''));
    $st->bind_param('s', $code); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    $today = date('Y-m-d');
    $err = null;
    if (!$v || !$v['is_active'])                                   $err = 'Voucher code not found or no longer active.';
    elseif ($v['valid_from'] && $today < $v['valid_from'])         $err = 'This voucher can be used from ' . date('M j, Y', strtotime($v['valid_from'])) . '.';
    elseif ($v['valid_until'] && $today > $v['valid_until'])       $err = 'This voucher expired on ' . date('M j, Y', strtotime($v['valid_until'])) . '.';
    elseif ($v['usage_limit'] !== null && (int)$v['used_count'] >= (int)$v['usage_limit']) $err = 'This voucher has been fully used.';
    elseif ($v['requires_account'] && !$aid)                       $err = 'Log in to your passenger account to use this voucher.';
    elseif ($v['account_id'] && (int)$v['account_id'] !== $aid)   $err = 'This voucher belongs to another passenger account.';
    elseif ($fare_total + 0.001 < (float)$v['min_amount'])         $err = 'This voucher needs a total fare of at least ' . peso($v['min_amount']) . '.';
    elseif ($aid) {
        $st = db()->prepare('SELECT COUNT(*) FROM voucher_redemption WHERE voucher_id=? AND account_id=?');
        $vid = (int)$v['voucher_id'];
        $st->bind_param('ii', $vid, $aid); $st->execute();
        if ((int)$st->get_result()->fetch_row()[0] >= (int)$v['per_account_limit']) $err = 'You have already used this voucher.';
    }
    if ($err) return [null, $err, 0.0];
    $d = voucher_discount($v, $fare_total);
    if ($d <= 0) return [null, 'This voucher gives no discount on this booking.', 0.0];
    return [$v, null, $d];
}

/**
 * Apply a voucher to the fares of a booking just saved (inside the booking transaction).
 * $fares = [passenger_id => fare]. Lowers each fare (largest first), records the redemption and the ledger line.
 * Returns the discount given. Throws DomainException when the voucher can no longer be used.
 */
function voucher_apply_locked(string $code, int $aid, string $ref, array $fares): float {
    $total = array_sum($fares);
    [$v, $err, $disc] = voucher_check($code, $aid, $total, true);
    if (!$v) throw new DomainException($err ?: 'Voucher could not be used.');
    $db = db();
    arsort($fares);
    $left = $disc; $vid = (int)$v['voucher_id']; $n = count($fares); $i = 0;
    $st = $db->prepare('UPDATE passenger SET fare=fare-?, voucher_id=?, voucher_discount=? WHERE passenger_id=?');
    foreach ($fares as $pid => $fare) {
        $i++;
        $part = $i === $n ? $left : min($left, round($disc * $fare / $total, 2));
        $part = min($part, (float)$fare);
        if ($part <= 0) continue;
        $st->bind_param('didi', $part, $vid, $part, $pid); $st->execute();
        $left = round($left - $part, 2);
    }
    $acc = $aid ?: null;
    $st = $db->prepare('INSERT INTO voucher_redemption (voucher_id, account_id, booking_ref, discount_amount) VALUES (?,?,?,?)');
    $st->bind_param('iisd', $vid, $acc, $ref, $disc); $st->execute();
    $st = $db->prepare('UPDATE voucher SET used_count=used_count+1 WHERE voucher_id=?');
    $st->bind_param('i', $vid); $st->execute();
    $first = (int)array_key_first($fares);
    ledger_post('Voucher Discount', -$disc, ['reference_no' => $v['code'], 'account_id' => $acc, 'booking_ref' => $ref,
        'passenger_id' => $first, 'fee_type' => 'Ticket Fare', 'description' => 'Voucher ' . $v['code'] . ' — ' . $v['description']]);
    audit('Voucher redeemed ' . $v['code'], 'voucher', $vid, [], ['booking_ref' => $ref, 'discount' => $disc, 'account_id' => $acc]);
    return $disc;
}

/** Personal vouchers of an account that can still be used. */
function acct_vouchers(int $aid): array {
    $st = db()->prepare("SELECT * FROM voucher WHERE account_id=? AND is_active=1 AND (usage_limit IS NULL OR used_count < usage_limit)
                         AND (valid_until IS NULL OR valid_until >= CURDATE()) ORDER BY valid_until IS NULL, valid_until");
    $st->bind_param('i', $aid); $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Public promo vouchers running now (shown on the Rewards page). */
function promo_vouchers(): array {
    return db()->query("SELECT * FROM voucher WHERE account_id IS NULL AND source='Promo' AND is_active=1
        AND (usage_limit IS NULL OR used_count < usage_limit) AND (valid_from IS NULL OR valid_from <= CURDATE())
        AND (valid_until IS NULL OR valid_until >= CURDATE()) ORDER BY created_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
}

/** Give the loyalty reward for every LOYALTY_TRIP_EVERY completed trips not rewarded yet. Returns new voucher codes. */
function loyalty_check(int $aid): array {
    if (LOYALTY_TRIP_EVERY <= 0 || LOYALTY_TRIP_VALUE <= 0) return [];
    $due = intdiv(acct_trips_done($aid), (int)LOYALTY_TRIP_EVERY);
    $db = db();
    $codes = [];
    $db->begin_transaction();
    try {
        $st = $db->prepare('SELECT loyalty_issued FROM passenger_account WHERE account_id=? FOR UPDATE');
        $st->bind_param('i', $aid); $st->execute();
        $done = (int)($st->get_result()->fetch_row()[0] ?? 0);
        for ($k = $done + 1; $k <= $due; $k++) {
            $trips = $k * (int)LOYALTY_TRIP_EVERY;
            $vid = voucher_issue_locked(['source' => 'Loyalty', 'description' => peso(LOYALTY_TRIP_VALUE) . " off — thank you for $trips trips!",
                'discount_type' => 'Fixed', 'discount_value' => LOYALTY_TRIP_VALUE, 'account_id' => $aid, 'valid_until' => date('Y-m-d', strtotime('+6 months'))]);
            $codes[] = voucher_code($vid);
        }
        if ($due > $done) {
            $st = $db->prepare('UPDATE passenger_account SET loyalty_issued=? WHERE account_id=?');
            $st->bind_param('ii', $due, $aid); $st->execute();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('MANLOAD loyalty_check ' . $aid . ': ' . $e->getMessage());
        return [];
    }
    return $codes;
}

// ═════════════════════════════════════════════════════════════════════════
// Accounts
// ═════════════════════════════════════════════════════════════════════════

/** Register a new account (+ welcome voucher). Returns ['ok', 'msg', 'account_id']. */
function acct_register(array $d): array {
    $db = db();
    $st = $db->prepare('SELECT 1 FROM passenger_account WHERE email=?');
    $st->bind_param('s', $d['email']); $st->execute();
    if ($st->get_result()->fetch_row()) return ['ok' => false, 'msg' => 'An account with this email already exists. Log in instead.'];
    if (acct_find_by_login($d['mobile'])) return ['ok' => false, 'msg' => 'An account with this mobile number already exists. Log in instead.'];   // mobile is a login too
    $db->begin_transaction();
    try {
        $no   = next_number('PA');
        $hash = password_hash($d['password'], PASSWORD_DEFAULT);
        $st = $db->prepare('INSERT INTO passenger_account (account_no, email, password_hash, first_name, last_name, mobile) VALUES (?,?,?,?,?,?)');
        $st->bind_param('ssssss', $no, $d['email'], $hash, $d['first_name'], $d['last_name'], $d['mobile']);
        $st->execute();
        $aid = (int)$db->insert_id;
        audit('Passenger account created ' . $no, 'passenger_account', $aid, [], ['email' => $d['email']]);
        if (WELCOME_VOUCHER_PCT > 0) {
            voucher_issue_locked(['source' => 'Welcome', 'description' => (int)WELCOME_VOUCHER_PCT . '% off your first booking',
                'discount_type' => 'Percent', 'discount_value' => WELCOME_VOUCHER_PCT, 'account_id' => $aid,
                'valid_until' => date('Y-m-d', strtotime('+3 months'))]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('MANLOAD acct_register: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Could not create the account. Please try again.'];
    }
    return ['ok' => true, 'account_id' => $aid, 'msg' => 'Welcome! Your account ' . $no . ' is ready.'];
}

/** Link the tickets of a booking to an account (proof: booking ref / ticket no. + a passenger's last name). */
function acct_link_booking(int $aid, string $code, string $last): array {
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    if (!preg_match('/^[A-Z0-9\-]{4,30}$/', $code) || trim($last) === '') return ['ok' => false, 'msg' => 'Enter the booking reference and a passenger\'s last name.'];
    $st = db()->prepare("SELECT booking_ref FROM passenger WHERE (booking_ref=? OR ticket_no=?) AND booking_ref IS NOT NULL AND booking_ref<>''
                         AND LOWER(last_name)=LOWER(?) LIMIT 1");
    $st->bind_param('sss', $code, $code, $last); $st->execute();
    $ref = $st->get_result()->fetch_row()[0] ?? null;
    if (!$ref) return ['ok' => false, 'msg' => 'No booking matches those details.'];
    $st = db()->prepare('SELECT COUNT(*) FROM passenger WHERE booking_ref=? AND account_id IS NOT NULL AND account_id<>?');
    $st->bind_param('si', $ref, $aid); $st->execute();
    if ((int)$st->get_result()->fetch_row()[0] > 0) return ['ok' => false, 'msg' => "Booking $ref is already linked to another account."];
    $st = db()->prepare('UPDATE passenger SET account_id=? WHERE booking_ref=? AND account_id IS NULL');
    $st->bind_param('is', $aid, $ref); $st->execute();
    $n = db()->affected_rows;
    if ($n) audit('Booking linked to account ' . $ref, 'passenger_account', $aid, [], ['booking_ref' => $ref, 'tickets' => $n]);
    return ['ok' => true, 'msg' => $n ? "Booking $ref ($n ticket(s)) added to your account." : "Booking $ref is already in your account."];
}
