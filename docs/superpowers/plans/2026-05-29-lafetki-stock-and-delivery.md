# Lafetki Stock Reduction + Campaign Delivery Method — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a Lafetki campaign ticket or reward pledge is paid, decrement Lafetki Icebreakers stock; and require reward pledgers to select a delivery courier before paying.

**Architecture:** DB migration adds `icebreaker_qty` to `campaign_rewards` and five delivery columns to `campaign_pledges`. Stock reduction fires in `process_campaign_dsk_result()` (already the single paid-payment handler). The campaign pledge form's existing `#deliverySection` is replaced with a Speedy/BoxNow courier widget; `campaign/checkout.php` validates and saves the new fields.

**Tech Stack:** PHP 8.4, MySQL/PDO, PHPUnit 13. BoxNow widget: `/assets/js/boxnow-widget.js` + `BoxNowWidget.open()`. Speedy office fields: plain text inputs (no live API needed for campaign flow).

---

## File Map

| File | Action |
|---|---|
| `migrations/021_lafetki_stock_and_delivery.sql` | **Create** — two idempotent ALTER TABLE blocks |
| `api/campaign-payment-return.php` | **Modify** — add `define` constant + `reduce_icebreaker_stock()` function + one call |
| `campaign/index.php` | **Modify** — replace `#deliverySection` content + update JS |
| `campaign/checkout.php` | **Modify** — replace delivery validation block + expand INSERT |
| `tests/CampaignTest.php` | **Modify** — add tests for migration schema, stock SQL, delivery columns |

---

## Task 1: DB migration

**Files:**
- Create: `migrations/021_lafetki_stock_and_delivery.sql`
- Modify: `tests/CampaignTest.php`

- [ ] **Step 1: Write failing tests for new schema**

Add three methods to `tests/CampaignTest.php`, inside the class body after the last test method:

```php
public function test_icebreaker_qty_column_exists(): void
{
    $cols = self::$pdo->query(
        "SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'"
    )->fetchAll();
    $this->assertNotEmpty($cols, 'Run migration 021 first.');
}

public function test_icebreaker_qty_values_per_reward(): void
{
    // Skip if column missing (migration not yet run)
    $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'")->fetchAll();
    if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

    $rows = self::$pdo->query(
        "SELECT id, icebreaker_qty FROM campaign_rewards WHERE id IN (1,2,3,4) ORDER BY id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $this->assertSame(1, (int)($rows[1] ?? -1), 'Reward 1 (Benefactor) → 1 pack');
    $this->assertSame(2, (int)($rows[2] ?? -1), 'Reward 2 (Goodwill) → 2 packs');
    $this->assertSame(4, (int)($rows[3] ?? -1), 'Reward 3 (Laughing Man) → 4 packs');
    $this->assertSame(5, (int)($rows[4] ?? -1), 'Reward 4 (Miracle Worker) → 5 packs');
}

public function test_delivery_courier_columns_exist(): void
{
    foreach (['delivery_courier','delivery_type','office_code','office_name','office_city'] as $col) {
        $cols = self::$pdo->query(
            "SHOW COLUMNS FROM campaign_pledges LIKE '$col'"
        )->fetchAll();
        $this->assertNotEmpty($cols, "campaign_pledges.$col column must exist (run migration 021).");
    }
}
```

- [ ] **Step 2: Run tests — expect failures**

```bash
php vendor/bin/phpunit --filter "test_icebreaker_qty_column_exists|test_icebreaker_qty_values_per_reward|test_delivery_courier_columns_exist" --no-coverage
```

Expected: all three fail (columns don't exist yet).

- [ ] **Step 3: Write the migration file**

Create `migrations/021_lafetki_stock_and_delivery.sql`:

```sql
-- 021: Lafetki Icebreakers stock qty per reward + campaign pledge delivery columns

ALTER TABLE campaign_rewards
  ADD COLUMN IF NOT EXISTS icebreaker_qty TINYINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE campaign_rewards SET icebreaker_qty = 1 WHERE id = 1;
UPDATE campaign_rewards SET icebreaker_qty = 2 WHERE id = 2;
UPDATE campaign_rewards SET icebreaker_qty = 4 WHERE id = 3;
UPDATE campaign_rewards SET icebreaker_qty = 5 WHERE id = 4;

ALTER TABLE campaign_pledges
  ADD COLUMN IF NOT EXISTS delivery_courier VARCHAR(50)  NULL AFTER delivery_address,
  ADD COLUMN IF NOT EXISTS delivery_type    VARCHAR(50)  NULL AFTER delivery_courier,
  ADD COLUMN IF NOT EXISTS office_code      VARCHAR(100) NULL AFTER delivery_type,
  ADD COLUMN IF NOT EXISTS office_name      VARCHAR(200) NULL AFTER office_code,
  ADD COLUMN IF NOT EXISTS office_city      VARCHAR(100) NULL AFTER office_name;
```

- [ ] **Step 4: Apply migration locally**

```bash
curl -s "http://oddminds.test/migrate.php?list"
# Should show 021 as pending

curl -s "http://oddminds.test/migrate.php?run=021_lafetki_stock_and_delivery"
```

- [ ] **Step 5: Run tests — expect pass**

```bash
php vendor/bin/phpunit --filter "test_icebreaker_qty_column_exists|test_icebreaker_qty_values_per_reward|test_delivery_courier_columns_exist" --no-coverage
```

Expected: all three pass.

- [ ] **Step 6: Commit**

```bash
git add migrations/021_lafetki_stock_and_delivery.sql tests/CampaignTest.php
git commit -m "feat: add icebreaker_qty to campaign_rewards and delivery columns to campaign_pledges (migration 021)"
```

---

## Task 2: Stock reduction on payment confirmed

**Files:**
- Modify: `api/campaign-payment-return.php`
- Modify: `tests/CampaignTest.php`

- [ ] **Step 1: Write failing tests for stock decrement SQL pattern**

Add to `tests/CampaignTest.php` after the last test method:

```php
public function test_stock_decrement_sql_reduces_by_qty(): void
{
    // Create a temporary product + variant to test the SQL without touching prod data
    self::$pdo->prepare(
        "INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,variant_attributes)
         VALUES ('test-icebreaker-tmp','Test','Test',25.00,0,1,'variant','')"
    )->execute();
    $prod_id = (int)self::$pdo->lastInsertId();

    self::$pdo->prepare(
        "INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,stock,active,sort_order)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$prod_id,'Тест','Test','{}','',50,1,0]);
    $variant_id = (int)self::$pdo->lastInsertId();

    // Simulate ticket with qty=2
    $qty = 2;
    $stmt = self::$pdo->prepare(
        'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    $stmt->execute([$qty, $variant_id, $qty]);
    $this->assertSame(1, $stmt->rowCount(), 'Decrement must affect exactly 1 row');

    $stock = (int)self::$pdo->query("SELECT stock FROM product_variants WHERE id=$variant_id")->fetchColumn();
    $this->assertSame(48, $stock, 'Stock should be 50 - 2 = 48');

    // Guard: decrement beyond stock must affect 0 rows (no negative stock)
    $overshoot = 100;
    $stmt->execute([$overshoot, $variant_id, $overshoot]);
    $this->assertSame(0, $stmt->rowCount(), 'Overshoot must affect 0 rows (guard)');

    // Cleanup
    self::$pdo->exec("DELETE FROM product_variants WHERE id=$variant_id");
    self::$pdo->exec("DELETE FROM products WHERE id=$prod_id");
}

public function test_icebreaker_qty_lookup_per_reward(): void
{
    $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'")->fetchAll();
    if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

    // Simulate the lookup reduce_icebreaker_stock() does for a reward pledge
    $rid = $this->insertReward(25.0);
    // Set icebreaker_qty for our test reward
    self::$pdo->prepare("UPDATE campaign_rewards SET icebreaker_qty = 3 WHERE id = ?")->execute([$rid]);

    $row = self::$pdo->prepare('SELECT icebreaker_qty FROM campaign_rewards WHERE id = ?');
    $row->execute([$rid]);
    $qty = (int)($row->fetchColumn() ?: 0);
    $this->assertSame(3, $qty);
}
```

- [ ] **Step 2: Run tests — expect failures**

```bash
php vendor/bin/phpunit --filter "test_stock_decrement_sql_reduces_by_qty|test_icebreaker_qty_lookup_per_reward" --no-coverage
```

Expected: `test_stock_decrement_sql_reduces_by_qty` passes (SQL pattern already works), `test_icebreaker_qty_lookup_per_reward` skips until migration runs — this step confirms no regressions.

- [ ] **Step 3: Add `reduce_icebreaker_stock()` to `api/campaign-payment-return.php`**

At the top of the file, after the `require_once` block (before line 15 `start_session()`), add:

```php
define('ICEBREAKER_VARIANT_ID', 8);
```

At the bottom of the file (after the closing `}` of `generate_campaign_cert`), add:

```php
function reduce_icebreaker_stock(PDO $pdo, array $pledge): void
{
    if ($pledge['pledge_type'] === 'ticket') {
        $qty = max(1, (int)($pledge['ticket_qty'] ?? 1));
    } elseif (!empty($pledge['reward_id'])) {
        $row = $pdo->prepare('SELECT icebreaker_qty FROM campaign_rewards WHERE id = ?');
        $row->execute([$pledge['reward_id']]);
        $qty = (int)($row->fetchColumn() ?: 0);
    } else {
        return; // pure donation — no Icebreakers included
    }
    if ($qty <= 0) return;

    $stmt = $pdo->prepare(
        'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    $stmt->execute([$qty, ICEBREAKER_VARIANT_ID, $qty]);
    if ($stmt->rowCount() === 0) {
        error_log("reduce_icebreaker_stock: insufficient stock for pledge {$pledge['pledge_number']}, wanted {$qty}");
    }
}
```

- [ ] **Step 4: Call `reduce_icebreaker_stock()` in `process_campaign_dsk_result()`**

Inside `process_campaign_dsk_result()`, locate the block:

```php
        if ($pledge['payment_status'] !== 'paid') {
            $pdo->prepare("UPDATE campaign_pledges SET payment_status='paid' WHERE id=?")
                ->execute([$pledge['id']]);
            $pledge['payment_status'] = 'paid';

            $is_ticket = ($pledge['pledge_type'] ?? 'donation') === 'ticket';
```

Add the call immediately after `$pledge['payment_status'] = 'paid';`:

```php
        if ($pledge['payment_status'] !== 'paid') {
            $pdo->prepare("UPDATE campaign_pledges SET payment_status='paid' WHERE id=?")
                ->execute([$pledge['id']]);
            $pledge['payment_status'] = 'paid';

            reduce_icebreaker_stock($pdo, $pledge);

            $is_ticket = ($pledge['pledge_type'] ?? 'donation') === 'ticket';
```

- [ ] **Step 5: Run full test suite**

```bash
php vendor/bin/phpunit --no-coverage
```

Expected: all existing tests pass, new tests pass.

- [ ] **Step 6: Commit**

```bash
git add api/campaign-payment-return.php tests/CampaignTest.php
git commit -m "feat: reduce Lafetki Icebreakers stock on ticket purchase and reward pledge payment"
```

---

## Task 3: Delivery method widget in campaign form

**Files:**
- Modify: `campaign/index.php`

- [ ] **Step 1: Load BoxNow partner ID before the header**

In `campaign/index.php`, near the top where `$pdo` is available (after `$pdo = get_pdo();` on line 13), add:

```php
$boxnow_partner_id = setting_resolve('boxnow_partner_id', 'BOXNOW_PARTNER_ID');
```

`setting_resolve` is defined in `includes/settings.php` (already included). This mirrors how `checkout/index.php` resolves it.

- [ ] **Step 2: Add BoxNow widget script to `$page_head_extra`**

Find where `$page_head_extra` is set (search for `$page_head_extra = '`). Append the BoxNow script and partner ID at the end of that string before the closing quote:

```php
// Add to end of $page_head_extra (before the closing quote):
<script src="/assets/js/boxnow-widget.js"></script>
<script>var _campaignBoxnowPartnerId = <?= json_encode($boxnow_partner_id) ?>;</script>
```

If `$page_head_extra` is set as a heredoc or multi-line string, append the two script lines. If it's not yet defined, add:

```php
$page_head_extra = (isset($page_head_extra) ? $page_head_extra : '') .
    '<script src="/assets/js/boxnow-widget.js"></script>' .
    '<script>var _campaignBoxnowPartnerId = ' . json_encode($boxnow_partner_id) . ';</script>';
```

- [ ] **Step 3: Replace `#deliverySection` content**

Find the block from `<!-- Delivery address — shown only when a reward is selected -->` to the closing `</div>` of `#deliverySection` (lines 391–416 in the current file). Replace the entire `<div id="deliverySection" ...>...</div>` with:

```php
          <!-- Delivery method — shown only when a reward is selected -->
          <div id="deliverySection" style="display:none;">
            <div style="background:#f0faf9;border:1px solid #b2dbd7;border-radius:6px;padding:.85rem;margin-bottom:1rem;font-size:.85rem;color:#2d6a35;">
              <?= $is_en ? 'Choose how you want to receive your reward.' : 'Изберете как да получите наградата си.' ?>
            </div>

            <!-- Courier radio -->
            <div class="form-field">
              <label style="margin-bottom:.5rem;display:block;"><?= $is_en ? 'Courier' : 'Куриер' ?></label>
              <div style="display:flex;gap:1rem;">
                <?php foreach (['speedy' => 'Speedy', 'boxnow' => 'BoxNow'] as $cv => $cl): ?>
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:600;">
                  <input type="radio" name="courier" value="<?= $cv ?>" id="campaign_courier_<?= $cv ?>"
                         onchange="campaignCourierChange(this.value)" style="cursor:pointer;">
                  <?= $cl ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Speedy: delivery type sub-choice -->
            <div id="campaignSpeedyTypes" style="display:none;" class="form-field">
              <label style="margin-bottom:.5rem;display:block;"><?= $is_en ? 'Delivery type' : 'Вид доставка' ?></label>
              <div style="display:flex;gap:1rem;">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                  <input type="radio" name="_speedy_type_ui" value="office" id="campaign_type_office"
                         onchange="campaignTypeChange('office')" style="cursor:pointer;">
                  <?= $is_en ? 'To office' : 'До офис' ?>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                  <input type="radio" name="_speedy_type_ui" value="address" id="campaign_type_address"
                         onchange="campaignTypeChange('address')" style="cursor:pointer;">
                  <?= $is_en ? 'To address' : 'До адрес' ?>
                </label>
              </div>
            </div>

            <!-- Speedy office fields -->
            <div id="campaignOfficeFields" style="display:none;">
              <div class="form-field">
                <label><?= $is_en ? 'Office name' : 'Офис' ?></label>
                <input type="text" name="courier_office_name" id="campaignOfficeName"
                       placeholder="<?= $is_en ? 'e.g. Speedy Sofia Centre' : 'напр. Speedy София Център' ?>">
              </div>
              <div class="form-field">
                <label><?= $is_en ? 'City' : 'Град' ?></label>
                <input type="text" name="courier_office_city" id="campaignOfficeCity">
              </div>
              <div class="form-field">
                <label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label>
                <input type="tel" name="delivery_phone" id="campaignOfficePhone" placeholder="+359...">
              </div>
            </div>

            <!-- Speedy address fields -->
            <div id="campaignAddressFields" style="display:none;">
              <div class="form-field">
                <label><?= $is_en ? 'Street address' : 'Адрес' ?></label>
                <input type="text" name="delivery_address" autocomplete="street-address"
                       placeholder="<?= $is_en ? 'Street, no., floor, apt.' : 'ул., №, ет., ап.' ?>">
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div class="form-field">
                  <label><?= $is_en ? 'City' : 'Град' ?></label>
                  <input type="text" name="delivery_city" autocomplete="address-level2">
                </div>
                <div class="form-field">
                  <label><?= $is_en ? 'Postcode' : 'Пощенски код' ?></label>
                  <input type="text" name="delivery_postcode" autocomplete="postal-code">
                </div>
              </div>
              <div class="form-field">
                <label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label>
                <input type="tel" name="delivery_phone_addr" autocomplete="tel" placeholder="+359...">
              </div>
            </div>

            <!-- BoxNow locker picker -->
            <div id="campaignBoxnowFields" style="display:none;">
              <div class="form-field">
                <label style="margin-bottom:.5rem;display:block;"><?= $is_en ? 'Select a BoxNow locker' : 'Изберете автомат BoxNow' ?></label>
                <button type="button" onclick="campaignOpenBoxnow()"
                        style="padding:.55rem 1.25rem;background:#6CD04E;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:600;">
                  📦 <?= $is_en ? 'Choose from map' : 'Изберете от картата' ?>
                </button>
                <div id="campaignBoxnowSelected" style="display:none;margin-top:.75rem;padding:.75rem 1rem;border-radius:6px;font-size:.875rem;background:#f0ffeb;border:2px solid #6CD04E;"></div>
              </div>
              <div class="form-field">
                <label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label>
                <input type="tel" name="delivery_phone_boxnow" id="delivery_phone_boxnow" autocomplete="tel" placeholder="+359...">
              </div>
            </div>

            <!-- Hidden fields written by JS -->
            <input type="hidden" name="courier_office_code" id="campaignOfficeCode" value="">
            <input type="hidden" name="delivery_type" id="campaignDeliveryTypeHidden" value="">
          </div>
```

- [ ] **Step 4: Update the JS `selectReward` function**

In the `<script>` block starting around line 559, find and update `selectReward`:

Current code to find:
```js
  var deliveryFields = delivery ? delivery.querySelectorAll('input') : [];
```

Replace with (remove the old `deliveryFields` line entirely and update `selectReward`):

```js
  function selectReward(card) {
    cards.forEach(function (c) { c.classList.remove('selected'); });
    if (card) {
      card.classList.add('selected');
      rewardInput.value = card.dataset.rewardId;
      amtEur.value = parseFloat(card.dataset.amount).toFixed(2);
      updateBgnHint();
      delivery.style.display = 'block';
      // mark courier radios required; field-level required set by campaignCourierChange
      document.querySelectorAll('input[name="courier"]').forEach(function(r){ r.required = true; });
    } else {
      rewardInput.value = '0';
      delivery.style.display = 'none';
      document.querySelectorAll('input[name="courier"]').forEach(function(r){ r.required = false; });
      campaignResetDelivery();
    }
  }
```

Also remove the two `deliveryFields.forEach` calls inside the old `selectReward`.

- [ ] **Step 5: Add courier/type JS handlers after the `selectReward` function**

Add immediately after the closing `}` of `selectReward`:

```js
  function campaignResetDelivery() {
    ['campaignSpeedyTypes','campaignOfficeFields','campaignAddressFields','campaignBoxnowFields'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    document.querySelectorAll('#deliverySection input[type=text],#deliverySection input[type=tel]').forEach(function(f){ f.required = false; f.value = ''; });
    document.getElementById('campaignOfficeCode').value = '';
    document.getElementById('campaignDeliveryTypeHidden').value = '';
  }

  function campaignCourierChange(courier) {
    campaignResetDelivery();
    document.querySelectorAll('input[name="courier"]').forEach(function(r){ r.required = true; });
    if (courier === 'speedy') {
      document.getElementById('campaignSpeedyTypes').style.display = '';
      document.getElementById('campaignDeliveryTypeHidden').value = '';
      document.querySelectorAll('input[name="_speedy_type_ui"]').forEach(function(r){ r.required = true; r.checked = false; });
    } else if (courier === 'boxnow') {
      document.getElementById('campaignBoxnowFields').style.display = '';
      document.getElementById('campaignDeliveryTypeHidden').value = 'locker';
      document.getElementById('delivery_phone_boxnow') && (document.getElementById('delivery_phone_boxnow').required = true);
    }
  }

  function campaignTypeChange(type) {
    document.getElementById('campaignOfficeFields').style.display  = (type === 'office')  ? '' : 'none';
    document.getElementById('campaignAddressFields').style.display = (type === 'address') ? '' : 'none';
    document.getElementById('campaignDeliveryTypeHidden').value = type;
    // Set required only on visible fields
    document.querySelectorAll('#campaignOfficeFields input').forEach(function(f){ f.required = (type === 'office'); });
    document.querySelectorAll('#campaignAddressFields input').forEach(function(f){ f.required = (type === 'address'); });
  }

  function campaignOpenBoxnow() {
    if (typeof BoxNowWidget === 'undefined' || !_campaignBoxnowPartnerId) return;
    BoxNowWidget.open(_campaignBoxnowPartnerId, function(locker) {
      var name = locker.name || locker.address || ('BoxNow #' + locker.id);
      document.getElementById('campaignOfficeCode').value = locker.id;
      document.getElementById('campaignBoxnowSelected').textContent = name;
      document.getElementById('campaignBoxnowSelected').style.display = '';
    });
  }
```

- [ ] **Step 6: Run tests (no regressions)**

```bash
php vendor/bin/phpunit --no-coverage
```

- [ ] **Step 7: Commit**

```bash
git add campaign/index.php
git commit -m "feat: add courier selection widget to campaign pledge form for reward deliveries"
```

---

## Task 4: Validate and save delivery fields in checkout

**Files:**
- Modify: `campaign/checkout.php`
- Modify: `tests/CampaignTest.php`

- [ ] **Step 1: Write failing tests for delivery column storage**

Add to `tests/CampaignTest.php`:

```php
public function test_pledge_with_reward_stores_delivery_courier(): void
{
    $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_pledges LIKE 'delivery_courier'")->fetchAll();
    if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

    $rid = $this->insertReward(50.0);
    self::$pdo->prepare(
        "INSERT INTO campaign_pledges
         (pledge_number,pledge_type,lang,name,email,amount_eur,ticket_qty,reward_id,
          delivery_address,delivery_courier,delivery_type,office_code,office_name,office_city,payment_status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')"
    )->execute([
        'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2))),
        'donation', 'bg', 'Тест', 'test@example.com',
        50.0, 1, $rid,
        null,            // delivery_address (null for office)
        'speedy',        // delivery_courier
        'office',        // delivery_type
        'SOF001',        // office_code
        'Speedy София',  // office_name
        'София',         // office_city
    ]);
    $id = (int)self::$pdo->lastInsertId();
    self::$pledge_ids[] = $id;

    $row = self::$pdo->query("SELECT * FROM campaign_pledges WHERE id=$id")->fetch();
    $this->assertSame('speedy', $row['delivery_courier']);
    $this->assertSame('office', $row['delivery_type']);
    $this->assertSame('SOF001', $row['office_code']);
    $this->assertSame('Speedy София', $row['office_name']);
    $this->assertSame('София', $row['office_city']);
}
```

- [ ] **Step 2: Run test — expect pass (column exists, INSERT works)**

```bash
php vendor/bin/phpunit --filter "test_pledge_with_reward_stores_delivery_courier" --no-coverage
```

Expected: pass (migration already applied in Task 1).

- [ ] **Step 3: Replace the delivery validation block in `campaign/checkout.php`**

Find and replace the block from `// Delivery address — required when a donation reward is selected` to the closing `}` of that block (lines 74–93):

**Old block (remove entirely):**
```php
// Delivery address — required when a donation reward is selected; tickets never need it
$delivery_address = null;
if (!$is_ticket && $reward_id > 0) {
    $del_addr     = trim($_POST['delivery_address']  ?? '');
    $del_city     = trim($_POST['delivery_city']     ?? '');
    $del_postcode = trim($_POST['delivery_postcode'] ?? '');
    $del_phone    = trim($_POST['delivery_phone']    ?? '');

    if ($del_addr === '')     $errors[] = 'Моля, въведете адрес за доставка.';
    if ($del_city === '')     $errors[] = 'Моля, въведете град.';

    if (empty($errors)) {
        $delivery_address = json_encode([
            'address'  => $del_addr,
            'city'     => $del_city,
            'postcode' => $del_postcode,
            'phone'    => $del_phone,
        ]);
    }
}
```

**New block (replace with):**
```php
// Delivery — required when a reward is selected; tickets never need delivery
$delivery_address  = null;
$delivery_courier  = null;
$delivery_type_val = null;
$office_code_val   = null;
$office_name_val   = null;
$office_city_val   = null;

if (!$is_ticket && $reward_id > 0) {
    $valid_types = ['speedy' => ['office', 'address'], 'boxnow' => ['locker']];

    $delivery_courier  = trim($_POST['courier'] ?? '');
    $delivery_type_val = trim($_POST['delivery_type'] ?? '');

    if (!array_key_exists($delivery_courier, $valid_types)) {
        $errors[] = 'Моля, изберете куриер.';
    } elseif (!in_array($delivery_type_val, $valid_types[$delivery_courier], true)) {
        $errors[] = 'Моля, изберете вид доставка.';
    }

    if (empty($errors)) {
        if ($delivery_courier === 'speedy' && $delivery_type_val === 'address') {
            $del_addr     = trim($_POST['delivery_address'] ?? '');
            $del_city     = trim($_POST['delivery_city']    ?? '');
            $del_postcode = trim($_POST['delivery_postcode'] ?? '');
            $del_phone    = trim($_POST['delivery_phone']   ?? '');
            if ($del_addr === '') $errors[] = 'Моля, въведете адрес за доставка.';
            if ($del_city === '') $errors[] = 'Моля, въведете град.';
            if (empty($errors)) {
                $delivery_address = json_encode([
                    'address'  => $del_addr,
                    'city'     => $del_city,
                    'postcode' => $del_postcode,
                    'phone'    => $del_phone,
                ]);
            }
        } elseif ($delivery_courier === 'speedy' && $delivery_type_val === 'office') {
            $office_name_val = trim($_POST['courier_office_name'] ?? '');
            $office_city_val = trim($_POST['courier_office_city'] ?? '');
            $office_code_val = trim($_POST['courier_office_code'] ?? '');
            $del_phone       = trim($_POST['delivery_phone']      ?? '');
            if ($office_name_val === '') $errors[] = 'Моля, въведете офис за доставка.';
            if ($office_city_val === '') $errors[] = 'Моля, въведете град на офиса.';
            if (empty($errors)) {
                $delivery_address = json_encode(['phone' => $del_phone]);
            }
        } elseif ($delivery_courier === 'boxnow') {
            $office_code_val = trim($_POST['courier_office_code'] ?? '');
            $office_name_val = trim($_POST['courier_office_name'] ?? '');
            $del_phone       = trim($_POST['delivery_phone_boxnow'] ?? '');
            if ($office_code_val === '') $errors[] = 'Моля, изберете автомат BoxNow.';
            if (empty($errors)) {
                $delivery_address = json_encode(['phone' => $del_phone]);
            }
        }
    }
}
```

- [ ] **Step 4: Expand the `INSERT INTO campaign_pledges` to include new columns**

Find the `INSERT INTO campaign_pledges` statement (lines 110–124). Replace it:

**Old:**
```php
    $pdo->prepare("
        INSERT INTO campaign_pledges
            (pledge_number, pledge_type, lang, name, email, amount_eur, ticket_qty, reward_id, delivery_address, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([
        $pledge_number,
        $pledge_type,
        $pledge_lang,
        $name,
        $email,
        $amount_eur,
        $ticket_qty,
        (!$is_ticket && $reward_id > 0) ? $reward_id : null,
        $delivery_address,
    ]);
```

**New:**
```php
    $pdo->prepare("
        INSERT INTO campaign_pledges
            (pledge_number, pledge_type, lang, name, email, amount_eur, ticket_qty, reward_id,
             delivery_address, delivery_courier, delivery_type, office_code, office_name, office_city,
             payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([
        $pledge_number,
        $pledge_type,
        $pledge_lang,
        $name,
        $email,
        $amount_eur,
        $ticket_qty,
        (!$is_ticket && $reward_id > 0) ? $reward_id : null,
        $delivery_address,
        $delivery_courier,
        $delivery_type_val,
        $office_code_val,
        $office_name_val,
        $office_city_val,
    ]);
```

- [ ] **Step 5: Run full test suite**

```bash
php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add campaign/checkout.php tests/CampaignTest.php
git commit -m "feat: validate and save courier delivery method for campaign reward pledges"
```

---

## Verification

After all tasks complete:

1. **Migration applied** — run `php vendor/bin/phpunit --no-coverage` → all green.

2. **Stock reduction** — on production, check `product_variants` where `id=8` stock before and after a test ticket purchase (use the admin campaign-backers view or SSH + MySQL query).

3. **Delivery widget** — open `/campaign/` locally, click a reward card, verify the courier section appears; select Speedy → see type choices; select office → see office name/city fields; select BoxNow → see widget button.

4. **Checkout save** — complete a test pledge with a reward and a Speedy office selection; verify `delivery_courier='speedy'` and `office_name` is saved in `campaign_pledges`.

5. **No regression** — pure donations (no reward) and ticket purchases go through existing flow unchanged.
