-- Remove the Back Office role (2026-11): it had the same access as the Admin.
-- Back Office users become Admin. Roles left: Admin, Passage, Coast Guard.
ALTER TABLE `user` MODIFY `role` enum('Admin','Passage','Back Office','Coast Guard') NOT NULL DEFAULT 'Passage';
UPDATE `user` SET `role`='Admin' WHERE `role`='Back Office';
ALTER TABLE `user` MODIFY `role` enum('Admin','Passage','Coast Guard') NOT NULL DEFAULT 'Passage';
-- The old Back Office demo accounts "backoffice1" (renamed admin2 for a while) and "cargo1", and the extra Passage account "staff2", are deleted (user request 2026-11-13).
-- Its check-ins / manifest locks keep their data; the "by" name becomes blank (ON DELETE SET NULL).
DELETE FROM `user` WHERE `username` IN ('backoffice1', 'admin2', 'cargo1', 'staff2');
