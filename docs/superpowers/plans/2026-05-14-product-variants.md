# Product Variants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `variant` product type where each variant has its own image, stock, and freeform attribute values (e.g. look, scent).

**Architecture:** New `product_variants` DB table holds per-variant data; `products.type` ENUM gains `'variant'`; `products.variant_attributes` JSON defines dimension labels. Admin editor gains a third type panel. Public product page shows a radio-list variant selector that swaps the main image on selection. Cart/checkout decrement stock on `product_variants` instead of `products` for variant-type items.

**Tech Stack:** PHP 8.4, PDO/MySQL, vanilla JS, PHPUnit 13.

---

## Task 1: DB Migration

**Files:**
- Create: `migrations/019_product_variants.sql`

- [ ] **Step 1: Write the migration**

```sql
-- migrations/019_product_variants.sql

ALTER TABLE products
    MODIFY COLUMN `type` ENUM('standard','print','variant') NOT NULL DEFAULT 'standard',
    ADD COLUMN `variant_attributes` JSON NULL AFTER `variants`;

CREATE TABLE IF NOT EXISTS product_variants (
    id          INT          NOT NULL AUTO_INCREMENT,
    product_id  INT          NOT NULL,
    label_bg    VARCHAR(255) NOT NULL,
    label_en    VARCHAR(255) NULL,
    attributes  JSON         NOT NULL,
    image       VARCHAR(255) NULL,
    stock       INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_product_id (product_id),
    CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Run the migration**

```bash
php migrate.php
```

Expected output: `Applied: 019_product_variants.sql`

- [ ] **Step 3: Verify schema**

```bash
php -r "
require 'db.config.php';
\$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
\$row = \$pdo->query('DESCRIBE products type')->fetch();
echo \$row['Type'], PHP_EOL;
\$pdo->query('SELECT 1 FROM product_variants LIMIT 1');
echo 'product_variants OK', PHP_EOL;
"
```

Expected: `enum('standard','print','variant')` and `product_variants OK`

- [ ] **Step 4: Commit**

```bash
git add migrations/019_product_variants.sql
git commit -m "feat: add product_variants table and variant type enum"
```

---

## Task 2: DB test — product_variants table

**Files:**
- Create: `tests/Shop/ProductVariantsDbTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('db')]
final class ProductVariantsDbTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $variant_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (!empty(self::$variant_ids)) {
            $in = implode(',', array_fill(0, count(self::$variant_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE id IN ($in)")
                      ->execute(self::$variant_ids);
        }
        if (!empty(self::$product_ids)) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                      ->execute(self::$product_ids);
        }
    }

    private function insertVariantProduct(array $attrs = ['Вид', 'Аромат']): int
    {
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,variant_attributes)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            'test-variant-' . uniqid(), 'Тест Сапун', 'Test Soap',
            8.00, 0, 1, 'variant', json_encode($attrs),
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $id;
        return $id;
    }

    private function insertVariant(int $product_id, array $overrides = []): int
    {
        $d = array_merge([
            'label_bg'   => 'Лавандула',
            'label_en'   => 'Lavender',
            'attributes' => ['Вид' => 'лилав', 'Аромат' => 'лавандула'],
            'image'      => 'upload-abc123.jpg',
            'stock'      => 10,
            'active'     => 1,
            'sort_order' => 0,
        ], $overrides);
        self::$pdo->prepare(
            'INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,stock,active,sort_order)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $product_id, $d['label_bg'], $d['label_en'],
            json_encode($d['attributes'], JSON_UNESCAPED_UNICODE),
            $d['image'], $d['stock'], $d['active'], $d['sort_order'],
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$variant_ids[] = $id;
        return $id;
    }

    public function testInsertAndRetrieveVariant(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid);

        $stmt = self::$pdo->prepare('SELECT * FROM product_variants WHERE id = ?');
        $stmt->execute([$vid]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($pid, (int)$row['product_id']);
        $this->assertSame('Лавандула', $row['label_bg']);
        $this->assertSame(10, (int)$row['stock']);

        $attrs = json_decode($row['attributes'], true);
        $this->assertSame('лилав', $attrs['Вид']);
        $this->assertSame('лавандула', $attrs['Аромат']);
    }

    public function testVariantBelongsToProduct(): void
    {
        $pid = $this->insertVariantProduct();
        $this->insertVariant($pid, ['label_bg' => 'Портокал', 'stock' => 5]);
        $this->insertVariant($pid, ['label_bg' => 'Роза',     'stock' => 0]);

        $stmt = self::$pdo->prepare(
            'SELECT label_bg, stock FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order'
        );
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();

        $this->assertCount(2, $rows);
        $this->assertSame('Портокал', $rows[0]['label_bg']);
    }

    public function testStockDecrementOnVariant(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, ['stock' => 8]);

        self::$pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        )->execute([3, $vid, 3]);

        $check = self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?');
        $check->execute([$vid]);
        $this->assertSame(5, (int)$check->fetchColumn());
    }

    public function testStockDecrementFailsWhenInsufficient(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, ['stock' => 2]);

        $stmt = self::$pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([5, $vid, 5]);
        $this->assertSame(0, (int)$stmt->rowCount());

        $check = self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?');
        $check->execute([$vid]);
        $this->assertSame(2, (int)$check->fetchColumn());
    }

    public function testVariantTypeProductHasVariantAttributes(): void
    {
        $pid = $this->insertVariantProduct(['Вид', 'Аромат']);

        $stmt = self::$pdo->prepare('SELECT `type`, variant_attributes FROM products WHERE id = ?');
        $stmt->execute([$pid]);
        $row = $stmt->fetch();

        $this->assertSame('variant', $row['type']);
        $attrs = json_decode($row['variant_attributes'], true);
        $this->assertSame(['Вид', 'Аромат'], $attrs);
    }

    public function testFirstActiveVariantImageQuery(): void
    {
        $pid = $this->insertVariantProduct();
        $this->insertVariant($pid, ['image' => 'first.jpg', 'sort_order' => 0]);
        $this->insertVariant($pid, ['image' => 'second.jpg', 'sort_order' => 1]);

        $stmt = self::$pdo->prepare(
            'SELECT image FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order ASC LIMIT 1'
        );
        $stmt->execute([$pid]);
        $this->assertSame('first.jpg', $stmt->fetchColumn());
    }
}
```

- [ ] **Step 2: Run tests (expect pass — pure DB operations)**

```bash
php vendor/bin/phpunit tests/Shop/ProductVariantsDbTest.php --testdox
```

Expected: all 6 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Shop/ProductVariantsDbTest.php
git commit -m "test: add ProductVariantsDbTest covering table ops and stock decrement"
```

---

## Task 3: Admin — POST handler for variant type

**Files:**
- Modify: `admin/product-edit.php` (lines 48–139)

- [ ] **Step 1: Extend type whitelist and add variant POST handler**

In `product-edit.php`, replace:

```php
$prod_type = in_array($_POST['type'] ?? '', ['standard', 'print'], true)
    ? $_POST['type']
    : 'standard';
```

With:

```php
$prod_type = in_array($_POST['type'] ?? '', ['standard', 'print', 'variant'], true)
    ? $_POST['type']
    : 'standard';
```

- [ ] **Step 2: Add variant attribute + variant rows parsing after the `if ($prod_type === 'print') { ... }` block (around line 99)**

After the closing `}` of the print block, add:

```php
$variant_attributes_json = null;
$submitted_variants = []; // will be processed after DB save to get product id
if ($prod_type === 'variant') {
    $raw_attrs = $_POST['variant_attributes'] ?? [];
    $clean_attrs = [];
    foreach ($raw_attrs as $a) {
        $a = trim((string)$a);
        if ($a !== '' && mb_strlen($a) <= 64) $clean_attrs[] = $a;
    }
    $variant_attributes_json = json_encode($clean_attrs, JSON_UNESCAPED_UNICODE);

    // Parse variant rows from POST
    $vr_ids      = $_POST['pv_id']       ?? [];
    $vr_labels   = $_POST['pv_label_bg'] ?? [];
    $vr_labels_en= $_POST['pv_label_en'] ?? [];
    $vr_images   = $_POST['pv_image']    ?? [];
    $vr_stocks   = $_POST['pv_stock']    ?? [];
    $vr_attrs    = $_POST['pv_attrs']    ?? []; // JSON strings per row

    foreach (array_keys($vr_labels) as $i) {
        $lbl = trim($vr_labels[$i] ?? '');
        if ($lbl === '') continue;
        $row_attrs = json_decode($vr_attrs[$i] ?? '{}', true);
        if (!is_array($row_attrs)) $row_attrs = [];
        $submitted_variants[] = [
            'id'       => (int)($vr_ids[$i] ?? 0),
            'label_bg' => $lbl,
            'label_en' => trim($vr_labels_en[$i] ?? ''),
            'image'    => trim($vr_images[$i] ?? ''),
            'stock'    => max(0, (int)($vr_stocks[$i] ?? 0)),
            'attrs'    => $row_attrs,
        ];
    }
}
```

- [ ] **Step 3: Add variant validation (around line 105, after print validation)**

After the print validation block, add:

```php
if ($prod_type === 'variant' && empty($submitted_variants)) {
    $errors[] = 'Добавете поне един вариант.';
}
```

- [ ] **Step 4: Update the INSERT/UPDATE SQL to include `variant_attributes`**

Replace the existing INSERT:

```php
$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,description_bg,description_en,price_eur,stock,active,image,`type`,variants) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json]);
```

With:

```php
$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,description_bg,description_en,price_eur,stock,active,image,`type`,variants,variant_attributes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json, $variant_attributes_json]);
```

And replace the existing UPDATE:

```php
$pdo->prepare('UPDATE products SET slug=?,name_bg=?,name_en=?,description_bg=?,description_en=?,price_eur=?,stock=?,active=?,image=?,`type`=?,variants=? WHERE id=?')
    ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json, $id]);
```

With:

```php
$pdo->prepare('UPDATE products SET slug=?,name_bg=?,name_en=?,description_bg=?,description_en=?,price_eur=?,stock=?,active=?,image=?,`type`=?,variants=?,variant_attributes=? WHERE id=?')
    ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json, $variant_attributes_json, $id]);
```

- [ ] **Step 5: After the INSERT/UPDATE, upsert variant rows**

After the `flash_set / header / exit` block that follows a successful save, add this before the `header('Location:...)`:

```php
        // Upsert product_variants for variant-type products
        if ($prod_type === 'variant') {
            $saved_id = $is_new ? (int)$pdo->lastInsertId() : $id;
            // Get existing variant ids for this product (to detect deleted ones)
            $existing_stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
            $existing_stmt->execute([$saved_id]);
            $existing_ids = array_column($existing_stmt->fetchAll(), 'id');
            $submitted_ids = array_filter(array_column($submitted_variants, 'id'));

            // Soft-delete removed variants (only if not ordered)
            foreach ($existing_ids as $eid) {
                if (!in_array((int)$eid, $submitted_ids, true)) {
                    // Check if ordered
                    $ord = $pdo->prepare(
                        "SELECT COUNT(*) FROM orders WHERE JSON_SEARCH(items, 'one', ?, NULL, '$[*].variant_id') IS NOT NULL"
                    );
                    $ord->execute([(string)$eid]);
                    if ((int)$ord->fetchColumn() > 0) {
                        $pdo->prepare('UPDATE product_variants SET active = 0 WHERE id = ?')->execute([$eid]);
                    } else {
                        $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$eid]);
                    }
                }
            }

            // Insert or update each submitted variant
            foreach ($submitted_variants as $sort => $sv) {
                $attrs_json = json_encode($sv['attrs'], JSON_UNESCAPED_UNICODE);
                if ($sv['id'] > 0 && in_array($sv['id'], $existing_ids, true)) {
                    $pdo->prepare(
                        'UPDATE product_variants SET label_bg=?,label_en=?,attributes=?,image=?,stock=?,sort_order=?,active=1 WHERE id=? AND product_id=?'
                    )->execute([$sv['label_bg'], $sv['label_en'], $attrs_json, $sv['image'], $sv['stock'], $sort, $sv['id'], $saved_id]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,stock,sort_order) VALUES (?,?,?,?,?,?,?)'
                    )->execute([$saved_id, $sv['label_bg'], $sv['label_en'], $attrs_json, $sv['image'], $sv['stock'], $sort]);
                }
            }
        }
```

Note: this block must come **before** `flash_set` / `header` / `exit`. Restructure the save block so that the upsert runs inside the `if (!$errors)` branch, before the redirect.

- [ ] **Step 6: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all existing tests pass. No new tests for the POST handler — it will be covered by manual smoke testing after the UI is in place.

- [ ] **Step 7: Commit**

```bash
git add admin/product-edit.php
git commit -m "feat: add variant type to admin product POST handler"
```

---

## Task 4: Admin — variant type UI panel

**Files:**
- Modify: `admin/product-edit.php` (type selector section + new variantsPanel)

- [ ] **Step 1: Add "С варианти" radio to type selector (around line 244)**

Find the type selector block and replace it with:

```php
<div class="form-group" style="margin-top:1.5rem;">
  <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Тип продукт</label>
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="standard"
             <?= ($product['type'] ?? 'standard') === 'standard' ? 'checked' : '' ?>
             onchange="switchType('standard')"
             style="accent-color:var(--teal);">
      Стандартен
    </label>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="print"
             <?= ($product['type'] ?? '') === 'print' ? 'checked' : '' ?>
             onchange="switchType('print')"
             style="accent-color:var(--teal);">
      Печат (тениска)
    </label>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="variant"
             <?= ($product['type'] ?? '') === 'variant' ? 'checked' : '' ?>
             onchange="switchType('variant')"
             style="accent-color:var(--teal);">
      С варианти
    </label>
  </div>
</div>
```

- [ ] **Step 2: Replace `toggleVariants` JS with `switchType`**

In the `<script>` block (around line 387), replace:

```js
function toggleVariants(show) {
  document.getElementById('variantsPanel').style.display = show ? '' : 'none';
}
```

With:

```js
function switchType(type) {
  document.getElementById('variantsPanel').style.display       = type === 'print'   ? '' : 'none';
  document.getElementById('productVariantsPanel').style.display = type === 'variant' ? '' : 'none';
  document.getElementById('stockField').style.display          = type === 'variant' ? 'none' : '';
  document.getElementById('imageField').style.display          = type === 'variant' ? 'none' : '';
}
```

- [ ] **Step 3: Add `id="stockField"` and `id="imageField"` to the relevant wrappers**

Find the stock input wrapper (around line 197) and add `id="stockField"`:

```php
<label id="stockField" style="font-size:.875rem;font-weight:600;<?= $product['type'] === 'variant' ? 'display:none;' : '' ?>">Наличност
```

Find the image `<div class="form-group"` wrapper (around line 226) and add `id="imageField"`:

```php
<div id="imageField" class="form-group" style="margin-top:1.5rem;<?= $product['type'] === 'variant' ? 'display:none;' : '' ?>">
```

- [ ] **Step 4: Load existing variant data for the form**

After the existing `$vdata = json_decode(...)` block (around line 264), add:

```php
$prod_variant_attrs = json_decode($product['variant_attributes'] ?? '[]', true) ?? [];
$prod_variants_rows = [];
if (($product['type'] ?? '') === 'variant' && !$is_new) {
    $pv_stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order');
    $pv_stmt->execute([$id]);
    $prod_variants_rows = $pv_stmt->fetchAll();
}
```

- [ ] **Step 5: Add the product variants panel HTML**

After the closing `</div>` of `variantsPanel` (the print panel), add:

```php
<?php $is_variant_type = ($product['type'] ?? 'standard') === 'variant'; ?>
<div id="productVariantsPanel" style="<?= $is_variant_type ? '' : 'display:none;' ?>margin-top:1.5rem;padding:1.25rem;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--off-white);">
  <h3 style="font-size:.95rem;font-weight:700;margin:0 0 1rem;">Варианти на продукта</h3>

  <!-- Attribute dimensions -->
  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.4rem;">Атрибути (какво се различава)</label>
    <small style="color:var(--text-muted);display:block;margin-bottom:.5rem;">напр. "Вид", "Аромат" — станат заглавия на колоните долу</small>
    <div id="attrTags" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem;">
      <?php foreach ($prod_variant_attrs as $attr): ?>
        <span class="attr-tag" style="display:inline-flex;align-items:center;gap:.3rem;background:#e8f0fe;border:1px solid #4a90e2;border-radius:20px;padding:.2rem .75rem;font-size:.85rem;">
          <?= h($attr) ?>
          <input type="hidden" name="variant_attributes[]" value="<?= h($attr) ?>">
          <button type="button" onclick="removeAttrTag(this)" style="background:none;border:none;cursor:pointer;color:#888;padding:0;font-size:.9rem;line-height:1;">×</button>
        </span>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:.5rem;">
      <input type="text" id="newAttrInput" placeholder="Добави атрибут..." maxlength="64"
             style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;width:180px;"
             onkeydown="if(event.key==='Enter'){event.preventDefault();addAttrTag();}">
      <button type="button" onclick="addAttrTag()" class="btn btn--outline" style="font-size:.82rem;">Добави</button>
    </div>
  </div>

  <!-- Variants table -->
  <div>
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Варианти</label>
    <div id="pvTableWrap" style="overflow-x:auto;">
      <table id="pvTable" style="width:100%;border-collapse:collapse;font-size:.85rem;">
        <thead id="pvHead">
          <tr style="border-bottom:2px solid var(--border);">
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Снимка</th>
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Название (BG) *</th>
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Название (EN)</th>
            <!-- attr columns injected by JS -->
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Наличност</th>
            <th style="width:30px;"></th>
          </tr>
        </thead>
        <tbody id="pvBody">
          <?php foreach ($prod_variants_rows as $pvr): ?>
          <?php $pvr_attrs = json_decode($pvr['attributes'] ?? '{}', true) ?? []; ?>
          <tr class="pv-row" data-id="<?= (int)$pvr['id'] ?>">
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <?php if ($pvr['image']): ?>
                <img src="/assets/images/products/<?= h($pvr['image']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid var(--border);" onclick="pickVariantImage(this)" title="Смени снимка">
              <?php else: ?>
                <div style="width:44px;height:44px;border:2px dashed var(--border);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-muted);font-size:1.2rem;" onclick="pickVariantImage(this)" title="Добави снимка">+</div>
              <?php endif; ?>
              <input type="hidden" name="pv_id[]" value="<?= (int)$pvr['id'] ?>">
              <input type="hidden" class="pv-image-val" name="pv_image[]" value="<?= h($pvr['image'] ?? '') ?>">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="text" name="pv_label_bg[]" value="<?= h($pvr['label_bg']) ?>" required
                     style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="text" name="pv_label_en[]" value="<?= h($pvr['label_en'] ?? '') ?>"
                     style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <!-- attr value cells injected by JS via rebuildAttrCols() -->
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="number" name="pv_stock[]" value="<?= (int)$pvr['stock'] ?>" min="0"
                     style="width:70px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;text-align:center;">
              <button type="button" onclick="removePvRow(this)"
                      style="background:none;border:none;color:#e53935;cursor:pointer;font-size:1.1rem;padding:.2rem;">✕</button>
            </td>
          </tr>
          <!-- Store existing attr values as data attribute for JS to pick up -->
          <script>document.currentScript.closest('tr').dataset.attrs = <?= json_encode(json_encode($pvr_attrs, JSON_UNESCAPED_UNICODE)) ?>;</script>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Hidden inputs for pv_attrs[] are managed by JS -->
    <div id="pvAttrInputs"></div>
    <button type="button" onclick="addPvRow()" class="btn btn--outline" style="margin-top:.75rem;font-size:.82rem;">+ Добави вариант</button>
  </div>
</div>
```

- [ ] **Step 6: Add JS for variant panel management**

In the `<script>` block at the bottom of the file, add:

```js
// ── Product variants panel ───────────────────────────────────────────────────

function getAttrLabels() {
  return Array.from(document.querySelectorAll('#attrTags .attr-tag input[name="variant_attributes[]"]'))
              .map(function(i){ return i.value; });
}

function addAttrTag() {
  var inp = document.getElementById('newAttrInput');
  var val = inp.value.trim();
  if (!val || val.length > 64) return;
  // Prevent duplicates
  var existing = getAttrLabels();
  if (existing.includes(val)) { inp.value=''; return; }

  var span = document.createElement('span');
  span.className = 'attr-tag';
  span.style.cssText = 'display:inline-flex;align-items:center;gap:.3rem;background:#e8f0fe;border:1px solid #4a90e2;border-radius:20px;padding:.2rem .75rem;font-size:.85rem;';
  span.innerHTML = val
    + '<input type="hidden" name="variant_attributes[]" value="' + val.replace(/"/g,'&quot;') + '">'
    + '<button type="button" onclick="removeAttrTag(this)" style="background:none;border:none;cursor:pointer;color:#888;padding:0;font-size:.9rem;line-height:1;">×</button>';
  document.getElementById('attrTags').insertBefore(span, document.getElementById('attrTags').lastElementChild);
  inp.value = '';
  rebuildAttrCols();
}

function removeAttrTag(btn) {
  btn.closest('.attr-tag').remove();
  rebuildAttrCols();
}

function rebuildAttrCols() {
  var labels  = getAttrLabels();
  var head    = document.getElementById('pvHead').querySelector('tr');
  var rows    = document.querySelectorAll('#pvBody .pv-row');

  // Remove old attr <th> elements (between EN col and Наличност col)
  head.querySelectorAll('th.attr-col').forEach(function(th){ th.remove(); });
  // Insert new attr <th> before Наличност
  var stockTh = head.querySelectorAll('th')[3]; // Снимка, BG, EN, [attrs...], Наличност
  labels.forEach(function(lbl) {
    var th = document.createElement('th');
    th.className = 'attr-col';
    th.style.cssText = 'text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;';
    th.textContent = lbl;
    head.insertBefore(th, stockTh);
  });

  // Rebuild attr cells in each row
  rows.forEach(function(row) {
    // Remove old attr cells
    row.querySelectorAll('td.attr-col').forEach(function(td){ td.remove(); });
    // Parse existing attr values (from data-attrs on row or from hidden input)
    var attrHidden = row.querySelector('input.pv-attrs-json');
    var existing = {};
    if (attrHidden) {
      try { existing = JSON.parse(attrHidden.value); } catch(e){}
    } else if (row.dataset.attrs) {
      try { existing = JSON.parse(JSON.parse(row.dataset.attrs)); } catch(e){}
    }

    // Find Наличност td (index 3 originally, now before delete)
    var tds = row.querySelectorAll('td');
    var stockTd = tds[tds.length - 2]; // second-to-last
    labels.forEach(function(lbl) {
      var td = document.createElement('td');
      td.className = 'attr-col';
      td.style.cssText = 'padding:.4rem .5rem;vertical-align:middle;';
      td.innerHTML = '<input type="text" placeholder="' + lbl + '" value="' + (existing[lbl] || '').replace(/"/g,'&quot;') + '"'
        + ' style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"'
        + ' data-attr="' + lbl.replace(/"/g,'&quot;') + '" onchange="syncAttrJson(this.closest(\'tr\'))">';
      row.insertBefore(td, stockTd);
    });
    syncAttrJson(row);
  });
}

function syncAttrJson(row) {
  var labels = getAttrLabels();
  var obj = {};
  labels.forEach(function(lbl) {
    var inp = row.querySelector('input[data-attr="' + lbl.replace(/"/g,'\\"') + '"]');
    if (inp) obj[lbl] = inp.value;
  });
  var hidden = row.querySelector('input.pv-attrs-json');
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.className = 'pv-attrs-json';
    hidden.name = 'pv_attrs[]';
    row.appendChild(hidden);
  }
  hidden.value = JSON.stringify(obj);
}

function addPvRow() {
  var labels = getAttrLabels();
  var tbody  = document.getElementById('pvBody');
  var tr     = document.createElement('tr');
  tr.className = 'pv-row';
  tr.dataset.id = '0';

  var attrCols = labels.map(function(lbl) {
    return '<td class="attr-col" style="padding:.4rem .5rem;vertical-align:middle;">'
      + '<input type="text" placeholder="' + lbl.replace(/"/g,'&quot;') + '" data-attr="' + lbl.replace(/"/g,'&quot;') + '"'
      + ' style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"'
      + ' onchange="syncAttrJson(this.closest(\'tr\'))">'
      + '</td>';
  }).join('');

  tr.innerHTML = '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<div style="width:44px;height:44px;border:2px dashed var(--border);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-muted);font-size:1.2rem;" onclick="pickVariantImage(this)" title="Добави снимка">+</div>'
    + '<input type="hidden" name="pv_id[]" value="0">'
    + '<input type="hidden" class="pv-image-val" name="pv_image[]" value="">'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="text" name="pv_label_bg[]" required style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="text" name="pv_label_en[]" style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + attrCols
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="number" name="pv_stock[]" value="0" min="0" style="width:70px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;text-align:center;">'
    + '<button type="button" onclick="removePvRow(this)" style="background:none;border:none;color:#e53935;cursor:pointer;font-size:1.1rem;padding:.2rem;">✕</button>'
    + '</td>';
  tbody.appendChild(tr);
  syncAttrJson(tr);
}

function removePvRow(btn) {
  btn.closest('tr').remove();
}

function pickVariantImage(el) {
  var row = el.closest('tr');
  openMediaPicker(function(p) {
    var fn = p.indexOf('/assets/images/products/') === 0
           ? p.replace('/assets/images/products/', '') : p;
    row.querySelector('.pv-image-val').value = fn;
    // Replace placeholder div or existing img with img tag
    var imgEl = row.querySelector('img');
    if (imgEl) {
      imgEl.src = '/assets/images/products/' + fn;
    } else {
      el.outerHTML = '<img src="/assets/images/products/' + fn + '" style="width:44px;height:44px;object-fit:cover;border-radius:4px;cursor:pointer;border:1px solid var(--border);" onclick="pickVariantImage(this)" title="Смени снимка">';
    }
  }, { cat: 'products' });
}

// Init on page load
document.addEventListener('DOMContentLoaded', function() {
  rebuildAttrCols();
});
```

- [ ] **Step 7: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 8: Smoke test manually**

1. Go to `/admin/product-edit.php` (new product)
2. Select "С варианти"
3. Add attributes "Вид", "Аромат" — columns appear in the table
4. Add two variant rows, upload images, fill labels and stock
5. Save — confirm redirect to products list with success flash
6. Edit the product — variants load correctly
7. Delete a variant row, save — confirm it's gone

- [ ] **Step 9: Commit**

```bash
git add admin/product-edit.php
git commit -m "feat: add variant type UI panel to admin product editor"
```

---

## Task 5: Public product page — variant selector

**Files:**
- Modify: `magazin/index.php`

- [ ] **Step 1: Load variant rows for variant-type products**

In the product detail route (around line 20), after the `$is_print` block, add:

```php
$is_variant     = false;
$prod_variants  = [];
$variant_attrs  = [];

if ($p && $p['type'] === 'variant') {
    $is_variant    = true;
    $variant_attrs = json_decode($p['variant_attributes'] ?? '[]', true) ?? [];
    $pv_stmt = $pdo->prepare(
        'SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order'
    );
    $pv_stmt->execute([$p['id']]);
    $prod_variants = $pv_stmt->fetchAll();
}
```

- [ ] **Step 2: Set listing image for variant products**

In the shop listing loop (where product cards are built), for variant products the `$p['image']` column is empty. When building the card image src, add a fallback: query the first active variant's image. Do this at the top of the listing section, right after fetching `$products`:

```php
// For variant products, image column is empty — use first active variant's image
$variant_images = [];
$variant_product_ids = array_column(
    array_filter($products, fn($p) => $p['type'] === 'variant'),
    'id'
);
if ($variant_product_ids) {
    $in2 = implode(',', array_fill(0, count($variant_product_ids), '?'));
    $vi_stmt = $pdo->prepare(
        "SELECT DISTINCT product_id, image FROM product_variants
         WHERE product_id IN ($in2) AND active=1 AND image != ''
         ORDER BY product_id, sort_order LIMIT " . count($variant_product_ids)
    );
    $vi_stmt->execute($variant_product_ids);
    foreach ($vi_stmt->fetchAll() as $vi) {
        $variant_images[$vi['product_id']] = $vi['image'];
    }
}
```

Then when rendering card image, use:

```php
$card_img = $p['type'] === 'variant'
    ? ($variant_images[$p['id']] ?? '')
    : $p['image'];
```

- [ ] **Step 3: Add inline CSS for variant selector**

In `$page_head_extra` for the product detail page (around line 51), append:

```php
.pv-option{display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;border:2px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color .15s;}
.pv-option:hover{border-color:var(--teal);}
.pv-option.active{border-color:var(--teal);background:#e8f7f9;}
.pv-option.disabled{opacity:.45;cursor:default;}
.pv-thumb{width:40px;height:40px;object-fit:cover;border-radius:4px;flex-shrink:0;background:var(--off-white);}
.pv-thumbstrip{display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap;}
.pv-thumbstrip img{width:52px;height:52px;object-fit:cover;border-radius:4px;cursor:pointer;border:2px solid transparent;transition:border-color .15s;}
.pv-thumbstrip img.active{border-color:var(--teal);}
```

- [ ] **Step 4: In the image/mockup column, handle variant type**

In the left column (around line 83), extend the image rendering block:

```php
<?php
  if ($is_variant && !empty($prod_variants)) {
      // First active variant image
      $first_pv = $prod_variants[0];
      $initial_src = $first_pv['image'] ? '/assets/images/products/' . $first_pv['image'] : '';
  }
?>
<?php if ($is_variant): ?>
  <?php if ($initial_src): ?>
    <img id="mockupImg" src="<?= h($initial_src) ?>" alt="<?= h($name) ?>"
         style="width:100%;height:100%;object-fit:cover;display:block;border-radius:var(--radius-lg);">
  <?php else: ?>
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:5rem;">🖼</div>
  <?php endif; ?>
  <!-- Thumbnail strip -->
  <?php if (count($prod_variants) > 1): ?>
  <div class="pv-thumbstrip" style="margin-top:.75rem;">
    <?php foreach ($prod_variants as $pvi => $pv): ?>
      <?php if ($pv['image']): ?>
        <img src="/assets/images/products/<?= h($pv['image']) ?>"
             alt="<?= h($lang === 'bg' ? $pv['label_bg'] : ($pv['label_en'] ?: $pv['label_bg'])) ?>"
             class="<?= $pvi === 0 ? 'active' : '' ?>"
             onclick="selectVariant(<?= (int)$pv['id'] ?>)"
             title="<?= h($lang === 'bg' ? $pv['label_bg'] : ($pv['label_en'] ?: $pv['label_bg'])) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 5: Add variant selector in the details column**

In the details column, after the price display and before the add-to-cart form (around line 116), add the variant selector for variant-type products:

```php
<?php if ($is_variant && !empty($prod_variants)): ?>
  <div style="margin-bottom:1rem;">
    <div style="font-size:.85rem;font-weight:600;margin-bottom:.6rem;">
      <?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:.5rem;">
      <?php foreach ($prod_variants as $pvi => $pv): ?>
        <?php
          $pv_label = $lang === 'bg' ? $pv['label_bg'] : ($pv['label_en'] ?: $pv['label_bg']);
          $pv_attrs_arr = json_decode($pv['attributes'] ?? '{}', true) ?? [];
          $pv_attr_str  = implode(' · ', array_map(
              fn($k,$v) => h($k) . ': ' . h($v),
              array_keys($pv_attrs_arr), $pv_attrs_arr
          ));
          $pv_in_stock = (int)$pv['stock'] > 0;
          $pv_img_src  = $pv['image'] ? '/assets/images/products/' . $pv['image'] : '';
          $pv_data = json_encode([
              'id'    => (int)$pv['id'],
              'image' => $pv_img_src,
              'label' => $pv_label,
          ]);
        ?>
        <div class="pv-option<?= $pvi === 0 ? ' active' : '' ?><?= !$pv_in_stock ? ' disabled' : '' ?>"
             id="pvo-<?= (int)$pv['id'] ?>"
             <?= $pv_in_stock ? "onclick=\"selectVariant({$pv['id']})\"" : '' ?>>
          <?php if ($pv_img_src): ?>
            <img class="pv-thumb" src="<?= h($pv_img_src) ?>" alt="">
          <?php else: ?>
            <div class="pv-thumb" style="background:var(--off-white);border-radius:4px;"></div>
          <?php endif; ?>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:.95rem;"><?= h($pv_label) ?></div>
            <?php if ($pv_attr_str): ?>
              <div style="font-size:.78rem;color:var(--text-muted);"><?= $pv_attr_str ?></div>
            <?php endif; ?>
          </div>
          <div style="font-size:.8rem;flex-shrink:0;<?= $pv_in_stock ? 'color:var(--teal);' : 'color:#e53935;' ?>">
            <?= $pv_in_stock
                ? (int)$pv['stock'] . ($lang === 'bg' ? ' бр.' : ' left')
                : ($lang === 'bg' ? 'Изчерпан' : 'Out of stock') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
```

- [ ] **Step 6: Update add-to-cart form for variant type**

The existing form already renders for `$p['stock'] > 0`. For variant products, stock lives on variants, not the product, so change the condition:

```php
<?php
  $show_cart_form = $is_variant
      ? !empty(array_filter($prod_variants, fn($pv) => (int)$pv['stock'] > 0))
      : (int)$p['stock'] > 0;
?>
<?php if ($show_cart_form): ?>
  <form method="POST" action="/cart/add.php" id="addToCartForm">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
    <input type="hidden" name="redirect"   value="product">
    <input type="hidden" name="_lang"      value="<?= h($lang) ?>">
    <?php if ($is_variant): ?>
      <input type="hidden" name="variant_id" id="variantIdInput"
             value="<?= !empty($prod_variants) ? (int)$prod_variants[0]['id'] : '' ?>">
    <?php endif; ?>
    <!-- existing colour/size selectors only for print type ... -->
```

Keep the existing print-type selectors inside the form untouched, gated on `$is_print`.

- [ ] **Step 7: Add `selectVariant` JS**

After the existing print JS (at the bottom of the single-product section, before `require footer`), add:

```php
<?php if ($is_variant && !empty($prod_variants)): ?>
<script>
var _pvData = <?= json_encode(
  array_column(
    array_map(fn($pv) => [
      'id'    => (int)$pv['id'],
      'image' => $pv['image'] ? '/assets/images/products/' . $pv['image'] : '',
      'stock' => (int)$pv['stock'],
    ], $prod_variants),
    null
  )
, JSON_UNESCAPED_UNICODE) ?>;

function selectVariant(vid) {
  var pv = _pvData.find(function(v){ return v.id === vid; });
  if (!pv || pv.stock === 0) return;

  // Update hidden input
  var inp = document.getElementById('variantIdInput');
  if (inp) inp.value = vid;

  // Swap main image
  var img = document.getElementById('mockupImg');
  if (img && pv.image) img.src = pv.image;

  // Update option highlight
  document.querySelectorAll('.pv-option').forEach(function(el){
    el.classList.remove('active');
  });
  var opt = document.getElementById('pvo-' + vid);
  if (opt) opt.classList.add('active');

  // Update thumbnail strip
  document.querySelectorAll('.pv-thumbstrip img').forEach(function(th){
    th.classList.toggle('active', parseInt(th.dataset.vid || '0') === vid);
  });
}

// Set data-vid on thumbnail images
document.querySelectorAll('.pv-thumbstrip img').forEach(function(th, i) {
  if (_pvData[i]) th.dataset.vid = _pvData[i].id;
});
</script>
<?php endif; ?>
```

- [ ] **Step 8: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 9: Smoke test manually**

1. Create a variant product in admin with 2 variants, each with a photo
2. Visit `/magazin/{slug}/`
3. Both variants appear in the list
4. Click second variant — main image swaps, option highlights
5. Click thumbnail — same swap
6. Out-of-stock variant shows disabled, cannot be clicked
7. Add to cart — correct `variant_id` posted

- [ ] **Step 10: Commit**

```bash
git add magazin/index.php
git commit -m "feat: variant product selector on public product page"
```

---

## Task 6: Cart — accept and display variant

**Files:**
- Modify: `cart/add.php`
- Modify: `cart/index.php`

- [ ] **Step 1: Update `cart/add.php` to validate variant_id**

After the existing `$product` fetch (line 35), replace the stock check and the print-block opening with:

```php
// ── Variant product validation ────────────────────────────────────────────────
$variant_id    = 0;
$variant_label = '';

if ($product['type'] === 'variant') {
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    if (!$variant_id) {
        flash_set('error', 'Моля изберете вариант.');
        header('Location: ' . $redirect);
        exit;
    }
    $pdo2 = get_pdo();
    $pv_stmt = $pdo2->prepare(
        'SELECT id, label_bg, stock, active FROM product_variants WHERE id = ? AND product_id = ?'
    );
    $pv_stmt->execute([$variant_id, $product_id]);
    $pv = $pv_stmt->fetch();
    if (!$pv || !$pv['active']) {
        flash_set('error', 'Вариантът не е намерен.');
        header('Location: ' . $redirect);
        exit;
    }
    if ((int)$pv['stock'] <= 0) {
        flash_set('error', h($pv['label_bg']) . ' е изчерпан.');
        header('Location: ' . $redirect . '?out=1');
        exit;
    }
    $variant_label = $pv['label_bg'];
}

// For standard/print products, check product-level stock
if ($product['type'] !== 'variant' && (int)$product['stock'] <= 0) {
    flash_set('error', h($product['name_bg']) . ' е изчерпан.');
    header('Location: ' . $redirect . '?out=1');
    exit;
}
```

Remove the original stock check block (lines 43–47).

- [ ] **Step 2: Store variant_id in cart session**

In the `if (!$found)` block (around line 133), add `variant_id` to the entry:

```php
$entry = ['product_id' => $product_id, 'quantity' => min($qty, $stock_limit)];
if ($product['type'] === 'print') {
    $entry['colour']          = $colour;
    $entry['size']            = $size;
    $entry['design_file']     = $design_file;
    $entry['design_position'] = $design_position;
}
if ($product['type'] === 'variant') {
    $entry['variant_id']    = $variant_id;
    $entry['variant_label'] = $variant_label;
}
```

For `$stock_limit`, for variant products use `(int)$pv['stock']`; for others use `(int)$product['stock']`. Add this before the cart loop:

```php
$stock_limit = $product['type'] === 'variant' ? (int)$pv['stock'] : (int)$product['stock'];
```

Also update the matching logic in the cart-update loop:

```php
foreach ($cart as &$item) {
    $match = $item['product_id'] === $product_id;
    if ($product['type'] === 'variant') $match = $match && (int)($item['variant_id'] ?? 0) === $variant_id;
    if ($match) {
        $item['quantity'] = min($item['quantity'] + $qty, $stock_limit);
        if ($product['type'] === 'variant') {
            $item['variant_label'] = $variant_label;
        }
        $found = true;
        break;
    }
}
```

- [ ] **Step 3: Display variant label in cart (`cart/index.php`)**

In `cart/index.php`, in the `$cart_data[]` array build (around line 41), add:

```php
'variant_id'    => $item['variant_id']    ?? null,
'variant_label' => $item['variant_label'] ?? null,
```

In the cart HTML, find where colour/size is displayed (around line 136):

```php
<?php if (!empty($row['colour']) || !empty($row['size'])): ?>
```

After this block, add:

```php
<?php if (!empty($row['variant_label'])): ?>
  <div style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem;">
    <?= h($row['variant_label']) ?>
  </div>
<?php endif; ?>
```

For the thumbnail, when `$p['type'] === 'variant'`, fetch the variant image:

In the cart data build loop, add:

```php
'variant_image' => null, // filled below
```

After the loop, add a bulk variant image fetch:

```php
$variant_image_ids = array_filter(array_column($cart_data, 'variant_id'));
if ($variant_image_ids) {
    $in3 = implode(',', array_fill(0, count($variant_image_ids), '?'));
    $vi2 = $pdo->prepare("SELECT id, image FROM product_variants WHERE id IN ($in3)");
    $vi2->execute(array_values($variant_image_ids));
    $vi2_map = [];
    foreach ($vi2->fetchAll() as $vir) $vi2_map[$vir['id']] = $vir['image'];
    foreach ($cart_data as &$cd) {
        if ($cd['variant_id'] && isset($vi2_map[$cd['variant_id']])) {
            $cd['variant_image'] = $vi2_map[$cd['variant_id']];
        }
    }
    unset($cd);
}
```

In the thumbnail rendering block, add a case for variant type before the generic `elseif ($p['image'])`:

```php
<?php elseif ($p['type'] === 'variant' && !empty($row['variant_image'])): ?>
  <a href="<?= $prod_url ?>">
    <img src="/assets/images/products/<?= h($row['variant_image']) ?>" alt=""
         style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;">
  </a>
```

- [ ] **Step 4: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 5: Smoke test**

1. Add a variant product to cart
2. Cart shows variant name and variant's image thumbnail
3. Add same product different variant — appears as separate cart row
4. Add same product same variant — quantity increments

- [ ] **Step 6: Commit**

```bash
git add cart/add.php cart/index.php
git commit -m "feat: cart accepts and displays variant products"
```

---

## Task 7: Checkout — stock decrement on product_variants

**Files:**
- Modify: `checkout/index.php`

- [ ] **Step 1: Pass variant data through `load_cart_products`**

In the `load_cart_products` function (around line 36), add to each `$rows[]` entry:

```php
'variant_id'    => $item['variant_id']    ?? null,
'variant_label' => $item['variant_label'] ?? null,
```

- [ ] **Step 2: Update `update_cart_from_post` to preserve variant fields**

In the `foreach (['colour', 'size', 'design_file', 'design_position']` line (around line 60), add `'variant_id', 'variant_label'` to the array:

```php
foreach (['colour', 'size', 'design_file', 'design_position', 'variant_id', 'variant_label'] as $k) {
```

- [ ] **Step 3: Include `variant_id` in order items JSON**

In the items JSON build (around line 263), add:

```php
if (!empty($row['variant_id']))    $entry['variant_id']    = (int)$row['variant_id'];
if (!empty($row['variant_label'])) $entry['variant_label'] = $row['variant_label'];
```

- [ ] **Step 4: Decrement variant stock instead of product stock**

In the transactional stock decrement block (around line 290), replace:

```php
foreach ($rows as $row) {
    $p  = $row['product'];
    $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?')
        ->execute([$row['quantity'], $p['id'], $row['quantity']]);
    $affected = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
    if ((int)$affected === 0) {
        throw new RuntimeException($p['name_bg'] . ' вече не е в наличност.');
    }
}
```

With:

```php
foreach ($rows as $row) {
    $p = $row['product'];
    if ($p['type'] === 'variant' && !empty($row['variant_id'])) {
        $pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND product_id = ? AND stock >= ?'
        )->execute([$row['quantity'], $row['variant_id'], $p['id'], $row['quantity']]);
    } else {
        $pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        )->execute([$row['quantity'], $p['id'], $row['quantity']]);
    }
    $affected = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
    if ((int)$affected === 0) {
        throw new RuntimeException($p['name_bg'] . ' вече не е в наличност.');
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 6: Smoke test full order flow**

1. Add a variant product to cart (2 units, variant with stock=5)
2. Complete checkout
3. Check `product_variants.stock` in DB — should be 3
4. Check `orders.items` JSON — should contain `variant_id` and `variant_label`

- [ ] **Step 7: Commit**

```bash
git add checkout/index.php
git commit -m "feat: checkout decrements product_variants stock for variant products"
```

---

## Task 8: Admin order view — display variant label

**Files:**
- Modify: whichever admin file renders the order items list (check `admin/order-view.php` or similar)

- [ ] **Step 1: Find where order items are rendered**

```bash
grep -rn "name_bg\|colour\|size\|design_file" /Users/detelinavasileva/Code/oddminds/admin/ --include="*.php" -l
```

Identify the file that loops through `$items` / `$order['items']` to display product lines.

- [ ] **Step 2: Add variant_label display**

In that file, wherever `colour` and `size` are displayed as variant details, add:

```php
<?php if (!empty($item['variant_label'])): ?>
  <span style="font-size:.8rem;color:var(--text-muted);"><?= h($item['variant_label']) ?></span>
<?php endif; ?>
```

- [ ] **Step 3: Run tests**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add admin/order-view.php   # or whichever file was modified
git commit -m "feat: show variant_label in admin order view"
```

---

## Self-Review Checklist

- [x] Migration creates `product_variants` table and extends `type` ENUM — **Task 1**
- [x] Admin POST saves `variant_attributes` and upserts variant rows — **Task 3**
- [x] Admin UI: type switcher, attribute tags, dynamic table columns, image picker — **Task 4**
- [x] Public product page: variant selector radio list, image swap, thumbnail strip — **Task 5**
- [x] Shop listing uses first active variant image — **Task 5, Step 2**
- [x] `cart/add.php` validates `variant_id`, checks variant stock — **Task 6**
- [x] Cart displays variant label and variant image — **Task 6**
- [x] Checkout decrements `product_variants.stock` — **Task 7**
- [x] Order items JSON stores `variant_id` + `variant_label` — **Task 7**
- [x] Admin order view shows variant label — **Task 8**
- [x] DB tests cover table ops and stock decrement — **Task 2**
