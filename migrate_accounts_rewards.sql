-- ═══════════════════════════════════════════════════════════════════════════
-- Passenger accounts, wallet (credits), loyalty points / tiers, vouchers and
-- the system ledger. Safe to run more than once (IF NOT EXISTS / IGNORE).
--   mysql -uroot manload < sql/migrate_accounts_rewards.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- Passenger self-service account (public site, separate from staff `user`)
CREATE TABLE IF NOT EXISTS passenger_account (
    account_id      INT AUTO_INCREMENT PRIMARY KEY,
    account_no      VARCHAR(20)  NOT NULL UNIQUE,                 -- PA-2026-000001
    email           VARCHAR(100) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    first_name      VARCHAR(50)  NOT NULL,
    last_name       VARCHAR(50)  NOT NULL,
    mobile          VARCHAR(20)  NULL,
    address         VARCHAR(255) NULL,
    profile_id      INT          NULL,                            -- passenger_profile of the holder
    wallet_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    points_balance  INT          NOT NULL DEFAULT 0,
    lifetime_points INT          NOT NULL DEFAULT 0,
    auto_pay        TINYINT(1)   NOT NULL DEFAULT 1,              -- deduct excess baggage / fees from the wallet at check-in
    loyalty_issued  INT          NOT NULL DEFAULT 0,              -- trip-milestone rewards already given
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pa_profile (profile_id),
    CONSTRAINT fk_pa_profile FOREIGN KEY (profile_id) REFERENCES passenger_profile(profile_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet ledger (one row per movement of credits; amount + = in, - = out)
CREATE TABLE IF NOT EXISTS wallet_txn (
    txn_id        INT AUTO_INCREMENT PRIMARY KEY,
    txn_no        VARCHAR(20)  NOT NULL UNIQUE,                   -- WL-2026-000001
    account_id    INT          NOT NULL,
    txn_type      ENUM('Cash-in','Payment','Refund','Adjustment') NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    method        VARCHAR(20)  NULL,                              -- cash-in: Cash / GCash / Maya / Card / Bank Transfer
    reference_no  VARCHAR(100) NULL,
    payment_id    INT          NULL,                              -- OR paid / refunded with this movement
    booking_ref   VARCHAR(20)  NULL,
    note          VARCHAR(255) NULL,
    created_by    INT          NULL,                              -- staff user; NULL = the passenger online
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wt_account (account_id, created_at),
    KEY idx_wt_payment (payment_id),
    CONSTRAINT fk_wt_account FOREIGN KEY (account_id) REFERENCES passenger_account(account_id),
    CONSTRAINT fk_wt_payment FOREIGN KEY (payment_id) REFERENCES payment(payment_id) ON DELETE SET NULL,
    CONSTRAINT fk_wt_user    FOREIGN KEY (created_by) REFERENCES user(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reward points movements
CREATE TABLE IF NOT EXISTS points_txn (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    account_id    INT          NOT NULL,
    points        INT          NOT NULL,                          -- + earned, - redeemed / reversed
    balance_after INT          NOT NULL,
    reason        ENUM('Earned','Redeemed','Reversed','Bonus','Adjustment') NOT NULL,
    payment_id    INT          NULL,
    voucher_id    INT          NULL,
    note          VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pt_account (account_id, created_at),
    KEY idx_pt_payment (payment_id),
    CONSTRAINT fk_pt_account FOREIGN KEY (account_id) REFERENCES passenger_account(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vouchers: promo codes (public) and personal rewards (account_id set)
CREATE TABLE IF NOT EXISTS voucher (
    voucher_id        INT AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(30)  NOT NULL UNIQUE,
    description       VARCHAR(150) NOT NULL,
    source            ENUM('Promo','Welcome','Points','Loyalty') NOT NULL DEFAULT 'Promo',
    discount_type     ENUM('Percent','Fixed') NOT NULL DEFAULT 'Percent',
    discount_value    DECIMAL(10,2) NOT NULL,
    max_discount      DECIMAL(10,2) NULL,                         -- cap for Percent vouchers
    min_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,        -- minimum booking fare
    valid_from        DATE         NULL,
    valid_until       DATE         NULL,
    usage_limit       INT          NULL,                          -- total uses (NULL = unlimited)
    per_account_limit INT          NOT NULL DEFAULT 1,
    requires_account  TINYINT(1)   NOT NULL DEFAULT 0,
    account_id        INT          NULL,                          -- personal voucher of this account only
    used_count        INT          NOT NULL DEFAULT 0,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_by        INT          NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_v_account (account_id),
    CONSTRAINT fk_v_account FOREIGN KEY (account_id) REFERENCES passenger_account(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_v_user    FOREIGN KEY (created_by) REFERENCES user(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_redemption (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    voucher_id      INT          NOT NULL,
    account_id      INT          NULL,
    booking_ref     VARCHAR(20)  NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL,
    redeemed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vr_voucher (voucher_id),
    KEY idx_vr_account (account_id),
    CONSTRAINT fk_vr_voucher FOREIGN KEY (voucher_id) REFERENCES voucher(voucher_id),
    CONSTRAINT fk_vr_account FOREIGN KEY (account_id) REFERENCES passenger_account(account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System ledger: every money / credit / discount / points movement in one place
CREATE TABLE IF NOT EXISTS ledger_entry (
    entry_id      INT AUTO_INCREMENT PRIMARY KEY,
    entry_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    entry_type    ENUM('Payment','Payment Void','Wallet Cash-in','Wallet Payment','Wallet Refund','Wallet Adjustment',
                       'Voucher Discount','Points Earned','Points Redeemed','Points Reversed') NOT NULL,
    amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,            -- pesos (+ in / - out)
    points        INT          NOT NULL DEFAULT 0,
    method        VARCHAR(20)  NULL,
    fee_type      VARCHAR(30)  NULL,
    reference_no  VARCHAR(100) NULL,                              -- OR no / wallet txn no / voucher code
    account_id    INT          NULL,
    passenger_id  INT          NULL,
    payment_id    INT          NULL,
    wallet_txn_id INT          NULL,
    voyage_id     INT          NULL,
    booking_ref   VARCHAR(20)  NULL,
    description   VARCHAR(255) NULL,
    user_id       INT          NULL,
    KEY idx_le_at (entry_at),
    KEY idx_le_type (entry_type, entry_at),
    KEY idx_le_account (account_id),
    KEY idx_le_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tickets booked by / linked to an account, and the voucher applied to them
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='passenger' AND column_name='account_id');
SET @s := IF(@c = 0, 'ALTER TABLE passenger ADD COLUMN account_id INT NULL AFTER profile_id, ADD KEY idx_pax_account (account_id),
    ADD COLUMN voucher_id INT NULL AFTER fare, ADD COLUMN voucher_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER voucher_id', 'SELECT 1');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Wallet as a payment method
ALTER TABLE payment MODIFY pay_method ENUM('Cash','GCash','Maya','Bank Transfer','Card','Wallet') NOT NULL DEFAULT 'Cash';

-- Settings
INSERT IGNORE INTO system_setting (setting_key, setting_value, label, unit, sort_order) VALUES
 ('POINTS_PESO_PER_POINT', '10',    'Fare paid for 1 reward point', 'PHP', 20),
 ('POINTS_REDEEM_BLOCK',   '100',   'Points needed for one reward voucher', 'points', 21),
 ('POINTS_REDEEM_VALUE',   '50',    'Value of one points reward voucher', 'PHP', 22),
 ('WELCOME_VOUCHER_PCT',   '10',    'Welcome voucher for new accounts (0 = none)', '%', 23),
 ('LOYALTY_TRIP_EVERY',    '5',     'Loyalty reward after every N completed trips (0 = off)', 'trips', 24),
 ('LOYALTY_TRIP_VALUE',    '100',   'Loyalty trip reward voucher value', 'PHP', 25),
 ('WALLET_MIN_CASHIN',     '100',   'Smallest wallet cash-in', 'PHP', 26),
 ('WALLET_MAX_BALANCE',    '20000', 'Largest wallet balance allowed', 'PHP', 27);

-- Ledger backfill: the official receipts issued before the ledger existed
INSERT INTO ledger_entry (entry_at, entry_type, amount, method, fee_type, reference_no, passenger_id, payment_id, voyage_id, booking_ref, description, user_id)
SELECT p.paid_at, 'Payment', p.amount, p.pay_method, p.fee_type, p.or_no, p.passenger_id, p.payment_id, p.voyage_id, pa.booking_ref,
       CONCAT(p.fee_type, ' (backfilled)'), p.cashier_id
FROM payment p LEFT JOIN passenger pa ON pa.passenger_id = p.passenger_id
WHERE NOT EXISTS (SELECT 1 FROM ledger_entry le WHERE le.payment_id = p.payment_id AND le.entry_type = 'Payment');

INSERT INTO ledger_entry (entry_at, entry_type, amount, method, fee_type, reference_no, passenger_id, payment_id, voyage_id, booking_ref, description, user_id)
SELECT p.paid_at, 'Payment Void', -p.amount, p.pay_method, p.fee_type, p.or_no, p.passenger_id, p.payment_id, p.voyage_id, pa.booking_ref,
       CONCAT('Voided: ', IFNULL(p.void_reason, ''), ' (backfilled)'), p.voided_by
FROM payment p LEFT JOIN passenger pa ON pa.passenger_id = p.passenger_id
WHERE p.is_voided = 1 AND NOT EXISTS (SELECT 1 FROM ledger_entry le WHERE le.payment_id = p.payment_id AND le.entry_type = 'Payment Void');
