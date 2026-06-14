# Lafetki Icebreakers Stock Reduction + Campaign Delivery Method — Design Spec

**Date:** 2026-05-29  
**Status:** Approved

---

## Goal

Two related features for the Lafetki campaign:

1. **Stock reduction** — when a ticket or a reward pledge is paid, automatically decrement the Lafetki Icebreakers product variant stock by the correct quantity.
2. **Delivery method selection** — when a pledger picks a reward, they must select a courier and provide delivery details before paying. Pure donations (no reward) keep the existing flow.

---

## Context

- **Lafetki Icebreakers** = `product_variants.id = 8` (`product_id = 18`, label_en "Icebreakers"), currently 83 units in stock on production.
- Payment confirmation happens in `api/campaign-payment-return.php` → `process_campaign_dsk_result()`.
- Stock decrement pattern from the shop: `UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?` (guard prevents negative stock).
- The campaign form (`campaign/index.php`) already has a `#deliverySection` that appears when a reward is selected; it currently shows plain address fields.
- Shipping cost is **not** added to the pledge total — it comes out of campaign funds. Pledger pays exactly the reward tier amount.

---

## Feature 1 — Stock Reduction

### Rules

| Pledge type | Quantity deducted |
|---|---|
| Ticket | `ticket_qty` packs (1 pack per ticket) |
| Pledge — Benefactor (reward id 1, €25) | 1 pack |
| Pledge — Goodwill (reward id 2, €50) | 2 packs |
| Pledge — Laughing Man (reward id 3, €100) | 4 packs |
| Pledge — Miracle Worker (reward id 4, €250) | 5 packs |
| Donation without reward | No change |

If stock is insufficient at decrement time, log the discrepancy but do not block payment confirmation (payment is already accepted).

### DB migration

```sql
ALTER TABLE campaign_rewards
  ADD COLUMN IF NOT EXISTS icebreaker_qty TINYINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE campaign_rewards SET icebreaker_qty = 1 WHERE id = 1;
UPDATE campaign_rewards SET icebreaker_qty = 2 WHERE id = 2;
UPDATE campaign_rewards SET icebreaker_qty = 4 WHERE id = 3;
UPDATE campaign_rewards SET icebreaker_qty = 5 WHERE id = 4;
```

### Code change — `api/campaign-payment-return.php`

Add a `reduce_icebreaker_stock(PDO $pdo, array $pledge): void` function.  
Call it inside `process_campaign_dsk_result()`, inside the `if ($pledge['payment_status'] !== 'paid')` block, before document generation.

```
const ICEBREAKER_VARIANT_ID = 8;

function reduce_icebreaker_stock(PDO $pdo, array $pledge): void {
    if ($pledge['pledge_type'] === 'ticket') {
        $qty = max(1, (int)($pledge['ticket_qty'] ?? 1));
    } elseif ($pledge['reward_id']) {
        $row = $pdo->prepare('SELECT icebreaker_qty FROM campaign_rewards WHERE id = ?');
        $row->execute([$pledge['reward_id']]);
        $qty = (int)($row->fetchColumn() ?: 0);
    } else {
        return;
    }
    if ($qty <= 0) return;

    $affected = $pdo->prepare(
        'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    $affected->execute([$qty, ICEBREAKER_VARIANT_ID, $qty]);
    if ($affected->rowCount() === 0) {
        error_log("reduce_icebreaker_stock: insufficient stock for pledge {$pledge['pledge_number']}, wanted {$qty}");
    }
}
```

---

## Feature 2 — Delivery Method Selection

### DB migration (idempotent)

New columns on `campaign_pledges`:

```sql
ALTER TABLE campaign_pledges
  ADD COLUMN IF NOT EXISTS delivery_courier VARCHAR(50)  NULL AFTER delivery_address,
  ADD COLUMN IF NOT EXISTS delivery_type    VARCHAR(50)  NULL AFTER delivery_courier,
  ADD COLUMN IF NOT EXISTS office_code      VARCHAR(100) NULL AFTER delivery_type,
  ADD COLUMN IF NOT EXISTS office_name      VARCHAR(200) NULL AFTER office_code,
  ADD COLUMN IF NOT EXISTS office_city      VARCHAR(100) NULL AFTER office_name;
```

The existing `delivery_address` JSON field (`{address, city, postcode, phone}`) is kept for address-delivery pledges.

### Campaign form — `campaign/index.php`

Replace the content of `#deliverySection` with a two-level courier widget:

**Level 1 — Courier radio:**
- Speedy
- BoxNow

**Level 2 — Delivery type (shown under each courier):**
- Speedy: "До офис" / "До адрес"
- BoxNow: office/locker only (no sub-choice, auto-selected)

**Fields per selection:**

| Selection | Fields shown |
|---|---|
| Speedy → office | Office name (text), office city (text), phone |
| Speedy → address | Street address, city, postcode, phone |
| BoxNow → locker | BoxNow widget button (same as shop), hidden code/name fields |

Hidden inputs: `delivery_courier`, `delivery_type`, `courier_office_code`, `courier_office_name`, `courier_office_city`.

JS: when a reward card is selected, show `#deliverySection` and mark the courier radio fields as `required`. When deselected, hide and un-require.

### Campaign checkout — `campaign/checkout.php`

When `reward_id > 0`, validate and save:

```
$courier  = in_array($_POST['courier'] ?? '', ['speedy','boxnow']) ? $_POST['courier'] : '';
$del_type = /* whitelist per courier */ ...;
$office_code = trim($_POST['courier_office_code'] ?? '');
$office_name = trim($_POST['courier_office_name'] ?? '');
$office_city = trim($_POST['courier_office_city'] ?? '');
```

Validation rules (same as shop):
- Courier must be 'speedy' or 'boxnow'.
- Delivery type must be valid for the chosen courier.
- For address delivery: `delivery_address` and `delivery_city` required.
- For office: `office_name` and `office_city` required (or office_code if BoxNow widget was used).

Save to new columns on INSERT of `campaign_pledges`.

---

## Files Changed

| File | Change |
|---|---|
| `migrations/021_lafetki_stock_and_delivery.sql` | Two idempotent ALTER TABLE statements: icebreaker_qty on rewards, delivery cols on pledges |
| `api/campaign-payment-return.php` | Add `reduce_icebreaker_stock()` + call it on payment confirmed |
| `campaign/index.php` | Replace `#deliverySection` with full courier widget |
| `campaign/checkout.php` | Validate + save courier/office fields for reward pledges |
| `tests/CampaignTest.php` | Tests for stock decrement logic and delivery field validation |

---

## Edge Cases

- **Duplicate payment callback:** `process_campaign_dsk_result` already guards with `if ($pledge['payment_status'] !== 'paid')` — stock is decremented exactly once.
- **Insufficient stock:** Log and continue; the pledge is already paid and must be fulfilled manually.
- **Reward without delivery:** Not possible — reward selection always triggers the delivery section (required fields).
- **Ticket + reward:** Not possible — tickets and rewards are mutually exclusive pledge types.
