-- Seed default shipping rates so checkout never shows €0.
-- Admin can override these values via the Couriers settings page.
-- Uses INSERT IGNORE so re-running doesn't overwrite admin-configured values.
INSERT IGNORE INTO `shipping_rates` (`courier`, `delivery_type`, `rate_eur`) VALUES
    ('speedy', 'office',  3.99),
    ('speedy', 'apt',     3.99),
    ('speedy', 'address', 4.99),
    ('boxnow', 'locker',  3.99);
