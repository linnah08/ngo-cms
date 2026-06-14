-- Newsletter subscribers and campaigns

CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`              int          NOT NULL AUTO_INCREMENT,
  `email`           varchar(150) NOT NULL,
  `name`            varchar(150)          DEFAULT NULL,
  `lang`            enum('bg','en')       NOT NULL DEFAULT 'bg',
  `source`          enum('web_banner','customer_import','manual') NOT NULL DEFAULT 'web_banner',
  `status`          enum('active','unsubscribed')                 NOT NULL DEFAULT 'active',
  `token`           char(64)     NOT NULL,
  `subscribed_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` datetime              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id`               int          NOT NULL AUTO_INCREMENT,
  `subject_bg`       varchar(255) NOT NULL DEFAULT '',
  `subject_en`       varchar(255) NOT NULL DEFAULT '',
  `body_bg`          longtext     NOT NULL,
  `body_en`          longtext     NOT NULL,
  `status`           enum('draft','sending','sent') NOT NULL DEFAULT 'draft',
  `created_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`          datetime              DEFAULT NULL,
  `recipient_count`  int                   DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
