<?php
/**
 * PayMongo checkout — MOCK-UP (no PayMongo account / API, no real money).
 *
 * Flow: the passenger chooses Pay Online → pm_start_checkout() opens a checkout session for the
 * fares still due → modules/payments/paymongo_checkout.php shows a PayMongo-style checkout page
 * (GCash, Maya or card) → "Pay" calls pm_record(), which issues the ORs exactly like a cashier's
 * Verify (verify_online_payment) and marks the tickets PAID.
 *
 * The Admin turns online payment on/off in Setup › System Settings (payment_gateway.provider:
 * 'PayMongo' = on, 'Off' = off).
 */
require_once __DIR__ . '/checkin_helpers.php';

// PayMongo payment method → our pay_method (payment.pay_method enum)
const PM_METHODS = [
    'gcash' => ['label' => 'GCash',               'method' => 'GCash'],
    'maya'  => ['label' => 'Maya',                'method' => 'Maya'],
    'card'  => ['label' => 'Debit / Credit Card', 'method' => 'Card'],
];

/** Online payment switched on by the Admin? */
function pm_enabled(): bool {
    static $on = null;
    if ($on === null) $on = (db()->query('SELECT provider FROM payment_gateway WHERE gateway_id=1')->fetch_row()[0] ?? 'PayMongo') !== 'Off';
    return $on;
}

/** Unpaid passenger fares of a booking (the part paid online — vehicle freight is paid at the port). */
function pm_due_rows(string $ref): array {
    $st = db()->prepare("SELECT passenger_id, last_name, first_name, class, pax_category, fare, email, contact_no, online_pay_checkout
                         FROM passenger WHERE booking_ref=? AND fare_paid=0 AND fare > 0 AND status NOT IN ('Cancelled','No-show')
                         ORDER BY passenger_id");
    $st->bind_param('s', $ref); $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Checkout page link of a session. */
function pm_checkout_url(string $ref, string $cs): string {
    return BASE_URL . '/modules/payments/paymongo_checkout.php?ref=' . urlencode($ref) . '&k=' . booking_key($ref) . '&cs=' . urlencode($cs);
}

/**
 * Open a checkout session for the fares still due on a booking.
 * Returns the checkout page URL to send the passenger to. Throws RuntimeException.
 */
function pm_start_checkout(string $ref): string {
    if (!pm_enabled()) throw new RuntimeException('Online payment is not available right now.');
    $rows = pm_due_rows($ref);
    if (!$rows) throw new RuntimeException('Nothing left to pay on this booking.');
    $due = round(array_sum(array_map(fn($p) => (float)$p['fare'], $rows)), 2);
    $cs  = 'cs_' . bin2hex(random_bytes(12));   // PayMongo-style checkout session id

    $st = db()->prepare("UPDATE passenger SET online_pay_checkout=?
                         WHERE booking_ref=? AND fare_paid=0 AND fare > 0 AND status NOT IN ('Cancelled','No-show')");
    $st->bind_param('ss', $cs, $ref); $st->execute();
    audit('Online payment started ' . $ref . ' (PayMongo mock-up)', 'passenger', (int)$rows[0]['passenger_id'], [], ['checkout' => $cs, 'amount' => $due]);
    return pm_checkout_url($ref, $cs);
}

/**
 * Record a successful online payment: issue the ORs, or leave it for a cashier if the amount differs.
 * Returns: 'paid' — ORs issued, tickets PAID · 'review' — amount differs, left for Online Payments
 *          'done' — already recorded / nothing left to pay
 */
function pm_record(string $ref, string $pay_ref, float $amount, string $method): string {
    $db = db();
    $st = $db->prepare("SELECT 1 FROM payment WHERE reference_no=? AND is_voided=0 LIMIT 1");
    $st->bind_param('s', $pay_ref); $st->execute();
    if ($st->get_result()->fetch_row()) return 'done';

    $rows = pm_due_rows($ref);
    if (!$rows) return 'done';
    $due = round(array_sum(array_map(fn($p) => (float)$p['fare'], $rows)), 2);

    // Mark the fares as an online payment received, then issue the ORs (same path as a cashier's Verify)
    $st = $db->prepare("UPDATE passenger SET online_pay_method=?, online_pay_ref=?, online_pay_status='pending'
                        WHERE booking_ref=? AND fare_paid=0 AND fare > 0 AND status NOT IN ('Cancelled','No-show')");
    $st->bind_param('sss', $method, $pay_ref, $ref); $st->execute();

    if (abs($amount - $due) > 0.009) {
        audit('Online payment amount differs ' . $ref, 'passenger', (int)$rows[0]['passenger_id'], [], ['payment' => $pay_ref, 'paid' => $amount, 'due' => $due]);
        return 'review';
    }
    $r = verify_online_payment($ref, null);
    if ($r['ok']) return 'paid';
    if (str_starts_with($r['msg'], 'No pending online payment')) return 'done';
    throw new RuntimeException($r['msg']);
}
