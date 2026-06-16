-- IRIS Pay by Bank (open banking / account-to-account redirect payment).
-- iris_payment_hash : IRIS's unique paymentHash, used to re-query the
--   authoritative payment status (status + settled sum + receiver IBAN).
-- iris_callback_token : per-order capability token embedded only in the
--   hookUrl (never exposed to the browser) to authenticate the callback caller.
ALTER TABLE `orders`
    ADD COLUMN `iris_payment_hash`   varchar(100) DEFAULT NULL,
    ADD COLUMN `iris_callback_token` char(64)     DEFAULT NULL;

-- Add 'iris' as a payment method
ALTER TABLE `orders`
    MODIFY COLUMN `payment_method` enum('cod','bank_transfer','card','iris') DEFAULT 'cod';
