# Lafetki Subdomain — Design Spec

**Date:** 2026-05-31
**Status:** Approved

---

## Goal

Move the Lafetki campaign to its own subdomain (`lafetki.oddminds.org`) with a tabbed single-page layout that separates content into digestible sections instead of one long page.

---

## URL & Routing

- New subdomain: `lafetki.oddminds.org`
- Served from a new directory: `lafetki/index.php` at the project root
- The existing `/campaign/` path remains unchanged (checkout, confirmation, payment-failed live there and are not moved)
- The campaign strip on `/campaign/index.php` can optionally link to `lafetki.oddminds.org` but that is out of scope for this spec
- Server config (Apache/nginx vhost) maps `lafetki.oddminds.org` to the project root with `DOCUMENT_ROOT` pointing at the same codebase — `config.php`, `includes/`, `templates/` all available

---

## Page Structure

### Sticky top nav
- Left: **Лафетки ♦** logo (teal + orange accent)
- Centre: tab buttons — Идеята · Въпроси · Бюджет · Награди · Серии · Събитие
- Active tab: filled teal background; Събитие tab uses orange when active
- Nav is `position: sticky; top: 0` — always visible

### Tab panels (left column)
Only one panel is visible at a time. Switching tabs swaps the visible panel and scrolls to the top.

| Tab | Content |
|---|---|
| Идеята | Existing `campaign_description` rich text + photo carousel with lightbox (unchanged) |
| Въпроси | Existing FAQ accordion (`campaign_faq` setting) (unchanged) |
| Бюджет | Existing budget table (`campaign_budget` setting) (unchanged) |
| Награди | Full-size reward cards from `campaign_rewards` DB table; selecting a card reveals an inline checkout strip; pure donation option below |
| Серии | Five series cards: Icebreakers (available), No Filter / Mum in my Eyes / Eye to Eye / Family Chronicles (coming soon) |
| Събитие | Existing event info (name, date, time, place, description, Facebook link) — only rendered when `event_active = 1` |

### Sticky right sidebar (right column, `position: sticky; top: 72px`)

| Active panel | Sidebar shows |
|---|---|
| Идеята, Въпроси, Бюджет, Серии | Progress bar · supporter count · days remaining · distance to goal · soft "Виж как да помогнеш" outline button (navigates to Награди tab) · note: "Без задължение" |
| Награди | Sidebar hidden — the panel itself is the action |
| Събитие | Ticket purchase form (name, email, qty stepper, total, buy button) |

### Inline checkout strip (Награди panel)
Appears below the reward cards when one is selected:
- Heading: "Завърши подкрепата — {reward title}"
- Three-column row: name field · email field · "Продължи към плащане →" button
- Note: DSK Bank · donation certificate by email
- POSTs to `/campaign/checkout.php` (existing handler, unchanged)

### Pure donation option (Награди panel)
Below the checkout strip: dashed border box, free-amount EUR input, "Дари →" button. Also POSTs to `/campaign/checkout.php`.

### Bottom campaign strip
Static block above the footer:
- Campaign title · slim progress bar · raised / target · supporter count · days remaining
- Background: `#f0f7fa`, bordered top and bottom

### Footer
Inherits the site footer (`templates/footer.php`) or a minimal equivalent: "© Фондация Различни умове · oddminds.org"

---

## Data Sources

All data comes from the same `settings` table and `campaign_rewards` DB table that `/campaign/index.php` already reads. No new DB tables or columns needed.

| Data | Source |
|---|---|
| Title, description, risks | `campaign_title`, `campaign_description` settings |
| Photos | `campaign_photos` JSON setting |
| Budget | `campaign_budget` JSON setting |
| FAQ | `campaign_faq` JSON setting |
| Rewards | `campaign_rewards` table |
| Progress stats | `campaign_pledges` table (paid pledges) |
| Target / end date | `campaign_target_eur`, `campaign_end_date` settings |
| Event | `event_active`, `event_name`, `event_date`, `event_time`, `event_place`, `event_description`, `event_fb_url`, `event_ticket_price` settings |

EN/BG language toggle follows the same `get_lang()` pattern as the existing campaign page. URL: `lafetki.oddminds.org` = BG; `lafetki.oddminds.org/en/` or `?lang=en` — to be confirmed, out of scope for now; default BG only.

---

## Series Cards

Static content (not CMS-managed at this stage):

| Series | Status | Colour |
|---|---|---|
| Icebreakers | Налична (green badge) | Teal gradient |
| No Filter | Предстои (yellow badge) | Orange gradient |
| Mum in my Eyes | Предстои | Warm yellow gradient |
| Eye to Eye | Предстои | Green gradient |
| Family Chronicles | Предстои | Purple gradient |

---

## Checkout Flow

Selecting a reward on `lafetki.oddminds.org` submits the pledge form to `https://oddminds.org/campaign/checkout.php` (absolute URL via `SITE_URL`) — the existing handler. No changes to checkout, payment, or confirmation flows.

**Cross-subdomain session / CSRF:** The CSRF token is session-based. For the session cookie to be readable on both `oddminds.org` and `lafetki.oddminds.org`, the cookie domain must be set to `.oddminds.org` (leading dot). Check `session.cookie_domain` in `config.php` or `php.ini` before implementing. If it is not set, add `ini_set('session.cookie_domain', '.oddminds.org')` before `session_start()` in `config.php`.

---

## Styling

- Inline styles for all layout-critical rules (grid, sticky, flex) per project convention — do not rely on `main.css`
- Teal: `#0387A5` (matches main site)
- Orange accent: `#e8763a` (Събитие / Лафетки brand)
- Background: `#faf9f7`
- Page uses `templates/header.php` and `templates/footer.php` (sets `$page_title`, `$page_description`, `$page_head_extra` before requiring header)

---

## Out of Scope

- Redirect from `/campaign/` to `lafetki.oddminds.org`
- EN language version of the subdomain
- CMS management of series cards
- Any changes to checkout, payment, or confirmation flows
- Mobile/responsive layout (follow-up)
