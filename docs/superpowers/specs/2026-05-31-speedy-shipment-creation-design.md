# Speedy Shipment Creation — Design

**Date:** 2026-05-31
**Status:** Approved

## Problem

When an order is placed with Speedy as the courier, the admin has no way to create a Speedy shipment (товарителница) from within the admin panel. `SpeedyCourier::createShipment()` already exists but is never called. There is also no label-download or cancel flow for Speedy, even though BoxNow has all three.

## Goal

Mirror the BoxNow shipment flow for Speedy orders: the admin can create a shipment, download the label PDF, and cancel — all without leaving the admin panel.

## Constraints

- No COD — all orders are card payments only.
- Non-technical admin audience: UI must be click-based, no code editing.
- Inline styles required for any new UI card (CSS may be stale-cached on server).
- All migrations must be idempotent (`IF NOT EXISTS`).

---

## Section 1: Data Layer

**New migration:** `migrations/022_speedy_shipment_id.sql`

```sql
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS speedy_shipment_id VARCHAR(100) DEFAULT NULL;
```

`speedy_shipment_id` stores the Speedy shipment number returned by the API.
`tracking_number` (already on the table) is also populated with the same value so the existing "shipped" email flow works without changes.

---

## Section 2: `SpeedyCourier.php` Additions

Two new public methods. No changes to existing methods.

### `cancelShipment(string $shipmentId): void`

Calls `DELETE /shipment/{id}` on the Speedy REST API.
Throws `RuntimeException` on API or network error.

### `getLabel(string $shipmentId): string`

Calls `POST /print/` with:
- `parcels: [{ id: $shipmentId }]`
- `paperSize: "A6_H"`
- `format: "pdf"`

Speedy returns a JSON envelope with a base64-encoded PDF under `files[0].contents`.
This method decodes and returns the raw PDF binary, ready to stream to the browser.

---

## Section 3: `admin/order-view.php` — UI Card + POST Handlers

A new sidebar card renders when `$order['courier'] === 'speedy' && $order['type'] === 'physical'`.

### State A — No shipment yet

Shows:
- Editable weight field (label: "Тегло (кг)", default `1.0`)
- Editable parcel count field (label: "Брой пакети", default `1`)
- "Създай товарителница" button → `POST action=create_speedy_label`

**`create_speedy_label` handler:**
1. Reads weight and pack_count from POST (validated as float/int).
2. Builds shipment payload from order: `receiver_name`, `receiver_phone`, `receiver_city`, `receiver_address` or `receiver_office` (depending on `delivery_type`), `delivery_type` mapped from order's `delivery_type` field (`office` → `office`, `address` → `door`).
3. Calls `SpeedyCourier::createShipment()`.
4. Stores `speedy_shipment_id` and `tracking_number` (same value) in DB.
5. Re-fetches order; sets `$success`.

### State B — Shipment exists

Shows:
- Shipment number in `<code>` block.
- "Печат на етикет (PDF)" → `GET /admin/speedy-label.php?order_id={id}` (opens in new tab).
- "Анулирай пратката" → `POST action=cancel_speedy_label` (uses `_adminConfirm` modal).

**`cancel_speedy_label` handler:**
1. Reads `speedy_shipment_id` from order.
2. Calls `SpeedyCourier::cancelShipment($shipmentId)`.
3. Clears `speedy_shipment_id` (set to NULL) in DB.
4. Re-fetches order; sets `$success`.

---

## Section 4: `admin/speedy-label.php`

New endpoint, mirrors `admin/boxnow-label.php`.

- `admin_require_login()` at top.
- Reads `order_id` from GET (cast to int), validates order exists and `courier === 'speedy'` and `speedy_shipment_id` is set.
- Calls `(new SpeedyCourier())->getLabel($shipmentId)`.
- Streams result with headers:
  - `Content-Type: application/pdf`
  - `Content-Disposition: inline; filename="speedy-{$shipmentId}.pdf"`
  - `Content-Length: strlen($pdf)`

---

## Section 5: Tests

Two new test methods added to `tests/Couriers/SpeedyCourierTest.php` in the existing `speedy` + `integration` groups:

- **`testCreateShipmentReturnsShipmentNumber()`** — creates a real test shipment with minimal valid data, asserts `shipment_number` is a non-empty string. Stores the shipment number for the cancel test.
- **`testCancelShipmentDoesNotThrow()`** — cancels the shipment created above, asserts no exception is thrown.

`getLabel()` is verified manually (binary PDF output is impractical to assert in CI).

---

## Files Changed

| File | Change |
|------|--------|
| `migrations/022_speedy_shipment_id.sql` | New — adds `speedy_shipment_id` column |
| `includes/couriers/SpeedyCourier.php` | Add `cancelShipment()` and `getLabel()` |
| `admin/order-view.php` | Add Speedy card UI + 2 POST handlers |
| `admin/speedy-label.php` | New — streams Speedy label PDF |
| `tests/Couriers/SpeedyCourierTest.php` | Add create + cancel integration tests |
