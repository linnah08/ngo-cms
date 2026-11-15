-- Product variants: an optional description of their own (BG + EN, rich text).
-- The product page shows it under the product's shared description while that
-- variant is chosen. Empty = nothing extra is shown.
--
-- Portable to MySQL 9 and MariaDB 10.5. A re-run skips the ALTER ("Duplicate column").

ALTER TABLE product_variants
  ADD COLUMN description_bg TEXT NULL AFTER label_en,
  ADD COLUMN description_en TEXT NULL AFTER description_bg;
