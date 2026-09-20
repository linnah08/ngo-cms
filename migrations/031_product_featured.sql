-- migrations/031_product_featured.sql
-- Marks products to be shown in the "Featured products" section on the
-- homepage (BG + EN). Toggled per product in admin/product-edit.php.

-- NB: plain ADD COLUMN (no "IF NOT EXISTS" — that is MariaDB-only and is a
-- syntax error on MySQL). On a DB that already has the column, MySQL raises
-- "Duplicate column", which the migration runner ignores safely.
ALTER TABLE products
    ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
