-- migrations/026_variant_images.sql
-- Multiple photos per product variant.
-- `image` stays as the chosen primary. `images` is the ordered gallery (it must contain `image`).

-- NB: plain ADD COLUMN (no "IF NOT EXISTS" — that is MariaDB-only and is a
-- syntax error on MySQL). On a DB that already has the column, MySQL raises
-- "Duplicate column", which the migration runner ignores safely.
ALTER TABLE product_variants
    ADD COLUMN images JSON NULL AFTER image;

-- Backfill: existing variants with a single image become a one-photo gallery.
UPDATE product_variants
SET images = JSON_ARRAY(image)
WHERE (images IS NULL OR JSON_LENGTH(images) = 0)
  AND image IS NOT NULL AND image <> '';
