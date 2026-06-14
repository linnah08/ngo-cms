-- Add 'apt' to delivery_type ENUM on orders and shipping_rates
ALTER TABLE `orders`
    MODIFY COLUMN `delivery_type` enum('office','apt','address','locker') DEFAULT NULL;

ALTER TABLE `shipping_rates`
    MODIFY COLUMN `delivery_type` enum('office','apt','address','locker') NOT NULL;
