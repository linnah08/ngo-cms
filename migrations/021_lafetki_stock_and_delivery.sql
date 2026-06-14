-- 021: Lafetki Icebreakers stock qty per reward + campaign pledge delivery columns
-- Uses plain ALTER TABLE — migrate.php ignores "Duplicate column" errors.

ALTER TABLE campaign_rewards
  ADD COLUMN icebreaker_qty TINYINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE campaign_rewards SET icebreaker_qty = 1 WHERE id = 1;
UPDATE campaign_rewards SET icebreaker_qty = 2 WHERE id = 2;
UPDATE campaign_rewards SET icebreaker_qty = 4 WHERE id = 3;
UPDATE campaign_rewards SET icebreaker_qty = 5 WHERE id = 4;

ALTER TABLE campaign_pledges ADD COLUMN delivery_courier VARCHAR(50)  NULL AFTER delivery_address;
ALTER TABLE campaign_pledges ADD COLUMN delivery_type    VARCHAR(50)  NULL AFTER delivery_courier;
ALTER TABLE campaign_pledges ADD COLUMN office_code      VARCHAR(100) NULL AFTER delivery_type;
ALTER TABLE campaign_pledges ADD COLUMN office_name      VARCHAR(200) NULL AFTER office_code;
ALTER TABLE campaign_pledges ADD COLUMN office_city      VARCHAR(100) NULL AFTER office_name;
