<?php
/**
 * Business settings come from the database (tables `system_setting` and
 * `payment_account`), editable by the Admin in Setup > System Settings.
 * They are exposed as constants so every page can use them directly.
 * The defaults below are used only if a row is missing.
 */
require_once __DIR__ . '/../config/db.php';

const SETTING_DEFAULTS = [
    'PAX_AVG_WEIGHT_KG'      => 65,
    'LOAD_WARN_PCT'          => 80,
    'LOAD_CRITICAL_PCT'      => 95,
    'DISCOUNT_PCT'           => 20,
    'MINOR_DISCOUNT_PCT'     => 50,
    'INFANT_FARE'            => 0,
    'BOOKING_CUTOFF_MINUTES' => 30,
    'BOOKING_DAYS_AHEAD'     => 30,
    'TICKET_GRACE_HOURS'     => 2,
    'CLEARANCE_VALID_HOURS'  => 48,
    'ARCHIVE_KEEP_MONTHS'    => 2,
    // Passenger accounts, wallet and rewards (includes/wallet.php)
    'POINTS_PESO_PER_POINT'  => 10,
    'POINTS_REDEEM_BLOCK'    => 100,
    'POINTS_REDEEM_VALUE'    => 50,
    'WELCOME_VOUCHER_PCT'    => 10,
    'LOYALTY_TRIP_EVERY'     => 5,
    'LOYALTY_TRIP_VALUE'     => 100,
    'WALLET_MIN_CASHIN'      => 100,
    'WALLET_MAX_BALANCE'     => 20000,
];

(function () {
    $values = SETTING_DEFAULTS;
    foreach (db()->query('SELECT setting_key, setting_value FROM system_setting')->fetch_all() as [$k, $v]) {
        if (array_key_exists($k, $values) && is_numeric($v)) $values[$k] = $v + 0;
    }
    foreach ($values as $k => $v) if (!defined($k)) define($k, $v);

    // Online payment = PayMongo checkout mock-up (includes/paymongo_mock.php); the Admin can switch it off.
    $accounts = [];
    $provider = db()->query('SELECT provider FROM payment_gateway WHERE gateway_id = 1')->fetch_row()[0] ?? 'PayMongo';
    if ($provider !== 'Off') $accounts = ['GCash' => ['name' => 'GCash (via PayMongo)'], 'Maya' => ['name' => 'Maya (via PayMongo)'], 'Card' => ['name' => 'Debit / credit card (via PayMongo)']];
    if (!defined('ONLINE_PAY_ACCOUNTS')) define('ONLINE_PAY_ACCOUNTS', $accounts);
})();

const LOAD_MAX_PCT = 100;                                 // over 100 % is always blocked
const DISCOUNTED_CATEGORIES = ['Senior', 'PWD', 'Student']; // RA 9994, RA 10754, RA 11314
