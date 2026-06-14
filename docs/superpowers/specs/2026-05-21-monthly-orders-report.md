# Monthly Orders Report — Design Spec

**Date:** 2026-05-21
**Status:** Approved

---

## Overview

Automated monthly report of all paid/refunded orders emailed to `detelina@oddminds.org` on the 1st of each month. Also available on demand via an admin page.

---

## Scope

**Included orders:** all three types — `physical`, `donation`, `ticket`
**Included payment statuses:** `paid`, `refunded`
**Excluded:** `pending`, `cancelled`, and any other status

---

## Components

### 1. `includes/reports/monthly-orders.php`

Shared report logic. Exposes one function:

```php
function generate_monthly_orders_csv(int $year, int $month): string
```

- Queries `orders` WHERE `payment_status IN ('paid', 'refunded')` AND `created_at` falls within the given month
- Returns a UTF-8 CSV string with BOM (so Excel opens it correctly)
- Columns in order: `Date`, `Order Number`, `Type`, `Payment Status`, `Amount (EUR)`, `Customer Name`
- Date format: `YYYY-MM-DD`
- Type values: `physical` → `purchase`, `donation` → `donation`, `ticket` → `ticket`
- Amount: two decimal places, no currency symbol
- Rows ordered by `created_at ASC`

### 2. `admin/monthly-report.php`

Admin page accessible at `/admin/monthly-report.php`.

- Requires `admin_require_login()`
- Uses standard admin layout (`admin-header.php`, `admin-footer.php`)
- UI: year/month dropdowns defaulting to the previous calendar month, "Download CSV" button
- On POST: calls `generate_monthly_orders_csv()`, outputs with headers:
  - `Content-Type: text/csv; charset=UTF-8`
  - `Content-Disposition: attachment; filename="report-YYYY-MM.csv"`
- CSRF protected (`csrf_verify()` on POST)

### 3. `cron/monthly-report-cron.php`

Standalone PHP script called by cPanel cron.

- No web output — CLI only
- Generates the previous calendar month's CSV via `generate_monthly_orders_csv()`
- Writes CSV to a temp file (`sys_get_temp_dir()/report-YYYY-MM.csv`)
- Calls `send_mail()` with:
  - `$to`: `detelina@oddminds.org`
  - `$subject`: `Месечен отчет — [Bulgarian month name] YYYY` (e.g. `Месечен отчет — Май 2026`)
  - `$body_html`: brief HTML body noting the month and order count
  - `$attachments`: `[['path' => $tmp_path, 'name' => 'report-YYYY-MM.csv']]`
- Deletes the temp file after sending
- Logs success/failure to PHP error log

**cPanel cron schedule:** `0 6 1 * *` (06:00 on the 1st of every month)

---

## Admin Navigation

Add a "Месечен отчет" link to the admin sidebar under the existing reports/orders section.

---

## Security

- Admin page: `admin_require_login()` + CSRF on POST
- Cron script: no authentication needed (not web-accessible — lives in `cron/` directory with `.htaccess` deny all)

---

## Testing

- Unit test for `generate_monthly_orders_csv()`: seed orders with mixed statuses and types, assert correct rows, correct columns, correct CSV format
- Manual test: download report from admin page, open in Excel, verify BOM and encoding

---

## Files Changed / Created

| File | Action |
|---|---|
| `includes/reports/monthly-orders.php` | Create |
| `admin/monthly-report.php` | Create |
| `cron/monthly-report-cron.php` | Create |
| `cron/.htaccess` | Create (deny all web access) |
| `admin/includes/admin-nav.php` (or equivalent) | Edit — add nav link |
