-- What the customer agreed to when they placed the order, and which version of
-- the text they agreed to. Legal documents get revised; without the version
-- there is no way to show afterwards what someone actually accepted.
--
-- JSON rather than columns because the set of consents changes over time and a
-- new one should not need a migration.
ALTER TABLE orders ADD COLUMN consents JSON NULL AFTER invoice_data;
