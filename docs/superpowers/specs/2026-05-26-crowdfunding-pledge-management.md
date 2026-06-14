# Spec: Crowdfunding Pledge Management

**Date:** 2026-05-26
**Status:** Approved

---

## Overview

Campaign pledges (crowdfunding donations) need full document and refund management — mirroring what already exists for shop orders. Specifically: donation certs auto-generated at payment (already implemented, needs to write into `documents` table), a dedicated pledge detail admin page, and a DSK Bank refund action.

---

## Requirements

- Donation cert: auto-generated when pledge payment succeeds (existing behaviour), but cert must be recorded in the `documents` table so signing works.
- No receipt needed for campaign pledges.
- Refund: same logic as order cancellation — DSK Bank refund + status update + email to donor.
- UI: dedicated `admin/pledge-view.php` detail page, mirroring `order-view.php`.

---

## Data Layer

### orders.type ENUM extension

Add `'pledge'` to `orders.type` ENUM. Done inline and idempotently at the top of `campaign-payment-return.php`:

```php
try { $pdo->exec("ALTER TABLE orders MODIFY COLUMN type ENUM('physical','donation','ticket','pledge') NOT NULL"); } catch (Throwable) {}
```

### Synthetic orders row per paid pledge

When a pledge payment succeeds (in `campaign-payment-return.php`), after the cert PDF is written:

1. Insert a row into `orders`:
   - `order_number` = `pledge_number`
   - `type` = `'pledge'`
   - `status` = `'confirmed'`
   - `customer_name` = `pledge.name`
   - `customer_email` = `pledge.email`
   - `items` = `[{"type":"donation","amount_eur":<amount>,"recipient":"foundation"}]`
   - `subtotal_eur` = `pledge.amount_eur`
   - `shipping_eur` = 0
   - `total_eur` = `pledge.amount_eur`
   - `payment_method` = `'card'`
   - `payment_status` = `'paid'`
   - `invoice_data` = `{"donor_type":"individual"}`
   - `created_at` = `pledge.created_at`

2. Insert into `documents`:
   - `order_id` = ID of the new orders row
   - `type` = `'donation_cert'`
   - `number` = cert sequence number (already computed)
   - `formatted_number` = formatted cert number (already computed)
   - `file_path` = relative path to cert PDF

3. Continue updating `campaign_pledges.cert_number` and `campaign_pledges.cert_path` as before (backwards compat).

### Linking pledge ↔ orders

Pledge and its orders row are linked by `campaign_pledges.pledge_number = orders.order_number`. No new column needed.

### Legacy pledges

Existing paid pledges have `cert_path`/`cert_number` set but no linked orders row and no `documents` entry. `pledge-view.php` detects this case and shows a "Регенерирай сертификат" button. Clicking it:

1. Creates the missing orders row (as above, but using stored `pledge.created_at`).
2. Reads the existing cert file from `cert_path` if it exists, otherwise generates a new cert PDF.
3. Inserts into `documents`.

---

## New file: `admin/pledge-view.php`

Requires `admin_require_shop()`. Loaded via `?id={pledge_id}`.

### Data loading

```
campaign_pledges WHERE id = ?         → $pledge
orders WHERE order_number = pledge_number AND type = 'pledge'  → $order (may be null for legacy)
documents WHERE order_id = order.id AND type = 'donation_cert' → $cert_doc (may be null)
campaign_rewards WHERE id = pledge.reward_id                   → $reward (may be null)
```

### Layout sections

**Header**
- Pledge number (monospace), donor name, email
- Amount: EUR + BGN equivalent
- Date created
- Reward tier title (if any)
- Delivery address (if any) — formatted from JSON

**Payment status badge**
- `paid` → green "Платено"
- `reversed` → grey "Върнато"
- `pending` → amber "Чакащо"
- `failed` → red "Неуспешно"

**Documents section**

If `$cert_doc` exists (cert is in `documents` table):
- Show: "Сертификат за дарение № {formatted_number}" · date
- Download button → `/admin/download-document.php?id={cert_doc.id}`
- Sign button (if not yet signed and user can sign) → POST to `/admin/sign-document.php`
- Signed badge (if signed) — same as order-view.php

If `$cert_doc` is null but `$pledge['cert_path']` is set (legacy):
- Show download link to `cert_path` directly
- Show "Регенерирай сертификат" button → POST action `regenerate_cert`

If neither:
- Show "Сертификатът ще бъде генериран автоматично при плащане."

**Refund section**

Visible only when `$pledge['payment_status'] === 'paid'` and `!empty($pledge['dsk_order_id'])`.

- Button: "Върни плащането" — opens `_adminConfirm` modal
- On confirm → POST with `action=refund`

### POST handlers

**`action = refund`**

1. Verify: `payment_status === 'paid'` and `dsk_order_id` not empty.
2. `require DSKBankPayment.php`; call `(new DSKBankPayment())->refund($pledge['dsk_order_id'], (float)$pledge['amount_eur'])`.
3. On success:
   - `UPDATE campaign_pledges SET payment_status='reversed' WHERE id=?`
   - `UPDATE orders SET payment_status='refunded' WHERE order_number=? AND type='pledge'`
   - Send `pledge-reversed-customer` email to `$pledge['email']`
   - Redirect to `pledge-view.php?id={id}&refund_ok=1`
4. On DSK failure: log error, show error inline, leave statuses unchanged.

**`action = regenerate_cert`**

1. Create orders row if missing (as per Data Layer above).
2. If `pledge.cert_path` file exists on disk:
   - Use `pledge.cert_number` as the document number; format it (5-digit zero-padded).
   - Insert into `documents` with `file_path = pledge.cert_path` and the existing number — no new sequence increment.
3. If file does not exist:
   - Atomically increment `document_sequences` for `donation_cert` to get a new number.
   - Generate new cert PDF using `DonationCertGenerator`.
   - Write file; update `pledge.cert_number` and `pledge.cert_path`.
   - Insert into `documents`.
4. Redirect back with success message.

---

## New email template: `includes/emails/pledge-reversed-customer.php`

Simple transactional email:

- Subject: "Върнато плащане — {pledge_number}"
- Body: donor name, amount EUR, pledge number, message that the amount has been returned to their card within the standard bank processing time.

Follows existing email template pattern (`render_email()` / `email_tpl_get()`).

---

## Changes to existing files

### `campaign-payment-return.php`

- Add inline idempotent ENUM migration for `orders.type` at top.
- In `generate_campaign_cert()` (or equivalent): after writing cert PDF and incrementing sequence, insert orders row then insert `documents` row.
- Keep existing `UPDATE campaign_pledges SET cert_number, cert_path` for backwards compat.
- Wrap orders insert + documents insert in a try/catch — failure logs an error but does not abort payment confirmation (cert_path fallback still works).

### `campaign-backers.php`

- Donations tab: add "Виж →" link per row → `/admin/pledge-view.php?id={pledge.id}`.

### `sign-document.php`

- After signing, currently redirects to `order-view.php?id={order_id}`.
- Fetch the orders row type. If `type = 'pledge'`, look up `campaign_pledges WHERE pledge_number = orders.order_number`, redirect to `pledge-view.php?id={pledge.id}`.
- Otherwise redirect to `order-view.php` as before.

---

## Security checklist

- `admin_require_shop()` on `pledge-view.php`.
- `csrf_verify()` on all POST handlers.
- All `$_POST` values: `(int)` for IDs, `trim()` + whitelist for enums.
- No raw user data echoed — use `h()`.
- Parameterised queries throughout.
- Refund action verifies pledge ownership (loaded by ID, not passed as POST param).

---

## Testing

- Paying a new test pledge → orders row created, cert in `documents`, cert_path still set on pledge.
- Opening `pledge-view.php` for new pledge → cert shows with download + sign.
- Signing cert → redirects back to `pledge-view.php` (not `order-view.php`).
- Refund action → DSK call made, both statuses updated, email sent.
- Opening `pledge-view.php` for legacy pledge (cert_path set, no orders row) → legacy download shown, regenerate button works.
- `campaign-backers.php` donations tab → "Виж" link opens correct pledge.
