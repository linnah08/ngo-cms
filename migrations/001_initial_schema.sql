-- Initial schema

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','author') DEFAULT 'author',
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(150) NOT NULL,
  `name_bg` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `description_bg` text,
  `description_en` text,
  `price_eur` decimal(10,2) NOT NULL,
  `stock` int DEFAULT '0',
  `active` tinyint(1) DEFAULT '1',
  `image` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(20) NOT NULL,
  `type` enum('physical','donation') NOT NULL,
  `status` enum('new','confirmed','shipped','delivered','cancelled') DEFAULT 'new',
  `customer_name` varchar(150) NOT NULL,
  `customer_email` varchar(150) NOT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `delivery_type` enum('office','apt','address','locker') DEFAULT NULL,
  `courier` varchar(30) DEFAULT NULL,
  `courier_office_code` varchar(50) DEFAULT NULL,
  `courier_office_name` varchar(255) DEFAULT NULL,
  `delivery_address` text,
  `delivery_city` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `boxnow_parcel_id` varchar(100) DEFAULT NULL,
  `items` json NOT NULL,
  `subtotal_eur` decimal(10,2) NOT NULL,
  `shipping_eur` decimal(10,2) DEFAULT '0.00',
  `total_eur` decimal(10,2) NOT NULL,
  `donation_message` text,
  `payment_method` enum('cod','bank_transfer','card') DEFAULT 'cod',
  `payment_status` enum('pending','paid','refunded') DEFAULT 'pending',
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipping_rates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `courier` varchar(30) NOT NULL,
  `delivery_type` enum('office','apt','address','locker') NOT NULL,
  `rate_eur` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `courier_type` (`courier`,`delivery_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
