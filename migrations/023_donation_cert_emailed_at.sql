-- Track when a donation certificate was emailed after signing
ALTER TABLE `documents`
  ADD COLUMN `emailed_at` DATETIME NULL AFTER `signed_by`;
