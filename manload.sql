-- =====================================================================
-- MANLOAD : An Automated System for Vessel Manifest and Load Control
-- Complete database schema + seed data (MariaDB 10.4+ / MySQL 8)
--
-- Import into an EMPTY database named `manload`:
--   phpMyAdmin > Import > manload.sql
--
-- Every column the application uses is defined here. The PHP code no
-- longer changes the database structure at runtime.
--
-- Default login for every seeded account:  password
-- (change these from Profile after the first login)
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+08:00";
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `login_throttle`, `system_setting`, `payment_account`, `archive_passenger`, `archive_voyage`, `archive_batch`,
  `departure_clearance`, `clearance_document`,
  `payment`, `baggage`, `cargo`, `vehicle`, `passenger`, `passenger_profile`, `voyage`,
  `schedule_template`, `vessel_zone`, `vessel`, `route_fare`, `route`,
  `vehicle_type`, `audit_log`, `user`, `port`, `doc_counter`;

-- ---------------------------------------------------------------------
-- Ports
-- ---------------------------------------------------------------------
CREATE TABLE `port` (
  `port_id`    int(11) NOT NULL AUTO_INCREMENT,
  `port_code`  varchar(10) NOT NULL,
  `port_name`  varchar(100) NOT NULL,
  `location`   varchar(100) DEFAULT NULL,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`port_id`),
  UNIQUE KEY `port_code` (`port_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `port` (`port_id`,`port_code`,`port_name`,`location`) VALUES
(1,'SUG','Surigao City Port','Surigao City, Surigao del Norte'),
(2,'DPA','Dapa Port, Siargao','Dapa, Surigao del Norte'),
(3,'SJO','San Jose Port, Dinagat','San Jose, Dinagat Islands'),
(4,'DGT','Dumaguete Port','Dumaguete City, Negros Oriental'),
(5,'DAP','Dapitan Port','Dapitan City, Zamboanga del Norte');

-- ---------------------------------------------------------------------
-- Users  (roles: see config/auth.php ROLES)
-- ---------------------------------------------------------------------
CREATE TABLE `user` (
  `user_id`       int(11) NOT NULL AUTO_INCREMENT,
  `username`      varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name`     varchar(100) NOT NULL,
  `email`         varchar(100) DEFAULT NULL,
  `role`          enum('Admin','Passage','Coast Guard') NOT NULL DEFAULT 'Passage',
  `port_id`       int(11) DEFAULT NULL,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `last_login`    datetime DEFAULT NULL,
  `created_at`    datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  KEY `port_id` (`port_id`),
  CONSTRAINT `user_port_fk` FOREIGN KEY (`port_id`) REFERENCES `port` (`port_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- password for all seeded users = "password"
INSERT INTO `user` (`user_id`,`username`,`password_hash`,`full_name`,`email`,`role`,`port_id`) VALUES
(1,'admin',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','System Administrator',NULL,'Admin',1),
(3,'passage1',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Passage Staff One',NULL,'Passage',1),
(6,'coastguard1','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','PCG Inspector One',NULL,'Coast Guard',1);

-- ---------------------------------------------------------------------
-- System settings (editable by Admin in Setup > System Settings)
-- ---------------------------------------------------------------------
CREATE TABLE `system_setting` (
  `setting_key`   varchar(40) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `label`         varchar(120) NOT NULL,
  `unit`          varchar(20) DEFAULT NULL,
  `sort_order`    int(11) NOT NULL DEFAULT 0,
  `updated_by`    int(11) DEFAULT NULL,
  `updated_at`    datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_setting` (`setting_key`,`setting_value`,`label`,`unit`,`sort_order`) VALUES
('PAX_AVG_WEIGHT_KG','65','Average passenger weight used in the load computation','kg',1),
('LOAD_WARN_PCT','80','Load warning level (yellow)','%',2),
('LOAD_CRITICAL_PCT','95','Load critical level (red)','%',3),
('DISCOUNT_PCT','20','Senior / PWD / Student fare discount','%',4),
('INFANT_FARE','0','Infant fare (under 2 years old)','PHP',5),
('MINOR_DISCOUNT_PCT','50','Minor (2–17 years old) fare discount','%',5),
('BOOKING_CUTOFF_MINUTES','30','Online booking closes before departure','minutes',6),
('BOOKING_DAYS_AHEAD','30','How many days ahead passengers can book','days',7),
('TICKET_GRACE_HOURS','2','Ticket stays valid after scheduled departure','hours',8),
('CLEARANCE_VALID_HOURS','48','Clearance certificate stays valid after departure','hours',9),
('ARCHIVE_KEEP_MONTHS','2','Months kept live before the monthly batch may archive them','months',10);

-- ---------------------------------------------------------------------
-- Accounts passengers pay to when paying online (Setup > System Settings)
-- ---------------------------------------------------------------------
CREATE TABLE `payment_account` (
  `account_id`     int(11) NOT NULL AUTO_INCREMENT,
  `method`         enum('GCash','Maya','Bank Transfer') NOT NULL,
  `account_name`   varchar(100) NOT NULL,
  `account_number` varchar(60) NOT NULL,
  `qr_image`       varchar(255) DEFAULT NULL,
  `qr_payload`     varchar(512) DEFAULT NULL,
  `is_active`      tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `method` (`method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_account` (`method`,`account_name`,`account_number`) VALUES
('GCash','MANLOAD Port Services','0917-000-0000'),
('Maya','MANLOAD Port Services','0918-000-0000'),
('Bank Transfer','MANLOAD Port Services','LANDBANK 0000-0000-00');

-- Maya Business (PayMaya) API keys for online payment (Admin › System Settings)
CREATE TABLE `payment_gateway` (
  `gateway_id`     tinyint(4) NOT NULL DEFAULT 1,
  `provider`       varchar(20) NOT NULL DEFAULT 'Maya',
  `mode`           enum('sandbox','live') NOT NULL DEFAULT 'sandbox',
  `public_key`     varchar(255) DEFAULT NULL,
  `secret_key`     varchar(255) DEFAULT NULL,
  `updated_by`     int(11) DEFAULT NULL,
  `updated_at`     datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `payment_gateway` (`gateway_id`) VALUES (1);

-- ---------------------------------------------------------------------
-- Audit log  (user_id NULL = public / system action)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_log` (
  `log_id`     int(11) NOT NULL AUTO_INCREMENT,
  `user_id`    int(11) DEFAULT NULL,
  `action`     varchar(150) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id`  int(11) DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `logged_at`  datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `logged_at` (`logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-in counter (login.php): 5 misses per username + IP = timed lock
CREATE TABLE `login_throttle` (
  `throttle_key` varchar(120) NOT NULL,
  `fails`        tinyint(3) unsigned NOT NULL DEFAULT 0,
  `lockouts`     tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`throttle_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sequential document numbers (OR, waybill, ticket, certificate...)
-- Incremented atomically by next_number() in includes/functions.php
-- ---------------------------------------------------------------------
CREATE TABLE `doc_counter` (
  `counter_key` varchar(40) NOT NULL,
  `last_value`  int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`counter_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Routes + fares per class
-- ---------------------------------------------------------------------
CREATE TABLE `route` (
  `route_id`              int(11) NOT NULL AUTO_INCREMENT,
  `route_code`            varchar(20) NOT NULL,
  `origin_port_id`        int(11) NOT NULL,
  `destination_port_id`   int(11) NOT NULL,
  `est_travel_hours`      decimal(5,2) DEFAULT NULL,
  `baggage_allowance_eco` decimal(8,2) NOT NULL DEFAULT 20.00,
  `baggage_allowance_bus` decimal(8,2) NOT NULL DEFAULT 30.00,
  `baggage_allowance_cab` decimal(8,2) NOT NULL DEFAULT 40.00,
  `excess_rate_per_kg`    decimal(8,2) NOT NULL DEFAULT 0.00,
  `freight_rate_per_kg`   decimal(8,2) NOT NULL DEFAULT 0.00,
  `hazardous_surcharge`   decimal(10,2) NOT NULL DEFAULT 500.00,
  `is_active`             tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`route_id`),
  UNIQUE KEY `route_code` (`route_code`),
  KEY `origin_port_id` (`origin_port_id`),
  KEY `destination_port_id` (`destination_port_id`),
  CONSTRAINT `route_orig_fk` FOREIGN KEY (`origin_port_id`) REFERENCES `port` (`port_id`),
  CONSTRAINT `route_dest_fk` FOREIGN KEY (`destination_port_id`) REFERENCES `port` (`port_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `route` (`route_id`,`route_code`,`origin_port_id`,`destination_port_id`,`est_travel_hours`,`excess_rate_per_kg`,`freight_rate_per_kg`) VALUES
(1,'SUG-DPA',1,2,1.50,30.00,15.00),
(2,'DPA-SUG',2,1,1.50,30.00,15.00),
(3,'SUG-SJO',1,3,1.00,30.00,15.00),
(4,'SJO-SUG',3,1,1.00,30.00,15.00),
(5,'DGT-DAP',4,5,2.50,30.00,15.00),
(6,'DAP-DGT',5,4,2.50,30.00,15.00);

CREATE TABLE `route_fare` (
  `fare_id`  int(11) NOT NULL AUTO_INCREMENT,
  `route_id` int(11) NOT NULL,
  `class`    enum('Economy','Tourist','Business') NOT NULL,
  `label`    varchar(60) NOT NULL,
  `amount`   decimal(10,2) NOT NULL,
  PRIMARY KEY (`fare_id`),
  UNIQUE KEY `route_class` (`route_id`,`class`),
  CONSTRAINT `fare_route_fk` FOREIGN KEY (`route_id`) REFERENCES `route` (`route_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `route_fare` (`route_id`,`class`,`label`,`amount`) VALUES
(1,'Economy','Economy',560),(1,'Tourist','Tourist (Aircon)',610),(1,'Business','Business Class',680),
(2,'Economy','Economy',560),(2,'Tourist','Tourist (Aircon)',610),(2,'Business','Business Class',680),
(3,'Economy','Economy',350),(3,'Tourist','Tourist (Aircon)',400),(3,'Business','Business / Premium',480),
(4,'Economy','Economy',350),(4,'Tourist','Tourist (Aircon)',400),(4,'Business','Business / Premium',480),
(5,'Economy','Economy / Non-AC',650),(5,'Tourist','Tourist Class',720),(5,'Business','Business Class',850),
(6,'Economy','Economy / Non-AC',650),(6,'Tourist','Tourist Class',720),(6,'Business','Business Class',850);

-- ---------------------------------------------------------------------
-- Vehicle types (weight_kg is used in the load computation)
-- ---------------------------------------------------------------------
CREATE TABLE `vehicle_type` (
  `type_id`     int(11) NOT NULL AUTO_INCREMENT,
  `type_name`   varchar(50) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `base_rate`   decimal(10,2) NOT NULL DEFAULT 0.00,
  `weight_kg`   decimal(10,2) NOT NULL DEFAULT 1500.00,
  `with_driver` tinyint(1) NOT NULL DEFAULT 0,   -- 1 = a driver travels with it (motorcycles, heavy trucks)
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `created_at`  datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vehicle_type` (`type_id`,`type_name`,`description`,`base_rate`,`weight_kg`,`with_driver`) VALUES
(1,'Motorcycle','2-wheel motor vehicle',350,150,1),
(2,'Car / Sedan','4-door passenger car',850,1300,0),
(3,'SUV / Van','Sports utility vehicle or van',950,1800,0),
(4,'Jeepney','PUJ / AUV',1200,2500,0),
(5,'Light Truck','Pick-up truck, L300, delivery van',1500,3000,0),
(6,'Heavy Truck','6-wheel cargo truck or larger',2500,10000,1),
(7,'Bus','Passenger bus',3000,12000,0),
(8,'Trailer','Articulated / container trailer',5000,20000,1);

-- ---------------------------------------------------------------------
-- Vessels
--   max_passengers : passenger capacity (PCG / MARINA certificate)
--   max_load_kg    : total allowable deadweight in kilograms
--   max_vehicles   : vehicle slots (0 = no vehicles, e.g. fast craft)
--   maintenance    : 1 = Under Maintenance -> no booking / no departure
-- ---------------------------------------------------------------------
CREATE TABLE `vessel` (
  `vessel_id`         int(11) NOT NULL AUTO_INCREMENT,
  `vessel_name`       varchar(100) NOT NULL,
  `vessel_type`       enum('RORO','Ferry','Fast Craft','Passenger Ship') NOT NULL,
  `marina_no`         varchar(30) DEFAULT NULL,
  `operator`          varchar(100) DEFAULT NULL,
  `max_passengers`    int(11) NOT NULL,
  `max_load_kg`       decimal(12,2) NOT NULL,
  `max_vehicles`      int(11) NOT NULL DEFAULT 0,
  `deck_count`        int(11) NOT NULL DEFAULT 1,
  `is_active`         tinyint(1) NOT NULL DEFAULT 1,
  `maintenance`       tinyint(1) NOT NULL DEFAULT 0,
  `maintenance_note`  varchar(255) DEFAULT NULL,
  `maintenance_since` date DEFAULT NULL,
  `maintenance_until` date DEFAULT NULL,
  `created_at`        datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`vessel_id`),
  UNIQUE KEY `vessel_name` (`vessel_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vessel` (`vessel_id`,`vessel_name`,`vessel_type`,`marina_no`,`operator`,`max_passengers`,`max_load_kg`,`max_vehicles`,`deck_count`) VALUES
(1,'M/V Nathan Ferry 3',  'Fast Craft','MARINA-FC-0003','Nathan Shipping Lines',  374,15000,0,2),
(2,'M/V Sean Ferry 2',    'Fast Craft','MARINA-FC-0002','Sean Shipping Lines',    374,15000,0,2),
(3,'M/V Evaristo Ferry 8','RORO',      'MARINA-RO-0008','Evaristo Shipping Lines',270,60000,20,3),
(4,'M/V Erica Ferry 9',   'RORO',      'MARINA-RO-0009','Erica Shipping Lines',   270,35000,12,2);

-- Seats per accommodation (optional; a class without a row shares max_passengers)
CREATE TABLE `vessel_class_seat` (
  `vessel_id` int(11) NOT NULL,
  `class`     enum('Economy','Tourist','Business') NOT NULL,
  `capacity`  int(11) NOT NULL,
  PRIMARY KEY (`vessel_id`,`class`),
  CONSTRAINT `vcs_vessel_fk` FOREIGN KEY (`vessel_id`) REFERENCES `vessel` (`vessel_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vessel_class_seat` (`vessel_id`,`class`,`capacity`) VALUES
(1,'Business',99),(1,'Tourist',245),(1,'Economy',30),
(2,'Business',99),(2,'Tourist',245),(2,'Economy',30),
(3,'Business',0),(3,'Tourist',0),(3,'Economy',270),     -- RORO: 270 Economy seats only
(4,'Business',0),(4,'Tourist',0),(4,'Economy',270);

-- ---------------------------------------------------------------------
-- Load zones per vessel (A1, A2, B1 ...)
-- Vehicles and cargo are sorted into these zones BEFORE departure so the
-- Coast Guard only needs to verify the load plan.
-- ---------------------------------------------------------------------
CREATE TABLE `vessel_zone` (
  `zone_id`       int(11) NOT NULL AUTO_INCREMENT,
  `vessel_id`     int(11) NOT NULL,
  `zone_code`     varchar(10) NOT NULL,
  `zone_name`     varchar(60) NOT NULL,
  `zone_use`      enum('Vehicle','Cargo','Mixed') NOT NULL DEFAULT 'Mixed',
  `max_kg`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_vehicles`  int(11) NOT NULL DEFAULT 0,
  `allows_hazard` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order`    int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`zone_id`),
  UNIQUE KEY `vessel_zone` (`vessel_id`,`zone_code`),
  CONSTRAINT `zone_vessel_fk` FOREIGN KEY (`vessel_id`) REFERENCES `vessel` (`vessel_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vessel_zone` (`vessel_id`,`zone_code`,`zone_name`,`zone_use`,`max_kg`,`max_vehicles`,`allows_hazard`,`sort_order`) VALUES
(1,'A1','Forward Cargo Hold','Cargo',2500,0,0,1),
(1,'A2','Aft Cargo Hold',    'Cargo',1500,0,0,2),
(2,'A1','Forward Cargo Hold','Cargo',2500,0,0,1),
(2,'A2','Aft Cargo Hold',    'Cargo',1500,0,0,2),
(3,'A1','Main Car Deck - Heavy Lane','Vehicle',30000,6,0,1),
(3,'A2','Main Car Deck - Light Lane','Vehicle',12000,14,0,2),
(3,'B1','Cargo Hold',               'Cargo',  8000,0,1,3),
(4,'A1','Car Deck - Heavy Lane',    'Vehicle',16000,4,0,1),
(4,'A2','Car Deck - Light Lane',    'Vehicle',6000,8,0,2),
(4,'B1','Cargo Hold',               'Cargo',  4000,0,1,3);

-- ---------------------------------------------------------------------
-- Weekly schedule template used by "Generate Schedule" (Voyages page)
-- ---------------------------------------------------------------------
CREATE TABLE `schedule_template` (
  `template_id`    int(11) NOT NULL AUTO_INCREMENT,
  `template_name`  varchar(60) DEFAULT NULL,
  `vessel_id`      int(11) NOT NULL,
  `route_id`       int(11) NOT NULL,
  `departure_time` time NOT NULL,
  `run_days`       varchar(13) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `link_group`     int(11) DEFAULT NULL,
  `is_active`      tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `trip` (`vessel_id`,`route_id`,`departure_time`),
  KEY `link_group` (`link_group`),
  CONSTRAINT `tpl_vessel_fk` FOREIGN KEY (`vessel_id`) REFERENCES `vessel` (`vessel_id`) ON DELETE CASCADE,
  CONSTRAINT `tpl_route_fk`  FOREIGN KEY (`route_id`)  REFERENCES `route` (`route_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schedule_template` (`vessel_id`,`route_id`,`departure_time`) VALUES
(1,1,'06:00:00'),(2,1,'10:00:00'),(3,1,'10:30:00'),(1,1,'14:00:00'),(2,1,'15:30:00'),
(1,2,'05:00:00'),(2,2,'06:00:00'),(1,2,'11:30:00'),(2,2,'13:00:00'),(3,2,'16:00:00'),
(4,3,'04:30:00'),(4,4,'08:00:00'),(4,3,'11:00:00'),(4,4,'15:00:00'),
(3,5,'04:30:00'),(3,6,'14:00:00');

-- ---------------------------------------------------------------------
-- Voyages
--   manifest_status : Open -> In Progress -> Locked -> Departed
--   ready_to_board  : set by the Coast Guard after verification
-- ---------------------------------------------------------------------
CREATE TABLE `voyage` (
  `voyage_id`       int(11) NOT NULL AUTO_INCREMENT,
  `voyage_no`       varchar(40) NOT NULL,
  `vessel_id`       int(11) NOT NULL,
  `route_id`        int(11) NOT NULL,
  `departure_date`  date NOT NULL,
  `departure_time`  time NOT NULL,
  `manifest_status` enum('Open','In Progress','Locked','Submitted','Departed','Cancelled') NOT NULL DEFAULT 'Open',
  `locked_by`       int(11) DEFAULT NULL,
  `locked_at`       datetime DEFAULT NULL,
  `total_pax`       int(11) NOT NULL DEFAULT 0,
  `total_crew`      int(11) NOT NULL DEFAULT 0,
  `total_load_kg`   decimal(12,2) NOT NULL DEFAULT 0.00,
  `ready_to_board`  tinyint(1) NOT NULL DEFAULT 0,
  `ready_by`        int(11) DEFAULT NULL,
  `ready_at`        datetime DEFAULT NULL,
  `departed_at`     datetime DEFAULT NULL,
  `archived`        tinyint(1) NOT NULL DEFAULT 0,
  `archived_at`     datetime DEFAULT NULL,
  `created_by`      int(11) DEFAULT NULL,
  `created_at`      datetime DEFAULT current_timestamp(),
  `notes`           varchar(255) DEFAULT NULL,          -- internal notes (staff only)
  `public_remarks`  varchar(255) DEFAULT NULL,          -- shown to passengers on the booking pages
  PRIMARY KEY (`voyage_id`),
  UNIQUE KEY `voyage_no` (`voyage_no`),
  UNIQUE KEY `vessel_departure` (`vessel_id`,`departure_date`,`departure_time`),
  KEY `route_id` (`route_id`),
  KEY `departure_date` (`departure_date`),
  CONSTRAINT `voyage_vessel_fk` FOREIGN KEY (`vessel_id`) REFERENCES `vessel` (`vessel_id`),
  CONSTRAINT `voyage_route_fk`  FOREIGN KEY (`route_id`)  REFERENCES `route` (`route_id`),
  CONSTRAINT `voyage_locked_fk` FOREIGN KEY (`locked_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `voyage_ready_fk`  FOREIGN KEY (`ready_by`)  REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `voyage_user_fk`   FOREIGN KEY (`created_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Passenger profiles (one row per real person; tickets link via passenger.profile_id)
-- ---------------------------------------------------------------------
CREATE TABLE `passenger_profile` (
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

-- ---------------------------------------------------------------------
-- Passengers
--   status     : Booked (online, not yet at port) -> Checked-in -> Boarded
--   ticket_valid_until : the ticket can no longer be used after this
--                        (scheduled departure + grace period)
-- ---------------------------------------------------------------------
CREATE TABLE `passenger` (
  `passenger_id`      int(11) NOT NULL AUTO_INCREMENT,
  `voyage_id`         int(11) NOT NULL,
  `ticket_no`         varchar(30) NOT NULL,
  `booking_ref`       varchar(20) DEFAULT NULL,
  `booked_via`        enum('Online','Counter') NOT NULL DEFAULT 'Counter',
  `profile_id`        int(11) DEFAULT NULL,   -- passenger_profile of this person
  `last_name`         varchar(50) NOT NULL,
  `first_name`        varchar(50) NOT NULL,
  `middle_name`       varchar(50) DEFAULT NULL,
  `age`               int(11) NOT NULL,
  `sex`               enum('Male','Female') NOT NULL,
  `nationality`       varchar(50) DEFAULT 'Filipino',
  `id_type`           varchar(30) DEFAULT NULL,
  `id_number`         varchar(30) DEFAULT NULL,
  `id_photo`          varchar(60) DEFAULT NULL,   -- ID photo file (storage/id_photos) attached to an online booking
  `id_check`          enum('Verified','Rejected') DEFAULT NULL,   -- staff check of the ID photo
  `id_checked_by`     int(11) DEFAULT NULL,
  `id_checked_at`     datetime DEFAULT NULL,
  `pax_category`      enum('Adult','Student','Minor','Senior','Infant','PWD') NOT NULL DEFAULT 'Adult',
  `seat_no`           varchar(10) DEFAULT NULL,
  `class`             enum('Economy','Tourist','Business') NOT NULL DEFAULT 'Economy',
  `address`           text DEFAULT NULL,
  `contact_no`        varchar(20) DEFAULT NULL,
  `email`             varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `remarks`           varchar(255) DEFAULT NULL,
  `base_fare`         decimal(10,2) NOT NULL DEFAULT 0.00,
  `fare`              decimal(10,2) NOT NULL DEFAULT 0.00,
  `fare_paid`         tinyint(1) NOT NULL DEFAULT 0,
  `online_pay_method` varchar(20) DEFAULT NULL,
  `online_pay_ref`    varchar(100) DEFAULT NULL,
  `online_pay_status` enum('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
  `online_pay_checkout` varchar(64) DEFAULT NULL,
  `status`            enum('Booked','Checked-in','Boarded','No-show','Cancelled') NOT NULL DEFAULT 'Checked-in',
  `ticket_valid_until` datetime DEFAULT NULL,
  `booked_at`         datetime DEFAULT current_timestamp(),
  `booked_by`         int(11) DEFAULT NULL,             -- staff user who issued the ticket (NULL = online)
  `checkin_time`      datetime DEFAULT NULL,
  `checkin_by`        int(11) DEFAULT NULL,
  `boarded_at`        datetime DEFAULT NULL,
  `boarded_by`        int(11) DEFAULT NULL,
  PRIMARY KEY (`passenger_id`),
  UNIQUE KEY `ticket_no` (`ticket_no`),
  KEY `voyage_id` (`voyage_id`),
  KEY `booking_ref` (`booking_ref`),
  KEY `online_pay_checkout` (`online_pay_checkout`),
  KEY `status` (`status`),
  KEY `profile_id` (`profile_id`),
  CONSTRAINT `pax_voyage_fk`  FOREIGN KEY (`voyage_id`)  REFERENCES `voyage` (`voyage_id`),
  CONSTRAINT `pax_checkin_fk` FOREIGN KEY (`checkin_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pax_boarded_fk` FOREIGN KEY (`boarded_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pax_bookedby_fk` FOREIGN KEY (`booked_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pax_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `passenger_profile` (`profile_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Baggage (one row per passenger)
-- ---------------------------------------------------------------------
CREATE TABLE `baggage` (
  `baggage_id`    int(11) NOT NULL AUTO_INCREMENT,
  `passenger_id`  int(11) NOT NULL,
  `voyage_id`     int(11) NOT NULL,
  `actual_weight` decimal(8,2) NOT NULL DEFAULT 0.00,
  `allowance_kg`  decimal(8,2) NOT NULL DEFAULT 20.00,
  `excess_kg`     decimal(8,2) GENERATED ALWAYS AS (greatest(`actual_weight` - `allowance_kg`,0)) STORED,
  `fee_rate`      decimal(8,2) NOT NULL DEFAULT 0.00,
  `discount_pct`  tinyint(4) NOT NULL DEFAULT 0,
  `excess_fee`    decimal(10,2) GENERATED ALWAYS AS (greatest(`actual_weight` - `allowance_kg`,0) * `fee_rate` * ((100 - `discount_pct`) / 100.0)) STORED,
  `tag_no`        varchar(20) DEFAULT NULL,
  `is_paid`       tinyint(1) NOT NULL DEFAULT 0,
  `created_at`    datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`baggage_id`),
  UNIQUE KEY `passenger_id` (`passenger_id`),
  KEY `voyage_id` (`voyage_id`),
  CONSTRAINT `bag_pax_fk`    FOREIGN KEY (`passenger_id`) REFERENCES `passenger` (`passenger_id`) ON DELETE CASCADE,
  CONSTRAINT `bag_voyage_fk` FOREIGN KEY (`voyage_id`)    REFERENCES `voyage` (`voyage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Cargo (waybills)  zone_id = assigned load zone (A1/A2/B1...)
-- ---------------------------------------------------------------------
CREATE TABLE `cargo` (
  `cargo_id`          int(11) NOT NULL AUTO_INCREMENT,
  `voyage_id`         int(11) NOT NULL,
  `waybill_no`        varchar(30) NOT NULL,
  `shipper_name`      varchar(100) NOT NULL,
  `shipper_contact`   varchar(20) DEFAULT NULL,
  `shipper_email`     varchar(100) DEFAULT NULL,
  `consignee_name`    varchar(100) NOT NULL,
  `consignee_address` text DEFAULT NULL,
  `cargo_description` text NOT NULL,
  `quantity`          int(11) NOT NULL DEFAULT 1,
  `gross_weight`      decimal(10,2) NOT NULL,
  `declared_value`    decimal(12,2) DEFAULT NULL,
  `cargo_type`        enum('General','Perishable','Hazardous','Livestock','Fragile','Oversized') NOT NULL DEFAULT 'General',
  `freight_amount`    decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_paid`           tinyint(1) NOT NULL DEFAULT 0,
  `zone_id`           int(11) DEFAULT NULL,
  `encoded_by`        int(11) DEFAULT NULL,
  `created_at`        datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`cargo_id`),
  UNIQUE KEY `waybill_no` (`waybill_no`),
  KEY `voyage_id` (`voyage_id`),
  KEY `zone_id` (`zone_id`),
  CONSTRAINT `cargo_voyage_fk` FOREIGN KEY (`voyage_id`)  REFERENCES `voyage` (`voyage_id`),
  CONSTRAINT `cargo_zone_fk`   FOREIGN KEY (`zone_id`)    REFERENCES `vessel_zone` (`zone_id`) ON DELETE SET NULL,
  CONSTRAINT `cargo_user_fk`   FOREIGN KEY (`encoded_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Vehicles (RoRo)  zone_id = assigned lane (A1/A2...)
-- ---------------------------------------------------------------------
CREATE TABLE `vehicle` (
  `vehicle_id`            int(11) NOT NULL AUTO_INCREMENT,
  `voyage_id`             int(11) NOT NULL,
  `vehicle_type_id`       int(11) NOT NULL,
  `plate_no`              varchar(20) NOT NULL,
  `make_model`            varchar(100) DEFAULT NULL,
  `color`                 varchar(30) DEFAULT NULL,
  `owner_name`            varchar(100) NOT NULL,
  `owner_contact`         varchar(20) DEFAULT NULL,
  `owner_email`           varchar(100) DEFAULT NULL,
  `owner_address`         text DEFAULT NULL,
  `receiver_name`         varchar(100) DEFAULT NULL,
  `declared_weight_kg`    decimal(10,2) DEFAULT NULL,
  `driver_passenger_id`   int(11) DEFAULT NULL,
  `freight_amount`        decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_paid`               tinyint(1) NOT NULL DEFAULT 0,
  `booking_ref`           varchar(20) DEFAULT NULL,
  `zone_id`               int(11) DEFAULT NULL,
  `encoded_by`            int(11) DEFAULT NULL,
  `created_at`            datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `voyage_plate` (`voyage_id`,`plate_no`),
  KEY `vehicle_type_id` (`vehicle_type_id`),
  KEY `zone_id` (`zone_id`),
  CONSTRAINT `veh_voyage_fk` FOREIGN KEY (`voyage_id`)       REFERENCES `voyage` (`voyage_id`),
  CONSTRAINT `veh_type_fk`   FOREIGN KEY (`vehicle_type_id`) REFERENCES `vehicle_type` (`type_id`),
  CONSTRAINT `veh_zone_fk`   FOREIGN KEY (`zone_id`)         REFERENCES `vessel_zone` (`zone_id`) ON DELETE SET NULL,
  CONSTRAINT `veh_driver_fk` FOREIGN KEY (`driver_passenger_id`)   REFERENCES `passenger` (`passenger_id`) ON DELETE SET NULL,
  CONSTRAINT `veh_user_fk`   FOREIGN KEY (`encoded_by`)      REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payments / Official Receipts
-- ---------------------------------------------------------------------
CREATE TABLE `payment` (
  `payment_id`   int(11) NOT NULL AUTO_INCREMENT,
  `or_no`        varchar(30) NOT NULL,
  `passenger_id` int(11) DEFAULT NULL,
  `cargo_id`     int(11) DEFAULT NULL,
  `vehicle_id`   int(11) DEFAULT NULL,
  `cashier_id`   int(11) DEFAULT NULL,
  `voyage_id`    int(11) NOT NULL,
  `fee_type`     enum('Ticket Fare','Excess Baggage','Cargo Freight','Vehicle Freight','Hazardous Surcharge','Terminal Fee','Other') NOT NULL,
  `amount`       decimal(10,2) NOT NULL,
  `pay_method`   enum('Cash','GCash','Maya','Bank Transfer','Card') NOT NULL DEFAULT 'Cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `paid_at`      datetime DEFAULT current_timestamp(),
  `is_voided`    tinyint(1) NOT NULL DEFAULT 0,
  `voided_by`    int(11) DEFAULT NULL,
  `void_reason`  text DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `or_no` (`or_no`),
  KEY `passenger_id` (`passenger_id`),
  KEY `cargo_id` (`cargo_id`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `voyage_id` (`voyage_id`),
  KEY `paid_at` (`paid_at`),
  CONSTRAINT `pay_pax_fk`     FOREIGN KEY (`passenger_id`) REFERENCES `passenger` (`passenger_id`) ON DELETE SET NULL,
  CONSTRAINT `pay_cargo_fk`   FOREIGN KEY (`cargo_id`)     REFERENCES `cargo` (`cargo_id`) ON DELETE SET NULL,
  CONSTRAINT `pay_vehicle_fk` FOREIGN KEY (`vehicle_id`)   REFERENCES `vehicle` (`vehicle_id`) ON DELETE SET NULL,
  CONSTRAINT `pay_cashier_fk` FOREIGN KEY (`cashier_id`)   REFERENCES `user` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pay_voyage_fk`  FOREIGN KEY (`voyage_id`)    REFERENCES `voyage` (`voyage_id`),
  CONSTRAINT `pay_void_fk`    FOREIGN KEY (`voided_by`)    REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Coast Guard: documents / checks required before "Ready to Board".
-- Edit this list after coordinating with the PCG station.
-- ---------------------------------------------------------------------
CREATE TABLE `clearance_document` (
  `doc_id`      int(11) NOT NULL AUTO_INCREMENT,
  `label`       varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_auto`     varchar(30) DEFAULT NULL COMMENT 'system-verified check key, NULL = manual tick',
  `sort_order`  int(11) NOT NULL DEFAULT 0,
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `clearance_document` (`label`,`description`,`is_required`,`is_auto`,`sort_order`) VALUES
('Passenger manifest complete','All checked-in passengers are listed with full name, age, sex and nationality',1,'manifest',1),
('Passenger count within capacity','Checked-in + boarded passengers do not exceed the certified passenger capacity',1,'pax_capacity',2),
('Total load within allowable kg','Passengers, baggage, cargo and vehicles do not exceed the vessel''s max load (kg)',1,'load_capacity',3),
('Loads sorted into zones','Every vehicle and cargo item has an assigned zone (A1/A2/B1...) and no zone is over its limit',1,'zones',4),
('Manifest locked','Check-in is closed and the manifest is locked',1,'locked',5),
('Vessel not under maintenance','Vessel is operational for this voyage date',1,'maintenance',6),
('Crew list presented','Crew count entered on the manifest; master and crew list matches the Minimum Safe Manning Certificate',1,'crew',7),
('Life-saving appliances checked','Life jackets, life rafts and buoys are complete and accessible',1,NULL,8),
('Weather / sea condition acceptable','No gale warning or tropical cyclone signal for the route',1,NULL,9),
('Hazardous cargo declared','Hazardous cargo (if any) is declared and stowed in an allowed zone',1,'hazard',10);

CREATE TABLE `departure_clearance` (
  `clearance_id`  int(11) NOT NULL AUTO_INCREMENT,
  `voyage_id`     int(11) NOT NULL,
  `cert_no`       varchar(30) NOT NULL,
  `is_cleared`    tinyint(1) NOT NULL DEFAULT 1,
  `total_pax`     int(11) NOT NULL DEFAULT 0,
  `total_crew`    int(11) NOT NULL DEFAULT 0,
  `total_vehicles` int(11) NOT NULL DEFAULT 0,
  `total_load_kg` decimal(12,2) NOT NULL DEFAULT 0.00,
  `checklist`     longtext DEFAULT NULL COMMENT 'JSON snapshot of the checklist',
  `valid_until`   datetime NOT NULL COMMENT 'certificate validity (departure + grace)',
  `issued_by`     int(11) DEFAULT NULL,
  `issued_at`     datetime DEFAULT current_timestamp(),
  `notes`         text DEFAULT NULL,
  PRIMARY KEY (`clearance_id`),
  UNIQUE KEY `cert_no` (`cert_no`),
  KEY `voyage_id` (`voyage_id`),
  CONSTRAINT `clr_voyage_fk` FOREIGN KEY (`voyage_id`) REFERENCES `voyage` (`voyage_id`),
  CONSTRAINT `clr_user_fk`   FOREIGN KEY (`issued_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Monthly archive (batch file). Detailed rows of a month are exported to
-- storage/archives/MANLOAD-YYYY-MM.zip, then summarized here so history
-- (including passenger names) stays searchable by day.
-- ---------------------------------------------------------------------
CREATE TABLE `archive_batch` (
  `batch_id`    int(11) NOT NULL AUTO_INCREMENT,
  `batch_month` char(7) NOT NULL,
  `file_name`   varchar(100) DEFAULT NULL,
  `file_size`   int(11) NOT NULL DEFAULT 0,
  `voyages`     int(11) NOT NULL DEFAULT 0,
  `passengers`  int(11) NOT NULL DEFAULT 0,
  `vehicles`    int(11) NOT NULL DEFAULT 0,
  `cargo_items` int(11) NOT NULL DEFAULT 0,
  `cargo_kg`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `revenue`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `exported_by` int(11) DEFAULT NULL,
  `exported_at` datetime DEFAULT current_timestamp(),
  `purged_at`   datetime DEFAULT NULL,
  `purged_by`   int(11) DEFAULT NULL,
  PRIMARY KEY (`batch_id`),
  UNIQUE KEY `batch_month` (`batch_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `archive_voyage` (
  `voyage_id`      int(11) NOT NULL,
  `batch_id`       int(11) NOT NULL,
  `voyage_no`      varchar(40) NOT NULL,
  `vessel_name`    varchar(100) NOT NULL,
  `route_code`     varchar(20) NOT NULL,
  `departure_date` date NOT NULL,
  `departure_time` time NOT NULL,
  `manifest_status` varchar(20) DEFAULT NULL,
  `total_pax`      int(11) NOT NULL DEFAULT 0,
  `total_vehicles` int(11) NOT NULL DEFAULT 0,
  `cargo_kg`       decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_load_kg`  decimal(12,2) NOT NULL DEFAULT 0.00,
  `revenue`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `cert_no`        varchar(30) DEFAULT NULL,
  PRIMARY KEY (`voyage_id`),
  KEY `departure_date` (`departure_date`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `archive_passenger` (
  `archive_id`   int(11) NOT NULL AUTO_INCREMENT,
  `batch_id`     int(11) NOT NULL,
  `voyage_id`    int(11) NOT NULL,
  `ticket_no`    varchar(30) DEFAULT NULL,
  `last_name`    varchar(60) DEFAULT NULL,
  `first_name`   varchar(60) DEFAULT NULL,
  `middle_name`  varchar(60) DEFAULT NULL,
  `age`          int(11) DEFAULT NULL,
  `sex`          varchar(10) DEFAULT NULL,
  `nationality`  varchar(50) DEFAULT NULL,
  `seat_no`      varchar(10) DEFAULT NULL,
  `class`        varchar(20) DEFAULT NULL,
  `pax_category` varchar(20) DEFAULT NULL,
  `contact_no`   varchar(30) DEFAULT NULL,
  `email`        varchar(100) DEFAULT NULL,
  `status`       varchar(20) DEFAULT NULL,
  PRIMARY KEY (`archive_id`),
  KEY `voyage_id` (`voyage_id`),
  KEY `batch_id` (`batch_id`),
  KEY `name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
