# Monthly Orders Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate a CSV of paid/refunded orders for any calendar month, downloadable from the admin, and emailed automatically to detelina@oddminds.org on the 1st of each month.

**Architecture:** Shared query/CSV logic in `includes/reports/monthly-orders.php` consumed by both the admin download page and the cron script. Admin page downloads directly via HTTP response headers; cron writes to a temp file, attaches to email, then deletes.

**Tech Stack:** PHP 8.4, PDO/MySQL, PHPMailer (via existing `send_mail()`), fputcsv, cPanel cron.

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `includes/reports/monthly-orders.php` | Create | `generate_monthly_orders_csv(int $year, int $month): string` |
| `tests/Reports/MonthlyOrdersReportTest.php` | Create | DB integration tests for the CSV function |
| `admin/monthly-report.php` | Create | Admin download page (month picker + POST handler) |
| `cron/monthly-report-cron.php` | Create | CLI script for 1st-of-month automated email |
| `cron/.htaccess` | Create | Deny all web access to cron directory |
| `admin/includes/admin-header.php` | Modify | Add "Месечен отчет" nav link after "Поръчки" |

---

### Task 1: Create the report function with failing test

**Files:**
- Create: `tests/Reports/MonthlyOrdersReportTest.php`
- Create: `includes/reports/monthly-orders.php`

- [ ] **Step 1: Create test directory and file**

Create `tests/Reports/MonthlyOrdersReportTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('reports')]
#[Group('db')]
final class MonthlyOrdersReportTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/reports/monthly-orders.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured — set db.config.php to run DB tests.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo || empty(self::$order_ids)) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")
                  ->execute(self::$order_ids);
    }

    private function insertOrder(array $overrides = []): int
    {
        $defaults = [
            'order_number'  => 'TEST-' . uniqid(),
            'type'          => 'physical',
            'status'        => 'confirmed',
            'customer_name' => 'Test Customer',
            'customer_email'=> 'test@example.com',
            'customer_phone'=> '',
            'delivery_type' => 'address',
            'items'         => '[]',
            'subtotal_eur'  => 10.00,
            'shipping_eur'  => 5.00,
            'total_eur'     => 15.00,
            'payment_method'=> 'card',
            'payment_status'=> 'paid',
            'lang'          => 'bg',
            'created_at'    => '2026-04-15 10:00:00',
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare(
            'INSERT INTO orders
             (order_number, type, status, customer_name, customer_email, customer_phone,
              delivery_type, items, subtotal_eur, shipping_eur, total_eur,
              payment_method, payment_status, lang, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['order_number'], $d['type'], $d['status'], $d['customer_name'],
            $d['customer_email'], $d['customer_phone'], $d['delivery_type'],
            $d['items'], $d['subtotal_eur'], $d['shipping_eur'], $d['total_eur'],
            $d['payment_method'], $d['payment_status'], $d['lang'], $d['created_at'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $id;
    }

    // --- Tests ---

    public function testReturnsHeaderRow(): void
    {
        $csv = generate_monthly_orders_csv(2026, 4);
        $lines = explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")));
        $this->assertStringContainsString('Order Number', $lines[0]);
        $this->assertStringContainsString('Amount (EUR)', $lines[0]);
    }

    public function testIncludesPaidOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PAID-001',
            'payment_status' => 'paid',
            'total_eur' => 20.00,
            'created_at' => '2026-04-10 09:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-PAID-001', $csv);
    }

    public function testIncludesRefundedOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-REFUND-001',
            'payment_status' => 'refunded',
            'total_eur' => 12.50,
            'created_at' => '2026-04-20 11:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-REFUND-001', $csv);
        $this->assertStringContainsString('refunded', $csv);
    }

    public function testExcludesPendingOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PENDING-001',
            'payment_status' => 'pending',
            'created_at' => '2026-04-05 08:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringNotContainsString('TEST-PENDING-001', $csv);
    }

    public function testExcludesOrdersFromOtherMonth(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-MAY-001',
            'payment_status' => 'paid',
            'created_at' => '2026-05-01 00:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringNotContainsString('TEST-MAY-001', $csv);
    }

    public function testPhysicalTypeLabelledPurchase(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PHYS-001',
            'type' => 'physical',
            'payment_status' => 'paid',
            'created_at' => '2026-04-12 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-PHYS-001', $csv);
        $this->assertStringContainsString('purchase', $csv);
    }

    public function testDonationTypeIncluded(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-DON-001',
            'type' => 'donation',
            'payment_status' => 'paid',
            'created_at' => '2026-04-14 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-DON-001', $csv);
        $this->assertStringContainsString('donation', $csv);
    }

    public function testAmountFormattedToTwoDecimals(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-AMT-001',
            'payment_status' => 'paid',
            'total_eur' => 99.90,
            'created_at' => '2026-04-16 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('99.90', $csv);
    }

    public function testStartsWithUtf8Bom(): void
    {
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testEmptyMonthReturnsOnlyHeader(): void
    {
        // January 2020 — no test data inserted for that month
        $csv = generate_monthly_orders_csv(2020, 1);
        $lines = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertCount(1, $lines); // header only
    }
}
```

- [ ] **Step 2: Run test to verify it fails (function not found)**

```bash
cd /Users/detelinavasileva/Code/oddminds
php vendor/bin/phpunit tests/Reports/MonthlyOrdersReportTest.php --testdox
```

Expected: error about `generate_monthly_orders_csv` not being found.

- [ ] **Step 3: Create the report function**

Create `includes/reports/monthly-orders.php`:

```php
<?php
declare(strict_types=1);

/**
 * Generate a UTF-8 BOM CSV of paid/refunded orders for a given month.
 *
 * @param int $year  Four-digit year (e.g. 2026)
 * @param int $month Month number 1–12
 * @return string    CSV string with UTF-8 BOM prepended (ready to output or write to file)
 */
function generate_monthly_orders_csv(int $year, int $month): string
{
    $pdo   = get_pdo();
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

    $stmt = $pdo->prepare(
        "SELECT created_at, order_number, type, payment_status, total_eur, customer_name
           FROM orders
          WHERE payment_status IN ('paid', 'refunded')
            AND created_at >= ?
            AND created_at <= ?
          ORDER BY created_at ASC"
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $type_labels = [
        'physical' => 'purchase',
        'donation' => 'donation',
        'ticket'   => 'ticket',
    ];

    $buf = fopen('php://temp', 'r+');
    fputcsv($buf, ['Date', 'Order Number', 'Type', 'Payment Status', 'Amount (EUR)', 'Customer Name']);

    foreach ($rows as $row) {
        fputcsv($buf, [
            substr($row['created_at'], 0, 10),
            $row['order_number'],
            $type_labels[$row['type']] ?? $row['type'],
            $row['payment_status'],
            number_format((float) $row['total_eur'], 2, '.', ''),
            $row['customer_name'],
        ]);
    }

    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);

    return "\xEF\xBB\xBF" . $csv;
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Reports/MonthlyOrdersReportTest.php --testdox
```

Expected: all tests PASS (or SKIPPED if no DB configured locally — that's fine).

- [ ] **Step 5: Commit**

```bash
git add includes/reports/monthly-orders.php tests/Reports/MonthlyOrdersReportTest.php
git commit -m "feat: add generate_monthly_orders_csv() with tests"
```

---

### Task 2: Create the admin download page

**Files:**
- Create: `admin/monthly-report.php`

- [ ] **Step 1: Create `admin/monthly-report.php`**

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/reports/monthly-orders.php';

$page_title_admin = 'Месечен отчет';
$active_nav       = 'monthly-report';

admin_require_login();

// Default to previous month
$default_year  = (int) date('Y', strtotime('first day of last month'));
$default_month = (int) date('n', strtotime('first day of last month'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify() || (http_response_code(400) && exit('Invalid token'));

    $year  = (int) ($_POST['year']  ?? $default_year);
    $month = (int) ($_POST['month'] ?? $default_month);

    if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
        http_response_code(400);
        exit('Invalid date.');
    }

    $csv      = generate_monthly_orders_csv($year, $month);
    $filename = sprintf('report-%04d-%02d.csv', $year, $month);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-content">
  <h1 class="admin-h1">Месечен отчет</h1>
  <p style="color:#666;margin-bottom:24px;">Изтеглете CSV с платените и върнати поръчки за избран месец.</p>

  <form method="post" action="/admin/monthly-report.php">
    <?= csrf_field() ?>

    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <label style="display:block;font-weight:600;margin-bottom:4px;">Месец</label>
        <select name="month" style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;">
          <?php
          $month_names = [
            1 => 'Януари', 2 => 'Февруари', 3 => 'Март',     4 => 'Април',
            5 => 'Май',    6 => 'Юни',      7 => 'Юли',      8 => 'Август',
            9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
          ];
          foreach ($month_names as $num => $name):
          ?>
            <option value="<?= $num ?>" <?= $num === $default_month ? 'selected' : '' ?>>
              <?= h($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display:block;font-weight:600;margin-bottom:4px;">Година</label>
        <select name="year" style="padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;">
          <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $default_year ? 'selected' : '' ?>>
              <?= $y ?>
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <div>
        <button type="submit"
                style="padding:8px 20px;background:#1a56db;color:#fff;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600;">
          Изтегли CSV
        </button>
      </div>
    </div>
  </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

- [ ] **Step 2: Run full test suite to confirm nothing broken**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all existing tests PASS. New tests PASS (or SKIPPED if no local DB).

- [ ] **Step 3: Commit**

```bash
git add admin/monthly-report.php
git commit -m "feat: add admin monthly orders report download page"
```

---

### Task 3: Create the cron script and protect the cron directory

**Files:**
- Create: `cron/monthly-report-cron.php`
- Create: `cron/.htaccess`

- [ ] **Step 1: Create `cron/.htaccess`**

```apache
Require all denied
```

- [ ] **Step 2: Create `cron/monthly-report-cron.php`**

```php
<?php
/**
 * Monthly orders report cron script.
 * Run via cPanel cron: 0 6 1 * *
 * Command: /usr/local/bin/php /home/detelinavasileva/public_html/oddminds.org/cron/monthly-report-cron.php
 */

// Ensure CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/includes/db.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/reports/monthly-orders.php';

// Previous calendar month
$ts    = strtotime('first day of last month');
$year  = (int) date('Y', $ts);
$month = (int) date('n', $ts);

$month_names_bg = [
    1 => 'Януари', 2 => 'Февруари', 3 => 'Март',     4 => 'Април',
    5 => 'Май',    6 => 'Юни',      7 => 'Юли',      8 => 'Август',
    9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
];

$month_name = $month_names_bg[$month];
$label      = "{$month_name} {$year}";
$filename   = sprintf('report-%04d-%02d.csv', $year, $month);

$csv     = generate_monthly_orders_csv($year, $month);
$tmp     = sys_get_temp_dir() . '/' . $filename;

if (file_put_contents($tmp, $csv) === false) {
    error_log("[monthly-report-cron] Failed to write temp file: {$tmp}");
    exit(1);
}

// Count data rows (subtract BOM header line)
$lines      = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
$row_count  = max(0, count($lines) - 1); // exclude header

$subject = "Месечен отчет — {$label}";
$body    = "<p>Здравей,</p>
<p>Прилагаме месечния отчет за поръчки за <strong>{$label}</strong>.</p>
<p>Брой поръчки (платени / върнати): <strong>{$row_count}</strong></p>
<p>Поздрави,<br>Фондация Различни умове</p>";

$ok = send_mail(
    'detelina@oddminds.org',
    $subject,
    $body,
    '',
    [['path' => $tmp, 'name' => $filename]]
);

unlink($tmp);

if ($ok) {
    echo "[monthly-report-cron] Report for {$label} sent successfully ({$row_count} rows).\n";
} else {
    error_log("[monthly-report-cron] Failed to send report email for {$label}.");
    exit(1);
}
```

- [ ] **Step 3: Run full test suite to confirm nothing broken**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests PASS.

- [ ] **Step 4: Commit**

```bash
git add cron/monthly-report-cron.php cron/.htaccess
git commit -m "feat: add monthly report cron script"
```

---

### Task 4: Add nav link to admin sidebar

**Files:**
- Modify: `admin/includes/admin-header.php`

- [ ] **Step 1: Add nav link after "Поръчки"**

In `admin/includes/admin-header.php`, find this block:

```php
      <a href="/admin/orders.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'orders' ? 'active' : '' ?>">
        Поръчки
      </a>
      <?php endif; ?>
```

Replace with:

```php
      <a href="/admin/orders.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'orders' ? 'active' : '' ?>">
        Поръчки
      </a>
      <a href="/admin/monthly-report.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'monthly-report' ? 'active' : '' ?>">
        Месечен отчет
      </a>
      <?php endif; ?>
```

- [ ] **Step 2: Run full test suite**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests PASS.

- [ ] **Step 3: Commit**

```bash
git add admin/includes/admin-header.php
git commit -m "feat: add Monthly Report link to admin nav"
```

---

## cPanel Cron Setup (manual step — server only)

After deploying, add this cron job in cPanel:

- **Schedule:** `0 6 1 * *` (06:00 on the 1st of every month)
- **Command:** `/usr/local/bin/php /home/detelinavasileva/public_html/oddminds.org/cron/monthly-report-cron.php`

This is a one-time manual action in the cPanel interface — not part of the git deploy.
