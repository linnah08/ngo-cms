-- Let an admin pick a future send date for a draft newsletter campaign; a
-- daily cron sends any draft whose send_date has arrived. Editable any time
-- before it goes out.
ALTER TABLE `newsletter_campaigns` ADD COLUMN `send_date` DATE NULL AFTER `body_en`;
