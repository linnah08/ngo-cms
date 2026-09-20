-- Campaign pledges mirror themselves into `orders` as a row with type='pledge'.
-- includes/pledge_documents.php, includes/pledge_shipping.php, admin/pledge-view.php,
-- admin/order-view.php and admin/sign-document.php all read that value back.
--
-- Until now it was never declared here. The enum only ever gained 'pledge' as a
-- side effect of a runtime ALTER TABLE inside api/campaign-payment-return.php, so
-- a freshly migrated database — a new adopter install, CI, or local dev — rejected
-- every pledge write with "Data truncated for column 'type'" until someone happened
-- to complete a card payment on that install. That ALTER is removed in the same
-- commit as this migration.

ALTER TABLE orders
    MODIFY COLUMN type ENUM('physical','donation','ticket','pledge') NOT NULL;
