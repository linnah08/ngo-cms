# Cart reminders: design

Trello: https://trello.com/c/uIGfordL/76-remind-shoppers-about-items-left-in-their-cart

## Intent

Shoppers put products in the cart and leave without ordering. We want to bring them back gently:

- **Email reminder:** for shoppers who gave us their email (checkout step 1) and asked to be reminded.
- **On-site notice:** for everyone else, shown when they come back on the same device.

The goal is to help people finish an order they meant to place, without nagging and without breaking privacy rules. The site is used by non-technical people, so it must be simple, plain-language, bilingual (BG/EN) and accessible.

## How it works today

- The cart is only `$_SESSION['cart']` (session cookie `OMSESSID`). Nothing is saved in the DB, so the cart is lost when the session expires.
- There are no customer accounts. The shopper's email is first known when they submit checkout step 1 (`action=contact` in `checkout/index.php`). The order row is created at step 3.
- Orders placed but left unpaid are already handled by `cron/unpaid-orders-cron.php`. This feature only covers carts that never became an order.
- Print products can carry an uploaded `design_file` and `design_position` on the cart entry (`cart/add.php`).

## Goals

- Save every non-empty cart to the DB, linked to the browser by a long-lived cookie holding a random token.
- A returning visitor whose session cart is empty, but who has a saved cart, sees a dismissible notice: "You left N items in your cart. [Show my cart]". Clicking it restores the cart.
- At checkout step 1 there is an unticked checkbox: "Email me a reminder if I don't finish my order" / „Изпратете ми напомняне по имейл, ако не завърша поръчката“. If it is ticked, the email, name and language are stored on the saved cart.
- A cron job sends **one** reminder email 24 hours after the cart was last changed, to opted-in carts with no order placed since. The email lists the items and has a "Back to my cart" link that restores the cart on any device, plus a one-click "Don't remind me" link.
- Admin can switch the **email** and the **on-site notice** on and off separately. Both are **off by default**.
- The job is listed in Admin → Автоматични задачи (`includes/scheduled_jobs.php`) and supports `--dry-run`, like `unpaid_orders`.

## Non-goals

- Customer accounts or login.
- Ticket pledges and plain donations (they don't go through the shop checkout).
- More than one reminder email, or reminders for unpaid orders (those already exist).
- Discount codes or incentives in the reminder.
- Admin analytics or reports on abandoned carts. The `saved_carts` table makes these possible later.

## Anti-goals (a failure even if it "works")

- Emailing someone who didn't tick the checkbox, emailing someone who has since placed the order, or emailing more than once.
- A restore that silently changes the cart: prices, stock or design files that changed must be handled visibly (see Edge cases).
- A notice that traps keyboard focus, isn't announced to screen readers, or shows up on every page after it was dismissed.
- Any way for one visitor to see or restore another visitor's cart (the token must be unguessable, and nothing is ever looked up by email).

## Constraints

- Generic feature: build it in **ngo-cms first**, then port it to oddminds (`Ported:` trailer). lafetki gets it with the next release.
- Migration **053** (check `ls migrations` at build time). Portable SQL for MariaDB 10.5 and MySQL 9 (no `ADD COLUMN IF NOT EXISTS`).
- CSRF on every POST, parameterised queries, `h()` on all output, inline styles for the notice (main.css may be stale-cached).
- BG/EN parity: every string goes into `t_or()` or strings, with both languages.
- Accessibility per CLAUDE.md: a real `<label for>` on the checkbox, the notice in a `role="status"` region, a keyboard-reachable close button with `aria-label`, a visible focus outline, and tap targets of at least 44px.
- Legal text changes stay in the shared generated templates (ngo-cms), never in a site's own copy.
- Tests for every new function and endpoint (`php vendor/bin/phpunit`).

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| **A (picked)** | Save the cart in a DB table, keyed by a random token in a long-lived cookie | Most work (migration, cron, settings), but one source of truth powers both the notice and the email, and the email link restores the cart on any device. |
| B | Cart in browser localStorage, plus a DB copy only for the email | The notice needs no cookie, but the email link can't restore on another device, and two copies of the cart drift apart (stock, price, pre-order checks). |
| C | Make the PHP session last ~30 days, and email from step 1 | Least code, but heavy on shared hosting, unreliable (the host's session garbage collection), and the email can't restore the cart. |

The user picked **A**. For email consent, the user picked an **unticked checkbox at step 1** (rather than a notice with opt-out).

## Design

### Data: `saved_carts` (migration 053)

| column | notes |
|---|---|
| `id` | PK |
| `token` | `CHAR(64)` unique: `bin2hex(random_bytes(32))`, also the cookie value |
| `items` | `JSON`: a copy of `$_SESSION['cart']` |
| `lang` | `bg`/`en`, the language the cart was last used in |
| `email`, `name` | NULL until checkout step 1 is submitted with the checkbox ticked |
| `remind_opt_in` | `TINYINT(1)` default 0 |
| `reminded_at` | NULL until the email is sent |
| `pledge_id` | NULL for a shop cart. Set for a campaign reward pledge (unique) |
| `remind_consent_at`, `remind_consent_text` | proof of consent: when, and the exact wording and language shown |
| `opted_out_at` | set by "Don't remind me" |
| `ordered_at` | set when an order is placed from this cart: no notice and no email after this |
| `dismissed_at` | the on-site notice was closed: don't show it again until the cart changes |
| `updated_at`, `created_at` | `updated_at` changes on every cart change: the 24h clock |

### Cookie

`om_cart` holds the token: 30 days, `HttpOnly`, `SameSite=Lax`, `Secure` on https. It is set on the first cart write. This is a strictly necessary cookie (it keeps the shopper's own cart), so no banner consent is needed. The cookie policy is a generated table in `includes/legal_templates.php` (next to `OMSESSID`): add an `om_cart` row there, BG and EN, shown only when either switch is on.

### Saving

A single helper, e.g. `saved_cart_sync()`, runs after every cart mutation (`cart/add.php`, `update.php`, `remove.php`, and `update_cart_from_post()` in checkout). It upserts `items`, `lang` and `updated_at` for the cookie's token. Changing the cart clears `dismissed_at`. It **never** clears `reminded_at`: a cart is emailed at most once, ever, even if the shopper edits it after the reminder. An emptied cart gets `items = []`, which means no notice and no email.

### Restoring

- **On-site:** on shop and cart pages (and the header cart badge area), if the session cart is empty and `om_cart` points to a saved cart with items that is not ordered and not dismissed, render the notice. "Show my cart" goes to `cart/?restore=1` (a GET that only reads the cookie's own cart, so no token in the URL), which copies `items` into the session and redirects to the cart.
- **From the email:** `cart/restore.php?t=<token>` (or similar). It loads the cart by token. If this device's session cart is empty, the saved cart is copied in and `om_cart` is pointed at that token. If the device already has a cart, the saved items are **added** to it (same product+variant+design: the higher quantity wins, never summed), the device keeps its own `om_cart` token, and the cart page shows a persistent "We added the items from your reminder to your cart" notice. *(Builder's call, flagged to the user.)* Either way it redirects to the cart. An unknown or expired token shows a friendly "This cart has expired" message on the cart page.
- **Validation on restore:** design files are never deleted automatically today (`uploads/print-designs/`), so a missing file is rare, but it is still handled. Restoring gives the device access to that design through `cart/design-thumb.php`, which is acceptable only because the token is unguessable.
- **Item checks on restore:** every item goes through the same checks as `cart/add.php` (product exists and is visible, variant exists, stock, pre-order rules). Items that fail are dropped, and the cart page shows a persistent notice naming each dropped item and why ("no longer available", "your uploaded design has expired"). Quantities above stock are lowered, with a visible message. Prices always come from the current product, never from the saved copy.

### Email consent (checkout step 1)

An unticked checkbox under the email field, with a real label. On `action=contact` with the box ticked: store `email`, `name`, `remind_opt_in=1` on the saved cart. If it is unticked, set `remind_opt_in=0` and clear `email`/`name` (the shopper changed their mind). Store the consent in the session too, so step 1 shows the box ticked when the shopper goes back to it. Record proof of consent on the saved cart: `remind_consent_at` and the exact wording and language shown (`remind_consent_text`), like `orders.consents`.

**Campaign reward pledges (the user's call: "checkout is checkout"):** reward pledges reuse the shop checkout from step 1, and get the same reminders as a shop cart.

- How pledges work today: `campaign/checkout.php` creates a `campaign_pledges` row (`payment_status='pending'`, name and email known) **before** checkout, and puts the pledge into `$_SESSION['checkout_data']` (`_pledge_number`, `_pledge_id`, `_pledge_amount`). The pledge is never in `$_SESSION['cart']`. Step 2 saves delivery onto the pledge and redirects to DSK. That is the pledge's equivalent of the shop's step 3 (the moment it becomes a payment).
- **Checkbox:** it is shown in pledge mode too, same wording. Ticking it stores the opt-in and the consent proof on a saved-checkout row for that pledge.
- **Data:** add a nullable `pledge_id` to `saved_carts`. A pledge gets its **own** row (own token, `items` empty, `pledge_id` set), separate from the shopper's shop cart row, so neither overwrites the other. One row per pledge (unique on `pledge_id`).
- **Done:** the row gets `ordered_at` when step 2 hands the pledge to DSK. A paid, failed or reversed pledge is never reminded.
- **Email:** the same template, with the reward name, amount and campaign title instead of the item list. "Finish my support" goes to the restore endpoint, which checks the pledge is still `pending`, the campaign is still open and the reward still available. It then rebuilds `checkout_data` from the pledge, calls `order_session_remember($pledge_number)`, and opens checkout step 1. If any check fails, it shows a friendly "This campaign has closed" or "This reward is no longer available" message with a link to the campaign.
- **On-site notice:** for a returning visitor whose cookie points to an unfinished pledge row, show "You started supporting <campaign>. [Finish]". If they also have a shop cart, show both lines in one notice.
- **Cookie:** the pledge row is linked by the same `om_cart` cookie: the cookie holds the shop-cart token, and the pledge row also stores that browser token (`browser_token`) so the notice can find it. *(Builder may restructure: a separate `browser_token` column on all rows, with `token` only for email links, is fine and probably cleaner.)*
- **Existing behaviour worth knowing:** step 2 in pledge mode does `unset($_SESSION['cart'])`, which throws away any shop cart the shopper had. With saved carts, the shop cart stays in the DB (that unset is not a cart change through the sync helper), so the on-site notice brings it back. Don't "fix" the unset as part of this work.

### Marking ordered

When the order row is committed (step 3, all payment methods), set `ordered_at` on the saved cart for that token. Unpaid orders are then handled by the existing unpaid-orders job.

### Privacy policy (the user's call: yes)

The privacy policy is generated in `includes/legal_templates.php` from the site's switches. When the **email** switch is on, it gets a short BG/EN paragraph in plain language, written in the same style as the existing sections and conditional the same way the newsletter text is. It covers: what we keep (name, email, the cart's contents or the pledge), why (one reminder you asked for at checkout), the legal basis (your consent via the checkbox), how long (up to 30 days, or until you order or click "Don't remind me"), and how to withdraw (the link in the email, or by contacting the organisation). When the email switch is off, the paragraph isn't shown. The on-site notice alone needs no paragraph: it only uses the cart cookie, which is covered in the cookie policy.

### Cron: `cron/cart-reminders-cron.php`

Registered in `scheduled_jobs()` as `cart_reminders`, runs hourly. It selects carts where:

- `remind_opt_in = 1`, the email is not null, items are not empty
- `reminded_at`, `ordered_at` and `opted_out_at` are all NULL
- `updated_at <= NOW() - INTERVAL 24 HOUR` and `updated_at > NOW() - INTERVAL 7 DAY` (don't email very old carts if the job was off)
- the email setting is on

Before sending, it re-checks the items: if none is still available, it marks the cart reminded and sends nothing. It also skips the cart if an order with this `customer_email` was created after the cart's `updated_at` (a guard for an order placed from another device). It sets `reminded_at` **before** sending, in the same row update, so a crash can never cause a second email. `--dry-run` lists who would be emailed without sending.

### Email template: `includes/emails/cart-reminder-customer.php`

In the shopper's `lang`, rendered with `render_email()` and sent with `send_mail()`. It contains a greeting by name, the item list (image, name, variant, quantity, current price in EUR and BGN as elsewhere in the shop emails), a "Back to my cart" button (the restore link), a short line on why they're getting this ("you asked us to remind you"), and a "Don't remind me" link. Register it in `admin/email-templates.php` alongside the other customer emails, so admins can edit it.

### "Don't remind me"

`cart/no-reminder.php?t=<token>` shows a confirmation page with a button (a POST with CSRF, so link scanners in mail clients can't trigger it). The POST sets `opted_out_at` and shows "Done, we won't remind you about this cart" in BG/EN.

### Admin

Two switches, **off by default**, in shop settings: "Email a reminder about unfinished orders" and "Show a 'you left items in your cart' notice". Stored with `setting_set()`. The cart is saved to the DB only when at least one switch is on. Both switches render on every site; when the shop module is off they have no effect.

### Clean-up

The cron also deletes saved carts with `updated_at` older than 30 days.

## Left to the builder

- Where exactly the two switches live (an existing admin page vs. a small new section). Follow existing patterns. It must be reachable and plain-language.
- The exact restore URLs and helper names.
- Which public pages show the notice (at least the shop listing, product pages and the cart). It must never appear in checkout or admin.
