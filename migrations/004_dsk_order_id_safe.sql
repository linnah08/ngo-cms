-- Adds dsk_order_id if not already present (002 may have failed on servers
-- where boxnow_parcel_id didn't exist yet, leaving the column missing).
-- Uses plain ALTER TABLE — migrate.php ignores "Duplicate column" errors.
ALTER TABLE `orders`
    ADD COLUMN `dsk_order_id` varchar(100) DEFAULT NULL;
