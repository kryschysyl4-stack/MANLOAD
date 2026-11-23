-- Payment QR codes: the GCash / Maya QR image passengers scan when paying online.
ALTER TABLE `payment_account` ADD COLUMN `qr_image` varchar(255) DEFAULT NULL AFTER `account_number`;
-- The text inside the QR (QR Ph / EMVCo). The booking page adds the amount to it and draws the QR.
ALTER TABLE `payment_account` ADD COLUMN `qr_payload` varchar(512) DEFAULT NULL AFTER `qr_image`;
