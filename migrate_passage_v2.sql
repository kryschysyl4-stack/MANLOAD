-- Passage booking update
--   * passenger type "Regular" is now called "Adult"
--   * Minors (2–17) get their own fare discount (System Settings)
ALTER TABLE `passenger` MODIFY `pax_category` enum('Regular','Adult','Student','Minor','Senior','Infant','PWD') NOT NULL DEFAULT 'Adult';
UPDATE `passenger` SET `pax_category`='Adult' WHERE `pax_category`='Regular';
ALTER TABLE `passenger` MODIFY `pax_category` enum('Adult','Student','Minor','Senior','Infant','PWD') NOT NULL DEFAULT 'Adult';
INSERT IGNORE INTO `system_setting` (`setting_key`,`setting_value`,`label`,`unit`,`sort_order`) VALUES
('MINOR_DISCOUNT_PCT','50','Minor (2–17 years old) fare discount','%',5);

-- Seats per accommodation (optional; a class without a row shares max_passengers)
CREATE TABLE IF NOT EXISTS `vessel_class_seat` (
  `vessel_id` int(11) NOT NULL,
  `class`     enum('Economy','Tourist','Business','Cabin') NOT NULL,
  `capacity`  int(11) NOT NULL,
  PRIMARY KEY (`vessel_id`,`class`),
  CONSTRAINT `vcs_vessel_fk` FOREIGN KEY (`vessel_id`) REFERENCES `vessel` (`vessel_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Print remarks (printed on the ticket)
ALTER TABLE `passenger` ADD COLUMN IF NOT EXISTS `remarks` varchar(255) DEFAULT NULL AFTER `emergency_contact`;

-- No Cabin accommodation
DELETE FROM `route_fare` WHERE `class`='Cabin';
DELETE FROM `vessel_class_seat` WHERE `class`='Cabin';
ALTER TABLE `route_fare`        MODIFY `class` enum('Economy','Tourist','Business') NOT NULL;
ALTER TABLE `vessel_class_seat` MODIFY `class` enum('Economy','Tourist','Business') NOT NULL;
ALTER TABLE `passenger`         MODIFY `class` enum('Economy','Tourist','Business') NOT NULL DEFAULT 'Economy';

-- Vehicle types that travel with a driver (motorcycles, heavy trucks)
ALTER TABLE `vehicle_type` ADD COLUMN IF NOT EXISTS `with_driver` tinyint(1) NOT NULL DEFAULT 0 AFTER `weight_kg`;
UPDATE `vehicle_type` SET `with_driver`=1 WHERE `type_name` IN ('Motorcycle','Heavy Truck','Trailer');

-- Who issued each ticket (the staff user who booked it; NULL = booked online)
ALTER TABLE `passenger` ADD COLUMN IF NOT EXISTS `booked_by` int(11) DEFAULT NULL AFTER `booked_at`;
ALTER TABLE `passenger` ADD CONSTRAINT `pax_bookedby_fk` FOREIGN KEY IF NOT EXISTS (`booked_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL;
UPDATE `passenger` p JOIN `audit_log` a ON a.action = CONCAT('Counter booking ', p.booking_ref)
   SET p.booked_by = a.user_id WHERE p.booked_by IS NULL AND p.booked_via = 'Counter';
UPDATE `passenger` SET booked_by = checkin_by
 WHERE booked_by IS NULL AND booked_via = 'Counter' AND checkin_by IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, booked_at, checkin_time)) < 5;

-- Trip notes (internal) and public remarks (shown to passengers on the booking pages)
ALTER TABLE `voyage` ADD COLUMN IF NOT EXISTS `notes` varchar(255) DEFAULT NULL AFTER `created_at`;
ALTER TABLE `voyage` ADD COLUMN IF NOT EXISTS `public_remarks` varchar(255) DEFAULT NULL AFTER `notes`;
