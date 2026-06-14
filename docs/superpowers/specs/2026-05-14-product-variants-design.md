# Product Variants Design

**Date:** 2026-05-14
**Status:** Approved

## Overview

Allow a single product to have multiple variants (e.g. a natural soap in lavender, citrus, and rose). Each variant has its own photo, stock, and freeform attribute values. This is implemented as a new product type (`variant`) with a dedicated `product_variants` table.

---

## Data Model

### 1. `products` table changes

Two additions:

- `type` ENUM extended: `'standard' | 'print' | 'variant'` (migration alters existing ENUM)
- `variant_attributes` JSON NULL — ordered list of attribute dimension labels for this product, e.g. `["Вид", "Аромат"]`. Freeform, defined per product in the admin.

For `type = 'variant'` products, the `image` and `stock` columns are ignored (stock and images live on variants).

### 2. New table: `product_variants`

```sql
CREATE TABLE product_variants (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  product_id  INT NOT NULL REFERENCES products(id),
  label_bg    VARCHAR(255) NOT NULL,
  label_en    VARCHAR(255) NULL,
  attributes  JSON NOT NULL,        -- {"Вид":"лилав","Аромат":"лавандула"}
  image       VARCHAR(255) NULL,    -- filename in assets/images/products/
  stock       INT DEFAULT 0,
  active      TINYINT(1) DEFAULT 1,
  sort_order  INT DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (product_id)
);
```

### 3. Cart item JSON (orders.items)

Variant product entries add two fields:

```json
{
  "type": "product",
  "product_id": 12,
  "variant_id": 34,
  "variant_label_bg": "Лавандула",
  "quantity": 2,
  "price_eur": 8.00,
  "subtotal_eur": 16.00
}
```

---

## Admin UI (`admin/product-edit.php`)

### Type selector
A third radio button "С варианти" added alongside "Стандартен" and "Печат (тениска)". Selecting it shows the variants panel and hides the product-level image upload and stock field.

### Variant attributes input
A tag-style input where the admin types freeform dimension labels (e.g. "Вид", "Аромат"). Each label becomes a column header in the variants table below. Stored as a JSON array in `variant_attributes`.

### Variants table
Each row represents one variant:

| Снимка | Название (BG) | Название (EN) | [attr1] | [attr2] | Наличност | Delete |
|--------|---------------|---------------|---------|---------|-----------|--------|

- **Снимка**: thumbnail that opens the existing image uploader on click
- **Attribute columns**: text inputs, one per dimension defined above; column headers are dynamic
- **Наличност**: numeric stock input
- **Delete**: removes the row (soft-delete on existing variants; immediate removal for unsaved rows)
- "Добави вариант" button appends a new empty row

### Save behaviour
On form submit:
- `variant_attributes` JSON saved to `products.variant_attributes`
- Each variant row upserted to `product_variants` (INSERT or UPDATE by id)
- Deleted rows marked `active = 0` if they have been ordered before, hard-deleted otherwise

---

## Public Product Page (`magazin/index.php`)

### Variant selector
Below the price, a radio list of all active variants. Each item shows:
- Variant thumbnail (small, ~36×36px)
- `label_bg` (or `label_en` if English session)
- Attribute values as subtitle: "Вид: лилав · Аромат: лавандула"
- Stock count ("8 бр.") if in stock, or "Изчерпан" if stock = 0 (disabled radio)

Selecting a variant:
- Swaps the main product image to the variant's image
- Updates a hidden `variant_id` input in the add-to-cart form

### Main image
Initialised to the first active variant's image on page load. Changes on variant selection via JS.

### Thumbnail strip
A row of small thumbnails for all active variants below the main image; clicking a thumbnail selects that variant.

### Add to cart
Disabled until a variant is selected (first variant pre-selected on load, so button is enabled by default). Posts `product_id` + `variant_id` + `quantity` to `/cart/add.php`.

### Shop listing card
Shows the first active variant's image. No other changes to the listing page.

---

## Cart & Checkout

### `/cart/add.php`
- Receives `variant_id` for variant products
- Validates: variant exists, belongs to the product, is active, has stock
- Cart session stores `variant_id` alongside `product_id`

### Cart display (`/cart/index.php`)
- Shows variant thumbnail and `label_bg` beneath the product name
- Quantity update and remove work the same as today

### Stock decrement
At order creation, stock is decremented on `product_variants.stock` (not `products.stock`) inside the existing transaction. Race condition protection: same `UPDATE … WHERE stock > 0` pattern used for standard products.

### Order view (admin)
Displays `variant_label_bg` alongside the product name in the items list.

---

## Files Changed / Created

| File | Change |
|------|--------|
| `migrations/019_product_variants.sql` | New migration: ENUM change + new table |
| `admin/product-edit.php` | Variant type UI, attribute tag input, variants table |
| `admin/upload-product-image.php` | No change (reused for variant images) |
| `magazin/index.php` | Variant selector, image swap JS, cart form update |
| `cart/add.php` | Accept + validate `variant_id` |
| `cart/index.php` | Show variant label + thumbnail |
| `checkout/index.php` | Stock decrement targets `product_variants` |
| `includes/print_helpers.php` | No change |

---

## Out of Scope

- Price per variant (single price on the product, no per-variant pricing)
- Combining variants with the print/custom-design system
- Bulk stock import
