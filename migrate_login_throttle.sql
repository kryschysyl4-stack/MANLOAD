-- Login lockout: counts failed sign-ins per username + IP. 5 misses = timed lock (1, 2, 4 … max 15 min).
-- login.php also creates this table on its own if it is missing.
CREATE TABLE IF NOT EXISTS `login_throttle` (
  `throttle_key` varchar(120) NOT NULL,
  `fails`        tinyint(3) unsigned NOT NULL DEFAULT 0,
  `lockouts`     tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`throttle_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
