ALTER TABLE `admin_users`
  MODIFY COLUMN `role` ENUM('admin','author','shop_admin','iris_admin') NOT NULL DEFAULT 'author';
