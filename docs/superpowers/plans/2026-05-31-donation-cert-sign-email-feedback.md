# Donation Cert Sign → Auto-Email + Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an admin signs a donation certificate, automatically email it to the donor, show "email sent at [time]" in the UI, and tell the admin upfront in the signing modal that the email will go out.

**Architecture:** Add `emailed_at` to the `documents` table. `sign-document.php` gains inline email-sending logic (same pattern as the existing `resend_cert` action in `order-view.php`) and records `emailed_at` on success. Both `order-view.php` and `pledge-view.php` get updated modal text and `emailed_at` display. `pledge-view.php` also gets a proper confirmation modal to replace the current bare submit.

**Tech Stack:** PHP 8.4, MySQL/PDO, PHPMailer via `send_mail()` / `render_email()` in `includes/mailer.php`.

---

## Files

| File | Action |
|------|--------|
| `migrations/023_donation_cert_emailed_at.sql` | Create — adds `emailed_at DATETIME NULL` to `documents` |
| `admin/sign-document.php` | Modify — add email send + record `emailed_at` after signing |
| `admin/order-view.php` | Modify — update modal text; display `emailed_at` badge in cert section |
| `admin/pledge-view.php` | Modify — replace bare submit with modal; display `emailed_at` in cert info |
| `tests/Admin/DonationCertEmailedAtTest.php` | Create — DB schema check + `emailed_at` write test |

---

### Task 1: Migration — add `emailed_at` to `documents`

**Files:**
- Create: `migrations/023_donation_cert_emailed_at.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Track when a donation certificate was emailed after signing
ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS emailed_at DATETIME NULL AFTER signed_by;
```

- [ ] **Step 2: Run the migration**

```bash
php /Users/detelinavasileva/Code/oddminds/migrate.php
```

Expected: Migration 023 applied, no errors.

- [ ] **Step 3: Verify the column exists**

```bash
php -r "
require '/Users/detelinavasileva/Code/oddminds/config.php';
require '/Users/detelinavasileva/Code/oddminds/admin/includes/db.php';
\$pdo = get_pdo();
\$col = \$pdo->query(\"SHOW COLUMNS FROM documents LIKE 'emailed_at'\")->fetch();
echo \$col ? 'OK: column exists' : 'FAIL: column missing';
"
```

Expected output: `OK: column exists`

- [ ] **Step 4: Commit**

```bash
git add migrations/023_donation_cert_emailed_at.sql
git commit -m "feat: add emailed_at to documents for cert email tracking"
```

---

### Task 2: Tests for `emailed_at` schema and DB write

**Files:**
- Create: `tests/Admin/DonationCertEmailedAtTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class DonationCertEmailedAtTest extends TestCase
{
    private PDO $pdo;
    private int $order_id = 0;

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No test DB available.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $this->pdo = get_pdo();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->prepare("DELETE FROM orders WHERE order_number = 'TEST-CERT-EMAILEDAT'")
            ->execute();
    }

    private function insertTestOrder(): int
    {
        $this->pdo->prepare(
            "INSERT INTO orders
             (order_number, type, status, customer_name, customer_email,
              total_eur, payment_method, payment_status)
             VALUES ('TEST-CERT-EMAILEDAT', 'donation', 'confirmed',
                     'Test Donor', 'donor@example.com',
                     25.00, 'card', 'paid')"
        )->execute();
        return (int) $this->pdo->lastInsertId();
    }

    private function insertTestDocument(int $order_id): int
    {
        $this->pdo->prepare(
            "INSERT INTO documents
             (order_id, type, number, formatted_number, file_path)
             VALUES (?, 'donation_cert', 99999, '99999', 'documents/donation_certs/2026/test.pdf')"
        )->execute([$order_id]);
        return (int) $this->pdo->lastInsertId();
    }

    public function test_emailed_at_column_exists(): void
    {
        $col = $this->pdo->query("SHOW COLUMNS FROM documents LIKE 'emailed_at'")->fetch();
        $this->assertNotFalse($col, 'documents.emailed_at column must exist after migration 023');
    }

    public function test_emailed_at_is_null_by_default(): void
    {
        $order_id = $this->insertTestOrder();
        $doc_id   = $this->insertTestDocument($order_id);

        $row = $this->pdo->prepare('SELECT emailed_at FROM documents WHERE id = ?');
        $row->execute([$doc_id]);
        $val = $row->fetchColumn();

        $this->assertNull($val, 'emailed_at must be NULL before an email is sent');
    }

    public function test_emailed_at_can_be_set(): void
    {
        $order_id = $this->insertTestOrder();
        $doc_id   = $this->insertTestDocument($order_id);

        $this->pdo->prepare('UPDATE documents SET emailed_at = NOW() WHERE id = ?')
            ->execute([$doc_id]);

        $row = $this->pdo->prepare('SELECT emailed_at FROM documents WHERE id = ?');
        $row->execute([$doc_id]);
        $val = $row->fetchColumn();

        $this->assertNotNull($val, 'emailed_at must be set after UPDATE');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $val, 'emailed_at must be a datetime string');
    }
}
```

- [ ] **Step 2: Run the tests to confirm they pass**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit tests/Admin/DonationCertEmailedAtTest.php --testdox
```

Expected: 3 tests, all pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Admin/DonationCertEmailedAtTest.php
git commit -m "test: add DonationCertEmailedAtTest for schema and DB write"
```

---

### Task 3: `sign-document.php` — auto-email on signing

**Files:**
- Modify: `admin/sign-document.php`

The current file ends with (after recording the signature in DB):

```php
// Record signature in DB
$pdo->prepare('UPDATE documents SET signed_at = NOW(), signed_by = ? WHERE id = ?')
    ->execute([$user_id, $doc_id]);

// For pledge-type orders, redirect to the pledge detail page instead
if (($order['type'] ?? '') === 'pledge') {
    $pledge_stmt = $pdo->prepare("SELECT id FROM campaign_pledges WHERE pledge_number = ?");
    $pledge_stmt->execute([$order['order_number']]);
    $pledge_id = $pledge_stmt->fetchColumn();
    if ($pledge_id) {
        header('Location: /admin/pledge-view.php?id=' . (int)$pledge_id . '&doc_success=' . urlencode('Документът е подписан.'));
        exit;
    }
}

header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode('Сертификатът е подписан успешно.'));
exit;
```

- [ ] **Step 1: Replace the tail of `sign-document.php` with the version that adds email sending**

Replace everything from `// Record signature in DB` to the final `exit;` with:

```php
// Record signature in DB
$pdo->prepare('UPDATE documents SET signed_at = NOW(), signed_by = ? WHERE id = ?')
    ->execute([$user_id, $doc_id]);

// Auto-email the signed cert to the donor
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

$ps = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
$ps->execute([$order['order_number']]);
$pledge_row = $ps->fetch() ?: null;

$pledge_for_email = $pledge_row ?: [
    'name'             => $order['customer_name'],
    'email'            => $order['customer_email'],
    'pledge_number'    => $order['order_number'],
    'amount_eur'       => $order['total_eur'],
    'created_at'       => $order['created_at'],
    'delivery_address' => null,
    'lang'             => 'bg',
];
$_cert_lang = $pledge_for_email['lang'] ?? 'bg';

$email_ok = send_mail(
    $order['customer_email'],
    render_email_subject('campaign-confirmation', $_cert_lang, [
        'name'          => $pledge_for_email['name'],
        'pledge_number' => $order['order_number'],
    ]),
    render_email('campaign-confirmation', ['pledge' => $pledge_for_email, 'lang' => $_cert_lang]),
    '',
    [['path' => $filepath, 'name' => 'certificate-' . $order['order_number'] . '.pdf']]
);

if ($email_ok) {
    $pdo->prepare('UPDATE documents SET emailed_at = NOW() WHERE id = ?')->execute([$doc_id]);
    _om_log('INFO', 'sign-document: cert emailed to ' . $order['customer_email'] . ' (doc ' . $doc_id . ')');
} else {
    _om_log('ERROR', 'sign-document: cert email failed for doc ' . $doc_id . ' / order ' . $order_id);
}

$sign_msg = $email_ok
    ? 'Сертификатът е подписан и изпратен по имейл.'
    : 'Сертификатът е подписан, но имейлът не беше изпратен.';

// For pledge-type orders, redirect to the pledge detail page instead
if (($order['type'] ?? '') === 'pledge') {
    $pledge_stmt = $pdo->prepare("SELECT id FROM campaign_pledges WHERE pledge_number = ?");
    $pledge_stmt->execute([$order['order_number']]);
    $pledge_id = $pledge_stmt->fetchColumn();
    if ($pledge_id) {
        header('Location: /admin/pledge-view.php?id=' . (int)$pledge_id . '&doc_success=' . urlencode($sign_msg));
        exit;
    }
}

header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode($sign_msg));
exit;
```

- [ ] **Step 2: Run the full test suite**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add admin/sign-document.php
git commit -m "feat: auto-email signed donation cert and record emailed_at"
```

---

### Task 4: `order-view.php` — modal text + `emailed_at` badge

**Files:**
- Modify: `admin/order-view.php`

Two changes: (a) modal body text, (b) cert section badges.

- [ ] **Step 1: Update the sign modal body text (around line 986)**

Find this exact block:
```php
    <?php if ($signer_has_sig): ?>
      <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
        Ще бъде приложен вашият запазен подпис и PDF-ът ще бъде презаписан.
      </p>
```

Replace with:
```php
    <?php if ($signer_has_sig): ?>
      <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
        Ще бъде приложен вашият запазен подпис, PDF-ът ще бъде презаписан и сертификатът ще бъде изпратен автоматично на <?= h($order['customer_email']) ?>.
      </p>
```

- [ ] **Step 2: Update the cert section to show `emailed_at` (around line 790)**

Find this exact block:
```php
            <?php if (!empty($existing_cert['signed_at'])): ?>
              &nbsp;<span style="background:#d1fae5;color:#065f46;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                ✓ Подписан <?= h(substr($existing_cert['signed_at'], 0, 10)) ?>
              </span>
            <?php else: ?>
              &nbsp;<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                Без подпис
              </span>
            <?php endif; ?>
```

Replace with:
```php
            <?php if (!empty($existing_cert['signed_at'])): ?>
              &nbsp;<span style="background:#d1fae5;color:#065f46;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                ✓ Подписан <?= h(substr($existing_cert['signed_at'], 0, 10)) ?>
              </span>
              <?php if (!empty($existing_cert['emailed_at'])): ?>
                &nbsp;<span style="background:#dbeafe;color:#1e40af;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                  ✉ Изпратен <?= h(substr($existing_cert['emailed_at'], 0, 16)) ?>
                </span>
              <?php else: ?>
                &nbsp;<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                  ✉ Имейлът не беше изпратен
                </span>
              <?php endif; ?>
            <?php else: ?>
              &nbsp;<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                Без подпис
              </span>
            <?php endif; ?>
```

- [ ] **Step 3: Run the full test suite**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add admin/order-view.php
git commit -m "feat: update sign modal text and show emailed_at badge in order-view cert section"
```

---

### Task 5: `pledge-view.php` — sign modal + `emailed_at` display

**Files:**
- Modify: `admin/pledge-view.php`

Two changes: (a) replace bare submit with a modal-triggered button, (b) show `emailed_at` in cert info.

- [ ] **Step 1: Replace the bare sign form with a modal-triggered button (around line 260)**

Find this exact block:
```php
      <?php if (empty($cert_doc['signed_at']) && function_exists('admin_can_sign') && admin_can_sign()): ?>
      <form method="POST" action="/admin/sign-document.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="doc_id" value="<?= (int)$cert_doc['id'] ?>">
        <button type="submit" class="btn btn--primary" style="font-size:.85rem;">Подпиши</button>
      </form>
      <?php endif; ?>
```

Replace with:
```php
      <?php if (empty($cert_doc['signed_at']) && function_exists('admin_can_sign') && admin_can_sign()): ?>
      <button type="button" class="btn btn--primary" style="font-size:.85rem;"
              onclick="openPledgeSignModal(<?= (int)$cert_doc['id'] ?>)">Подпиши</button>
      <?php endif; ?>
```

- [ ] **Step 2: Add `emailed_at` display to the cert info block (around line 254)**

Find this exact block:
```php
        <?php if (!empty($cert_doc['signed_at'])): ?>
        <div style="font-size:.78rem;color:#2d6a35;margin-top:.25rem;">Подписан на <?= substr($cert_doc['signed_at'], 0, 10) ?></div>
        <?php endif; ?>
```

Replace with:
```php
        <?php if (!empty($cert_doc['signed_at'])): ?>
        <div style="font-size:.78rem;color:#2d6a35;margin-top:.25rem;">Подписан на <?= h(substr($cert_doc['signed_at'], 0, 10)) ?></div>
        <?php if (!empty($cert_doc['emailed_at'])): ?>
        <div style="font-size:.78rem;color:#1e40af;margin-top:.2rem;">✉ Изпратен <?= h(substr($cert_doc['emailed_at'], 0, 16)) ?></div>
        <?php else: ?>
        <div style="font-size:.78rem;color:#b5870a;margin-top:.2rem;">✉ Имейлът не беше изпратен</div>
        <?php endif; ?>
        <?php endif; ?>
```

- [ ] **Step 3: Add the modal HTML and JS just before `require admin-footer.php`**

Find this line at the end of the file:
```php
<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

Insert before it:
```php
<?php if (function_exists('admin_can_sign') && admin_can_sign()): ?>
<div id="pledgeSignModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;"
     role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:10px;padding:2rem;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.25);">
    <h3 style="margin-top:0;font-size:1.05rem;">Подпис на сертификата</h3>
    <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
      Ще бъде приложен вашият запазен подпис, PDF-ът ще бъде презаписан и сертификатът ще бъде изпратен автоматично на <?= h($pledge['email']) ?>.
    </p>
    <form method="POST" action="/admin/sign-document.php" id="pledgeSignForm">
      <?= csrf_field() ?>
      <input type="hidden" name="doc_id" id="pledgeSignDocId" value="">
      <div style="display:flex;gap:.75rem;justify-content:flex-end;">
        <button type="button" id="pledgeSignModalCancel"
                style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;">
          Отказ
        </button>
        <button type="submit"
                style="padding:.55rem 1.25rem;border:none;border-radius:6px;background:var(--teal);color:#fff;cursor:pointer;font-size:.9rem;font-weight:600;">
          ✍ Приложи подписа
        </button>
      </div>
    </form>
  </div>
</div>
<script>
function openPledgeSignModal(docId) {
    document.getElementById('pledgeSignDocId').value = docId;
    var overlay = document.getElementById('pledgeSignModalOverlay');
    overlay.style.display = 'flex';
    document.getElementById('pledgeSignModalCancel').focus();
}
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('pledgeSignModalOverlay');
    document.getElementById('pledgeSignModalCancel').addEventListener('click', function () {
        overlay.style.display = 'none';
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.style.display = 'none';
    });
    document.addEventListener('keydown', function (e) {
        if (overlay.style.display !== 'none' && e.key === 'Escape') overlay.style.display = 'none';
    });
});
</script>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

- [ ] **Step 4: Run the full test suite**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin/pledge-view.php
git commit -m "feat: add sign confirmation modal and emailed_at display to pledge-view cert section"
```
