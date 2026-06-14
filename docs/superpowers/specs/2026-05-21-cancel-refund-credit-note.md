# Cancel Order: Refund + Credit Document + Email

**Date:** 2026-05-21
**Status:** Approved

---

## Overview

When an admin cancels an order:
1. A confirmation modal warns that the payment will be automatically refunded via DSK Bank.
2. On confirm, the order is cancelled and the refund is initiated via `DSKBankPayment::refund()`.
3. A separate "Издай кредитно известие" button (visible only after cancellation) lets the admin generate the credit document and send it to the customer by email.

All orders are paid by card (DSK Bank). COD/bank transfer do not exist in practice.

---

## 1. Cancellation with refund warning

### Trigger

In `admin/order-view.php`, the status-update form has a `<select name="status">`. When the admin changes it to `cancelled`, a JS `change` listener intercepts the form submit and shows `_adminConfirm` with:

> "Поръчката ще бъде отменена и сумата от **X.XX EUR** ще бъде върната автоматично на картата на клиента. Продължи?"

where X.XX is `$order['total_eur']`. The confirm is shown **only** when:
- The new value is `cancelled`, AND
- The current `$order['status']` is not already `cancelled`, AND
- `$order['payment_status'] === 'paid'`

If `payment_status` is not `paid` (e.g. pending, already refunded), the form submits without a special modal (existing generic confirm logic handles it).

### POST handler changes (`admin/order-view.php`)

After the existing `UPDATE orders SET status=...` line, when `$new_status === 'cancelled'` and `$order['payment_status'] === 'paid'`:

```php
// Initiate DSK Bank refund
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
$dsk = new DSKBankPayment();
try {
    $dsk->refund($order['dsk_order_id']); // full refund (null = full)
    $pdo->prepare('UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['refunded', $id]);
    $refund_ok = true;
} catch (Throwable $e) {
    error_log('Refund failed for order ' . $id . ': ' . $e->getMessage());
    $refund_ok = false;
}
```

If `$refund_ok === false`, redirect with a flash warning:
> "Поръчката е отменена, но автоматичното връщане на сумата не успя. Моля, обработете го ръчно в DSK Bank."

If `$refund_ok === true`, redirect with the normal success flash.

---

## 2. Credit document button

### When it appears

In the documents panel of `admin/order-view.php`, show a single **"Издай кредитно известие"** button when ALL of the following are true:
- `$order['status'] === 'cancelled'`
- A `credit_note` OR `storno_receipt` record does **not** yet exist in the `documents` table for this order
- An `invoice` OR `receipt` record **does** exist in the `documents` table for this order

### What it does

The button POSTs to `admin/generate-document.php` with:
- `order_id` = order ID
- `doc_type` = `credit_note` if source document is `invoice`, `storno_receipt` if source is `receipt`

The system resolves the correct `doc_type` server-side (not client-side) by querying which source document exists.

Button label is always "Издай кредитно известие" regardless of the underlying type — the distinction is invisible to the user.

---

## 3. Database changes

### `documents.type` ENUM

```sql
ALTER TABLE documents
    MODIFY COLUMN type ENUM('invoice','receipt','donation_cert','credit_note','storno_receipt') NOT NULL;
```

### `document_sequences` — no new rows

- `credit_note` shares the `invoice` sequence (same numbering series per Bulgarian accounting rules)
- `storno_receipt` shares the `receipt` sequence

`generate-document.php` maps each doc type to its sequence:
```php
$sequence_type = [
    'credit_note'     => 'invoice',
    'storno_receipt'  => 'receipt',
][$doc_type] ?? $doc_type;
```

### Storage directories

```
documents/credit_notes/{year}/
documents/storno_receipts/{year}/
```

Both covered by the existing `.htaccess` protection on `documents/`.

---

## 4. `generate-document.php` changes

### New types allowed

Add `credit_note` and `storno_receipt` to the allowed types check:
```php
if (!$order_id || !in_array($doc_type, ['invoice','receipt','donation_cert','credit_note','storno_receipt'], true)) {
```

### Sequence lookup uses mapped type

Replace direct `$doc_type` in the sequence query with `$sequence_type` (mapped above).

### New generator classes and directories

```php
$generators = [
    'invoice'        => 'InvoiceGenerator',
    'receipt'        => 'ReceiptGenerator',
    'donation_cert'  => 'DonationCertGenerator',
    'credit_note'    => 'CreditNoteGenerator',
    'storno_receipt' => 'StornoReceiptGenerator',
];

$type_dirs = [
    'invoice'        => 'invoices',
    'receipt'        => 'receipts',
    'donation_cert'  => 'donation_certs',
    'credit_note'    => 'credit_notes',
    'storno_receipt' => 'storno_receipts',
];
```

### Zero-padding

Credit note and storno receipt use 10-digit padding (same as invoice and receipt):
```php
$pad = in_array($doc_type, ['donation_cert']) ? 5 : 10;
```

### After PDF generation — send email

After the DB upsert, when `$doc_type` is `credit_note` or `storno_receipt`:

```php
if (in_array($doc_type, ['credit_note', 'storno_receipt'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
    $lang = $order['lang'] ?? 'bg';
    $tpl  = email_tpl_get('credit-note-customer', $lang, [
        'customer_name' => $order['customer_name'],
        'order_number'  => $order['order_number'],
        'doc_number'    => $doc_formatted,
    ]);
    $doc_label = ($doc_type === 'credit_note') ? 'кредитно известие' : 'сторно бележка';
    $body = render_email('credit-note-customer', [
        'order'     => $order,
        'tpl'       => $tpl,
        'doc_label' => $doc_label,
    ]);
    send_mail(
        $order['customer_email'],
        $tpl['subject'],
        $body,
        '',
        [['path' => $filepath, 'name' => $filename]]
    );
}
```

---

## 5. PDF generators

### `CreditNoteGenerator` (`includes/documents/CreditNoteGenerator.php`)

Extends `DocumentGenerator`. Layout mirrors `InvoiceGenerator` with these differences:

- Title: **"Кредитно Известие"** (large, centred, same style as invoice title block)
- Sub-header line below the document number: **"Към Фактура №{original_invoice_formatted_number} / {original_invoice_date}"**
  - Original invoice data is passed in via the `$document` array (see below)
- Recipient block: foundation is the **issuer** (left), customer is the **recipient** (right) — same as invoice
- Items table: same columns as invoice (name, unit, qty, unit price, total) — same items, same quantities, same prices (full reversal)
- Totals: subtotal, VAT (0% — foundation is not VAT-registered), total
- Footer: foundation bank details (IBAN, BIC)

### `StornoReceiptGenerator` (`includes/documents/StornoReceiptGenerator.php`)

Extends `DocumentGenerator`. Layout mirrors `ReceiptGenerator` with:

- Title: **"Сторно Бележка"**
- Sub-header: **"Към Бележка №{original_receipt_formatted_number} / {original_receipt_date}"**
- Otherwise identical to receipt layout

### Passing original document data

In `generate-document.php`, when `$doc_type` is `credit_note` or `storno_receipt`, look up the source document before generating:

```php
if (in_array($doc_type, ['credit_note', 'storno_receipt'])) {
    $source_type = ($doc_type === 'credit_note') ? 'invoice' : 'receipt';
    $src_stmt = $pdo->prepare('SELECT * FROM documents WHERE order_id = ? AND type = ?');
    $src_stmt->execute([$order_id, $source_type]);
    $source_doc = $src_stmt->fetch();
    // $source_doc['formatted_number'] and $source_doc['generated_at'] passed into $document array
    $document['source_formatted_number'] = $source_doc['formatted_number'];
    $document['source_date']             = substr($source_doc['generated_at'], 0, 10);
}
```

---

## 6. Email template

### `includes/emails/credit-note-customer.php`

PHP email template file (same pattern as `order-shipped-customer.php`):

```php
<?php
// Variables: $order (array), $tpl (rendered subject/intro/outro), $doc_label (string)
echo $tpl['intro'];
?>
<div class="box">
  <strong>Поръчка:</strong> <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Документ:</strong> прикачен PDF
</div>
<?php echo $tpl['outro']; ?>
<p style="color:#6b6560;font-size:14px;">
  При въпроси: <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
```

### Default content in `includes/email-templates.php`

Add to `_email_tpl_defaults()`:

```php
'credit-note-customer' => [
    'subject_bg' => 'Кредитен документ към поръчка {{order_number}}',
    'intro_bg'   => '<h2>Кредитен документ</h2>'
                  . '<p>Здравейте, {{customer_name}},</p>'
                  . '<p>Вашата поръчка <strong>{{order_number}}</strong> е отменена. '
                  . 'Намирате кредитния документ (№{{doc_number}}) като прикачен PDF файл.</p>',
    'outro_bg'   => '<p>Сумата ще бъде върната по картата, с която е извършено плащането, в рамките на 3–5 работни дни.</p>',
    'subject_en' => 'Credit document for order {{order_number}}',
    'intro_en'   => '<h2>Credit document</h2>'
                  . '<p>Hello {{customer_name}},</p>'
                  . '<p>Your order <strong>{{order_number}}</strong> has been cancelled. '
                  . 'Please find the credit document (№{{doc_number}}) attached as a PDF.</p>',
    'outro_en'   => '<p>The amount will be returned to your card within 3–5 working days.</p>',
],
```

### Entry in `admin/email-templates.php`

Add to the `$templates` array:

```php
'credit-note-customer' => [
    'label' => 'Кредитен документ (отменена поръчка)',
    'vars'  => ['{{customer_name}}', '{{order_number}}', '{{doc_number}}'],
],
```

---

## 7. Migration

New migration file `migrations/020_credit_note.sql`:

```sql
-- Extend documents.type enum with credit_note and storno_receipt
ALTER TABLE documents
    MODIFY COLUMN type ENUM('invoice','receipt','donation_cert','credit_note','storno_receipt') NOT NULL;
```

No new `document_sequences` rows needed (credit_note uses invoice sequence, storno_receipt uses receipt sequence).

---

## Files changed / created

| File | Action |
|------|--------|
| `migrations/020_credit_note.sql` | Create |
| `includes/documents/CreditNoteGenerator.php` | Create |
| `includes/documents/StornoReceiptGenerator.php` | Create |
| `includes/emails/credit-note-customer.php` | Create |
| `includes/email-templates.php` | Modify — add default template |
| `admin/email-templates.php` | Modify — add template entry |
| `admin/generate-document.php` | Modify — new types, sequence mapping, email send |
| `admin/order-view.php` | Modify — refund in POST handler, JS modal, credit note button |

---

## Out of scope

- Partial refunds — always full refund
- Cancelling already-cancelled orders — no-op (existing guard)
- English PDF content — generators produce Bulgarian text only (same as invoice/receipt)
