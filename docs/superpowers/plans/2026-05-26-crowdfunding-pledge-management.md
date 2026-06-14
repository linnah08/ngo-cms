# Crowdfunding Pledge Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give campaign donation pledges a dedicated admin detail page with cert download/signing, and a DSK Bank refund action — mirroring how shop orders work.

**Architecture:** When a campaign pledge payment succeeds, a synthetic `orders` row (type=`'pledge'`) is created and the cert is recorded in the `documents` table. This lets signing, download, and all existing document infrastructure work unchanged. A new `includes/pledge_documents.php` holds shared helper functions used by both `campaign-payment-return.php` and the new `admin/pledge-view.php`.

**Tech Stack:** PHP 8.4, PDO/MySQL, PHPUnit 13, mPDF (via existing DocumentGenerator), DSKBankPayment class.

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `includes/pledge_documents.php` | **Create** | `pledge_ensure_order_row()`, `pledge_insert_cert_document()`, `pledge_update_refunded_statuses()` |
| `tests/PledgeDocumentsTest.php` | **Create** | PHPUnit tests for the three helpers |
| `includes/email-templates.php` | **Modify** | Add `pledge-reversed-customer` defaults to `_email_tpl_defaults()` |
| `includes/emails/pledge-reversed-customer.php` | **Create** | Refund notification email template |
| `api/campaign-payment-return.php` | **Modify** | Require pledge_documents.php; add ENUM migration; call helpers inside `generate_campaign_cert()` |
| `admin/pledge-view.php` | **Create** | Pledge detail admin page (header, cert, refund, regenerate) |
| `admin/campaign-backers.php` | **Modify** | Add "Виж →" link per row in donations tab |
| `admin/sign-document.php` | **Modify** | Redirect to `pledge-view.php` for `type='pledge'` orders after signing |

---

## Task 1: Write failing tests for pledge_documents helpers

**Files:**
- Create: `tests/PledgeDocumentsTest.php`

- [ ] **Step 1.1: Create the test file**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pledge-documents')]
final class PledgeDocumentsTest extends TestCase
{
    private PDO $pdo;
    private string $pledge_number = 'TEST-PLG-PHPUNIT';

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No test DB available.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';
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
        // Cascade: orders FK deletes documents rows too
        $this->pdo->prepare("DELETE FROM orders WHERE order_number = ? AND type = 'pledge'")
            ->execute([$this->pledge_number]);
        $this->pdo->prepare("DELETE FROM campaign_pledges WHERE pledge_number = ?")
            ->execute([$this->pledge_number]);
    }

    private function insertTestPledge(): array
    {
        $this->pdo->prepare(
            "INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status)
             VALUES (?, 'Test Donor', 'test@example.com', 50.00, 'paid')"
        )->execute([$this->pledge_number]);

        return [
            'id'             => (int)$this->pdo->lastInsertId(),
            'pledge_number'  => $this->pledge_number,
            'name'           => 'Test Donor',
            'email'          => 'test@example.com',
            'amount_eur'     => '50.00',
            'created_at'     => date('Y-m-d H:i:s'),
        ];
    }

    // ── pledge_ensure_order_row ────────────────────────────────────────────────

    public function test_ensure_order_row_creates_new_row(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        $this->assertGreaterThan(0, $order_id);

        $row = $this->pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $row->execute([$order_id]);
        $order = $row->fetch();

        $this->assertNotFalse($order);
        $this->assertSame('pledge',    $order['type']);
        $this->assertSame($this->pledge_number, $order['order_number']);
        $this->assertSame('Test Donor', $order['customer_name']);
        $this->assertSame('test@example.com', $order['customer_email']);
        $this->assertSame('50.00', $order['total_eur']);
        $this->assertSame('paid',      $order['payment_status']);
        $this->assertSame('confirmed', $order['status']);
    }

    public function test_ensure_order_row_is_idempotent(): void
    {
        $pledge = $this->insertTestPledge();
        $id1    = pledge_ensure_order_row($this->pdo, $pledge);
        $id2    = pledge_ensure_order_row($this->pdo, $pledge);

        $this->assertSame($id1, $id2);

        $count = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM orders WHERE order_number = '{$this->pledge_number}' AND type = 'pledge'")
            ->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ── pledge_insert_cert_document ───────────────────────────────────────────

    public function test_insert_cert_document_creates_row(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');

        $row = $this->pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $row->execute([$order_id]);
        $doc = $row->fetch();

        $this->assertNotFalse($doc);
        $this->assertSame(42,       (int)$doc['number']);
        $this->assertSame('00042',  $doc['formatted_number']);
        $this->assertSame('documents/donation_certs/2026/00042_test.pdf', $doc['file_path']);
    }

    public function test_insert_cert_document_is_idempotent(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');
        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');

        $count = (int)$this->pdo
            ->prepare("SELECT COUNT(*) FROM documents WHERE order_id = ? AND type = 'donation_cert'")
            ->execute([$order_id]);
        // Re-fetch because execute() returns bool
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $stmt->execute([$order_id]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    // ── pledge_update_refunded_statuses ───────────────────────────────────────

    public function test_update_refunded_statuses_sets_both(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_update_refunded_statuses($this->pdo, $pledge['id'], $this->pledge_number);

        $p = $this->pdo->prepare('SELECT payment_status FROM campaign_pledges WHERE id = ?');
        $p->execute([$pledge['id']]);
        $this->assertSame('reversed', $p->fetchColumn());

        $o = $this->pdo->prepare("SELECT payment_status FROM orders WHERE order_number = ? AND type = 'pledge'");
        $o->execute([$this->pledge_number]);
        $this->assertSame('refunded', $o->fetchColumn());
    }
}
```

- [ ] **Step 1.2: Run the tests — expect failure because the file doesn't exist yet**

```bash
cd /Users/detelinavasileva/Code/oddminds
php vendor/bin/phpunit tests/PledgeDocumentsTest.php --group pledge-documents
```

Expected: Fatal error or "class not found" — `pledge_ensure_order_row` is not defined.

---

## Task 2: Create `includes/pledge_documents.php`

**Files:**
- Create: `includes/pledge_documents.php`

- [ ] **Step 2.1: Create the helper file**

```php
<?php
/**
 * Shared helpers for campaign pledge → orders/documents integration.
 * Used by api/campaign-payment-return.php and admin/pledge-view.php.
 */

/**
 * Find or create the synthetic orders row for a campaign pledge.
 * Returns the orders.id for the row (existing or newly created).
 *
 * @param array $pledge Row from campaign_pledges
 */
function pledge_ensure_order_row(PDO $pdo, array $pledge): int
{
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? AND type = 'pledge'");
    $stmt->execute([$pledge['pledge_number']]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int)$existing;
    }

    $items = json_encode([[
        'type'       => 'donation',
        'amount_eur' => (float)$pledge['amount_eur'],
        'recipient'  => 'foundation',
    ]]);

    $pdo->prepare("
        INSERT IGNORE INTO orders
            (order_number, type, status, customer_name, customer_email,
             items, subtotal_eur, shipping_eur, total_eur,
             payment_method, payment_status, invoice_data, created_at)
        VALUES (?, 'pledge', 'confirmed', ?, ?, ?, ?, 0, ?, 'card', 'paid', ?, ?)
    ")->execute([
        $pledge['pledge_number'],
        $pledge['name'],
        $pledge['email'],
        $items,
        (float)$pledge['amount_eur'],
        (float)$pledge['amount_eur'],
        json_encode(['donor_type' => 'individual']),
        $pledge['created_at'],
    ]);

    $new_id = (int)$pdo->lastInsertId();
    if ($new_id > 0) {
        return $new_id;
    }

    // INSERT IGNORE silently skipped a duplicate — fetch the existing row
    $stmt->execute([$pledge['pledge_number']]);
    return (int)$stmt->fetchColumn();
}

/**
 * Insert a documents row for a pledge cert if one doesn't already exist.
 * Idempotent — safe to call multiple times.
 */
function pledge_insert_cert_document(
    PDO    $pdo,
    int    $order_id,
    int    $cert_number,
    string $formatted_number,
    string $rel_path
): void {
    $check = $pdo->prepare("SELECT id FROM documents WHERE order_id = ? AND type = 'donation_cert'");
    $check->execute([$order_id]);
    if ($check->fetchColumn() !== false) {
        return;
    }

    $pdo->prepare("
        INSERT INTO documents (order_id, type, number, formatted_number, file_path)
        VALUES (?, 'donation_cert', ?, ?, ?)
    ")->execute([$order_id, $cert_number, $formatted_number, $rel_path]);
}

/**
 * Mark the pledge as reversed and its linked orders row as refunded.
 * Called after a successful DSK Bank refund.
 */
function pledge_update_refunded_statuses(PDO $pdo, int $pledge_id, string $pledge_number): void
{
    // campaign_pledges has no updated_at column — orders does
    $pdo->prepare("UPDATE campaign_pledges SET payment_status='reversed' WHERE id=?")
        ->execute([$pledge_id]);
    $pdo->prepare("UPDATE orders SET payment_status='refunded', updated_at=NOW() WHERE order_number=? AND type='pledge'")
        ->execute([$pledge_number]);
}
```

- [ ] **Step 2.2: Run the tests — expect pass**

```bash
php vendor/bin/phpunit tests/PledgeDocumentsTest.php --group pledge-documents
```

Expected: All 5 tests green.

- [ ] **Step 2.3: Run full test suite — no regressions**

```bash
php vendor/bin/phpunit
```

Expected: All tests pass (or same skip count as before).

- [ ] **Step 2.4: Commit**

```bash
git add includes/pledge_documents.php tests/PledgeDocumentsTest.php
git commit -m "feat: add pledge_documents helpers with tests"
```

---

## Task 3: Update `campaign-payment-return.php`

**Files:**
- Modify: `api/campaign-payment-return.php`

- [ ] **Step 3.1: Add require and ENUM migration at the top of the file**

After the existing `require_once` block (around line 13), add:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';

// Extend orders.type to include 'pledge' (idempotent)
try {
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN type ENUM('physical','donation','ticket','pledge') NOT NULL");
} catch (Throwable) {}
```

Add it right after `$pdo = get_pdo();` (line 16) so `$pdo` is available.

- [ ] **Step 3.2: Extend `generate_campaign_cert()` to create the orders row and documents entry**

Find the end of `generate_campaign_cert()` — the line that does:
```php
$pdo->prepare("UPDATE campaign_pledges SET cert_number=?, cert_path=? WHERE id=?")
    ->execute([$num, $rel_path, $pledge['id']]);
```

After that line and before the `} catch (Throwable $e) {` block, add:

```php
        // Create orders anchor row + document record so cert appears in signing flow
        $order_id = pledge_ensure_order_row($pdo, $pledge);
        pledge_insert_cert_document($pdo, $order_id, $num, $formatted, $rel_path);

        // Notify signing admin
        if (function_exists('send_mail') && defined('SIGNING_ADMIN_EMAIL')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
            send_mail(
                SIGNING_ADMIN_EMAIL,
                'Сертификат за дарение #' . $formatted . ' — нужен подпис',
                render_email('cert-needs-signature', [
                    'order'    => [
                        'customer_name'  => $pledge['name'],
                        'customer_email' => $pledge['email'],
                        'order_number'   => $pledge['pledge_number'],
                    ],
                    'document' => ['formatted_number' => $formatted],
                    'order_id' => $order_id,
                ])
            );
        }
```

- [ ] **Step 3.3: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All tests still pass.

- [ ] **Step 3.4: Commit**

```bash
git add api/campaign-payment-return.php
git commit -m "feat: create orders row and documents entry on campaign cert generation"
```

---

## Task 4: Add email template for pledge refund

**Files:**
- Modify: `includes/email-templates.php`
- Create: `includes/emails/pledge-reversed-customer.php`

- [ ] **Step 4.1: Add template defaults to `_email_tpl_defaults()`**

In `includes/email-templates.php`, find the closing `];` of the `return [...]` array in `_email_tpl_defaults()`. Add this entry before it:

```php
        'pledge-reversed-customer' => [
            'subject_bg' => 'Върнато плащане — {{pledge_number}}',
            'intro_bg'   => '<h2>Върнато плащане</h2>'
                          . '<p>Здравей {{name}},</p>'
                          . '<p>Плащането ти по кампанията в размер на <strong>{{amount_eur}} EUR</strong> '
                          . '(референтен номер <strong>{{pledge_number}}</strong>) е отменено и сумата '
                          . 'ще бъде върната по картата, с която е извършено плащането, '
                          . 'в рамките на 3–5 работни дни.</p>',
            'outro_bg'   => '<p>При въпроси се свържи с нас на <a href="mailto:' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '">' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '</a>.</p>',
            'subject_en' => 'Refunded payment — {{pledge_number}}',
            'intro_en'   => '<h2>Payment refunded</h2>'
                          . '<p>Hi {{name}},</p>'
                          . '<p>Your campaign contribution of <strong>{{amount_eur}} EUR</strong> '
                          . '(reference <strong>{{pledge_number}}</strong>) has been cancelled and '
                          . 'the amount will be returned to your card within 3–5 working days.</p>',
            'outro_en'   => '<p>If you have questions, contact us at <a href="mailto:' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '">' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '</a>.</p>',
        ],
```

- [ ] **Step 4.2: Create the email template file**

```php
<?php
// Variables: $pledge (array), $tpl (rendered subject/intro/outro from email_tpl_get)
echo $tpl['intro'];
?>

<div class="box">
  <strong>Референтен номер:</strong> <?= htmlspecialchars($pledge['pledge_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Сума:</strong> <?= htmlspecialchars(number_format((float)$pledge['amount_eur'], 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?> EUR
</div>

<?php echo $tpl['outro']; ?>
```

Save as `includes/emails/pledge-reversed-customer.php`.

- [ ] **Step 4.3: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 4.4: Commit**

```bash
git add includes/email-templates.php includes/emails/pledge-reversed-customer.php
git commit -m "feat: add pledge-reversed-customer email template"
```

---

## Task 5: Create `admin/pledge-view.php`

**Files:**
- Create: `admin/pledge-view.php`

- [ ] **Step 5.1: Create the file**

```php
<?php
$page_title_admin = 'Поддръжник на кампанията';
$active_nav       = 'campaign';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';
admin_require_shop();

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/campaign-backers.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id = ?');
$stmt->execute([$id]);
$pledge = $stmt->fetch();
if (!$pledge) { header('Location: /admin/campaign-backers.php'); exit; }

// Load linked orders row
$ord_stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? AND type = 'pledge'");
$ord_stmt->execute([$pledge['pledge_number']]);
$order = $ord_stmt->fetch() ?: null;

// Load cert document
$cert_doc = null;
if ($order) {
    $doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'donation_cert'");
    $doc_stmt->execute([$order['id']]);
    $cert_doc = $doc_stmt->fetch() ?: null;
}

// Load reward
$reward = null;
if ($pledge['reward_id']) {
    $r = $pdo->prepare('SELECT * FROM campaign_rewards WHERE id = ?');
    $r->execute([$pledge['reward_id']]);
    $reward = $r->fetch() ?: null;
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    // ── Refund ────────────────────────────────────────────────────────────────
    if ($action === 'refund') {
        if ($pledge['payment_status'] !== 'paid') {
            $errors[] = 'Тази вноска вече не е в статус "Платено".';
        } elseif (empty($pledge['dsk_order_id'])) {
            $errors[] = 'Няма DSK поръчка за връщане.';
        } else {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
            try {
                (new DSKBankPayment())->refund($pledge['dsk_order_id'], (float)$pledge['amount_eur']);
                pledge_update_refunded_statuses($pdo, (int)$pledge['id'], $pledge['pledge_number']);

                $tpl  = email_tpl_get('pledge-reversed-customer', 'bg', [
                    'name'          => $pledge['name'],
                    'pledge_number' => $pledge['pledge_number'],
                    'amount_eur'    => number_format((float)$pledge['amount_eur'], 2, '.', ' '),
                ]);
                send_mail(
                    $pledge['email'],
                    $tpl['subject'],
                    render_email('pledge-reversed-customer', ['pledge' => $pledge, 'tpl' => $tpl])
                );

                // Re-fetch pledge so status badge updates
                $stmt->execute([$id]);
                $pledge  = $stmt->fetch();
                $success = 'Плащането е върнато успешно.';
            } catch (Throwable $e) {
                error_log('pledge refund id=' . $id . ': ' . $e->getMessage());
                $errors[] = 'Грешка при връщане на плащането. Моля, обработете го ръчно в DSK Bank.';
            }
        }
    }

    // ── Regenerate cert ───────────────────────────────────────────────────────
    if ($action === 'regenerate_cert') {
        try {
            $order_id = pledge_ensure_order_row($pdo, $pledge);

            $cert_number    = (int)($pledge['cert_number'] ?? 0);
            $cert_path_rel  = $pledge['cert_path'] ?? '';
            $cert_path_abs  = $cert_path_rel !== '' ? $_SERVER['DOCUMENT_ROOT'] . $cert_path_rel : '';

            if ($cert_number > 0 && $cert_path_abs !== '' && file_exists($cert_path_abs)) {
                // Existing file — just register it in documents
                $formatted = str_pad((string)$cert_number, 5, '0', STR_PAD_LEFT);
                pledge_insert_cert_document($pdo, $order_id, $cert_number, $formatted, ltrim($cert_path_rel, '/'));
            } else {
                // Generate a fresh cert with a new sequence number
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

                $pdo->beginTransaction();
                $pdo->exec("UPDATE document_sequences SET last_number = last_number + 1 WHERE type = 'donation_cert'");
                $num = (int)$pdo->query("SELECT last_number FROM document_sequences WHERE type = 'donation_cert'")->fetchColumn();
                $pdo->commit();

                $formatted = str_pad((string)$num, 5, '0', STR_PAD_LEFT);
                $year      = date('Y', strtotime($pledge['created_at']));
                $dir       = $_SERVER['DOCUMENT_ROOT'] . "/documents/donation_certs/{$year}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename  = $formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $pledge['pledge_number']) . '.pdf';
                $filepath  = $dir . $filename;
                $rel       = "documents/donation_certs/{$year}/{$filename}";

                $fake_order = [
                    'id'             => $order_id,
                    'order_number'   => $pledge['pledge_number'],
                    'customer_name'  => $pledge['name'],
                    'customer_email' => $pledge['email'],
                    'total_eur'      => (float)$pledge['amount_eur'],
                    'payment_method' => 'card',
                    'created_at'     => $pledge['created_at'],
                    'invoice_data'   => json_encode(['donor_type' => 'individual']),
                ];
                $fake_items = [['type' => 'donation', 'amount_eur' => (float)$pledge['amount_eur'], 'recipient' => 'foundation']];
                $fake_doc   = ['formatted_number' => $formatted];

                $pdf = (new DonationCertGenerator())->generate($fake_order, $fake_items, $fake_doc);
                file_put_contents($filepath, $pdf);

                $pdo->prepare("UPDATE campaign_pledges SET cert_number=?, cert_path=? WHERE id=?")
                    ->execute([$num, "/{$rel}", $pledge['id']]);

                pledge_insert_cert_document($pdo, $order_id, $num, $formatted, $rel);

                // Re-fetch
                $stmt->execute([$id]);
                $pledge = $stmt->fetch();
            }

            // Re-fetch cert_doc so UI updates
            $doc_stmt->execute([$order_id]);
            $cert_doc = $doc_stmt->fetch() ?: null;
            $success  = 'Сертификатът е регенериран.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('pledge regenerate_cert id=' . $id . ': ' . $e->getMessage());
            $errors[] = 'Грешка при регенериране на сертификата.';
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$status_colors = [
    'paid'     => '#2d6a35',
    'reversed' => '#6b6560',
    'pending'  => '#b5870a',
    'failed'   => '#c0392b',
];
$status_labels = [
    'paid'     => 'Платено',
    'reversed' => 'Върнато',
    'pending'  => 'Чакащо',
    'failed'   => 'Неуспешно',
];
$addr = $pledge['delivery_address'] ? json_decode($pledge['delivery_address'], true) : null;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
  <h1 style="margin:0;font-size:1.4rem;">
    Поддръжник
    <span style="font-family:monospace;font-size:1rem;color:#6b6560;margin-left:.5rem;"><?= h($pledge['pledge_number']) ?></span>
  </h1>
  <a href="/admin/campaign-backers.php" class="btn btn--secondary">← Назад</a>
</div>

<?php if (!empty($errors)): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.25rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;">
  <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.25rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;">
  <?= h($success) ?>
</div>
<?php endif; ?>

<!-- Header card -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem 2rem;flex-wrap:wrap;">
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Дарител</div>
      <div style="font-weight:600;"><?= h($pledge['name']) ?></div>
      <div style="font-size:.85rem;color:#6b6560;"><?= h($pledge['email']) ?></div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Сума</div>
      <div style="font-weight:600;font-size:1.1rem;"><?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR</div>
      <div style="font-size:.82rem;color:#6b6560;"><?= number_format((float)$pledge['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ') ?> лв</div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Дата</div>
      <div><?= substr($pledge['created_at'], 0, 10) ?></div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Статус плащане</div>
      <div style="display:inline-block;padding:.25rem .65rem;border-radius:20px;font-size:.8rem;font-weight:600;
                  background:<?= h($status_colors[$pledge['payment_status']] ?? '#888') ?>22;
                  color:<?= h($status_colors[$pledge['payment_status']] ?? '#888') ?>;">
        <?= h($status_labels[$pledge['payment_status']] ?? $pledge['payment_status']) ?>
      </div>
    </div>
    <?php if ($reward): ?>
    <div style="grid-column:1/-1;">
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Награда</div>
      <div><?= h($reward['title']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($addr): ?>
    <div style="grid-column:1/-1;">
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Адрес за доставка</div>
      <div style="font-size:.88rem;">
        <?= h(implode(', ', array_filter([
          $addr['address']  ?? '',
          $addr['city']     ?? '',
          $addr['postcode'] ?? '',
          $addr['phone']    ?? '',
        ]))) ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Documents section -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem;">
  <h2 style="margin:0 0 1rem;font-size:1rem;font-weight:600;">Документи</h2>

  <?php if ($cert_doc): ?>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <div>
        <div style="font-weight:600;font-size:.9rem;">Сертификат за дарение № <?= h($cert_doc['formatted_number']) ?></div>
        <div style="font-size:.8rem;color:#6b6560;"><?= substr($cert_doc['generated_at'], 0, 16) ?></div>
        <?php if (!empty($cert_doc['signed_at'])): ?>
        <div style="font-size:.78rem;color:#2d6a35;margin-top:.25rem;">Подписан на <?= substr($cert_doc['signed_at'], 0, 10) ?></div>
        <?php endif; ?>
      </div>
      <a href="/admin/download-document.php?id=<?= (int)$cert_doc['id'] ?>" target="_blank"
         class="btn btn--secondary" style="font-size:.85rem;">Свали PDF</a>
      <?php if (empty($cert_doc['signed_at']) && function_exists('admin_can_sign') && admin_can_sign()): ?>
      <form method="POST" action="/admin/sign-document.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="doc_id" value="<?= (int)$cert_doc['id'] ?>">
        <button type="submit" class="btn btn--primary" style="font-size:.85rem;">Подпиши</button>
      </form>
      <?php endif; ?>
    </div>

  <?php elseif (!empty($pledge['cert_path'])): ?>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <div>
        <div style="font-weight:600;font-size:.9rem;">Сертификат № <?= h(str_pad((string)(int)$pledge['cert_number'], 5, '0', STR_PAD_LEFT)) ?></div>
        <div style="font-size:.78rem;color:#b5870a;">Генериран преди новата система — натисни Регенерирай за подписване.</div>
      </div>
      <a href="<?= h($pledge['cert_path']) ?>" target="_blank"
         class="btn btn--secondary" style="font-size:.85rem;">Свали PDF</a>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate_cert">
        <button type="submit" class="btn btn--outline" style="font-size:.85rem;">Регенерирай</button>
      </form>
    </div>

  <?php else: ?>
    <p style="color:#9b9590;margin:0;">Сертификатът ще бъде генериран автоматично при плащане.</p>
  <?php endif; ?>
</div>

<!-- Refund section -->
<?php if ($pledge['payment_status'] === 'paid' && !empty($pledge['dsk_order_id'])): ?>
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;">
  <h2 style="margin:0 0 .5rem;font-size:1rem;font-weight:600;">Връщане на плащането</h2>
  <p style="margin:0 0 1rem;font-size:.88rem;color:#6b6560;">
    Ще бъде инициирано автоматично връщане на <?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR
    по картата на дарителя и ще бъде изпратен имейл за потвърждение.
  </p>
  <form method="POST" id="refundForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="refund">
    <button type="button" id="refundBtn" class="btn btn--danger">Върни плащането</button>
  </form>
  <script>
  document.getElementById('refundBtn').addEventListener('click', function() {
    _adminConfirm('Сигурен ли си? Това ще върне <?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR на дарителя. Действието е необратимо.').then(function(ok) {
      if (ok) document.getElementById('refundForm').submit();
    });
  });
  </script>
</div>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

- [ ] **Step 5.2: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 5.3: Commit**

```bash
git add admin/pledge-view.php
git commit -m "feat: add pledge-view.php admin detail page"
```

---

## Task 6: Add "Виж" links in `campaign-backers.php`

**Files:**
- Modify: `admin/campaign-backers.php`

- [ ] **Step 6.1: Add the link column**

In `campaign-backers.php`, the donations tab currently shows: Награда + Изпратена cells, before the shared Статус cell. Add a Виж link cell in the donations section.

In the **table header** (`<thead>`), find:
```php
        <th style="padding:.65rem 1rem;text-align:center;font-weight:600;">Изпратена</th>
```
Add immediately after it (still inside the `<?php if ($tab === 'donations'): ?>` block, before `<?php else: ?>`):
```php
        <th style="padding:.65rem 1rem;"></th>
```

In the **table body** (`<tbody>`), find the donations tab row's "Изпратена" toggle cell — it ends with:
```php
        </td>
        <?php else: ?>
```
Add a new `<td>` immediately before `<?php else: ?>`:
```php
        <td style="padding:.65rem 1rem;text-align:right;">
          <a href="/admin/pledge-view.php?id=<?= (int)$b['id'] ?>"
             style="font-size:.82rem;color:#0387A5;text-decoration:none;font-weight:600;">Виж →</a>
        </td>
```

- [ ] **Step 6.2: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 6.3: Commit**

```bash
git add admin/campaign-backers.php
git commit -m "feat: link campaign-backers rows to pledge-view.php"
```

---

## Task 7: Fix `sign-document.php` redirect for pledge-type orders

**Files:**
- Modify: `admin/sign-document.php`

- [ ] **Step 7.1: Read the current redirect at the end of the file**

```bash
grep -n "Location\|order-view\|order_id" /Users/detelinavasileva/Code/oddminds/admin/sign-document.php | tail -20
```

The file currently ends with a redirect like:
```php
header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=...');
```

- [ ] **Step 7.2: Add pledge redirect logic**

Find the section that does the final redirect after signing succeeds. Before that redirect, add:

```php
// If this is a pledge-type order, redirect back to pledge-view instead
if (($order['type'] ?? '') === 'pledge') {
    $pledge_stmt = $pdo->prepare("SELECT id FROM campaign_pledges WHERE pledge_number = ?");
    $pledge_stmt->execute([$order['order_number']]);
    $pledge_id = $pledge_stmt->fetchColumn();
    if ($pledge_id) {
        header('Location: /admin/pledge-view.php?id=' . (int)$pledge_id . '&doc_success=' . urlencode('Документът е подписан.'));
        exit;
    }
}
```

Place this block immediately before the existing `header('Location: /admin/order-view.php?...')` line.

- [ ] **Step 7.3: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 7.4: Commit**

```bash
git add admin/sign-document.php
git commit -m "fix: redirect to pledge-view after signing pledge donation cert"
```

---

## Verification

- [ ] Open `admin/campaign-backers.php` — donations tab shows "Виж →" links.
- [ ] Click a paid pledge's "Виж →" — lands on `pledge-view.php` with correct name, amount, cert section.
- [ ] If pledge has a legacy cert (`cert_path` set, no `documents` row) — "Регенерирай" button appears.
- [ ] Click "Регенерирай" — cert appears in documents section with download + sign button.
- [ ] Click "Подпиши" — redirects back to `pledge-view.php` (not `order-view.php`) with success message.
- [ ] For a paid pledge with `dsk_order_id` — refund section visible with "Върни плащането" button.
- [ ] Confirm refund modal appears; on confirm, status updates to "Върнато" and email sent.
