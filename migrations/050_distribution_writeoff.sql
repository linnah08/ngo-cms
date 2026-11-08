-- Distribution: copies written off (damaged, lost) are a destination of their
-- own, so the stock overview can tell them apart from free samples.
--
-- Portable to MySQL 9 and MariaDB 10.5; re-running MODIFY COLUMN is harmless.

ALTER TABLE distribution_allocations
  MODIFY COLUMN destination ENUM('distributor', 'online', 'personal', 'sample', 'writeoff') NOT NULL DEFAULT 'distributor';
