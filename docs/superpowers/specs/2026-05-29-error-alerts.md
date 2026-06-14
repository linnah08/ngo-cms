# Spec: 5XX Error Email Alerts

**Date:** 2026-05-29
**Status:** Approved

---

## Overview

When a 500-class error occurs on production, the admin receives an email so they can fix it quickly. The alert email address and delivery frequency (per-error, daily, weekly) are configurable from the admin panel. Email content is editable through the existing email-templates system.

---

## Data Storage

### New DB table: `error_alerts`

Stores every captured 5XX error. Created lazily on first error (idempotent `CREATE TABLE IF NOT EXISTS`).

```sql
CREATE TABLE IF NOT EXISTS error_alerts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    error_class VARCHAR(100) NOT NULL DEFAULT '',
    message    TEXT NOT NULL,
    file       VARCHAR(500) NOT NULL DEFAULT '',
    line       INT NOT NULL DEFAULT 0,
    url        VARCHAR(2000) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`sent_at = NULL` means the row has not yet been included in a digest email.

### Settings table keys (existing encrypted store)

| Key | Values | Purpose |
|-----|--------|---------|
| `error_alert_enabled` | `1` / `0` | Master on/off switch |
| `error_alert_email` | email address | Where to send notifications |
| `error_alert_frequency` | `immediate` / `daily` / `weekly` | Delivery mode |

---

## Error Capture

### Hook points in `config.php`

Both existing handlers call a new `error_alert_capture()` function **after** writing to the log file:

1. `set_exception_handler` — uncaught exceptions → calls `error_alert_capture(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())`
2. `register_shutdown_function` — fatal/parse errors → same call using `error_get_last()` data

### `error_alert_capture()` function (new file: `includes/error-alerts.php`)

```
function error_alert_capture(string $error_class, string $message, string $file, int $line): void
```

Steps:
1. Wrapped entirely in `try/catch` — if anything fails, silently `error_log()` and return. Never throw.
2. Read `error_alert_enabled` and `error_alert_email` from settings. If either is missing/off, return.
3. Capture `$_SERVER['REQUEST_URI']` and `$_SERVER['HTTP_USER_AGENT']` (empty strings if CLI).
4. Insert a row into `error_alerts`.
5. If frequency = `immediate`: call `error_alert_send_immediate()`.
6. If frequency = `daily` or `weekly`: return (cron handles sending).

### Flood protection for immediate mode

Before sending, check: has an email already been sent for the same `md5($error_class . $message . $file . $line)` in the last 5 minutes? Check via a lightweight settings key `error_alert_last_hash` + `error_alert_last_hash_at`. If the hash matches and the timestamp is < 5 min ago, skip sending (the row is still inserted).

---

## Sending Logic

### `error_alert_send_immediate(array $row): void`

- Renders `render_email('error-alert', [...vars...])` and `email_tpl_subject('error-alert', 'bg', [...vars...])`
- Calls `send_mail($email, $subject, $body)`
- Marks the row `sent_at = NOW()`
- Updates `error_alert_last_hash` and `error_alert_last_hash_at` in settings

### `admin/send-error-digest.php` (cron script)

Called by the server cron daily at 08:00. Runs in CLI context.

Logic:
1. Bootstrap: `require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php'` — uses `DOCUMENT_ROOT` env var or derives from `__DIR__`.
2. Read `error_alert_enabled`, `error_alert_email`, `error_alert_frequency`.
3. If disabled or no email: exit 0.
4. If frequency = `immediate`: exit 0 (nothing to do).
5. If frequency = `daily`: fetch all rows where `sent_at IS NULL` and `created_at >= NOW() - INTERVAL 1 DAY`.
6. If frequency = `weekly` and today is Monday: fetch rows where `sent_at IS NULL` and `created_at >= NOW() - INTERVAL 7 DAY`. If today is not Monday: exit 0.
7. If no rows found: exit 0 (no email when nothing happened).
8. Render digest email via `render_email('error-digest', [...])`.
9. Send via `send_mail()`.
10. Mark all fetched rows `sent_at = NOW()`.

**Cron line (daily, 08:00):**
```
0 8 * * * php /var/www/html/admin/send-error-digest.php >> /var/www/html/logs/error-digest-cron.log 2>&1
```
The exact path is shown in the admin UI.

---

## Email Templates

Two new entries added to the existing system in `includes/email-templates.php` and `admin/email-templates.php`.

### `error-alert` (per-error)

**Available vars:** `{{error_class}}`, `{{url}}`, `{{timestamp}}`

The body is rendered by `includes/emails/error-alert.php`:
- intro (editable)
- auto-generated box: error class, message (truncated at 500 chars), file:line, URL, timestamp
- outro (editable)

**Default subject (BG):** `[Odd Minds] Грешка на сайта — {{error_class}}`
**Default intro (BG):** `<h2>Грешка на сайта</h2><p>Засечена е нова грешка на {{timestamp}}.</p>`
**Default outro (BG):** `<p>Влез в сървъра и провери логовете за повече детайли.</p>`

English defaults mirror BG.

### `error-digest` (daily/weekly)

**Available vars:** `{{count}}`, `{{period}}`, `{{from_date}}`, `{{to_date}}`

The body is rendered by `includes/emails/error-digest.php`:
- intro (editable)
- auto-generated table: timestamp | error class | message (truncated) | file:line | URL
- outro (editable)

**Default subject (BG):** `[Odd Minds] {{count}} грешки — {{period}}`
**Default intro (BG):** `<h2>Обобщение на грешките</h2><p>За периода {{from_date}} – {{to_date}} са засечени <strong>{{count}}</strong> грешки.</p>`
**Default outro (BG):** `<p>Влез в сървъра и провери логовете за повече детайли.</p>`

---

## Admin UI

### New page: `admin/error-alerts.php`

- `admin_require_admin()` access guard
- CSRF on POST
- Fields:
  - **Enable alerts** — checkbox (default: off)
  - **Email address** — text input
  - **Frequency** — radio/select: "При всяка грешка" / "Веднъж дневно (08:00)" / "Веднъж седмично (понеделник, 08:00)"
  - **Save** button
  - **Send test email** button — POST action that inserts a fake error row and immediately triggers `error_alert_send_immediate()`, regardless of frequency setting, to verify the config works
- When frequency is `daily` or `weekly`: show a highlighted box with the exact cron command to copy
- Flash messages for save success/failure and test email sent

### `admin/email-templates.php` additions

Add to `$templates` array:
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

### Admin nav

Add "Известия за грешки" link to the admin navigation (in `admin/includes/admin-header.php`) under the Settings group (or wherever other config pages appear).

---

## Files Changed / Created

| File | Change |
|------|--------|
| `config.php` | Call `error_alert_capture()` from both error handlers |
| `includes/error-alerts.php` | **New** — `error_alert_capture()`, `error_alert_send_immediate()`, `error_alert_ensure_table()` |
| `includes/email-templates.php` | Add `error-alert` and `error-digest` defaults to `_email_tpl_defaults()` |
| `includes/emails/error-alert.php` | **New** — per-error email PHP template |
| `includes/emails/error-digest.php` | **New** — digest email PHP template |
| `admin/error-alerts.php` | **New** — admin config UI |
| `admin/send-error-digest.php` | **New** — cron digest script |
| `admin/email-templates.php` | Add two new template entries to `$templates` |
| `admin/includes/admin-header.php` | Add nav link |

---

## Security

- `admin/error-alerts.php`: `admin_require_admin()` + `csrf_verify()` on POST
- `admin/send-error-digest.php`: runs CLI only — add a guard: `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }`
- Error messages stored in DB are not echoed back to the browser in any public context
- `message` column truncated at 2000 chars on insert to prevent runaway storage from recursive errors

---

## Edge Cases

- **DB unavailable when error occurs:** `error_alert_capture()` catches the PDO exception and returns silently. The existing file log still works.
- **Mailer fails during immediate send:** caught and logged to file; the DB row remains with `sent_at = NULL` (will be picked up by the next cron run if frequency switches).
- **No errors in digest period:** cron exits without sending — no empty emails.
- **Recursive errors in the notification code:** the `try/catch` wrapper prevents any secondary 500.
- **CLI errors (e.g. cron jobs, migration scripts):** `REQUEST_URI` and `HTTP_USER_AGENT` default to empty strings; email still sends with the error details.
