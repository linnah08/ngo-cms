# Error Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send email notifications when 5XX errors occur on production, with configurable frequency (immediate / daily digest / weekly digest) and editable email templates.

**Architecture:** A new `includes/error-alerts.php` captures errors into a DB table and sends immediately or batches for cron. Both error handlers in `config.php` call `error_alert_capture()` after logging. Email content uses the existing `email_tpl_get()` system. The admin UI at `admin/error-alerts.php` stores three settings (`enabled`, `email`, `frequency`) in the encrypted `settings` table.

**Tech Stack:** PHP 8.4, PDO/MySQL, PHPMailer via `send_mail()`, PHPUnit 13, existing `setting_get()`/`setting_set()` helpers, existing `render_email()`/`email_tpl_get()` helpers.

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `includes/error-alerts.php` | **Create** | `error_alert_ensure_table()`, `error_alert_capture()`, `error_alert_send_immediate()` |
| `includes/emails/error-alert.php` | **Create** | Per-error email PHP template |
| `includes/emails/error-digest.php` | **Create** | Digest email PHP template |
| `includes/email-templates.php` | **Modify** | Add `error-alert` and `error-digest` to `_email_tpl_defaults()` |
| `config.php` | **Modify** | Call `error_alert_capture()` from both error handlers |
| `admin/error-alerts.php` | **Create** | Admin config UI (enable, email, frequency, test button) |
| `admin/send-error-digest.php` | **Create** | CLI cron script that sends daily/weekly digests |
| `admin/email-templates.php` | **Modify** | Add two entries to `$templates` sidebar list |
| `admin/includes/admin-header.php` | **Modify** | Add nav link "Известия за грешки" |
| `tests/ErrorAlertsTest.php` | **Create** | PHPUnit tests for capture and flood-protection logic |

---

## Task 1: Core capture library + tests

**Files:**
- Create: `includes/error-alerts.php`
- Create: `tests/ErrorAlertsTest.php`

- [ ] **Step 1.1: Write the failing tests**

Create `tests/ErrorAlertsTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ErrorAlertsTest extends TestCase
{
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) {
            self::markTestSkipped('DB not available — skipping error-alerts tests.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/error-alerts.php';
        self::$pdo = get_pdo();
        self::$pdo->exec("DELETE FROM error_alerts WHERE error_class = 'TestCapture'");
    }

    public function testEnsureTableCreatesTable(): void
    {
        error_alert_ensure_table();
        $result = self::$pdo->query("SHOW TABLES LIKE 'error_alerts'")->fetchColumn();
        $this->assertSame('error_alerts', $result);
    }

    public function testCaptureInsertsRowWhenEnabled(): void
    {
        setting_set('error_alert_enabled',   '1');
        setting_set('error_alert_email',     'test@example.com');
        setting_set('error_alert_frequency', 'daily');

        error_alert_capture('TestCapture', 'Test message', '/some/file.php', 42);

        $row = self::$pdo->query(
            "SELECT * FROM error_alerts WHERE error_class = 'TestCapture' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('Test message', $row['message']);
        $this->assertSame('/some/file.php', $row['file']);
        $this->assertSame(42, (int)$row['line']);
        $this->assertNull($row['sent_at']);
    }

    public function testCaptureDoesNothingWhenDisabled(): void
    {
        setting_set('error_alert_enabled', '0');

        $before = (int) self::$pdo->query(
            "SELECT COUNT(*) FROM error_alerts WHERE error_class = 'TestCapture'"
        )->fetchColumn();

        error_alert_capture('TestCapture', 'Should not insert', '/file.php', 1);

        $after = (int) self::$pdo->query(
            "SELECT COUNT(*) FROM error_alerts WHERE error_class = 'TestCapture'"
        )->fetchColumn();

        $this->assertSame($before, $after);
    }

    public function testMessageTruncatedAt2000Chars(): void
    {
        setting_set('error_alert_enabled',   '1');
        setting_set('error_alert_email',     'test@example.com');
        setting_set('error_alert_frequency', 'daily');

        error_alert_capture('TestCapture', str_repeat('x', 3000), '/file.php', 1);

        $row = self::$pdo->query(
            "SELECT message FROM error_alerts WHERE error_class = 'TestCapture' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertLessThanOrEqual(2000, mb_strlen($row['message']));
    }

    public function testFloodProtectionSkipsSendForSameHashWithin5Min(): void
    {
        error_alert_ensure_table();
        self::$pdo->prepare(
            "INSERT INTO error_alerts (error_class, message, file, line) VALUES ('TestCapture', 'Flood test', '/f.php', 7)"
        )->execute();
        $id  = (int) self::$pdo->lastInsertId();
        $row = self::$pdo->query("SELECT * FROM error_alerts WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);

        // Prime the flood guard so it looks like this error was just sent
        $hash = md5('TestCapture' . 'Flood test' . '/f.php' . '7');
        setting_set('error_alert_last_hash',    $hash);
        setting_set('error_alert_last_hash_at', date('Y-m-d H:i:s'));

        error_alert_send_immediate($row, 'test@example.com');

        // sent_at must still be NULL — send was suppressed
        $sentAt = self::$pdo->query("SELECT sent_at FROM error_alerts WHERE id = $id")->fetchColumn();
        $this->assertNull($sentAt);

        // Cleanup flood guard
        setting_set('error_alert_last_hash',    '');
        setting_set('error_alert_last_hash_at', '');
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pdo)) return;
        self::$pdo->exec("DELETE FROM error_alerts WHERE error_class = 'TestCapture'");
        setting_set('error_alert_enabled',   '0');
        setting_set('error_alert_email',     '');
        setting_set('error_alert_frequency', 'immediate');
    }
}
```

- [ ] **Step 1.2: Run tests to confirm they fail**

```bash
php vendor/bin/phpunit tests/ErrorAlertsTest.php -v
```

Expected: errors like `Call to undefined function error_alert_capture()` — confirms the tests are live.

- [ ] **Step 1.3: Implement `includes/error-alerts.php`**

Create `includes/error-alerts.php`:

```php
<?php

function error_alert_ensure_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    if (!function_exists('get_pdo')) {
        require_once __DIR__ . '/../admin/includes/db.php';
    }
    get_pdo()->exec("
        CREATE TABLE IF NOT EXISTS error_alerts (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            error_class VARCHAR(100)  NOT NULL DEFAULT '',
            message     TEXT          NOT NULL,
            file        VARCHAR(500)  NOT NULL DEFAULT '',
            line        INT           NOT NULL DEFAULT 0,
            url         VARCHAR(2000) NOT NULL DEFAULT '',
            user_agent  VARCHAR(500)  NOT NULL DEFAULT '',
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at     DATETIME      DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ensured = true;
}

function error_alert_capture(string $error_class, string $message, string $file, int $line): void
{
    try {
        if (!function_exists('get_pdo')) {
            require_once __DIR__ . '/../admin/includes/db.php';
        }
        if (!function_exists('setting_get')) {
            require_once __DIR__ . '/settings.php';
        }

        if (setting_get('error_alert_enabled') !== '1') return;
        $email = setting_get('error_alert_email');
        if ($email === '') return;

        $url        = $_SERVER['REQUEST_URI']     ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $message    = mb_substr($message, 0, 2000);

        error_alert_ensure_table();

        $stmt = get_pdo()->prepare("
            INSERT INTO error_alerts (error_class, message, file, line, url, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$error_class, $message, $file, $line, $url, $user_agent]);
        $id  = (int) get_pdo()->lastInsertId();
        $row = get_pdo()->query("SELECT * FROM error_alerts WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);

        if (setting_get('error_alert_frequency', 'immediate') === 'immediate') {
            error_alert_send_immediate($row, $email);
        }
    } catch (\Throwable $e) {
        error_log('error_alert_capture: ' . $e->getMessage());
    }
}

function error_alert_send_immediate(array $row, string $email): void
{
    // Flood protection: skip if the same error was sent within the last 5 minutes
    $hash    = md5(($row['error_class'] ?? '') . ($row['message'] ?? '') . ($row['file'] ?? '') . (string)($row['line'] ?? 0));
    $lastHash = setting_get('error_alert_last_hash');
    $lastAt   = setting_get('error_alert_last_hash_at');
    if ($lastHash === $hash && $lastAt !== '' && (time() - (int)strtotime($lastAt)) < 300) {
        return;
    }

    if (!function_exists('render_email')) {
        require_once __DIR__ . '/mailer.php';
    }
    if (!function_exists('email_tpl_subject')) {
        require_once __DIR__ . '/email-templates.php';
    }

    $vars = [
        'error_class' => $row['error_class'] ?? '',
        'url'         => $row['url']         ?? '',
        'timestamp'   => $row['created_at']  ?? date('Y-m-d H:i:s'),
    ];
    $subject = email_tpl_subject('error-alert', 'bg', $vars);
    $body    = render_email('error-alert', ['row' => $row]);

    if (send_mail($email, $subject, $body)) {
        if (isset($row['id']) && (int)$row['id'] > 0) {
            get_pdo()->prepare('UPDATE error_alerts SET sent_at = NOW() WHERE id = ?')
                     ->execute([(int)$row['id']]);
        }
        setting_set('error_alert_last_hash',    $hash);
        setting_set('error_alert_last_hash_at', date('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 1.4: Run tests — expect pass**

```bash
php vendor/bin/phpunit tests/ErrorAlertsTest.php -v
```

Expected: 5 tests, 5 assertions — all green. The flood-protection test may show as skipped/passed; confirm `testFloodProtectionSkipsSendForSameHashWithin5Min` passes without errors.

- [ ] **Step 1.5: Run full test suite to check no regressions**

```bash
php vendor/bin/phpunit
```

Expected: all previously-passing tests still pass.

- [ ] **Step 1.6: Commit**

```bash
git add includes/error-alerts.php tests/ErrorAlertsTest.php
git commit -m "feat: add error_alert_capture and send_immediate with flood protection"
```

---

## Task 2: Email template defaults + PHP template files

**Files:**
- Modify: `includes/email-templates.php`
- Create: `includes/emails/error-alert.php`
- Create: `includes/emails/error-digest.php`

- [ ] **Step 2.1: Add defaults to `_email_tpl_defaults()` in `includes/email-templates.php`**

Inside `_email_tpl_defaults()`, after the last existing array entry (after the `'pledge-reversed-customer'` block, before the closing `];`), add:

```php
        'error-alert' => [
            'subject_bg' => '[Odd Minds] Грешка на сайта — {{error_class}}',
            'intro_bg'   => '<h2>Грешка на сайта</h2>'
                          . '<p>Засечена е нова грешка на <strong>{{timestamp}}</strong>.</p>',
            'outro_bg'   => '<p>Провери логовете на сървъра за повече детайли.</p>',
            'subject_en' => '[Odd Minds] Site error — {{error_class}}',
            'intro_en'   => '<h2>Site error detected</h2>'
                          . '<p>A new error was captured at <strong>{{timestamp}}</strong>.</p>',
            'outro_en'   => '<p>Check the server logs for more details.</p>',
        ],

        'error-digest' => [
            'subject_bg' => '[Odd Minds] {{count}} грешки — {{period}}',
            'intro_bg'   => '<h2>Обобщение на грешките</h2>'
                          . '<p>За периода <strong>{{from_date}} – {{to_date}}</strong> са засечени <strong>{{count}}</strong> грешки.</p>',
            'outro_bg'   => '<p>Провери логовете на сървъра за повече детайли.</p>',
            'subject_en' => '[Odd Minds] {{count}} errors — {{period}}',
            'intro_en'   => '<h2>Error digest</h2>'
                          . '<p>For the period <strong>{{from_date}} – {{to_date}}</strong>, <strong>{{count}}</strong> errors were captured.</p>',
            'outro_en'   => '<p>Check the server logs for more details.</p>',
        ],
```

- [ ] **Step 2.2: Create `includes/emails/error-alert.php`**

```php
<?php
// Available via extract(): $row (array — one row from error_alerts table)
$_tpl = email_tpl_get('error-alert', 'bg', [
    'error_class' => htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8'),
    'url'         => htmlspecialchars($row['url']         ?? '', ENT_QUOTES, 'UTF-8'),
    'timestamp'   => htmlspecialchars($row['created_at']  ?? '', ENT_QUOTES, 'UTF-8'),
]);
?>
<?= $_tpl['intro'] ?>

<div class="box" style="font-family:monospace;font-size:.85rem;line-height:1.6;word-break:break-word;">
  <strong>Тип:</strong> <?= htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Съобщение:</strong><br>
  <?= nl2br(htmlspecialchars(mb_substr($row['message'] ?? '', 0, 500), ENT_QUOTES, 'UTF-8')) ?><br><br>
  <strong>Файл:</strong> <?= htmlspecialchars($row['file'] ?? '', ENT_QUOTES, 'UTF-8') ?>:<?= (int)($row['line'] ?? 0) ?><br>
  <?php if (!empty($row['url'])): ?>
  <strong>URL:</strong> <?= htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8') ?><br>
  <?php endif; ?>
  <strong>Дата/час:</strong> <?= htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
</div>

<?= $_tpl['outro'] ?>
```

- [ ] **Step 2.3: Create `includes/emails/error-digest.php`**

```php
<?php
// Available via extract(): $rows (array of error_alerts rows), $period, $from_date, $to_date
$_count = count($rows);
$_tpl = email_tpl_get('error-digest', 'bg', [
    'count'     => $_count,
    'period'    => htmlspecialchars($period    ?? '', ENT_QUOTES, 'UTF-8'),
    'from_date' => htmlspecialchars($from_date ?? '', ENT_QUOTES, 'UTF-8'),
    'to_date'   => htmlspecialchars($to_date   ?? '', ENT_QUOTES, 'UTF-8'),
]);
?>
<?= $_tpl['intro'] ?>

<table>
  <thead>
    <tr>
      <th>Дата/час</th>
      <th>Тип</th>
      <th>Съобщение</th>
      <th>Файл:ред</th>
      <th>URL</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $_r): ?>
    <tr>
      <td style="white-space:nowrap;font-size:.8rem;"><?= htmlspecialchars($_r['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-size:.8rem;"><?= htmlspecialchars($_r['error_class'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars(mb_substr($_r['message'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-family:monospace;font-size:.78rem;white-space:nowrap;"><?= htmlspecialchars(basename($_r['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?>:<?= (int)($_r['line'] ?? 0) ?></td>
      <td style="font-size:.78rem;"><?= htmlspecialchars(mb_substr($_r['url'] ?? '', 0, 60), ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?= $_tpl['outro'] ?>
```

- [ ] **Step 2.4: Verify templates render without errors**

Add a quick test to confirm `render_email('error-alert', ...)` doesn't throw. Run from CLI:

```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = __DIR__;
\$_SERVER['REQUEST_URI'] = '/';
\$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'config.php';
require_once 'includes/mailer.php';
require_once 'includes/email-templates.php';
\$row = ['error_class'=>'RuntimeException','message'=>'Test','file'=>'/index.php','line'=>1,'url'=>'/','user_agent'=>'','created_at'=>date('Y-m-d H:i:s'),'sent_at'=>null];
\$html = render_email('error-alert', ['row' => \$row]);
echo strlen(\$html) > 500 ? 'OK — rendered ' . strlen(\$html) . ' chars' . PHP_EOL : 'FAIL' . PHP_EOL;
"
```

Expected output: `OK — rendered XXXX chars`

- [ ] **Step 2.5: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 2.6: Commit**

```bash
git add includes/email-templates.php includes/emails/error-alert.php includes/emails/error-digest.php
git commit -m "feat: add error-alert and error-digest email templates with defaults"
```

---

## Task 3: Hook error capture into `config.php`

**Files:**
- Modify: `config.php`

- [ ] **Step 3.1: Update `set_exception_handler` in `config.php`**

The existing handler (lines 24–40) currently ends with `exit(1)`. Add the capture call right after `_om_log(...)` and before `http_response_code(500)`:

Old:
```php
set_exception_handler(function(Throwable $e): void {
    _om_log(
        get_class($e),
        $e->getMessage() . "\n" . $e->getTraceAsString(),
        $e->getFile(),
        $e->getLine()
    );
    http_response_code(500);
```

New:
```php
set_exception_handler(function(Throwable $e): void {
    _om_log(
        get_class($e),
        $e->getMessage() . "\n" . $e->getTraceAsString(),
        $e->getFile(),
        $e->getLine()
    );
    try {
        if (!function_exists('error_alert_capture')) {
            @require_once __DIR__ . '/includes/error-alerts.php';
        }
        if (function_exists('error_alert_capture')) {
            error_alert_capture(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        }
    } catch (\Throwable) {}
    http_response_code(500);
```

- [ ] **Step 3.2: Update `register_shutdown_function` in `config.php`**

The existing shutdown handler (lines 43–53). Add capture after `_om_log('FATAL', ...)`:

Old:
```php
register_shutdown_function(function(): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        _om_log('FATAL', $e['message'], $e['file'], $e['line']);
        if (!headers_sent()) {
```

New:
```php
register_shutdown_function(function(): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        _om_log('FATAL', $e['message'], $e['file'], $e['line']);
        try {
            if (!function_exists('error_alert_capture')) {
                @require_once (__SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/includes/error-alerts.php';
            }
            if (function_exists('error_alert_capture')) {
                error_alert_capture('FATAL', $e['message'], $e['file'], $e['line']);
            }
        } catch (\Throwable) {}
        if (!headers_sent()) {
```

**Important:** Fix `__SERVER` to `$_SERVER` in the line above — that is the correct variable name (`$_SERVER['DOCUMENT_ROOT'] ?? __DIR__`).

- [ ] **Step 3.3: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green — the config.php change is a no-op when no exception fires during tests.

- [ ] **Step 3.4: Commit**

```bash
git add config.php
git commit -m "feat: hook error_alert_capture into config.php exception and shutdown handlers"
```

---

## Task 4: Admin email-templates additions

**Files:**
- Modify: `admin/email-templates.php`

- [ ] **Step 4.1: Add the two template entries to `$templates`**

In `admin/email-templates.php`, add after the `'pledge-reversed-customer'` entry and before the closing `];` of `$templates`:

```php
    'error-alert' => [
        'label' => 'Известие за грешка (единично)',
        'vars'  => ['{{error_class}}', '{{url}}', '{{timestamp}}'],
    ],
    'error-digest' => [
        'label' => 'Обобщение на грешки (дневно/седмично)',
        'vars'  => ['{{count}}', '{{period}}', '{{from_date}}', '{{to_date}}'],
    ],
```

- [ ] **Step 4.2: Verify the page loads without errors**

```bash
php -l admin/email-templates.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4.3: Commit**

```bash
git add admin/email-templates.php
git commit -m "feat: add error-alert and error-digest to email-templates admin sidebar"
```

---

## Task 5: Admin config UI (`admin/error-alerts.php`)

**Files:**
- Create: `admin/error-alerts.php`

- [ ] **Step 5.1: Create `admin/error-alerts.php`**

```php
<?php
$page_title_admin = 'Известия за грешки';
$active_nav       = 'error-alerts';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/error-alerts.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'test') {
        $email = setting_get('error_alert_email');
        if ($email === '') {
            flash_set('error', 'Добави имейл адрес преди да изпратиш тестово съобщение.');
        } else {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
            error_alert_ensure_table();
            $test_row = [
                'id'          => 0,
                'error_class' => 'TestException',
                'message'     => 'Това е тестово известие за грешка от Odd Minds Admin.',
                'file'        => '/var/www/html/index.php',
                'line'        => 42,
                'url'         => '/test-page',
                'user_agent'  => 'Admin test',
                'created_at'  => date('Y-m-d H:i:s'),
                'sent_at'     => null,
            ];
            // Clear flood guard so test always sends
            setting_set('error_alert_last_hash',    '');
            setting_set('error_alert_last_hash_at', '');
            error_alert_send_immediate($test_row, $email);
            flash_set('success', 'Тестов имейл е изпратен на ' . $email . '.');
        }
    } else {
        $enabled  = isset($_POST['enabled']) ? '1' : '0';
        $raw_email = trim($_POST['email'] ?? '');
        $email    = filter_var($raw_email, FILTER_VALIDATE_EMAIL) !== false ? $raw_email : '';
        $freq_map = ['immediate' => true, 'daily' => true, 'weekly' => true];
        $freq     = isset($freq_map[$_POST['frequency'] ?? '']) ? $_POST['frequency'] : 'immediate';

        setting_set('error_alert_enabled',   $enabled);
        setting_set('error_alert_email',     $email);
        setting_set('error_alert_frequency', $freq);
        flash_set('success', 'Настройките са запазени.');
    }
    header('Location: /admin/error-alerts.php');
    exit;
}

$enabled   = setting_get('error_alert_enabled',   '0');
$email_val = setting_get('error_alert_email',     '');
$freq      = setting_get('error_alert_frequency', 'immediate');
$flash     = flash_get();

$doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$cron_cmd = "0 8 * * * php {$doc_root}/admin/send-error-digest.php >> {$doc_root}/logs/error-digest-cron.log 2>&1";

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Известия за грешки</h1>
</div>

<?php foreach ($flash as $f): ?>
<div class="admin-alert admin-alert--<?= $f['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:1.5rem;">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="max-width:600px;">
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;">
    <form method="POST" id="alertForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">

      <div class="form-group" style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
        <label style="margin:0;font-weight:600;cursor:pointer;" for="enabled">
          <input type="checkbox" name="enabled" id="enabled" value="1"
                 <?= $enabled === '1' ? 'checked' : '' ?>
                 style="width:1rem;height:1rem;margin-right:.4rem;cursor:pointer;">
          Активирай известията за грешки
        </label>
      </div>

      <div class="form-group">
        <label for="alert_email">Имейл за известия</label>
        <input type="email" name="email" id="alert_email"
               value="<?= h($email_val) ?>"
               placeholder="admin@example.com"
               style="width:100%;box-sizing:border-box;">
      </div>

      <div class="form-group">
        <label>Честота на известията</label>
        <div style="display:flex;flex-direction:column;gap:.6rem;margin-top:.4rem;">
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="immediate"
                   <?= $freq === 'immediate' ? 'checked' : '' ?>>
            При всяка грешка (незабавно)
          </label>
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="daily"
                   <?= $freq === 'daily' ? 'checked' : '' ?>>
            Веднъж дневно — обобщение в 08:00
          </label>
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="weekly"
                   <?= $freq === 'weekly' ? 'checked' : '' ?>>
            Веднъж седмично — обобщение в понеделник в 08:00
          </label>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;align-items:center;margin-top:1.5rem;">
        <button type="submit" class="btn btn--primary">Запази</button>
        <button type="submit" form="testForm" class="btn btn--outline">Изпрати тестов имейл</button>
      </div>
    </form>

    <form method="POST" id="testForm" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
    </form>
  </div>

  <?php if ($freq === 'daily' || $freq === 'weekly'): ?>
  <div style="background:#e4f0f5;border:1px solid #b3d4e0;border-radius:var(--radius-lg);padding:1.25rem 1.5rem;margin-top:1.5rem;">
    <p style="margin:0 0 .75rem;font-weight:600;font-size:.9rem;">Настройка на Cron</p>
    <p style="margin:0 0 .75rem;font-size:.85rem;color:var(--text-muted);">
      Добави следния ред в crontab на сървъра (чрез <code>crontab -e</code>):
    </p>
    <code style="display:block;background:#fff;border:1px solid #b3d4e0;border-radius:6px;padding:.75rem 1rem;font-size:.78rem;word-break:break-all;">
      <?= h($cron_cmd) ?>
    </code>
    <p style="margin:.75rem 0 0;font-size:.8rem;color:var(--text-muted);">
      <?= $freq === 'daily'
          ? 'Скриптът се изпълнява всеки ден в 08:00 и изпраща грешките от последните 24 часа.'
          : 'Скриптът се изпълнява всеки ден в 08:00; обобщение се изпраща само в понеделник.' ?>
    </p>
  </div>
  <?php endif; ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

- [ ] **Step 5.2: Syntax check**

```bash
php -l admin/error-alerts.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5.3: Commit**

```bash
git add admin/error-alerts.php
git commit -m "feat: add error-alerts admin config UI"
```

---

## Task 6: Cron digest script

**Files:**
- Create: `admin/send-error-digest.php`

- [ ] **Step 6.1: Create `admin/send-error-digest.php`**

```php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$root = dirname(__DIR__);
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $root;
}

require_once $root . '/config.php';
require_once $root . '/admin/includes/db.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/email-templates.php';
require_once $root . '/includes/error-alerts.php';

if (setting_get('error_alert_enabled') !== '1') {
    echo "Alerts disabled — nothing to do.\n";
    exit(0);
}

$email = setting_get('error_alert_email');
if ($email === '') {
    echo "No alert email configured — nothing to do.\n";
    exit(0);
}

$freq = setting_get('error_alert_frequency', 'immediate');
if ($freq === 'immediate') {
    echo "Frequency is 'immediate' — cron not needed.\n";
    exit(0);
}

// Weekly: only send on Monday (date('N') === '1')
if ($freq === 'weekly' && date('N') !== '1') {
    echo "Weekly digest: today is not Monday — skipping.\n";
    exit(0);
}

$interval = $freq === 'daily' ? 'INTERVAL 1 DAY' : 'INTERVAL 7 DAY';
error_alert_ensure_table();

$stmt = get_pdo()->query("
    SELECT * FROM error_alerts
    WHERE sent_at IS NULL
      AND created_at >= NOW() - {$interval}
    ORDER BY created_at ASC
");
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No unsent errors in period — skipping.\n";
    exit(0);
}

$count     = count($rows);
$from_date = date('d.m.Y', strtotime($rows[0]['created_at']));
$to_date   = date('d.m.Y');
$period    = $freq === 'daily' ? 'последните 24 часа' : 'последната седмица';

$subject = email_tpl_subject('error-digest', 'bg', [
    'count'     => $count,
    'period'    => $period,
    'from_date' => $from_date,
    'to_date'   => $to_date,
]);
$body = render_email('error-digest', [
    'rows'      => $rows,
    'period'    => $period,
    'from_date' => $from_date,
    'to_date'   => $to_date,
]);

if (send_mail($email, $subject, $body)) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    get_pdo()->exec("UPDATE error_alerts SET sent_at = NOW() WHERE id IN ({$ids})");
    echo date('Y-m-d H:i:s') . " — Digest sent: {$count} error(s) to {$email}\n";
} else {
    echo date('Y-m-d H:i:s') . " — ERROR: Failed to send digest email\n";
    exit(1);
}
```

- [ ] **Step 6.2: Syntax check**

```bash
php -l admin/send-error-digest.php
```

Expected: `No syntax errors detected`

- [ ] **Step 6.3: Commit**

```bash
git add admin/send-error-digest.php
git commit -m "feat: add cron script for daily/weekly error digest emails"
```

---

## Task 7: Admin nav link

**Files:**
- Modify: `admin/includes/admin-header.php`

- [ ] **Step 7.1: Add nav link after the "Имейл шаблони" entry**

In `admin/includes/admin-header.php`, find the email-templates nav link (around line 122–125):

```php
      <a href="/admin/email-templates.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'email-templates' ? 'active' : '' ?>">
        Имейл шаблони
      </a>
```

Add directly after it:

```php
      <a href="/admin/error-alerts.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'error-alerts' ? 'active' : '' ?>">
        Известия за грешки
      </a>
```

- [ ] **Step 7.2: Run full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 7.3: Commit**

```bash
git add admin/includes/admin-header.php
git commit -m "feat: add error-alerts nav link to admin sidebar"
```

---

## Task 8: End-to-end smoke test

- [ ] **Step 8.1: Open the admin config page in a browser**

Visit `http://oddminds.test/admin/error-alerts.php`

Expected:
- Page loads with no PHP warnings
- Form shows enable checkbox, email field, three frequency radio buttons
- "Запази" and "Изпрати тестов имейл" buttons visible

- [ ] **Step 8.2: Configure and send a test email**

1. Check "Активирай известията за грешки"
2. Enter your real email address
3. Leave frequency on "При всяка грешка"
4. Click "Запази" — expect success flash
5. Click "Изпрати тестов имейл" — expect success flash
6. Check your inbox for a test error alert email

- [ ] **Step 8.3: Verify the email templates are editable**

Visit `http://oddminds.test/admin/email-templates.php`

Expected: "Известие за грешка (единично)" and "Обобщение на грешки (дневно/седмично)" appear in the left sidebar and are editable with TinyMCE.

- [ ] **Step 8.4: Verify cron command appears for daily/weekly**

1. On the error-alerts page, select "Веднъж дневно"
2. Click "Запази"
3. Confirm the blue cron command box appears below the form with the correct server path

- [ ] **Step 8.5: Final commit if any polish was applied**

```bash
git add -p
git commit -m "fix: error-alerts UI polish"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] DB table `error_alerts` — Task 1
- [x] Settings keys `enabled`, `email`, `frequency` — Tasks 1, 5
- [x] `error_alert_capture()` hooks into both `config.php` handlers — Task 3
- [x] Flood protection (5-min cooldown per unique error) — Task 1
- [x] `error_alert_send_immediate()` marks row `sent_at` — Task 1
- [x] `admin/send-error-digest.php` daily/weekly cron — Task 6
- [x] `error-alert` and `error-digest` template defaults — Task 2
- [x] PHP template files for both emails — Task 2
- [x] Templates editable via email-templates admin — Task 4
- [x] Admin UI with enable/email/frequency/test button — Task 5
- [x] Cron command shown in UI for daily/weekly — Task 5
- [x] Nav link in admin sidebar — Task 7
- [x] CLI-only guard on cron script — Task 6
- [x] All functions wrapped in try/catch (no secondary 500s) — Task 1, 3

**Type consistency:**
- `error_alert_capture(string, string, string, int): void` — defined Task 1, called Task 3 ✓
- `error_alert_send_immediate(array $row, string $email): void` — defined Task 1, called Tasks 1, 5 ✓
- `error_alert_ensure_table(): void` — defined Task 1, called Tasks 5, 6 ✓
- `render_email('error-alert', ['row' => $row])` — template expects `$row` (via extract) ✓
- `render_email('error-digest', ['rows' => $rows, 'period' => ..., 'from_date' => ..., 'to_date' => ...])` — template expects those vars ✓
