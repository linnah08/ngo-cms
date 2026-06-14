-- Per-send tracking rows: one row per campaign × subscriber.
-- The 32-char hex token is embedded in the tracking pixel URL and rewritten links.

CREATE TABLE IF NOT EXISTS `newsletter_sends` (
  `id`            int          NOT NULL AUTO_INCREMENT,
  `campaign_id`   int          NOT NULL,
  `subscriber_id` int          NOT NULL,
  `token`         char(32)     NOT NULL,
  `sent_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `opened_at`     datetime              DEFAULT NULL,
  `open_count`    int          NOT NULL DEFAULT 0,
  `clicked_at`    datetime              DEFAULT NULL,
  `click_count`   int          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token`              (`token`),
  UNIQUE KEY `campaign_subscriber`(`campaign_id`, `subscriber_id`),
  KEY `campaign_id`               (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
