# Cart & Pledge Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Save shop carts and unfinished campaign reward pledges, show a "you left something" notice to returning visitors, and send one opt-in reminder email 24h later.

**Architecture:** A `saved_carts` table keyed by a random token. A browser cookie `om_cart` links a device to its rows. One library (`includes/saved_carts.php`) owns all reads and writes. The cart endpoints and checkout call into it. An hourly cron sends reminders through the existing `email_tpl_get` / `render_email` / mailer pipeline, following the pattern of `includes/payment/unpaid_orders.php`.

**Tech Stack:** PHP 8.4, no framework, PDO (MariaDB 10.5 prod / MySQL 9 local), PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-11-27-cart-reminders-design.md`

## Global Constraints

- Work in the worktree `../oddminds-oss-wt/cart-reminders` (branch `cart-reminders`). Stage files by name. Never `git add -A`. Never `git stash`.
- Migration file `migrations/053_saved_carts.sql`. Re-check `ls migrations` first. Plain DDL, no `ADD COLUMN IF NOT EXISTS`.
- Every POST: `csrf_verify()`. Every query: `prepare()`/`execute()`. Every output: `h()`.
- Every user-facing string: `t_or(key, bg, en, $lang)`, in both languages, in plain language.
- New public-page markup uses fully inline styles for layout. The notice uses `role="status"`. The close button is a real `<button>` with `aria-label`, has a visible focus outline and is at least 44×44px.
- Settings keys: `cart_reminder_email` and `cart_reminder_notice`, values `'1'`/`'0'`, default `'0'` (both **off**).
- Timing constants in `includes/saved_carts.php`: `SAVED_CART_REMIND_AFTER_HOURS = 24`, `SAVED_CART_REMIND_MAX_AGE_DAYS = 7`, `SAVED_CART_KEEP_DAYS = 30`. Cookie `om_cart`: 30 days, HttpOnly, SameSite=Lax, Secure on https.
- Checkbox copy, exactly: BG „Изпратете ми напомняне по имейл, ако не завърша поръчката“, EN "Email me a reminder if I don't finish my order".
- Run `php vendor/bin/phpunit` at the end of every task. All tests green before commit.
- This is generic code: it lands in ngo-cms. Porting to oddminds is a separate, later step (commit trailer `Ported: ngo-cms <sha>`).

## Review Focus

1. **Second email:** a cart that was reminded, then edited, then abandoned again → no second email (`reminded_at` is never reset). Test in Task 6.
2. **Ordered from another device:** an opted-in cart whose email placed an order after `updated_at` → no email. Test in Task 6.
3. **Restore onto a non-empty cart:** the email link opened on a device with its own cart → items merged (the higher qty wins for the same product+variant+print options+design), the device keeps its own token, and a visible notice is shown. Test in Task 3.
4. **Stale items on restore:** a product deactivated, a variant deleted, stock lowered or a design file missing → dropped or capped, each named in a persistent notice. Prices are never taken from the saved copy. Test in Task 3.
5. **Token abuse:** a malformed, unknown or expired token, or a token for an `ordered_at` row → a friendly "expired" message and no data from any other row. `no-reminder` GET never changes anything. Tests in Tasks 3 and 6.

---

### Task 1: Table + core library

**Files:**
- Create: `migrations/053_saved_carts.sql`
- Create: `includes/saved_carts.php`
- Test: `tests/Cart/SavedCartsTest.php`

**Interfaces:**
- Produces (all in `includes/saved_carts.php`):
  - `saved_carts_enabled(): bool` (either setting is `'1'`); `saved_cart_email_enabled(): bool`; `saved_cart_notice_enabled(): bool`
  - `saved_cart_token_valid(string $t): bool` (exactly 64 lowercase hex)
  - `saved_cart_browser_token(bool $create): ?string`: reads `$_COOKIE['om_cart']`. If `$create` is true and the cookie is missing or invalid, it makes `bin2hex(random_bytes(32))` and sets the cookie.
  - `saved_cart_sync(PDO $pdo, array $items, string $lang): void`: no-op unless `saved_carts_enabled()` and not in pledge mode (`!empty($_SESSION['checkout_data']['_pledge_number'])`). Otherwise it upserts the shop row (`pledge_id IS NULL`) for the browser token: `items`, `lang`, `updated_at=NOW()`, `dismissed_at=NULL`. It never touches `reminded_at`.
  - `saved_cart_find(PDO $pdo, string $token): ?array` (by `token`, `items` decoded to an array)
  - `saved_cart_for_browser(PDO $pdo): array{cart: ?array, pledge: ?array}`: the newest shop row and the newest pledge row for the cookie, excluding rows with `ordered_at`, `dismissed_at` or `updated_at` older than 30 days. The shop row also needs non-empty items.
  - `saved_cart_mark_ordered(PDO $pdo, ?int $pledge_id = null): void`: with null, it marks the browser's shop row. With an id, it marks that pledge row.

- [ ] **Step 1: Write the migration.** Table `saved_carts`: `id INT UNSIGNED AUTO_INCREMENT PK`, `token CHAR(64) NOT NULL UNIQUE` (email-link token), `browser_token CHAR(64) NOT NULL` (indexed: the `om_cart` cookie value), `pledge_id INT UNSIGNED NULL UNIQUE`, `items JSON NOT NULL`, `lang CHAR(2) NOT NULL DEFAULT 'bg'`, `email VARCHAR(200) NULL`, `name VARCHAR(200) NULL`, `remind_opt_in TINYINT(1) NOT NULL DEFAULT 0`, `remind_consent_at DATETIME NULL`, `remind_consent_text VARCHAR(500) NULL`, `reminded_at`, `opted_out_at`, `ordered_at`, `dismissed_at` (all `DATETIME NULL`), `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, plus an index on `(remind_opt_in, reminded_at, updated_at)`. *(The spec's separate `browser_token` option is taken: the shop row and pledge rows share `browser_token`, and each has its own `token`.)*
- [ ] **Step 2: Write failing tests** in `tests/Cart/SavedCartsTest.php` (DB-backed, skip when `!test_db_available()`, following the `tests/Payment/UnpaidOrdersTest.php` setup/teardown pattern; settings set and restored per test):
  - `test_sync_is_noop_when_both_switches_off`: no row created.
  - `test_sync_creates_then_updates_one_row_per_browser`: two syncs, `COUNT(*)=1`, `items` equals the second array.
  - `test_sync_never_clears_reminded_at`: set `reminded_at`, sync again, still set.
  - `test_sync_clears_dismissed_at`.
  - `test_sync_skipped_in_pledge_mode`.
  - `test_for_browser_ignores_ordered_dismissed_empty_and_old_rows`.
  - `test_token_valid_rejects_short_uppercase_and_non_hex`.
- [ ] **Step 3: Run:** `php vendor/bin/phpunit tests/Cart/SavedCartsTest.php`. Expected: FAIL (function undefined).
- [ ] **Step 4: Implement** `includes/saved_carts.php` with the interfaces above. Cookie setting goes through one private helper `saved_cart_set_cookie(string $token): void`, with the `Secure` flag when `str_starts_with(SITE_URL,'https')`, and the value also written to `$_COOKIE` so the same request sees it.
- [ ] **Step 5: Run the migration locally** (`php migrate.php`) and the test file. Expected: PASS. Also run `tests/MigrationPortabilityTest.php`. Expected: PASS.
- [ ] **Step 6: Commit:** `feat(cart): saved_carts table and library`

### Task 2: Hook cart changes and orders

**Files:**
- Modify: `cart/add.php` (after `$_SESSION['cart'] = $cart;`), `cart/update.php`, `cart/remove.php`, `checkout/index.php:68-84` (`update_cart_from_post`), `checkout/index.php` near `:475` (after `$pdo->commit()` of the order), `checkout/index.php` pledge branch near `:311` (before `unset($_SESSION['checkout_data'], $_SESSION['cart'])`)
- Test: `tests/Cart/SavedCartsHooksTest.php`

**Interfaces:**
- Consumes: `saved_cart_sync`, `saved_cart_mark_ordered` (Task 1).

- [ ] **Step 1: Failing tests**, in the style of `tests/Cart/CartMutationTest.php` (session-level) plus a source-scan test:
  - `test_every_cart_write_calls_sync`: scan `cart/add.php`, `cart/update.php`, `cart/remove.php` and `checkout/index.php`. Every file that assigns `$_SESSION['cart'] =` also calls `saved_cart_sync(`. (Exempt only the pledge-branch `unset`.)
  - `test_order_commit_marks_ordered`: `checkout/index.php` calls `saved_cart_mark_ordered($pdo)` after `$pdo->commit()`.
  - `test_pledge_handoff_marks_pledge_row`: the pledge branch calls `saved_cart_mark_ordered($pdo, $pledge_id)` before redirecting to DSK.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Add the calls.** Wrap each in `try { … } catch (Throwable $e) { error_log(...) }`: a saved-cart failure must never break add-to-cart or an order.
- [ ] **Step 4: Run the tests plus `tests/Cart tests/Shop`.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat(cart): save carts on every change; mark ordered`

### Task 3: Restore (cookie, email link, pledge)

**Files:**
- Modify: `includes/saved_carts.php`
- Create: `cart/restore.php`
- Modify: `cart/index.php` (show restore messages via the existing `flash_get()` block, as persistent errors/info)
- Test: `tests/Cart/SavedCartRestoreTest.php`

**Interfaces:**
- Produces:
  - `saved_cart_validate_items(PDO $pdo, array $items, string $lang): array{items: array, notes: list<string>}`: applies the checks from `cart/add.php` (product `active=1`; variant exists for `type='variant'`; stock cap unless `product_is_preorder`; for `type='print'` the `design_file`, when set, must exist under `uploads/print-designs/`). Each dropped or capped item adds one plain-language note naming the product in `$lang`. Prices are not stored or read.
  - `saved_cart_merge(array $current, array $saved): array{items: array, merged: bool}`: identity is product_id + variant_id + colour + size + design_file. The higher quantity wins. New lines are appended.
  - `saved_cart_restore_pledge(PDO $pdo, array $row, string $lang): ?string`: returns an error message, or null after setting `$_SESSION['checkout_data']` (`customer_name`, `customer_email`, `customer_phone` from the pledge, plus `_pledge_number`, `_pledge_id` and `_pledge_amount`) and calling `order_session_remember($pledge_number)`. It fails with a message when the pledge is not `pending`, the campaign is closed, or the reward is gone. Use the same checks `campaign/checkout.php` does.
- Endpoint `cart/restore.php`, GET only, read-only except session and cookie:
  - `?t=<token>` (email link): unknown, invalid or ordered → redirect to the cart with the "expired" note (BG „Тази количка вече не е налична.“ / EN "This cart is no longer available."). Pledge row → `saved_cart_restore_pledge` then checkout step 1 (or the campaign page with the error). Shop row → validate. If the session cart is empty: copy it in and point the cookie at that row's `browser_token`. Otherwise: merge and add the note "We added the items from your reminder to your cart." Then sync and redirect to the cart.
  - no `t` (on-site notice): use `saved_cart_for_browser`, with the same validate-and-merge rules.

- [ ] **Step 1: Failing tests** (DB-backed, with seeded products as in `UnpaidOrdersTest`):
  - `test_validate_drops_inactive_and_deleted_variant_with_named_notes`
  - `test_validate_caps_quantity_to_stock_unless_preorder`
  - `test_validate_drops_print_item_whose_design_file_is_missing`
  - `test_merge_higher_quantity_wins_and_appends_new_lines`
  - `test_restore_pledge_refuses_paid_pledge`, `test_restore_pledge_sets_checkout_data_for_pending`
  - HTTP test via `tests/Support/TestServer.php`: `test_restore_with_unknown_token_shows_expired_and_leaks_nothing`, `test_restore_onto_nonempty_cart_merges_and_keeps_device_token`
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** the helpers and `cart/restore.php` (`module_public_guard('shop')`, `start_session()`, `token` checked by `saved_cart_token_valid` before any query).
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat(cart): restore saved carts and pledges`

### Task 4: Consent checkbox at checkout step 1

**Files:**
- Modify: `checkout/index.php` (step-1 handler `:103`, step-1 form near the email field `:667`)
- Modify: `includes/saved_carts.php`
- Test: `tests/Shop/CartReminderConsentTest.php`

**Interfaces:**
- Produces: `saved_cart_record_consent(PDO $pdo, bool $opted_in, string $email, string $name, string $lang, string $consent_text, ?int $pledge_id = null): void`. When opted in: sets `email`, `name`, `remind_opt_in=1`, `remind_consent_at=NOW()`, `remind_consent_text`. When not: `remind_opt_in=0`, `email=NULL`, `name=NULL`. With `$pledge_id` it upserts the pledge row (new random `token`, the browser's `browser_token`, `items='[]'`).

- [ ] **Step 1: Failing tests:**
  - `test_checkbox_renders_unticked_with_label_when_email_switch_on` (it is rendered in pledge mode too). The exact copy from Global Constraints, `<label for>`, no `checked`.
  - `test_checkbox_absent_when_email_switch_off`
  - `test_ticked_records_consent_text_and_time`, `test_unticked_after_ticked_clears_email`
  - `test_pledge_mode_ticked_creates_pledge_row_and_leaves_shop_row_untouched`
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Render only when `saved_cart_email_enabled()`. Remember the choice in `$_SESSION['checkout_data']['cart_reminder']` so that going back shows the box ticked. Call `saved_cart_record_consent` only once step 1 validates without errors, passing the exact label string shown and `$order_lang`.
- [ ] **Step 4: Run** the tests plus `tests/Shop/EnglishCheckoutTest.php`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(checkout): opt-in reminder checkbox at step 1`

### Task 5: On-site notice

**Files:**
- Create: `templates/partials/saved-cart-notice.php`
- Modify: `templates/header.php` (include the partial right after the header, only on shop listing, product and cart pages; never in checkout or `/admin`)
- Create: `cart/dismiss-notice.php` (POST + CSRF, sets `dismissed_at` on the browser's rows, redirects back to a same-site path only)
- Test: `tests/Cart/SavedCartNoticeTest.php`

**Interfaces:**
- Consumes: `saved_cart_notice_enabled`, `saved_cart_for_browser`.

- [ ] **Step 1: Failing HTTP tests:**
  - `test_notice_shows_count_and_restore_link_when_session_cart_empty`: "You left 2 items in your cart." / „Оставихте 2 продукта в количката.“, with the link to `cart/restore.php`.
  - `test_notice_shows_pledge_line`: "You started supporting {campaign}." with a Finish link to the restore endpoint.
  - `test_notice_hidden_when_session_cart_not_empty`, `…_when_switch_off`, `…_on_checkout`, `…_after_dismiss`
  - `test_notice_markup_accessible`: `role="status"`, a `<button` with `aria-label`, `min-height:44px`.
  - `test_dismiss_rejects_offsite_redirect`
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** with inline styles only. The close button is a small POST form, so it works without JS.
- [ ] **Step 4: Run** the tests plus `tests/IconCloseButtonsLabelledTest.php` and `tests/Admin/AdminPagesNoWarningsHttpTest.php`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(cart): returning-visitor notice for saved carts`

### Task 6: Reminder email, opt-out, cron job

**Files:**
- Modify: `includes/email-templates.php` (defaults for `cart-reminder-customer`), `admin/email-templates.php:~42` (registry entry with label „Напомняне за незавършена поръчка“ and vars `{{customer_name}}`, `{{restore_url}}`)
- Create: `includes/emails/cart-reminder-customer.php` (items with image, name, variant, quantity and current price in the format the order emails use; or for a pledge, reward, amount and campaign title; then a button and a "Don't remind me" link)
- Modify: `includes/saved_carts.php`
- Create: `cart/no-reminder.php` (GET shows the confirm page; POST + CSRF sets `opted_out_at`)
- Create: `cron/cart-reminders-cron.php` (copy the CLI guard and bootstrap from `cron/unpaid-orders-cron.php`; supports `--dry-run`; calls `scheduled_job_track('cart_reminders')`)
- Modify: `includes/scheduled_jobs.php:22` (entry `cart_reminders`: hourly `['0','*','*','*','*']`, `grace` 3h, `needed_when` "Нужна е само ако е включено напомнянето по имейл за незавършени поръчки.") and the status switch near `:191`, which mirrors `unpaid_orders` and keys off `saved_cart_email_enabled()`
- Test: `tests/Cart/CartRemindersJobTest.php`, `tests/ScheduledJobsTest.php` (extend)

**Interfaces:**
- Produces:
  - `saved_carts_due(PDO $pdo): list<array>`: the rows matching the spec's cron conditions (opt-in, email set, not reminded/opted-out/ordered, `updated_at` between 7 days and 24h ago), excluding rows whose email has an `orders` row with `created_at > updated_at`.
  - `send_cart_reminder(PDO $pdo, array $row, ?callable $mailer = null): bool`: claims the row with `UPDATE … SET reminded_at=NOW() WHERE id=? AND reminded_at IS NULL` (rowCount 0 → false). Re-validates the items, and if none are left (or the pledge is no longer pending) returns false while keeping the claim. A mailer failure does **not** reset the claim (at most one email beats delivery).
  - `run_cart_reminders_job(PDO $pdo, ?callable $mailer = null): array{emailed:int, skipped:int, deleted:int}`: sends, then `DELETE FROM saved_carts WHERE updated_at < NOW() - INTERVAL 30 DAY`. Returns early with zeros when the email switch is off (still deletes).

- [ ] **Step 1: Failing tests** (with an injected mailer, as in `UnpaidOrdersTest`):
  - `test_due_picks_opted_in_row_older_than_24h_only`
  - `test_due_skips_rows_older_than_7_days`, `…_opted_out`, `…_ordered`, `…_not_opted_in`
  - `test_due_skips_when_same_email_ordered_after_update` *(Review Focus 2)*
  - `test_reminded_then_edited_cart_is_never_emailed_again` *(Review Focus 1)*
  - `test_send_claims_before_mailing_and_never_twice`
  - `test_send_skips_cart_with_no_available_items`
  - `test_email_contains_restore_link_and_no_reminder_link_in_shopper_language`
  - `test_pledge_reminder_shows_reward_and_amount`
  - `test_job_deletes_rows_older_than_30_days`
  - `test_no_reminder_get_changes_nothing_post_opts_out` *(Review Focus 5)*
  - `ScheduledJobsTest`: `cart_reminders` is registered and its script exists.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** The restore URL is `SITE_URL . '/cart/restore.php?t=' . $row['token']`, and the opt-out URL is `SITE_URL . '/cart/no-reminder.php?t=' . $row['token']`. Email copy: subject BG „Оставихте нещо в количката си“ / EN "You left something in your cart". For pledges: BG „Подкрепата ви не е завършена“ / EN "Your support isn't finished yet". Intro and outro are editable via the template registry, and include the line "You're getting this because you asked us to remind you." / „Получавате това писмо, защото поискахте да ви напомним.“
- [ ] **Step 4: Run** the tests plus `php cron/cart-reminders-cron.php --dry-run`. Expected: PASS, and the dry run prints a count with nothing sent.
- [ ] **Step 5: Commit:** `feat(cart): reminder email, opt-out and hourly job`

### Task 7: Admin switches + legal texts

**Files:**
- Create: `admin/cart-reminders.php`: `admin_require_login()`, then 403 unless `admin_can_manage_shop()`. Two checkboxes with labels: „Изпращай имейл напомняне за незавършени поръчки“ and „Показвай известие „Оставихте продукти в количката““, each with a one-line plain explanation. POST + `csrf_verify()` → `setting_set`. Set `$page_title_admin` and `$active_nav` **before** requiring `admin-header.php`. Link it from the admin shop navigation next to Orders, and from the `cart_reminders` row on the scheduled-jobs page.
- Modify: `includes/legal_templates.php`: in `legal_scope()` add `'cart_reminders' => $on('shop') && saved_cart_email_enabled()` and `'cart_cookie' => $on('shop') && saved_carts_enabled()` (guard with `function_exists`). Add the cookie-table rows next to `OMSESSID` (`:214` BG, `:243` EN): `om_cart`, "Remembers your basket so you can come back to it.", "30 days". Add the privacy paragraph in `legal_privacy_bg`/`_en` when `cart_reminders` is on, covering the five points in the spec's Privacy section.
- Test: `tests/Admin/CartRemindersAdminTest.php`, `tests/LegalTemplatesTest.php` (extend)

- [ ] **Step 1: Failing tests:**
  - `test_page_requires_login_and_shop_role`, `test_post_without_csrf_rejected`, `test_post_saves_both_switches`, `test_defaults_are_off`
  - `test_privacy_has_cart_reminder_paragraph_only_when_email_switch_on` (BG and EN)
  - `test_cookie_table_lists_om_cart_only_when_a_switch_is_on`
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the full suite:** `php vendor/bin/phpunit`. Expected: all green, including `AdminPagesNoWarningsHttpTest` and `TranslateButtonCoverageTest` (no `*_en` fields added).
- [ ] **Step 5: Commit:** `feat(admin): cart reminder switches; privacy and cookie texts`

### Task 8: End-to-end check in the browser

- [ ] Turn both switches on locally (`php scripts/make-test-admin.php` if needed). Add two products, close the session (clear `OMSESSID` only), and reload the shop. Expect the notice. Restore, and expect the cart back.
- [ ] Do checkout step 1 with the box ticked, set `updated_at` back 25h in the local DB, and run `php cron/cart-reminders-cron.php`. Expect the email in the local mail copy. Open its link in a fresh browser and expect the cart restored. Run the cron again and expect nothing sent.
- [ ] Repeat for a campaign reward pledge.
- [ ] Commit any fixes. The user decides on push and release.
