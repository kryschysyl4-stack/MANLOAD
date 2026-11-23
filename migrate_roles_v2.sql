-- Collapse staff roles to: Admin, Passage, Back Office, Coast Guard
--   Supervisor, Cargo Clerk  -> Back Office
--   Checkin Staff, Cashier   -> Passage
ALTER TABLE `user` MODIFY `role` enum('Admin','Supervisor','Checkin Staff','Cashier','Cargo Clerk','Coast Guard','Passage','Back Office') NOT NULL DEFAULT 'Passage';
UPDATE `user` SET `role`='Back Office' WHERE `role` IN ('Supervisor','Cargo Clerk');
UPDATE `user` SET `role`='Passage'     WHERE `role` IN ('Checkin Staff','Cashier');
ALTER TABLE `user` MODIFY `role` enum('Admin','Passage','Back Office','Coast Guard') NOT NULL DEFAULT 'Passage';
