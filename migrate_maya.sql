-- Online payment through the Maya Business (PayMaya) Checkout API — Maya wallet, debit/credit
-- card or QR Ph — replacing the uploaded GCash / Maya QR codes. Safe to run on a database
-- that never had the QR / PayMongo / PayPal changes, and on one that did.

-- Maya API keys, set by the Admin in Setup > System Settings (one row)
CREATE TABLE IF NOT EXISTS `payment_gateway` (
  `gateway_id`     tinyint(4) NOT NULL DEFAULT 1,
  `provider`       varchar(20) NOT NULL DEFAULT 'Maya',
  `mode`           enum('sandbox','live') NOT NULL DEFAULT 'sandbox',
  `public_key`     varchar(255) DEFAULT NULL,
  `secret_key`     varchar(255) DEFAULT NULL,
  `updated_by`     int(11) DEFAULT NULL,
  `updated_at`     datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `payment_gateway` (`gateway_id`) VALUES (1);

-- The Maya checkout opened for a booking's unpaid fares
ALTER TABLE `passenger` ADD COLUMN IF NOT EXISTS `online_pay_checkout` varchar(64) DEFAULT NULL AFTER `online_pay_status`,
                        ADD INDEX IF NOT EXISTS `online_pay_checkout` (`online_pay_checkout`);

-- Receipts paid online by card (Maya wallet / QR Ph payments are recorded as 'Maya')
ALTER TABLE `payment` MODIFY `pay_method`
  enum('Cash','GCash','Maya','Bank Transfer','Card') NOT NULL DEFAULT 'Cash';
