ALTER TABLE orders
    ADD COLUMN lang ENUM('bg','en') NOT NULL DEFAULT 'bg' AFTER notes;

ALTER TABLE campaign_pledges
    ADD COLUMN lang ENUM('bg','en') NOT NULL DEFAULT 'bg' AFTER pledge_type;
