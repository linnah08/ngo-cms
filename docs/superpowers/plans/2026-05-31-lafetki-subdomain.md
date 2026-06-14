# Lafetki Subdomain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `lafetki.oddminds.org` as a standalone tabbed campaign page, served from `lafetki/index.php`, with sections (Идеята / Въпроси / Бюджет / Награди / Серии / Събитие) shown one at a time.

**Architecture:** A single new PHP file (`lafetki/index.php`) loads data from the same `settings` table and `campaign_rewards` DB that `/campaign/index.php` already uses, then renders a tabbed layout — one content panel visible at a time, sticky nav at top, informational sidebar on the right, progress strip above the footer. Form submissions go to the existing `/campaign/checkout.php` using absolute URLs via `SITE_URL`. Session cookie domain is widened to `.oddminds.org` so CSRF tokens work across the subdomain.

**Tech Stack:** PHP 8.4, PDO/MySQL, vanilla JS (inline), Apache vhost for subdomain, PHPUnit 13

---

## File Map

| Action | Path | Purpose |
|---|---|---|
| Modify | `config.php` | Set session cookie domain to `.oddminds.org` in production |
| Create | `lafetki/index.php` | Full tabbed campaign page |
| Modify | `tests/HttpTest.php` | Add smoke test for `lafetki.oddminds.test` |

---

## Task 1: Session cookie domain — share session across subdomain

**Files:**
- Modify: `config.php:101-103`

- [ ] **Step 1: Open `config.php` and locate the session block (around line 101)**

```php
// Start session early — must happen before any output so the cookie can be set.
// CSRF and cart both depend on $_SESSION being available before HTML is rendered.
if (session_status() === PHP_SESSION_NONE) session_start();
```

- [ ] **Step 2: Replace those three lines with the cookie-domain guard**

```php
// Start session early — must happen before any output so the cookie can be set.
// CSRF and cart both depend on $_SESSION being available before HTML is rendered.
// Widen the cookie domain so subdomains (e.g. lafetki.oddminds.org) share the session.
if (str_contains(SITE_URL, 'oddminds.org')) {
    ini_set('session.cookie_domain', '.oddminds.org');
}
if (session_status() === PHP_SESSION_NONE) session_start();
```

- [ ] **Step 3: Run the test suite to confirm nothing broke**

```bash
php vendor/bin/phpunit --stop-on-failure
```

Expected: all existing tests pass (session behaviour is unchanged on `oddminds.test` where SITE_URL does not contain `oddminds.org`).

- [ ] **Step 4: Commit**

```bash
git add config.php
git commit -m "fix: widen session cookie domain to .oddminds.org for subdomain support"
```

---

## Task 2: Server vhost (manual step — do before testing locally)

This is a server configuration task, not a code change. **No changes to `deploy.php` needed** — both domains share the same document root, so every git push deploys both automatically.

**On the production server (cPanel):**
1. Log in to cPanel → Subdomains
2. Create subdomain: `lafetki` → Domain: `oddminds.org`
3. **Document root: `/home/detelinavasileva/public_html/oddminds.org`** (same as the main site — type it manually if cPanel prefills a different path)
4. Save. Apache now serves `lafetki.oddminds.org` from the same directory as `oddminds.org`. No separate deploy step, ever.

**For local dev**, add to `/etc/hosts`:
```
127.0.0.1  lafetki.oddminds.test
```
Then add a vhost in your local Apache/nginx config pointing `lafetki.oddminds.test` to the same document root as `oddminds.test`.

---

## Task 3: `lafetki/index.php` — PHP data layer + `.htaccess`

**Files:**
- Create: `lafetki/index.php`
- Create: `lafetki/.htaccess`

- [ ] **Step 1: Create `lafetki/.htaccess` so the bare subdomain URL (`lafetki.oddminds.org/`) serves `index.php`**

The directory already behaves like any other PHP directory — `index.php` is served by default. No special rewrite rules needed. But we do want to block direct listing:

```apacheconf
Options -Indexes
```

Save to `lafetki/.htaccess`.

- [ ] **Step 2: Create `lafetki/index.php` — PHP header and data loading**

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

// Redirect to home when campaign is not active
if (setting_get('campaign_active', '0') !== '1') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

$pdo  = get_pdo();
$lang = get_lang();
$is_en = $lang === 'en';
$boxnow_partner_id = setting_resolve('boxnow_partner_id', 'BOXNOW_PARTNER_ID');

function _lf_pick(string $en_val, string $bg_val): string {
    return ($en_val !== '') ? $en_val : $bg_val;
}

// ── Settings ────────────────────────────────────────────────────────────────
$title_bg = setting_get('campaign_title',       'Помогни на Лафетки');
$title_en = setting_get('campaign_title_en',    '');
$desc_bg  = setting_get('campaign_description', '');
$desc_en  = setting_get('campaign_description_en', '');

$title = $is_en ? _lf_pick($title_en, $title_bg) : $title_bg;
$desc  = $is_en ? _lf_pick($desc_en,  $desc_bg)  : $desc_bg;

$target_eur = (float)(setting_get('campaign_target_eur', '5000') ?: '5000');
$end_date   = setting_get('campaign_end_date', '');
$photos     = json_decode(setting_get('campaign_photos', '[]'), true) ?: [];

// ── Event ────────────────────────────────────────────────────────────────────
$ev_active  = setting_get('event_active', '0') === '1';
$ev_name    = setting_get('event_name', '');
$ev_date    = setting_get('event_date', '');
$ev_time    = setting_get('event_time', '');
$ev_place   = setting_get('event_place', '');
$ev_fb_url  = setting_get('event_fb_url', '');
$ev_price   = (float)(setting_get('event_ticket_price', '0') ?: '0');
$ev_desc    = setting_get('event_description', '');
$ev_date_fmt = $ev_date
    ? (new DateTimeImmutable($ev_date))->format($is_en ? 'F j, Y' : 'd.m.Y')
    : '';
$ev_when = $ev_date_fmt . ($ev_time ? ', ' . $ev_time . ($is_en ? '' : ' ч.') : '');

// ── Budget ────────────────────────────────────────────────────────────────────
$budget_raw = json_decode(setting_get('campaign_budget', '[]'), true) ?: [];
$budget = array_map(function ($row) use ($is_en) {
    return [
        'label'      => ($is_en && ($row['label_en'] ?? '') !== '') ? $row['label_en'] : $row['label'],
        'amount_eur' => $row['amount_eur'],
    ];
}, $budget_raw);

// ── FAQ ───────────────────────────────────────────────────────────────────────
$faq_raw = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
$faq = array_map(function ($row) use ($is_en) {
    return [
        'q' => ($is_en && ($row['q_en'] ?? '') !== '') ? $row['q_en'] : $row['q'],
        'a' => ($is_en && ($row['a_en'] ?? '') !== '') ? $row['a_en'] : $row['a'],
    ];
}, $faq_raw);

// ── Rewards ───────────────────────────────────────────────────────────────────
$rewards_raw = $pdo->query("SELECT * FROM campaign_rewards WHERE active=1 ORDER BY position")->fetchAll();
$rewards = array_map(function ($r) use ($is_en) {
    $r['title']       = ($is_en && ($r['title_en'] ?? '') !== '') ? $r['title_en'] : $r['title'];
    $r['description'] = ($is_en && ($r['description_en'] ?? '') !== '') ? $r['description_en'] : $r['description'];
    return $r;
}, $rewards_raw);

// ── Stats ────────────────────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT COUNT(*) AS funders, COALESCE(SUM(amount_eur),0) AS raised
    FROM campaign_pledges WHERE payment_status='paid'
")->fetch();
$raised_eur = (float)$stats['raised'];
$funders    = (int)$stats['funders'];
$pct        = $target_eur > 0 ? min(100, $raised_eur / $target_eur * 100) : 0;

$days_left = null;
if ($end_date) {
    $days_left = max(0, (int)ceil((strtotime($end_date) - time()) / 86400));
}

$show_bgn = SHOW_DUAL_CURRENCY;
function lf_fmt_eur(float $eur, bool $show_bgn): string {
    $s = number_format($eur, 0, '.', ' ') . ' EUR';
    if ($show_bgn) {
        $bgn = number_format($eur * EUR_BGN_RATE, 0, '.', ' ') . ' лв';
        $s .= ' <small style="opacity:.7;font-weight:400;">(' . $bgn . ')</small>';
    }
    return $s;
}

// ── Page meta ─────────────────────────────────────────────────────────────────
$page_title       = h($title) . ' — Лафетки';
$page_description = $is_en
    ? 'Support Lafetki — the first Bulgarian card game for children with autism.'
    : 'Подкрепи Лафетки — първата българска игра с карти за деца с аутизъм.';

$page_head_extra = '<script src="/assets/js/boxnow-widget.js"></script>' . "\n" .
    '<script>var _campaignBoxnowPartnerId = ' . json_encode($boxnow_partner_id) . ';</script>';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>
```

- [ ] **Step 3: Run `php -l lafetki/index.php` to confirm no syntax errors**

```bash
php -l lafetki/index.php
```

Expected: `No syntax errors detected in lafetki/index.php`

- [ ] **Step 4: Commit**

```bash
git add lafetki/index.php lafetki/.htaccess
git commit -m "feat: add lafetki/index.php data layer and .htaccess"
```

---

## Task 4: Inline CSS + sticky nav + campaign strip

**Files:**
- Modify: `lafetki/index.php` (append after `require header.php`)

- [ ] **Step 1: Append the CSS `<style>` block and sticky nav immediately after `require header.php`**

```php
?>
<style>
* { box-sizing: border-box; }
body { background: #faf9f7; color: #1a2e2c; }

/* ── Nav ── */
.lf-topbar {
  position: sticky; top: 0; z-index: 100;
  background: #fff; border-bottom: 2px solid #e8ddd5;
  display: flex; align-items: center;
  padding: 0 2rem; height: 56px; gap: 2rem;
}
.lf-logo { font-size: 1.15rem; font-weight: 800; color: #0387A5; letter-spacing: -.02em; flex-shrink: 0; }
.lf-logo span { color: #e8763a; }
.lf-nav { display: flex; gap: .15rem; }
.lf-tab {
  padding: .38rem .9rem; font-size: .875rem; font-weight: 600;
  color: #6b7280; border-radius: 7px; cursor: pointer;
  border: none; background: none; font-family: inherit;
  transition: background .15s, color .15s; white-space: nowrap;
}
.lf-tab:hover { background: #f3f4f6; color: #1a2e2c; }
.lf-tab.active { background: #0387A5; color: #fff; }
.lf-tab.ev-tab { color: #e8763a; }
.lf-tab.ev-tab.active { background: #e8763a; color: #fff; }

/* ── Page body ── */
.lf-body {
  max-width: 1100px; margin: 0 auto;
  display: grid; grid-template-columns: 1fr 300px;
  gap: 3rem; padding: 2.5rem 2rem 4rem; align-items: start;
}

/* ── Panels ── */
.lf-panel { display: none; }
.lf-panel.active { display: block; }
.lf-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #0387A5; margin-bottom: .45rem; }
.lf-panel h2 { font-size: 1.45rem; font-weight: 800; margin-bottom: 1.25rem; }

/* ── Idea ── */
.lf-desc { font-size: .97rem; line-height: 1.85; color: #3d4f50; }
.lf-desc p + p { margin-top: 1em; }

/* ── Photo carousel ── (reuses existing campaign carousel CSS) */
.c-carousel { position: relative; margin: 1.5rem 0; border-radius: 10px; overflow: hidden; background: #000; user-select: none; }
.c-carousel__track { display: flex; transition: transform .35s cubic-bezier(.4,0,.2,1); }
.c-carousel__slide { flex: 0 0 100%; position: relative; }
.c-carousel__slide img { width: 100%; max-height: 420px; object-fit: contain; display: block; background: #111; cursor: zoom-in; }
.c-carousel__btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,.5); color: #fff; border: none; border-radius: 50%; width: 42px; height: 42px; font-size: 1.2rem; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; transition: background .15s; }
.c-carousel__btn:hover { background: rgba(0,0,0,.75); }
.c-carousel__btn--prev { left: .75rem; }
.c-carousel__btn--next { right: .75rem; }
.c-carousel__dots { display: flex; justify-content: center; gap: .5rem; padding: .75rem 0 .25rem; background: #1a1a1a; }
.c-carousel__dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,.35); border: none; cursor: pointer; padding: 0; transition: background .2s; }
.c-carousel__dot.active { background: #fff; }
.c-lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 9000; align-items: center; justify-content: center; }
.c-lightbox.open { display: flex; }
.c-lightbox img { max-width: 94vw; max-height: 90vh; object-fit: contain; border-radius: 6px; }
.c-lightbox__close { position: fixed; top: 1.25rem; right: 1.5rem; color: #fff; font-size: 2rem; cursor: pointer; background: none; border: none; z-index: 9001; }
.c-lightbox__prev, .c-lightbox__next { position: fixed; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,.15); color: #fff; border: none; border-radius: 50%; width: 48px; height: 48px; font-size: 1.4rem; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 9001; transition: background .15s; }
.c-lightbox__prev:hover, .c-lightbox__next:hover { background: rgba(255,255,255,.3); }
.c-lightbox__prev { left: 1rem; }
.c-lightbox__next { right: 1rem; }

/* ── FAQ ── */
.lf-faq-item { border-bottom: 1px solid #e8ddd5; padding: 1.05rem 0; }
.lf-faq-q { font-weight: 700; font-size: .95rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.lf-faq-arrow { transition: transform .2s; flex-shrink: 0; }
.lf-faq-item.open .lf-faq-arrow { transform: rotate(180deg); }
.lf-faq-a { font-size: .9rem; line-height: 1.7; color: #5a6b6c; margin-top: .7rem; display: none; }
.lf-faq-item.open .lf-faq-a { display: block; }

/* ── Budget ── */
.lf-budget { width: 100%; border-collapse: collapse; }
.lf-budget td { padding: .75rem; font-size: .93rem; border-top: 1px solid #e8ddd5; }
.lf-budget td:last-child { text-align: right; font-weight: 600; }
.lf-budget tfoot td { border-top: 2px solid #0387A5; font-weight: 800; font-size: 1rem; color: #0387A5; }

/* ── Rewards ── */
.lf-rewards-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; margin-bottom: 1.75rem; }
.lf-reward {
  border: 2px solid #e8ddd5; border-radius: 14px; padding: 1.4rem;
  background: #fff; cursor: pointer; transition: border-color .2s, box-shadow .2s, background .15s;
  position: relative;
}
.lf-reward:hover { border-color: #0387A5; box-shadow: 0 0 0 3px rgba(3,135,165,.1); }
.lf-reward.selected { border-color: #0387A5; background: #f0f9fc; box-shadow: 0 0 0 4px rgba(3,135,165,.15); }
.lf-reward-check {
  display: none; position: absolute; top: .75rem; right: .9rem;
  width: 22px; height: 22px; border-radius: 50%; background: #0387A5;
  color: #fff; font-size: .85rem; font-weight: 800;
  align-items: center; justify-content: center; line-height: 22px; text-align: center;
}
.lf-reward.selected .lf-reward-check { display: flex; }
.lf-reward-amt { font-size: 1.5rem; font-weight: 800; color: #0387A5; margin-bottom: .4rem; }
.lf-reward-title { font-weight: 800; font-size: 1rem; margin-bottom: .45rem; }
.lf-reward-desc { font-size: .87rem; color: #5a6b6c; line-height: 1.65; }
.lf-reward-badge { display: inline-block; font-size: .72rem; font-weight: 700; padding: .22rem .6rem; border-radius: 20px; background: #e4f0f5; color: #0387A5; margin-top: .65rem; }

/* ── Checkout strip ── */
.lf-checkout {
  display: none; background: #fff; border: 2px solid #0387A5; border-radius: 14px;
  padding: 1.5rem; box-shadow: 0 4px 20px rgba(3,135,165,.12); margin-top: .25rem;
}
.lf-checkout.open { display: block; }
.lf-checkout h3 { font-size: 1rem; font-weight: 800; margin-bottom: 1rem; }
.lf-checkout-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; align-items: end; }
.lf-field label { display: block; font-weight: 600; font-size: .8rem; margin-bottom: .28rem; color: #374151; }
.lf-field input { width: 100%; padding: .55rem .7rem; border: 1.5px solid #d1d5db; border-radius: 7px; font-size: .88rem; font-family: inherit; }
.lf-field input:focus { outline: none; border-color: #0387A5; box-shadow: 0 0 0 3px rgba(3,135,165,.1); }
.lf-submit { width: 100%; padding: .72rem; font-size: .95rem; font-weight: 800; background: #0387A5; color: #fff; border: none; border-radius: 8px; cursor: pointer; }
.lf-submit:hover { background: #026d87; }
.lf-checkout-note { font-size: .7rem; color: #9b9590; text-align: center; margin-top: .6rem; }

/* ── Donation option ── */
.lf-donation { margin-top: 1.5rem; padding: 1.25rem; border: 1.5px dashed #d1d5db; border-radius: 12px; }
.lf-donation p { font-size: .85rem; color: #6b6560; margin-bottom: .75rem; }
.lf-donation-row { display: flex; gap: .65rem; align-items: flex-end; }
.lf-donation-row input { flex: 1; padding: .55rem .7rem; border: 1.5px solid #d1d5db; border-radius: 7px; font-size: .88rem; font-family: inherit; }
.lf-donation-row button { padding: .55rem 1.1rem; background: #0387A5; color: #fff; border: none; border-radius: 7px; font-weight: 700; font-size: .88rem; cursor: pointer; white-space: nowrap; }

/* ── Series ── */
.lf-series-intro { font-size: .95rem; line-height: 1.75; color: #4a5568; margin-bottom: 1.75rem; }
.lf-series-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
.lf-series-card { border-radius: 12px; overflow: hidden; border: 2px solid #e8ddd5; background: #fff; transition: box-shadow .2s, border-color .2s; }
.lf-series-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); border-color: #b8d8e2; }
.lf-series-card.current { border-color: #0387A5; }
.lf-series-img { height: 120px; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; }
.lf-series-body { padding: .85rem 1rem 1rem; }
.lf-series-name { font-weight: 800; font-size: .93rem; margin-bottom: .3rem; }
.lf-series-desc { font-size: .77rem; color: #6b6560; line-height: 1.5; margin-bottom: .45rem; }
.lf-badge { display: inline-block; font-size: .67rem; font-weight: 700; padding: .18rem .55rem; border-radius: 20px; text-transform: uppercase; letter-spacing: .05em; }
.lf-badge.avail { background: #dcfce7; color: #166534; }
.lf-badge.soon  { background: #fef9c3; color: #854d0e; }

/* ── Event ── */
.lf-ev-meta { display: flex; flex-direction: column; gap: .45rem; margin-bottom: 1.25rem; }
.lf-ev-row { display: flex; align-items: center; gap: .55rem; font-size: .9rem; color: #4a5568; }

/* ── Right sidebar ── */
.lf-sticky { position: sticky; top: 72px; }
.lf-info-card {
  background: #fff; border: 1px solid #e8ddd5; border-radius: 14px;
  padding: 1.5rem; box-shadow: 0 2px 16px rgba(0,0,0,.05);
}
.lf-mini-bar-wrap { margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid #f0ebe5; }
.lf-mini-bar { height: 8px; border-radius: 4px; background: #e8f4f8; overflow: hidden; margin-bottom: .4rem; }
.lf-mini-bar-fill { height: 100%; border-radius: 4px; background: #0387A5; }
.lf-mini-bar-label { display: flex; justify-content: space-between; font-size: .76rem; color: #6b7280; }
.lf-mini-bar-label strong { color: #0387A5; }
.lf-info-stats { display: flex; flex-direction: column; gap: .85rem; margin-bottom: 1.35rem; }
.lf-info-stat { display: flex; align-items: center; gap: .75rem; }
.lf-info-icon { width: 36px; height: 36px; border-radius: 8px; background: #eef7fb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1rem; }
.lf-info-text { font-size: .82rem; color: #6b7280; }
.lf-info-text strong { display: block; font-size: 1rem; font-weight: 800; color: #1a2e2c; }
.lf-info-cta {
  display: block; width: 100%; padding: .7rem 1rem; text-align: center;
  background: transparent; border: 2px solid #0387A5; border-radius: 9px;
  color: #0387A5; font-weight: 700; font-size: .9rem; cursor: pointer;
  font-family: inherit; transition: background .15s, color .15s;
}
.lf-info-cta:hover { background: #0387A5; color: #fff; }
.lf-info-note { font-size: .72rem; color: #9b9590; text-align: center; margin-top: .6rem; line-height: 1.5; }

/* ── Ticket sidebar ── */
.lf-ticket-card {
  display: none; background: #fff; border: 2px solid #f0ddd1; border-radius: 14px;
  padding: 1.5rem; box-shadow: 0 4px 20px rgba(232,118,58,.1);
}
.lf-ticket-price { text-align: center; margin-bottom: 1.25rem; }
.lf-ticket-main { font-size: 2rem; font-weight: 800; color: #e8763a; }
.lf-ticket-sub { font-size: .78rem; color: #9b9590; margin-top: .15rem; }
.lf-ticket-qty { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem; }
.lf-qty-btn { width: 34px; height: 34px; border: 1.5px solid #d1d5db; border-radius: 7px; background: #f9fafb; font-size: 1.1rem; cursor: pointer; font-family: inherit; }
.lf-qty-inp { width: 46px; text-align: center; padding: .4rem; border: 1.5px solid #d1d5db; border-radius: 7px; font-size: 1rem; font-family: inherit; }
.lf-ticket-total { display: flex; justify-content: space-between; align-items: center; background: #fff8f4; border: 1.5px solid #fde8d8; border-radius: 8px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .88rem; color: #4a5568; }
.lf-ticket-total strong { font-size: 1.1rem; color: #e8763a; }
.lf-ticket-btn { width: 100%; padding: .8rem; font-size: .97rem; font-weight: 800; background: #e8763a; color: #fff; border: none; border-radius: 9px; cursor: pointer; }
.lf-ticket-btn:hover { background: #d0652e; }
.lf-form-note { font-size: .71rem; color: #9b9590; text-align: center; margin-top: .6rem; line-height: 1.5; }

/* ── Campaign strip (above footer) ── */
.lf-strip {
  background: #f0f7fa; border-top: 1px solid #d4e8f0; border-bottom: 1px solid #d4e8f0;
  padding: 1.1rem 2rem; display: flex; align-items: center; gap: 2rem;
}
.lf-strip-title { font-weight: 700; font-size: .95rem; color: #1a2e2c; flex-shrink: 0; }
.lf-strip-bar-wrap { flex: 1; max-width: 360px; }
.lf-strip-bar { height: 7px; border-radius: 4px; background: #cce5ef; overflow: hidden; margin-bottom: .3rem; }
.lf-strip-bar-fill { height: 100%; border-radius: 4px; background: #0387A5; }
.lf-strip-stats { display: flex; gap: 1.25rem; font-size: .76rem; color: #6b7280; }
.lf-strip-stats strong { color: #0387A5; font-weight: 700; }
.lf-strip-days { font-size: .78rem; color: #9b9590; margin-left: auto; white-space: nowrap; }

@media(max-width:700px) {
  .lf-body { grid-template-columns: 1fr; }
  .lf-sticky { position: static; }
  .lf-rewards-grid { grid-template-columns: 1fr; }
  .lf-checkout-row { grid-template-columns: 1fr; }
  .lf-series-grid { grid-template-columns: repeat(2,1fr); }
  .lf-strip { flex-wrap: wrap; }
  .lf-strip-bar-wrap { min-width: 100%; }
}
</style>

<!-- ── Sticky nav ── -->
<nav class="lf-topbar">
  <span class="lf-logo">Лафетки <span>♦</span></span>
  <div class="lf-nav">
    <button class="lf-tab active" data-panel="idea"><?= $is_en ? 'The Idea' : 'Идеята' ?></button>
    <button class="lf-tab" data-panel="faq"><?= $is_en ? 'FAQ' : 'Въпроси' ?></button>
    <button class="lf-tab" data-panel="budget"><?= $is_en ? 'Budget' : 'Бюджет' ?></button>
    <button class="lf-tab" data-panel="rewards"><?= $is_en ? 'Rewards' : 'Награди' ?></button>
    <button class="lf-tab" data-panel="series"><?= $is_en ? 'Series' : 'Серии' ?></button>
    <?php if ($ev_active && $ev_name): ?>
    <button class="lf-tab ev-tab" data-panel="event"><?= $is_en ? 'Event' : 'Събитие' ?></button>
    <?php endif; ?>
  </div>
</nav>
<?php
```

- [ ] **Step 2: Verify syntax**

```bash
php -l lafetki/index.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add lafetki page CSS and sticky nav"
```

---

## Task 5: Content panels — Идеята, Въпроси, Бюджет

**Files:**
- Modify: `lafetki/index.php` (append)

- [ ] **Step 1: Open the two-column grid and add the three info panels**

Append after the nav closing tag:

```php
<!-- ── Two-column body ── -->
<div class="lf-body">
<div class="lf-panels">

  <!-- Идеята -->
  <div class="lf-panel active" id="lf-panel-idea">
    <div class="lf-eyebrow"><?= $is_en ? 'About the project' : 'За какво става дума' ?></div>
    <h2><?= $is_en ? 'The Idea' : 'Идеята' ?></h2>
    <?php if ($desc): ?>
    <div class="lf-desc"><?= $desc ?></div>
    <?php endif; ?>

    <?php if ($photos): ?>
    <div class="c-carousel" id="lfCarousel" style="margin-top:1.75rem;">
      <div class="c-carousel__track" id="lfCarouselTrack">
        <?php foreach ($photos as $p): ?>
        <div class="c-carousel__slide">
          <img src="<?= h($p) ?>" alt="" loading="lazy">
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($photos) > 1): ?>
      <button class="c-carousel__btn c-carousel__btn--prev" id="lfCarouselPrev" aria-label="Previous">&#8249;</button>
      <button class="c-carousel__btn c-carousel__btn--next" id="lfCarouselNext" aria-label="Next">&#8250;</button>
      <div class="c-carousel__dots" id="lfCarouselDots">
        <?php foreach ($photos as $i => $p): ?>
        <button class="c-carousel__dot<?= $i === 0 ? ' active' : '' ?>" data-idx="<?= $i ?>"></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="c-lightbox" id="lfLightbox" role="dialog" aria-modal="true">
      <button class="c-lightbox__close" id="lfLightboxClose" aria-label="Close">&#x2715;</button>
      <button class="c-lightbox__prev" id="lfLightboxPrev" aria-label="Previous">&#8249;</button>
      <img id="lfLightboxImg" src="" alt="">
      <button class="c-lightbox__next" id="lfLightboxNext" aria-label="Next">&#8250;</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Въпроси -->
  <div class="lf-panel" id="lf-panel-faq">
    <div class="lf-eyebrow"><?= $is_en ? 'Have questions?' : 'Имате въпроси?' ?></div>
    <h2><?= $is_en ? 'FAQ' : 'Въпроси и отговори' ?></h2>
    <?php foreach ($faq as $item): ?>
    <div class="lf-faq-item">
      <div class="lf-faq-q">
        <?= h($item['q']) ?>
        <svg class="lf-faq-arrow" width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="lf-faq-a"><?= $item['a'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Бюджет -->
  <div class="lf-panel" id="lf-panel-budget">
    <div class="lf-eyebrow"><?= $is_en ? 'Transparency' : 'Прозрачност' ?></div>
    <h2><?= $is_en ? 'Where the money goes' : 'За какво са парите' ?></h2>
    <?php if ($budget): ?>
    <table class="lf-budget">
      <tbody>
        <?php foreach ($budget as $row): ?>
        <tr>
          <td><?= h($row['label']) ?></td>
          <td><?= lf_fmt_eur((float)$row['amount_eur'], $show_bgn) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><?= $is_en ? 'Total' : 'Общо' ?></td>
          <td><?= lf_fmt_eur(array_sum(array_column($budget, 'amount_eur')), $show_bgn) ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>
```

- [ ] **Step 2: Verify syntax**

```bash
php -l lafetki/index.php
```

- [ ] **Step 3: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add Идеята, Въпроси, Бюджет panels to lafetki page"
```

---

## Task 6: Rewards panel + checkout strip + donation option

**Files:**
- Modify: `lafetki/index.php` (append)

- [ ] **Step 1: Append the Награди panel**

```php
  <!-- Награди -->
  <div class="lf-panel" id="lf-panel-rewards">
    <div class="lf-eyebrow"><?= $is_en ? 'Choose a support level' : 'Изберете ниво на подкрепа' ?></div>
    <h2><?= $is_en ? 'Rewards' : 'Награди' ?></h2>

    <div class="lf-rewards-grid">
      <?php foreach ($rewards as $r): ?>
      <div class="lf-reward"
           data-reward-id="<?= (int)$r['id'] ?>"
           data-amount="<?= h((string)$r['amount_eur']) ?>"
           data-label="<?= h($r['title']) ?>"
           onclick="lfSelectReward(this)">
        <div class="lf-reward-check" aria-hidden="true">✓</div>
        <div class="lf-reward-amt"><?= lf_fmt_eur((float)$r['amount_eur'], $show_bgn) ?></div>
        <div class="lf-reward-title"><?= h($r['title']) ?></div>
        <div class="lf-reward-desc"><?= $r['description'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Checkout strip — revealed on reward selection -->
    <div class="lf-checkout" id="lf-checkout">
      <h3><?= $is_en ? 'Complete your support — ' : 'Завърши подкрепата — ' ?><span id="lf-selected-label"></span></h3>
      <form method="POST" action="<?= SITE_URL ?>/campaign/checkout.php" id="lfPledgeForm">
        <?= csrf_field() ?>
        <input type="hidden" name="lang" value="<?= $lang ?>">
        <input type="hidden" name="reward_id" id="lfRewardIdInput" value="0">
        <input type="hidden" name="amount_eur" id="lfAmountInput" value="">

        <div class="lf-checkout-row">
          <div class="lf-field">
            <label><?= $is_en ? 'Your name' : 'Вашето име' ?></label>
            <input type="text" name="name" required autocomplete="name"
                   placeholder="<?= $is_en ? 'First and last name' : 'Собствено и фамилно' ?>">
          </div>
          <div class="lf-field">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email"
                   placeholder="<?= $is_en ? 'your@email.com' : 'вашият@email.com' ?>">
          </div>
          <div>
            <button type="submit" class="lf-submit">
              <?= $is_en ? 'Continue to payment →' : 'Продължи към плащане →' ?>
            </button>
          </div>
        </div>

        <!-- Delivery fields (same as campaign/index.php — copy the entire #deliverySection block) -->
        <div id="lfDeliverySection" style="display:none;margin-top:1rem;">
          <div style="background:#f0faf9;border:1px solid #b2dbd7;border-radius:6px;padding:.85rem;margin-bottom:1rem;font-size:.85rem;color:#2d6a35;">
            <?= $is_en ? 'Choose how you want to receive your reward.' : 'Изберете как да получите наградата си.' ?>
          </div>
          <div style="margin-bottom:.85rem;">
            <label style="font-weight:600;font-size:.82rem;display:block;margin-bottom:.4rem;"><?= $is_en ? 'Courier' : 'Куриер' ?></label>
            <div style="display:flex;gap:1rem;">
              <?php foreach (['speedy' => 'Speedy', 'boxnow' => 'BoxNow'] as $cv => $cl): ?>
              <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:600;">
                <input type="radio" name="courier" value="<?= $cv ?>" id="lf_courier_<?= $cv ?>"
                       onchange="lfCourierChange(this.value)" style="cursor:pointer;">
                <?= $cl ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div id="lfSpeedyTypes" style="display:none;margin-bottom:.85rem;">
            <label style="font-weight:600;font-size:.82rem;display:block;margin-bottom:.4rem;"><?= $is_en ? 'Delivery type' : 'Вид доставка' ?></label>
            <div style="display:flex;gap:1rem;">
              <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                <input type="radio" name="_speedy_type_ui" value="office" onchange="lfTypeChange('office')" style="cursor:pointer;">
                <?= $is_en ? 'To office' : 'До офис' ?>
              </label>
              <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                <input type="radio" name="_speedy_type_ui" value="address" onchange="lfTypeChange('address')" style="cursor:pointer;">
                <?= $is_en ? 'To address' : 'До адрес' ?>
              </label>
            </div>
          </div>
          <div id="lfOfficeFields" style="display:none;">
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'Office name' : 'Офис' ?></label><input type="text" name="courier_office_name" id="lfOfficeName" placeholder="<?= $is_en ? 'e.g. Speedy Sofia Centre' : 'напр. Speedy София Център' ?>"></div>
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'City' : 'Град' ?></label><input type="text" name="courier_office_city" id="lfOfficeCity"></div>
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label><input type="tel" name="delivery_phone" id="lfOfficePhone" placeholder="+359..."></div>
          </div>
          <div id="lfAddressFields" style="display:none;">
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'Street address' : 'Адрес' ?></label><input type="text" name="delivery_address" autocomplete="street-address" placeholder="<?= $is_en ? 'Street, no., floor, apt.' : 'ул., №, ет., ап.' ?>"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
              <div class="lf-field"><label><?= $is_en ? 'City' : 'Град' ?></label><input type="text" name="delivery_city" autocomplete="address-level2"></div>
              <div class="lf-field"><label><?= $is_en ? 'Postcode' : 'Пощенски код' ?></label><input type="text" name="delivery_postcode" autocomplete="postal-code"></div>
            </div>
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label><input type="tel" name="delivery_phone_addr" autocomplete="tel" placeholder="+359..."></div>
          </div>
          <div id="lfBoxnowFields" style="display:none;">
            <div class="lf-field" style="margin-bottom:.75rem;">
              <label style="display:block;margin-bottom:.4rem;"><?= $is_en ? 'Select a BoxNow locker' : 'Изберете автомат BoxNow' ?></label>
              <button type="button" onclick="lfOpenBoxnow()" style="padding:.55rem 1.25rem;background:#6CD04E;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:600;">
                📦 <?= $is_en ? 'Choose from map' : 'Изберете от картата' ?>
              </button>
              <div id="lfBoxnowSelected" style="display:none;margin-top:.75rem;padding:.75rem 1rem;border-radius:6px;font-size:.875rem;background:#f0ffeb;border:2px solid #6CD04E;"></div>
            </div>
            <div class="lf-field" style="margin-bottom:.75rem;"><label><?= $is_en ? 'Contact phone' : 'Телефон за контакт' ?></label><input type="tel" name="delivery_phone_boxnow" id="lf_delivery_phone_boxnow" autocomplete="tel" placeholder="+359..."></div>
          </div>
          <input type="hidden" name="courier_office_code" id="lfOfficeCode" value="">
          <input type="hidden" name="delivery_type" id="lfDeliveryTypeHidden" value="">
        </div>

      </form>
      <p class="lf-checkout-note">
        <?= $is_en
          ? 'Payment processed securely by DSK Bank · Donation certificate sent by email'
          : 'Плащането се обработва сигурно от DSK Bank · Сертификат за дарение по имейл' ?>
      </p>
    </div>

    <!-- Pure donation option -->
    <div class="lf-donation">
      <p><?= $is_en ? 'Want to donate without a reward?' : 'Искате да дарите без награда?' ?></p>
      <form method="POST" action="<?= SITE_URL ?>/campaign/checkout.php">
        <?= csrf_field() ?>
        <input type="hidden" name="lang" value="<?= $lang ?>">
        <input type="hidden" name="reward_id" value="0">
        <div class="lf-donation-row">
          <input type="number" name="amount_eur" min="1" step="0.01"
                 placeholder="<?= $is_en ? 'amount in EUR' : 'сума в EUR' ?>">
          <input type="text" name="name" required placeholder="<?= $is_en ? 'Your name' : 'Вашето име' ?>" style="flex:1;padding:.55rem .7rem;border:1.5px solid #d1d5db;border-radius:7px;font-size:.88rem;font-family:inherit;">
          <input type="email" name="email" required placeholder="Email" style="flex:1;padding:.55rem .7rem;border:1.5px solid #d1d5db;border-radius:7px;font-size:.88rem;font-family:inherit;">
          <button type="submit"><?= $is_en ? 'Donate →' : 'Дари →' ?></button>
        </div>
      </form>
    </div>
  </div>
```

- [ ] **Step 2: Verify syntax**

```bash
php -l lafetki/index.php
```

- [ ] **Step 3: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add Награди panel with inline checkout to lafetki page"
```

---

## Task 7: Серии + Събитие panels

**Files:**
- Modify: `lafetki/index.php` (append)

- [ ] **Step 1: Append the Серии and Събитие panels**

```php
  <!-- Серии -->
  <div class="lf-panel" id="lf-panel-series">
    <div class="lf-eyebrow"><?= $is_en ? 'Five card series' : 'Пет серии карти' ?></div>
    <h2><?= $is_en ? 'Series' : 'Серии' ?></h2>
    <p class="lf-series-intro">
      <?= $is_en
        ? 'Lafetki is designed as a series of five card sets. We start with Icebreakers — and the rest depends on you.'
        : 'Лафетки е замислена като серия от пет комплекта карти. Започваме с Icebreakers — а останалите серии зависят от вас.' ?>
    </p>
    <div class="lf-series-grid">
      <?php
      $series = [
        ['emoji' => '🧊', 'name' => 'Icebreakers',      'desc_bg' => 'Разговорни карти за разчупване на леда',   'desc_en' => 'Conversation cards for breaking the ice',       'bg' => 'linear-gradient(135deg,#e4f0f5,#b8dde9)', 'current' => true],
        ['emoji' => '🎭', 'name' => 'No Filter',         'desc_bg' => 'Без филтри, без маски',                   'desc_en' => 'No filters, no masks',                          'bg' => 'linear-gradient(135deg,#fef3ec,#fde8d8)', 'current' => false],
        ['emoji' => '👁️',  'name' => 'Mum in my Eyes',   'desc_bg' => 'Майката през погледа на детето',          'desc_en' => 'A mother through a child\'s eyes',              'bg' => 'linear-gradient(135deg,#fef9ec,#fdefd8)', 'current' => false],
        ['emoji' => '🤝', 'name' => 'Eye to Eye',        'desc_bg' => 'Среща с различието',                      'desc_en' => 'Meeting with difference',                       'bg' => 'linear-gradient(135deg,#f0f9f4,#d4edd9)', 'current' => false],
        ['emoji' => '👨‍👩‍👧', 'name' => 'Family Chronicles', 'desc_bg' => 'Историите, които свързват',              'desc_en' => 'The stories that connect us',                   'bg' => 'linear-gradient(135deg,#faf0f9,#f0d8ed)', 'current' => false],
      ];
      foreach ($series as $s):
      ?>
      <div class="lf-series-card<?= $s['current'] ? ' current' : '' ?>">
        <div class="lf-series-img" style="background:<?= $s['bg'] ?>;"><?= $s['emoji'] ?></div>
        <div class="lf-series-body">
          <div class="lf-series-name"><?= h($s['name']) ?></div>
          <div class="lf-series-desc"><?= $is_en ? h($s['desc_en']) : h($s['desc_bg']) ?></div>
          <span class="lf-badge <?= $s['current'] ? 'avail' : 'soon' ?>">
            <?= $s['current'] ? ($is_en ? 'Available' : 'Налична') : ($is_en ? 'Coming soon' : 'Предстои') ?>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($ev_active && $ev_name): ?>
  <!-- Събитие -->
  <div class="lf-panel" id="lf-panel-event">
    <div class="lf-eyebrow" style="color:#e8763a;"><?= $is_en ? 'Launch event' : 'Събитие по повод' ?></div>
    <h2><?= h($ev_name) ?></h2>
    <div class="lf-ev-meta">
      <?php if ($ev_when): ?>
      <div class="lf-ev-row">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8763a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <strong><?= h($ev_when) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($ev_place): ?>
      <div class="lf-ev-row">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8763a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span><?= h($ev_place) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($ev_desc): ?>
    <div style="font-size:.95rem;line-height:1.8;color:#4a5568;margin-bottom:1.5rem;"><?= $ev_desc ?></div>
    <?php endif; ?>
    <?php if ($ev_fb_url): ?>
    <a href="<?= h($ev_fb_url) ?>" target="_blank" rel="noopener noreferrer"
       style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.25rem;background:#1877f2;color:#fff;border-radius:7px;font-weight:600;font-size:.92rem;text-decoration:none;">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
      <?= $is_en ? 'Facebook event' : 'Facebook събитие' ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /lf-panels -->
```

- [ ] **Step 2: Verify syntax**

```bash
php -l lafetki/index.php
```

- [ ] **Step 3: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add Серии and Събитие panels to lafetki page"
```

---

## Task 8: Sticky sidebar + bottom strip + close body

**Files:**
- Modify: `lafetki/index.php` (append)

- [ ] **Step 1: Append the right column (sidebar), close the two-column grid, add strip and footer**

```php
  <!-- ── Right sticky column ── -->
  <div class="lf-sticky">

    <!-- Info card — Идеята / Въпроси / Бюджет / Серии -->
    <div class="lf-info-card" id="lf-info-card">
      <div class="lf-mini-bar-wrap">
        <div class="lf-mini-bar">
          <div class="lf-mini-bar-fill" style="width:<?= round($pct) ?>%;"></div>
        </div>
        <div class="lf-mini-bar-label">
          <span><strong><?= lf_fmt_eur($raised_eur, $show_bgn) ?></strong> <?= $is_en ? 'raised' : 'набрано' ?></span>
          <span><?= round($pct) ?>%</span>
        </div>
      </div>
      <div class="lf-info-stats">
        <div class="lf-info-stat">
          <div class="lf-info-icon">🙌</div>
          <div class="lf-info-text">
            <strong><?= $funders ?></strong>
            <?= $is_en ? 'supporters so far' : 'души вече подкрепиха' ?>
          </div>
        </div>
        <?php if ($days_left !== null): ?>
        <div class="lf-info-stat">
          <div class="lf-info-icon">⏳</div>
          <div class="lf-info-text">
            <strong><?= $days_left ?> <?= $is_en ? 'days' : 'дни' ?></strong>
            <?= $is_en ? 'remaining' : 'оставащи' ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="lf-info-stat">
          <div class="lf-info-icon">🎯</div>
          <div class="lf-info-text">
            <strong><?= lf_fmt_eur(max(0, $target_eur - $raised_eur), $show_bgn) ?></strong>
            <?= $is_en ? 'to goal' : 'до целта' ?>
          </div>
        </div>
      </div>
      <button class="lf-info-cta" onclick="lfSwitchPanel('rewards')">
        <?= $is_en ? 'See how to help' : 'Виж как да помогнеш' ?>
      </button>
      <p class="lf-info-note">
        <?= $is_en
          ? 'No commitment — browse rewards and choose your level'
          : 'Без задължение — разгледайте наградите и изберете ниво на подкрепа' ?>
      </p>
    </div>

    <!-- Ticket card — Събитие panel only -->
    <?php if ($ev_active && $ev_name && $ev_price > 0): ?>
    <div class="lf-ticket-card" id="lf-ticket-card">
      <div class="lf-ticket-price">
        <div class="lf-ticket-main"><?= number_format($ev_price, 2, '.', ' ') ?> EUR</div>
        <div class="lf-ticket-sub"><?= $is_en ? 'per ticket · digital delivery' : 'на билет · изпращане по имейл' ?></div>
      </div>
      <form method="POST" action="<?= SITE_URL ?>/campaign/checkout.php" id="lfTicketForm">
        <?= csrf_field() ?>
        <input type="hidden" name="pledge_type" value="ticket">
        <input type="hidden" name="lang" value="<?= $lang ?>">
        <div class="lf-field" style="margin-bottom:.8rem;">
          <label><?= $is_en ? 'Your name' : 'Вашето име' ?></label>
          <input type="text" name="name" required autocomplete="name"
                 placeholder="<?= $is_en ? 'First and last name' : 'Собствено и фамилно' ?>">
        </div>
        <div class="lf-field" style="margin-bottom:.8rem;">
          <label>Email</label>
          <input type="email" name="email" required autocomplete="email"
                 placeholder="<?= $is_en ? 'your@email.com' : 'вашият@email.com' ?>">
        </div>
        <div class="lf-ticket-qty">
          <button type="button" class="lf-qty-btn" onclick="lfQtyChange(-1)">−</button>
          <input type="number" class="lf-qty-inp" id="lfTicketQty" name="ticket_qty"
                 value="1" min="1" max="10" oninput="lfQtySync()">
          <button type="button" class="lf-qty-btn" onclick="lfQtyChange(1)">+</button>
          <span style="margin-left:auto;font-size:.85rem;color:#6b6560;">
            × <strong><?= number_format($ev_price, 2, '.', ' ') ?> EUR</strong>
          </span>
        </div>
        <div class="lf-ticket-total">
          <span><?= $is_en ? 'Total' : 'Общо' ?></span>
          <strong id="lfTicketTotal"><?= number_format($ev_price, 2, '.', ' ') ?> EUR</strong>
        </div>
        <button type="submit" class="lf-ticket-btn">
          <?= $is_en ? 'Buy ticket →' : 'Купи билет →' ?>
        </button>
        <p class="lf-form-note">
          <?= $is_en
            ? 'Payment via DSK Bank. Ticket sent by email after payment.'
            : 'Плащане през DSK Bank. Билетът се изпраща по имейл след плащане.' ?>
        </p>
      </form>
      <script>
      var _lfTicketPrice = <?= (float)$ev_price ?>;
      function lfQtyChange(delta) {
        var inp = document.getElementById('lfTicketQty');
        var v = Math.min(10, Math.max(1, (parseInt(inp.value) || 1) + delta));
        inp.value = v;
        lfQtySync();
      }
      function lfQtySync() {
        var v = Math.min(10, Math.max(1, parseInt(document.getElementById('lfTicketQty').value) || 1));
        document.getElementById('lfTicketQty').value = v;
        var total = (_lfTicketPrice * v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        document.getElementById('lfTicketTotal').textContent = total + ' EUR';
      }
      </script>
    </div>
    <?php endif; ?>

  </div><!-- /lf-sticky -->

</div><!-- /lf-body -->

<!-- ── Campaign strip (above footer) ── -->
<div class="lf-strip">
  <span class="lf-strip-title"><?= h($title) ?></span>
  <div class="lf-strip-bar-wrap">
    <div class="lf-strip-bar">
      <div class="lf-strip-bar-fill" style="width:<?= round($pct) ?>%;"></div>
    </div>
    <div class="lf-strip-stats">
      <span><strong><?= lf_fmt_eur($raised_eur, $show_bgn) ?></strong> <?= $is_en ? 'raised of' : 'набрано от' ?> <?= lf_fmt_eur($target_eur, $show_bgn) ?></span>
      <span><?= $funders ?> <?= $is_en ? 'supporters' : 'поддръжника' ?></span>
    </div>
  </div>
  <?php if ($days_left !== null): ?>
  <span class="lf-strip-days"><?= $days_left ?> <?= $is_en ? 'days remaining' : 'дни оставащи' ?></span>
  <?php endif; ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
```

- [ ] **Step 2: Verify syntax**

```bash
php -l lafetki/index.php
```

- [ ] **Step 3: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add sticky sidebar, campaign strip, and footer to lafetki page"
```

---

## Task 9: JavaScript — tab switching, FAQ, reward selection, carousel

**Files:**
- Modify: `lafetki/index.php` (append before `require footer.php`)

- [ ] **Step 1: Insert the `<script>` block immediately before the `require footer.php` line**

Replace the closing `<?php require ...footer.php ?>` with:

```php
<script>
(function () {
  var infoCard   = document.getElementById('lf-info-card');
  var ticketCard = document.getElementById('lf-ticket-card');

  // ── Tab switching ─────────────────────────────────────────────────────────
  function lfSwitchPanel(panelId) {
    document.querySelectorAll('.lf-tab').forEach(function (t) { t.classList.remove('active'); });
    var tab = document.querySelector('.lf-tab[data-panel="' + panelId + '"]');
    if (tab) tab.classList.add('active');

    document.querySelectorAll('.lf-panel').forEach(function (p) { p.classList.remove('active'); });
    var panel = document.getElementById('lf-panel-' + panelId);
    if (panel) panel.classList.add('active');

    if (panelId === 'event') {
      if (infoCard)   infoCard.style.display   = 'none';
      if (ticketCard) ticketCard.style.display  = 'block';
    } else if (panelId === 'rewards') {
      if (infoCard)   infoCard.style.display   = 'none';
      if (ticketCard) ticketCard.style.display  = 'none';
    } else {
      if (infoCard)   infoCard.style.display   = 'block';
      if (ticketCard) ticketCard.style.display  = 'none';
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.lfSwitchPanel = lfSwitchPanel;

  document.querySelectorAll('.lf-tab').forEach(function (tab) {
    tab.addEventListener('click', function () { lfSwitchPanel(this.dataset.panel); });
  });

  // ── FAQ accordion ─────────────────────────────────────────────────────────
  document.querySelectorAll('.lf-faq-q').forEach(function (q) {
    q.addEventListener('click', function () { this.parentElement.classList.toggle('open'); });
  });

  // ── Reward card selection ─────────────────────────────────────────────────
  function lfSelectReward(card) {
    document.querySelectorAll('.lf-reward').forEach(function (c) { c.classList.remove('selected'); });
    card.classList.add('selected');
    document.getElementById('lf-selected-label').textContent = card.dataset.label;
    document.getElementById('lfRewardIdInput').value  = card.dataset.rewardId;
    document.getElementById('lfAmountInput').value    = parseFloat(card.dataset.amount).toFixed(2);

    // Show delivery section if reward has physical component (reward_id > 0)
    var delivery = document.getElementById('lfDeliverySection');
    if (delivery) {
      delivery.style.display = 'block';
      document.querySelectorAll('input[name="courier"]').forEach(function (r) { r.required = true; });
    }

    var checkout = document.getElementById('lf-checkout');
    checkout.classList.add('open');
    checkout.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  window.lfSelectReward = lfSelectReward;

  // ── Delivery helpers (mirror campaign/index.php) ──────────────────────────
  function lfResetDelivery() {
    ['lfSpeedyTypes','lfOfficeFields','lfAddressFields','lfBoxnowFields'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    document.querySelectorAll('#lfDeliverySection input[type=text],#lfDeliverySection input[type=tel]').forEach(function (f) {
      f.required = false; f.value = '';
    });
    var oc = document.getElementById('lfOfficeCode');
    if (oc) oc.value = '';
    var dt = document.getElementById('lfDeliveryTypeHidden');
    if (dt) dt.value = '';
  }

  function lfCourierChange(courier) {
    lfResetDelivery();
    document.querySelectorAll('input[name="courier"]').forEach(function (r) { r.required = true; });
    if (courier === 'speedy') {
      document.getElementById('lfSpeedyTypes').style.display = '';
      document.querySelectorAll('input[name="_speedy_type_ui"]').forEach(function (r) { r.required = true; r.checked = false; });
    } else if (courier === 'boxnow') {
      document.getElementById('lfBoxnowFields').style.display = '';
      document.getElementById('lfDeliveryTypeHidden').value = 'locker';
      var ph = document.getElementById('lf_delivery_phone_boxnow');
      if (ph) ph.required = true;
    }
  }
  window.lfCourierChange = lfCourierChange;

  function lfTypeChange(type) {
    document.getElementById('lfOfficeFields').style.display  = type === 'office'  ? '' : 'none';
    document.getElementById('lfAddressFields').style.display = type === 'address' ? '' : 'none';
    document.getElementById('lfDeliveryTypeHidden').value    = type;
    document.querySelectorAll('#lfOfficeFields input').forEach(function (f)  { f.required = type === 'office'; });
    document.querySelectorAll('#lfAddressFields input').forEach(function (f) { f.required = type === 'address'; });
  }
  window.lfTypeChange = lfTypeChange;

  function lfOpenBoxnow() {
    if (typeof BoxNowWidget === 'undefined' || !window._campaignBoxnowPartnerId) return;
    BoxNowWidget.open(window._campaignBoxnowPartnerId, function (locker) {
      var name = locker.name || locker.address || ('BoxNow #' + locker.id);
      document.getElementById('lfOfficeCode').value = locker.id;
      var sel = document.getElementById('lfBoxnowSelected');
      sel.textContent = name;
      sel.style.display = '';
    });
  }
  window.lfOpenBoxnow = lfOpenBoxnow;

  // ── Photo carousel + lightbox (mirrors campaign/index.php) ───────────────
  var photos = <?= json_encode($photos) ?>;
  (function () {
    if (!photos.length) return;
    var track   = document.getElementById('lfCarouselTrack');
    var dots    = document.querySelectorAll('#lfCarousel .c-carousel__dot');
    var prevBtn = document.getElementById('lfCarouselPrev');
    var nextBtn = document.getElementById('lfCarouselNext');
    var slides  = document.querySelectorAll('#lfCarousel .c-carousel__slide');
    var current = 0;

    function goTo(idx) {
      current = (idx + photos.length) % photos.length;
      track.style.transform = 'translateX(-' + (current * 100) + '%)';
      dots.forEach(function (d, i) { d.classList.toggle('active', i === current); });
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1); });
    dots.forEach(function (d) { d.addEventListener('click', function () { goTo(+d.dataset.idx); }); });

    var startX = null;
    track.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    track.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var dx = e.changedTouches[0].clientX - startX;
      if (Math.abs(dx) > 40) goTo(dx < 0 ? current + 1 : current - 1);
      startX = null;
    }, { passive: true });

    var lb      = document.getElementById('lfLightbox');
    var lbImg   = document.getElementById('lfLightboxImg');
    var lbClose = document.getElementById('lfLightboxClose');
    var lbPrev  = document.getElementById('lfLightboxPrev');
    var lbNext  = document.getElementById('lfLightboxNext');
    var lbIndex = 0;

    function lbShow(idx) {
      lbIndex = (idx + photos.length) % photos.length;
      lbImg.src = photos[lbIndex];
      lb.classList.add('open');
      document.body.style.overflow = 'hidden';
      if (photos.length <= 1) {
        if (lbPrev) lbPrev.style.display = 'none';
        if (lbNext) lbNext.style.display = 'none';
      }
    }
    function lbCloseFn() { lb.classList.remove('open'); document.body.style.overflow = ''; }

    slides.forEach(function (slide, i) {
      slide.querySelector('img').addEventListener('click', function () { lbShow(i); });
    });
    if (lbClose) lbClose.addEventListener('click', lbCloseFn);
    lb.addEventListener('click', function (e) { if (e.target === lb) lbCloseFn(); });
    if (lbPrev) lbPrev.addEventListener('click', function () { lbShow(lbIndex - 1); goTo(lbIndex); });
    if (lbNext) lbNext.addEventListener('click', function () { lbShow(lbIndex + 1); goTo(lbIndex); });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('open')) return;
      if (e.key === 'Escape')      lbCloseFn();
      if (e.key === 'ArrowLeft')   { lbShow(lbIndex - 1); goTo(lbIndex); }
      if (e.key === 'ArrowRight')  { lbShow(lbIndex + 1); goTo(lbIndex); }
    });
  })();
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
```

- [ ] **Step 2: Verify syntax one final time**

```bash
php -l lafetki/index.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Run full test suite**

```bash
php vendor/bin/phpunit --stop-on-failure
```

Expected: all existing tests pass.

- [ ] **Step 4: Commit**

```bash
git add lafetki/index.php
git commit -m "feat: add tab-switching JS, FAQ accordion, reward selection, and carousel to lafetki page"
```

---

## Task 10: HTTP smoke test

**Files:**
- Modify: `tests/HttpTest.php`

- [ ] **Step 1: Add the lafetki subdomain smoke test class at the bottom of `tests/HttpTest.php`**

```php
/**
 * Smoke test for lafetki.oddminds.test subdomain.
 * Skipped automatically when the subdomain isn't configured locally.
 */
#[Group('http')]
final class LafetkiHttpTest extends TestCase
{
    private static string $base = 'http://lafetki.oddminds.test';

    public static function setUpBeforeClass(): void
    {
        $ch = curl_init(self::$base . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            self::markTestSkipped('lafetki.oddminds.test is not reachable — skipping.');
        }
    }

    public function test_lafetki_root_returns_200_or_redirect(): void
    {
        $ch = curl_init(self::$base . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 200 when campaign active, 302 redirect to home when inactive
        $this->assertContains($code, [200, 302], "Expected 200 or 302, got $code");
    }
}
```

- [ ] **Step 2: Run tests (smoke test will auto-skip if subdomain not configured)**

```bash
php vendor/bin/phpunit --stop-on-failure
```

Expected: all pass or skip (no failures).

- [ ] **Step 3: Commit**

```bash
git add tests/HttpTest.php
git commit -m "test: add HTTP smoke test for lafetki.oddminds.test"
```

---

## Done

At this point `lafetki/index.php` is complete and all tests pass. The subdomain goes live once the cPanel vhost is created (Task 2) and the server is up to date via `git push` + deploy.
