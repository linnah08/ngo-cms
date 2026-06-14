# Multiple photos per product variant — design

**Date:** 2026-06-11
**Status:** Approved, ready for implementation plan

## Goal

Let admins attach **multiple photos to each product variant**, with one **selectable
primary photo** per variant. On the shop product-detail page, the customer browses the
selected variant's photos in a gallery (thumbnail strip on desktop, swipeable main image
on mobile).

## Scope

In scope:
- Storefront product-detail **gallery** for the selected variant.
- Admin: manage multiple photos per variant inline, mark one as primary, reorder, remove.

Out of scope (unchanged):
- Listing/grid cards, cart thumbnail, variant-selector list — all keep using the single
  **primary** photo.
- Listing cards do **not** rotate/hover through multiple photos.

## Data model

`product_variants` keeps its existing `image VARCHAR(255)` column and gains one new column:

- `images JSON NULL` — ordered array of photo filenames for the variant
  (e.g. `["red-1.jpg","red-2.jpg","red-3.jpg"]`).
- `image` — the **chosen primary** filename. It MUST be one element of `images`.
  If no primary is explicitly chosen, it defaults to `images[0]`.

Rationale: every existing consumer reads `image` and keeps working untouched — `image`
remains "the one photo to show everywhere except the detail gallery." `images` is purely
additive.

### Migration

New file `migrations/026_variant_images.sql`:

```sql
ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS images JSON NULL AFTER image;

-- Backfill: existing variants with a single image become a one-photo gallery.
UPDATE product_variants
SET images = JSON_ARRAY(image)
WHERE (images IS NULL OR JSON_LENGTH(images) = 0)
  AND image IS NOT NULL AND image <> '';
```

Idempotent: `ADD COLUMN IF NOT EXISTS`; backfill only touches rows with an empty `images`.

## Admin — `admin/product-edit.php`

The variant row's single-thumb cell becomes a horizontal thumbnail strip:

```
[★img][img][img][+]
```

- `+` opens the **existing** picker modal (`#pvImgModal`, upload + media library). The
  modal's `applyImage(filename)` is changed from *replace* to *append* a thumb to the
  current row's strip.
- Each thumb has:
  - a **star toggle** → marks it primary (exactly one primary per row; selecting a new
    one clears the previous).
  - a **remove** control (×). Removing the primary re-assigns primary to the new first
    thumb.
- Thumbs are **drag-to-reorder** within the row.
- Per-row hidden field `pv_images[]` carries a JSON object
  `{"images":["a.jpg","b.jpg"],"primary":"b.jpg"}` (replacing the old single
  `pv_image[]`). Old `pv_image[]` / `.pv-image-val` are removed.
- Cap: **8 photos** per variant (enforced in JS on `+`, and server-side on save).

### POST handler (`product-edit.php`)

- Parse each `pv_images[]` entry as JSON → `{images: string[], primary: string}`.
- Sanitise every filename: `basename()` + whitelist `[A-Za-z0-9._-]`; drop anything that
  fails or doesn't exist in `/assets/images/products/`. Truncate to 8.
- `primary`: if missing or not in the sanitised `images`, default to `images[0]`.
- Store `images` as JSON; store `image = primary`.
- Empty gallery → `images = NULL`/`[]`, `image = ''` (current placeholder behaviour).
- Update both INSERT and UPDATE statements for `product_variants` to include `images`.

## Storefront — `magazin/index.php` (product detail)

The thumbnail strip under the main image changes meaning: it is no longer
one-thumb-per-variant (variant switcher) — it is the **gallery of the currently selected
variant**.

- **Variant switching** stays in the option list (already present). Selecting a variant:
  - rebuilds the gallery strip from that variant's `images`,
  - sets the main image to that variant's **primary** (`image`).
- **Gallery thumb click** swaps only the main image (does not change variant).
- **Mobile:** main image is swipeable left/right through the selected variant's `images`
  (touch handlers); the thumbnail strip is hidden on phones (CSS, existing
  `max-width:640px` breakpoint).
- `_pvData` JS payload per variant gains `images: string[]` (already has `image`/primary).

Unchanged: listing cards, cart, variant-selector list thumbnails (all read primary
`image`).

## Edge cases / failure modes

- Variant with **zero** photos → placeholder; `image=''`; gallery strip empty; no swipe.
- Variant with **one** photo → gallery strip hidden (matches current `count > 1` logic at
  `magazin/index.php:145`); no swipe; main image shows that one photo.
- Backward compat: pre-migration variants get `images=[image]` via backfill; if backfill
  hasn't run, storefront falls back to `[image]` when `images` is null.
- Malformed `pv_images[]` JSON on POST → treat as empty gallery for that row (don't 500).
- Filename injection: admin-only, but still sanitise (basename + whitelist + existence).
- Removing a variant that has orders still soft-deletes (existing logic, unchanged).

## Tests — `tests/Shop/ProductVariantsDbTest.php`

- Save a variant with multiple `images`; load and assert order preserved.
- Primary: explicit `image` is honoured and stays in sync (is a member of `images`).
- No explicit primary → `image` defaults to `images[0]`.
- Empty gallery → `image=''`, placeholder path.
- Migration backfill: `image`-only row → `images=[image]`; idempotent on re-run.
- Server-side sanitisation: bad/duplicate/over-cap filenames are filtered.
