-- migrations/026_variant_images.sql
-- Multiple photos per product variant.
-- `image` stays as the chosen primary. `images` is the ordered gallery (it must contain `image`).

ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS images JSON NULL AFTER image;

-- Backfill: existing variants with a single image become a one-photo gallery.
UPDATE product_variants
SET images = JSON_ARRAY(image)
WHERE (images IS NULL OR JSON_LENGTH(images) = 0)
  AND image IS NOT NULL AND image <> '';
