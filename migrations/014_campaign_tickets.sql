ALTER TABLE campaign_pledges
    ADD COLUMN pledge_type   ENUM('donation','ticket') NOT NULL DEFAULT 'donation' AFTER pledge_number,
    ADD COLUMN ticket_code   VARCHAR(64)  NULL AFTER cert_path,
    ADD COLUMN ticket_path   VARCHAR(255) NULL AFTER ticket_code;
