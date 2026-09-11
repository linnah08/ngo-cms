-- migrations/033_product_sort_order.sql
-- Manual display order for products on the shop page (/magazin),
-- controlled by drag-to-reorder in /admin/products.php.
-- Lower sort_order is shown first; ties fall back to id.

-- NB: plain ADD COLUMN (no "IF NOT EXISTS" — that is MariaDB-only and is a
-- syntax error on MySQL). On a DB that already has the column, MySQL raises
-- "Duplicate column", which the migration runner ignores safely.
ALTER TABLE products
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER active;

-- Backfill: seed with id so the current shop order is preserved as the
-- starting point until an admin reorders.
UPDATE products SET sort_order = id WHERE sort_order = 0;
