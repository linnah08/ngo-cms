-- Track when a donation certificate was emailed after signing
ALTER TABLE `documents`
  ADD COLUMN IF NOT EXISTS `emailed_at` DATETIME NULL AFTER `signed_by`;
