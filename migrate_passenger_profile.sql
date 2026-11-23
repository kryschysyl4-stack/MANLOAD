-- Passenger profiles: one row per real person, so repeat travellers keep their details.
-- Each ticket (passenger row) links to its profile through passenger.profile_id.
-- Matching (link_passenger_profile() in includes/functions.php):
--   1) same ID type + ID number, else 2) same name + sex + mobile no., else 3) same name + sex + e-mail.
-- After importing, run tools/backfill_profiles.php once to link the existing tickets.
CREATE TABLE IF NOT EXISTS `passenger_profile` (
  `profile_id`        int(11) NOT NULL AUTO_INCREMENT,
  `last_name`         varchar(50) NOT NULL,
  `first_name`        varchar(50) NOT NULL,
  `middle_name`       varchar(50) DEFAULT NULL,
  `sex`               enum('Male','Female') NOT NULL,
  `age`               int(11) DEFAULT NULL,                 -- age on the latest trip
  `nationality`       varchar(50) DEFAULT 'Filipino',
  `pax_category`      enum('Adult','Student','Minor','Senior','Infant','PWD') NOT NULL DEFAULT 'Adult',
  `id_type`           varchar(30) DEFAULT NULL,
  `id_number`         varchar(30) DEFAULT NULL,
  `contact_no`        varchar(20) DEFAULT NULL,
  `email`             varchar(100) DEFAULT NULL,
  `address`           text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `trips`             int(11) NOT NULL DEFAULT 0,           -- tickets linked to this profile
  `last_trip_at`      datetime DEFAULT NULL,
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`profile_id`),
  KEY `name` (`last_name`,`first_name`),
  KEY `id_doc` (`id_type`,`id_number`),
  KEY `contact_no` (`contact_no`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `passenger` ADD COLUMN `profile_id` int(11) DEFAULT NULL AFTER `booked_via`,
                        ADD KEY `profile_id` (`profile_id`),
                        ADD CONSTRAINT `pax_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `passenger_profile` (`profile_id`) ON DELETE SET NULL;
