-- Store DSK Bank's internal order UUID so we can query status and process refunds
ALTER TABLE `orders`
    ADD COLUMN `dsk_order_id` varchar(100) DEFAULT NULL;
