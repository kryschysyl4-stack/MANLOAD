-- Online booking: passengers can attach a photo of their ID for verification.
-- The file lives in storage/id_photos/ (not web-readable); this column holds its file name.
ALTER TABLE `passenger` ADD COLUMN `id_photo` varchar(60) DEFAULT NULL AFTER `id_number`;
-- Staff check of the attached ID photo (Verify Ticket / Booking Details): who checked it, when, and the result
ALTER TABLE `passenger` ADD COLUMN `id_check`      enum('Verified','Rejected') DEFAULT NULL AFTER `id_photo`,
                        ADD COLUMN `id_checked_by` int(11) DEFAULT NULL AFTER `id_check`,
                        ADD COLUMN `id_checked_at` datetime DEFAULT NULL AFTER `id_checked_by`;
