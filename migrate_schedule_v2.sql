-- Schedule templates v2: a name and the weekdays each template runs (1=Mon … 7=Sun).
ALTER TABLE `schedule_template`
  ADD COLUMN `template_name` varchar(60) DEFAULT NULL AFTER `template_id`,
  ADD COLUMN `run_days` varchar(13) NOT NULL DEFAULT '1,2,3,4,5,6,7' AFTER `departure_time`;
-- Link schedules: an outbound template and its return template share link_group (= the outbound template_id).
ALTER TABLE `schedule_template` ADD COLUMN `link_group` int(11) DEFAULT NULL AFTER `run_days`, ADD KEY `link_group` (`link_group`);
