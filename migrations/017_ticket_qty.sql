ALTER TABLE campaign_pledges
    ADD COLUMN ticket_qty TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER ticket_path;
