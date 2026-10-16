-- Newsletter topics become a list the admin manages (Admin → Бюлетин → Теми)
-- instead of two fixed columns on newsletter_subscribers.
--
--   newsletter_topics             one row per topic: its BG/EN name, order, and
--                                 whether it is shown (hiding keeps the data —
--                                 subscribers' choices and old campaigns stay)
--   newsletter_subscriber_topics  which topics a subscriber chose. A subscriber
--                                 with no rows here gets every campaign: that is
--                                 how imports, manual adds and sign-ups on a
--                                 site without topics work.
--   newsletter_campaigns.topic    'all' or the id of a topic (was an ENUM)
--
-- Carry-over: the two built-in topics become rows with legacy_key news /
-- education. Their names stay empty — includes/newsletter.php then keeps
-- showing the wording the site already edited in place (pages.json) or the
-- strings.json default, which SQL cannot read. A subscriber who had exactly one
-- topic keeps that one; one who had both (everyone imported, and every sign-up
-- from before topics existed) gets no rows, i.e. everything, as before.
-- wants_news / wants_education are left in place, unused, so nothing is lost.
--
-- Portable to MySQL 9 and MariaDB 10.5; migrate.php makes a re-run safe.

CREATE TABLE IF NOT EXISTS newsletter_topics (
  id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_bg     VARCHAR(150) NOT NULL DEFAULT '',
  name_en     VARCHAR(150) NOT NULL DEFAULT '',
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  legacy_key  VARCHAR(20)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY legacy_key (legacy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscriber_topics (
  subscriber_id INT NOT NULL,
  topic_id      INT NOT NULL,
  PRIMARY KEY (subscriber_id, topic_id),
  KEY topic_id (topic_id),
  FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
  FOREIGN KEY (topic_id)      REFERENCES newsletter_topics(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO newsletter_topics (sort_order, legacy_key) VALUES (1, 'news'), (2, 'education');

INSERT IGNORE INTO newsletter_subscriber_topics (subscriber_id, topic_id)
  SELECT s.id, t.id FROM newsletter_subscribers s JOIN newsletter_topics t ON t.legacy_key = 'news'
  WHERE s.wants_news = 1 AND s.wants_education = 0;

INSERT IGNORE INTO newsletter_subscriber_topics (subscriber_id, topic_id)
  SELECT s.id, t.id FROM newsletter_subscribers s JOIN newsletter_topics t ON t.legacy_key = 'education'
  WHERE s.wants_education = 1 AND s.wants_news = 0;

ALTER TABLE newsletter_campaigns MODIFY COLUMN topic VARCHAR(20) NOT NULL DEFAULT 'all';

UPDATE newsletter_campaigns c JOIN newsletter_topics t ON t.legacy_key = c.topic
  SET c.topic = CAST(t.id AS CHAR);
