-- IRIS Pay by Bank (open banking / account-to-account redirect payment).
-- IRIS offers no status-query or signature API, so the server-to-server
-- callback is authenticated with a per-order capability token stored here
-- and embedded only in the hookUrl (never exposed to the customer's browser).
ALTER TABLE `orders`
    ADD COLUMN `iris_callback_token` char(64) DEFAULT NULL;

-- Add 'iris' as a payment method
ALTER TABLE `orders`
    MODIFY COLUMN `payment_method` enum('cod','bank_transfer','card','iris') DEFAULT 'cod';
