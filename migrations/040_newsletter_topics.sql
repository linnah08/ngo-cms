-- Newsletter topics: each subscriber picks which kinds of email they want, and
-- a campaign can go to everyone or to one topic only. Existing subscribers
-- signed up for everything, so both flags start on. The labels people see are
-- editable on the site, the column names are only internal keys.
ALTER TABLE `newsletter_subscribers` ADD COLUMN `wants_news` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `newsletter_subscribers` ADD COLUMN `wants_education` TINYINT(1) NOT NULL DEFAULT 1;

-- Where a subscriber came from, now including the shop checkout, the donation
-- form and the order/donation confirmation page.
ALTER TABLE `newsletter_subscribers`
  MODIFY COLUMN `source`
    ENUM('web_banner','customer_import','manual','checkout','donation','confirmation')
    NOT NULL DEFAULT 'web_banner';

ALTER TABLE `newsletter_campaigns` ADD COLUMN `topic` ENUM('all','news','education') NOT NULL DEFAULT 'all';
