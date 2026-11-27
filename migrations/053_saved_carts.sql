-- Saved carts: one shop-cart row per browser (pledge_id NULL) plus one row per
-- campaign pledge, so a forgotten cart or pledge can be followed up by email.
-- token is for email links; browser_token is the om_cart cookie value.
--
-- Portable to MySQL 9 and MariaDB 10.5.

CREATE TABLE IF NOT EXISTS saved_carts (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token               CHAR(64) NOT NULL,
  browser_token       CHAR(64) NOT NULL,
  pledge_id           INT UNSIGNED NULL,
  items               JSON NOT NULL,
  lang                CHAR(2) NOT NULL DEFAULT 'bg',
  email               VARCHAR(200) NULL,
  name                VARCHAR(200) NULL,
  remind_opt_in       TINYINT(1) NOT NULL DEFAULT 0,
  remind_consent_at   DATETIME NULL,
  remind_consent_text VARCHAR(500) NULL,
  reminded_at         DATETIME NULL,
  opted_out_at        DATETIME NULL,
  ordered_at          DATETIME NULL,
  dismissed_at        DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saved_carts_token (token),
  UNIQUE KEY uq_saved_carts_pledge (pledge_id),
  KEY idx_saved_carts_browser (browser_token),
  KEY idx_saved_carts_remind (remind_opt_in, reminded_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
