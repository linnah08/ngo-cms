-- Server-side conversions for Google Analytics 4 / Google Ads (includes/ga4.php).
-- At checkout the order keeps the visitor's GA client and session id and the
-- Google Ads click id — only when they accepted cookies, otherwise all stay NULL
-- and nothing is ever sent about them. paid_at is when the order turned paid;
-- conversion_sent_at when Google accepted the event (NULL = not sent yet).
--
-- Portable to MySQL 9 and MariaDB 10.5; migrate.php makes a re-run safe.

ALTER TABLE orders ADD COLUMN ga_client_id VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ga_session_id VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN gclid VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN paid_at DATETIME NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN conversion_sent_at DATETIME NULL DEFAULT NULL;
