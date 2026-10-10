-- Events and tickets ("Събития и билети") — its own module, no campaign needed.
--
-- Until now a site had ONE event, kept in the encrypted settings table
-- (event_name, event_date, …) and sold through the campaign checkout as
-- campaign_pledges rows with pledge_type = 'ticket'. Tickets stay in
-- campaign_pledges (the order, payment, PDF and order-view plumbing all read
-- them there); each one now names its event.
--
-- Carry-over: if the site has sold tickets, one event is created for them and
-- they are linked to it. Its title, date, place and price are still encrypted
-- in `settings`, which SQL cannot read, so the row is marked legacy_settings = 1
-- and events_adopt_legacy() (includes/events.php) fills it in from the settings
-- the first time an events page runs. A site without ticket sales gets no event.
--
-- Portable to MySQL 8/9 and MariaDB 10.5; no ADD COLUMN IF NOT EXISTS. The
-- INSERT does not read `events` itself, so it also runs on a TEMPORARY copy
-- (tests/Events/EventsMigrationTest.php).

CREATE TABLE IF NOT EXISTS events (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(190)  NOT NULL,
    title           VARCHAR(255)  NOT NULL,
    title_en        VARCHAR(255)  NOT NULL DEFAULT '',
    event_date      DATE          NULL,
    event_time      VARCHAR(5)    NOT NULL DEFAULT '',
    place           VARCHAR(255)  NOT NULL DEFAULT '',
    place_en        VARCHAR(255)  NOT NULL DEFAULT '',
    description     MEDIUMTEXT    NULL,
    description_en  MEDIUMTEXT    NULL,
    fb_url          VARCHAR(500)  NOT NULL DEFAULT '',
    price_eur       DECIMAL(10,2) NOT NULL DEFAULT 0,
    capacity        INT UNSIGNED  NULL,
    published       TINYINT(1)    NOT NULL DEFAULT 0,
    sales_open      TINYINT(1)    NOT NULL DEFAULT 0,
    legacy_settings TINYINT(1)    NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_events_slug (slug),
    KEY idx_events_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE campaign_pledges
    ADD COLUMN event_id INT UNSIGNED NULL AFTER pledge_type,
    ADD KEY idx_campaign_pledges_event (event_id);

INSERT INTO events (slug, title, published, sales_open, legacy_settings)
    SELECT 'bileti', 'Събитие', 0, 0, 1
      FROM campaign_pledges
     WHERE pledge_type = 'ticket'
    HAVING COUNT(*) > 0;

UPDATE campaign_pledges
   SET event_id = (SELECT id FROM events WHERE legacy_settings = 1 ORDER BY id LIMIT 1)
 WHERE pledge_type = 'ticket' AND event_id IS NULL;
