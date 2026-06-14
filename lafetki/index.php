<?php
// Always resolve to the project root regardless of how the vhost sets DOCUMENT_ROOT.
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
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

// ── Editable UI strings (stored as JSON blob, overridable via inline CMS) ────
$_lf_ui  = json_decode(setting_get('lf_ui', '{}'), true) ?: [];
// $_lf(key, bg_default, en_default) → current-language value
$_lf    = fn(string $k, string $bg, string $en): string => $is_en ? ($_lf_ui[$k.'_en'] ?? $en) : ($_lf_ui[$k] ?? $bg);
$_lf_bg = fn(string $k, string $bg): string => $_lf_ui[$k] ?? $bg;
$_lf_en = fn(string $k, string $en): string => $_lf_ui[$k.'_en'] ?? $en;

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
        'paid'       => !empty($row['paid']),
    ];
}, $budget_raw);
$budget_paid_count = count(array_filter($budget, fn($r) => $r['paid']));

// ── FAQ ───────────────────────────────────────────────────────────────────────
$faq_raw = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
$faq = array_map(function ($row) use ($is_en) {
    return [
        'q'    => ($is_en && ($row['q_en'] ?? '') !== '') ? $row['q_en'] : ($row['q'] ?? ''),
        'a'    => ($is_en && ($row['a_en'] ?? '') !== '') ? $row['a_en'] : ($row['a'] ?? ''),
        // Bilingual originals for the inline CMS editor.
        'q_bg' => $row['q']    ?? '',
        'q_en' => $row['q_en'] ?? '',
        'a_bg' => $row['a']    ?? '',
        'a_en' => $row['a_en'] ?? '',
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

// Packs sold = total printed minus current Icebreakers stock (shop variant under
// the 'lafetki' product). Single source of truth for inventory across all channels.
$packs_total = (int)setting_get('lafetki_total_packs', '100');
$in_stock    = (int)$pdo->query("
    SELECT COALESCE(SUM(pv.stock),0)
    FROM products p
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE p.slug = 'lafetki'
")->fetchColumn();
$packs_sold = max(0, $packs_total - $in_stock);
$pct        = $target_eur > 0 ? min(100, $raised_eur / $target_eur * 100) : 0;

// Link out to the actual Lafetki product in the shop (Icebreakers, available now).
$product_url   = SITE_URL . ($is_en ? '/en/shop/lafetki/' : '/magazin/lafetki/');
$product_avail = $in_stock > 0;
// Main product photo (falls back to the first variant image) for the buy CTA.
$lf_prod_img = (string)$pdo->query("
    SELECT COALESCE(NULLIF(p.image,''), (
        SELECT pv.image FROM product_variants pv
        WHERE pv.product_id = p.id AND pv.image <> '' ORDER BY pv.sort_order LIMIT 1
    ), '')
    FROM products p WHERE p.slug = 'lafetki'
")->fetchColumn();
$product_img = $lf_prod_img !== '' ? SITE_URL . '/assets/images/products/' . $lf_prod_img : '';

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
$page_title   = h($title) . ' — Лафетки';
$meta_desc_bg = setting_get('campaign_meta_description',    '');
$meta_desc_en = setting_get('campaign_meta_description_en', '');
$page_description = $is_en
    ? ($meta_desc_en !== '' ? $meta_desc_en : $meta_desc_bg)
    : $meta_desc_bg;

$page_head_extra = '<style>' .
    '.site-header{display:none!important;}' .
    '.newsletter-banner{display:none!important;}' .
    '.footer-grid{display:none!important;}' .
    '.site-footer{border-top:1px solid #e8ddd5;background:#fff;}' .
    'body.om-admin .lf-topbar{top:44px;}' .
    '</style>' . "\n" .
    '';

if (admin_logged_in() || admin_bar_token_verify()) {
    $_tinymce_key = setting_get('tinymce_api_key', 'no-api-key');
    $page_head_extra .= "\n" . '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';
    $page_head_extra .= "\n" . '<script>window._tinyBase={menubar:false,promotion:false,branding:false,plugins:"lists link hr code emoticons anchor charmap wordcount autoresize",min_height:120,toolbar:"bold italic underline | bullist numlist | link unlink | removeformat | code",toolbar_mode:"wrap",paste_as_text:false,link_default_target:"_blank",relative_urls:false,remove_script_host:false};</script>';
}

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
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
.lf-lang { display: flex; align-items: center; gap: .3rem; font-size: .82rem; font-weight: 800; flex-shrink: 0; }
.lf-lang-opt { color: #b0b4b8; text-decoration: none; padding: .05rem .2rem; border-radius: 5px; transition: color .15s; }
.lf-lang-opt:hover { color: #0387A5; }
.lf-lang-opt.active { color: #0387A5; }
.lf-lang-sep { color: #d8d8d8; }
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

/* ── Photo carousel ── */
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
/* In CMS edit mode show every answer so it can be edited in place. */
body.om-edit-mode .lf-faq-a { display: block; }
body.om-edit-mode .lf-faq-q { cursor: text; }

/* ── Budget ── */
.lf-budget { width: 100%; border-collapse: collapse; }
.lf-budget td { padding: .75rem; font-size: .93rem; border-top: 1px solid #e8ddd5; }
.lf-budget td:last-child { text-align: right; font-weight: 600; }
.lf-budget tfoot td { border-top: 2px solid #0387A5; font-weight: 800; font-size: 1rem; color: #0387A5; }
.lf-budget-summary { font-size: .9rem; color: #15803d; margin-bottom: .9rem; display: flex; align-items: center; gap: .4rem; }
.lf-budget tr.lf-budget-paid td { background: #f0faf4; }
.lf-budget tr.lf-budget-paid td:first-child { box-shadow: inset 3px 0 0 #16a34a; }
.lf-paid-badge {
  display: inline-flex; align-items: center; gap: .25rem;
  background: #dcfce7; color: #15803d; font-size: .68rem; font-weight: 700;
  border-radius: 20px; padding: .12rem .5rem; margin-left: .5rem;
  vertical-align: middle; white-space: nowrap;
}
.lf-budget-summary .lf-paid-badge { margin-left: 0; }

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
.lf-series-intro { font-size: .95rem; line-height: 1.75; color: #4a5568; margin-bottom: 1.5rem; }

/* Featured (available) series */
.lf-series-feat { display: grid; grid-template-columns: 280px 1fr; border: 2px solid #0387A5; border-radius: 18px; overflow: hidden; background: #fff; box-shadow: 0 10px 30px rgba(3,135,165,.10); text-decoration: none; color: inherit; transition: box-shadow .2s, transform .2s; }
.lf-series-feat:hover { box-shadow: 0 14px 36px rgba(3,135,165,.18); transform: translateY(-2px); }
.lf-series-feat-img { background: linear-gradient(135deg,#e4f0f5,#b8dde9); display: flex; align-items: center; justify-content: center; padding: 26px; text-decoration: none; color: inherit; }
.lf-series-feat-img img { width: 100%; max-width: 200px; border-radius: 10px; box-shadow: 0 10px 26px rgba(0,0,0,.18); }
.lf-series-feat-emoji { font-size: 3.4rem; }
.lf-series-feat-body { padding: 1.6rem 1.8rem; }
.lf-series-feat-eyebrow { display: flex; align-items: center; gap: .45rem; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #0387A5; }
.lf-feat-dot { width: 6px; height: 6px; border-radius: 50%; background: #0387A5; }
.lf-series-feat-name { font-size: 1.7rem; font-weight: 800; line-height: 1.1; margin: .4rem 0 .5rem; }
.lf-series-feat-desc { font-size: .98rem; line-height: 1.6; color: #6b6560; margin: 0; max-width: 46ch; }
.lf-series-feat-btn { display: inline-flex; align-items: center; gap: .5rem; margin-top: 1.2rem; background: #0387A5; color: #fff; padding: .7rem 1.4rem; border-radius: 11px; font-weight: 800; font-size: .95rem; text-decoration: none; transition: background .15s; }
.lf-series-feat:hover .lf-series-feat-btn { background: #026d87; }

/* Upcoming strip */
.lf-up-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: .9rem; margin-top: 1.1rem; }
.lf-up-card { border: 1.5px solid #ece3da; border-radius: 15px; background: #fff; padding: 1.15rem .9rem; text-align: center; }
.lf-up-card.current { border-color: #0387A5; }
.lf-up-num { font-size: .64rem; font-weight: 800; color: #c2b8ad; letter-spacing: .12em; }
.lf-up-emoji { width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: .5rem auto .7rem; }
.lf-up-name { font-weight: 800; font-size: 1rem; margin-bottom: .25rem; }
.lf-up-desc { font-size: .74rem; color: #8a8178; line-height: 1.45; min-height: 2.4em; }
.lf-up-badge { display: inline-block; margin-top: .6rem; font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: .2rem .55rem; border-radius: 20px; }
.lf-up-badge.soon { background: #fdf6d0; color: #9a7b1e; }
.lf-up-badge.avail { background: #dcfce7; color: #166534; }
.lf-buy-wrap { margin-top: .9rem; padding-top: .9rem; border-top: 1px solid #eee4dc; }
.lf-buy-head { display: flex; align-items: center; gap: .7rem; margin-bottom: .7rem; }
.lf-buy-photo { flex-shrink: 0; display: block; width: 60px; height: 60px; border-radius: 10px; overflow: hidden; border: 1px solid #e8ddd5; background: #f7f2ec; padding: 4px; }
.lf-buy-photo img { width: 100%; height: 100%; object-fit: contain; display: block; }
.lf-buy-eyebrow { flex: 1; min-width: 0; font-size: .82rem; font-weight: 600; color: #4a4540; margin: 0; line-height: 1.4; }
.lf-buy-cta { display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%; padding: .72rem 1.05rem; background: #0387A5; border: 2px solid #0387A5; border-radius: 10px; color: #fff; font-weight: 700; font-size: .92rem; text-decoration: none; white-space: nowrap; transition: background .15s, border-color .15s, transform .15s; }
.lf-buy-cta:hover { background: #026d87; border-color: #026d87; color: #fff; transform: translateY(-1px); }
.lf-buy-arrow { font-size: 1.05rem; line-height: 1; transition: transform .15s; }
.lf-buy-cta:hover .lf-buy-arrow { transform: translateX(3px); }

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
  .lf-series-feat { grid-template-columns: 1fr; }
  .lf-series-feat-img { padding: 22px; }
  .lf-up-grid { grid-template-columns: repeat(2,1fr); }
  .lf-strip { flex-wrap: wrap; }
  .lf-strip-bar-wrap { min-width: 100%; }
}
</style>

<!-- ── Sticky nav ── -->
<nav class="lf-topbar">
  <span class="lf-logo">Лафетки <span>♦</span></span>
  <div class="lf-nav">
    <?php
    $tabs = [
      ['panel'=>'idea',    'key'=>'tab_idea',    'bg'=>'Идеята',   'en'=>'The Idea', 'cls'=>''],
      ['panel'=>'faq',     'key'=>'tab_faq',     'bg'=>'Въпроси',  'en'=>'FAQ',      'cls'=>''],
      ['panel'=>'budget',  'key'=>'tab_budget',  'bg'=>'Бюджет',   'en'=>'Budget',   'cls'=>''],
      ['panel'=>'rewards', 'key'=>'tab_rewards', 'bg'=>'Награди',  'en'=>'Rewards',  'cls'=>''],
      ['panel'=>'series',  'key'=>'tab_series',  'bg'=>'Серии',    'en'=>'Series',   'cls'=>''],
    ];
    if ($ev_active && $ev_name) {
        $tabs[] = ['panel'=>'event','key'=>'tab_event','bg'=>'Събитие','en'=>'Event','cls'=>' ev-tab'];
    }
    foreach ($tabs as $i => $_t):
    ?>
    <button class="lf-tab<?= $_t['cls'] ?><?= $i === 0 ? ' active' : '' ?>" data-panel="<?= $_t['panel'] ?>"><span
        data-cms-field="<?= $_t['key'] ?>"
        data-cms-section="lf_ui"
        data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg($_t['key'], $_t['bg'])) ?>"
        data-cms-en="<?= h($_lf_en($_t['key'], $_t['en'])) ?>"
        ><?= h($_lf($_t['key'], $_t['bg'], $_t['en'])) ?></span></button>
    <?php endforeach; ?>
  </div>
  <div class="lf-lang" style="margin-left:auto;">
    <a href="/" onclick="this.href='/'+location.hash" class="lf-lang-opt<?= $is_en ? '' : ' active' ?>"<?= $is_en ? '' : ' aria-current="true"' ?> aria-label="Български">БГ</a>
    <span class="lf-lang-sep" aria-hidden="true">/</span>
    <a href="/en/" onclick="this.href='/en/'+location.hash" class="lf-lang-opt<?= $is_en ? ' active' : '' ?>"<?= $is_en ? ' aria-current="true"' : '' ?> aria-label="English">EN</a>
  </div>
  <a href="<?= SITE_URL . ($is_en ? '/en/' : '/') ?>" class="lf-back-link" style="font-size:.8rem;color:#6b7280;text-decoration:none;white-space:nowrap;flex-shrink:0;margin-left:.9rem;">← oddminds.org</a>
</nav>
<!-- ── Two-column body ── -->
<div class="lf-body">
<div class="lf-panels">

  <!-- Идеята -->
  <div class="lf-panel active" id="lf-panel-idea">
    <div class="lf-eyebrow"
         data-cms-field="eyebrow_idea" data-cms-section="lf_ui" data-cms-type="text"
         data-cms-bg="<?= h($_lf_bg('eyebrow_idea', 'За какво става дума')) ?>"
         data-cms-en="<?= h($_lf_en('eyebrow_idea', 'About the project')) ?>"
         ><?= h($_lf('eyebrow_idea', 'За какво става дума', 'About the project')) ?></div>
    <h2 data-cms-field="tab_idea" data-cms-section="lf_ui" data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg('tab_idea', 'Идеята')) ?>"
        data-cms-en="<?= h($_lf_en('tab_idea', 'The Idea')) ?>"
        ><?= h($_lf('tab_idea', 'Идеята', 'The Idea')) ?></h2>
    <?php if ($desc || admin_logged_in()): ?>
    <div class="lf-desc"
         id="lf-cms-desc"
         data-cms-field="description"
         data-cms-section="campaign_settings"
         data-cms-type="richtext"
         data-cms-bg="<?= h($desc_bg) ?>"
         data-cms-en="<?= h($desc_en) ?>"><?= $is_en ? $desc_en : $desc_bg ?></div>
    <?php endif; ?>

    <?php if ($photos): ?>
    <div class="c-carousel" id="lfCarousel" style="margin-top:1.75rem;">
      <div class="c-carousel__track" id="lfCarouselTrack">
        <?php foreach ($photos as $p): ?>
        <div class="c-carousel__slide">
          <img src="<?= h(str_starts_with($p, '/') ? SITE_URL . $p : $p) ?>" alt="" loading="lazy">
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
    <div class="lf-eyebrow"
         data-cms-field="eyebrow_faq" data-cms-section="lf_ui" data-cms-type="text"
         data-cms-bg="<?= h($_lf_bg('eyebrow_faq', 'Имате въпроси?')) ?>"
         data-cms-en="<?= h($_lf_en('eyebrow_faq', 'Have questions?')) ?>"
         ><?= h($_lf('eyebrow_faq', 'Имате въпроси?', 'Have questions?')) ?></div>
    <h2 data-cms-field="h2_faq" data-cms-section="lf_ui" data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg('h2_faq', 'Въпроси и отговори')) ?>"
        data-cms-en="<?= h($_lf_en('h2_faq', 'FAQ')) ?>"
        ><?= h($_lf('h2_faq', 'Въпроси и отговори', 'FAQ')) ?></h2>
    <?php foreach ($faq as $idx => $item): ?>
    <div class="lf-faq-item om-removable"
         data-cms-remove-type="faq"
         data-cms-remove-id="<?= $idx ?>">
      <div class="lf-faq-q">
        <span data-cms-field="q_<?= $idx ?>"
              data-cms-section="faq"
              data-cms-type="text"
              data-cms-bg="<?= h($item['q_bg']) ?>"
              data-cms-en="<?= h($item['q_en']) ?>"><?= h($item['q']) ?></span>
        <svg class="lf-faq-arrow" width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="lf-faq-a"
           data-cms-field="a_<?= $idx ?>"
           data-cms-section="faq"
           data-cms-type="richtext"
           data-cms-bg="<?= h($item['a_bg']) ?>"
           data-cms-en="<?= h($item['a_en']) ?>"><?= $item['a'] ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (admin_logged_in() || admin_bar_token_verify()): ?>
    <button class="om-add-btn" data-cms-add="faq">+ Add question</button>
    <?php endif; ?>
  </div>

  <!-- Бюджет -->
  <div class="lf-panel" id="lf-panel-budget">
    <div class="lf-eyebrow"
         data-cms-field="eyebrow_budget" data-cms-section="lf_ui" data-cms-type="text"
         data-cms-bg="<?= h($_lf_bg('eyebrow_budget', 'Прозрачност')) ?>"
         data-cms-en="<?= h($_lf_en('eyebrow_budget', 'Transparency')) ?>"
         ><?= h($_lf('eyebrow_budget', 'Прозрачност', 'Transparency')) ?></div>
    <h2 data-cms-field="h2_budget" data-cms-section="lf_ui" data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg('h2_budget', 'За какво са парите')) ?>"
        data-cms-en="<?= h($_lf_en('h2_budget', 'Where the money goes')) ?>"
        ><?= h($_lf('h2_budget', 'За какво са парите', 'Where the money goes')) ?></h2>
    <?php if ($budget): ?>
    <?php if ($budget_paid_count > 0): ?>
    <p class="lf-budget-summary">
      <?php if ($is_en): ?>
        <span class="lf-paid-badge">&#10003;</span> <?= $budget_paid_count ?> of <?= count($budget) ?> items funded — going according to plan.
      <?php else: ?>
        <span class="lf-paid-badge">&#10003;</span> <?= $budget_paid_count ?> от <?= count($budget) ?> пера са платени — върви по план.
      <?php endif; ?>
    </p>
    <?php endif; ?>
    <table class="lf-budget">
      <tbody>
        <?php foreach ($budget as $row): ?>
        <tr<?= $row['paid'] ? ' class="lf-budget-paid"' : '' ?>>
          <td>
            <?= h($row['label']) ?>
            <?php if ($row['paid']): ?>
            <span class="lf-paid-badge">&#10003; <?= $is_en ? 'Paid' : 'Платено' ?></span>
            <?php endif; ?>
          </td>
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

  <!-- Награди -->
  <div class="lf-panel" id="lf-panel-rewards">
    <div class="lf-eyebrow"
         data-cms-field="eyebrow_rewards" data-cms-section="lf_ui" data-cms-type="text"
         data-cms-bg="<?= h($_lf_bg('eyebrow_rewards', 'Изберете ниво на подкрепа')) ?>"
         data-cms-en="<?= h($_lf_en('eyebrow_rewards', 'Choose a support level')) ?>"
         ><?= h($_lf('eyebrow_rewards', 'Изберете ниво на подкрепа', 'Choose a support level')) ?></div>
    <h2 data-cms-field="tab_rewards" data-cms-section="lf_ui" data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg('tab_rewards', 'Награди')) ?>"
        data-cms-en="<?= h($_lf_en('tab_rewards', 'Rewards')) ?>"
        ><?= h($_lf('tab_rewards', 'Награди', 'Rewards')) ?></h2>

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

        <!-- Reward delivery note — delivery is selected at checkout step 2 -->
        <div id="lfDeliverySection" style="display:none;margin-top:1rem;">
          <div style="background:#f0faf9;border:1px solid #b2dbd7;border-radius:6px;padding:.85rem;font-size:.85rem;color:#2d6a35;">
            <?= $is_en ? 'You will choose the delivery method in the next step.' : 'В следващата стъпка ще изберете начин на доставка.' ?>
          </div>
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

    <!-- Campaign progress + buy area (mirrors the right-column info card).
         Distinct ids/no ids so the sticky #lf-info-card stays the single JS target. -->
    <div class="lf-info-card" id="lf-rewards-info-card" style="margin-top:1.75rem;">
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
          <div class="lf-info-icon">📦</div>
          <div class="lf-info-text">
            <strong><?= $packs_sold ?></strong>
            <?= $is_en ? 'packs sold' : 'продадени пакета' ?>
          </div>
        </div>
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
      <?php if ($product_avail): ?>
      <div class="lf-buy-wrap">
        <div class="lf-buy-head">
          <?php if ($product_img): ?>
          <a href="<?= h($product_url) ?>" class="lf-buy-photo" tabindex="-1" aria-hidden="true">
            <img src="<?= h($product_img) ?>" alt="" loading="lazy">
          </a>
          <?php endif; ?>
          <p class="lf-buy-eyebrow"
             data-cms-field="buy_eyebrow" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($_lf_bg('buy_eyebrow', 'Първата серия Ледоразбивачи е вече тук')) ?>"
             data-cms-en="<?= h($_lf_en('buy_eyebrow', 'The first series — Icebreakers — is here now')) ?>"
             ><?= h($_lf('buy_eyebrow', 'Първата серия Ледоразбивачи е вече тук', 'The first series — Icebreakers — is here now')) ?></p>
        </div>
        <a href="<?= h($product_url) ?>" class="lf-buy-cta">
          <span data-cms-field="buy_cta" data-cms-section="lf_ui" data-cms-type="text"
                data-cms-bg="<?= h($_lf_bg('buy_cta', 'Вземи Лафетки')) ?>"
                data-cms-en="<?= h($_lf_en('buy_cta', 'Get Lafetki')) ?>"
                ><?= h($_lf('buy_cta', 'Вземи Лафетки', 'Get Lafetki')) ?></span>
          <span class="lf-buy-arrow" aria-hidden="true">→</span>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Серии -->
  <div class="lf-panel" id="lf-panel-series">
    <div class="lf-eyebrow"
         data-cms-field="eyebrow_series" data-cms-section="lf_ui" data-cms-type="text"
         data-cms-bg="<?= h($_lf_bg('eyebrow_series', 'Пет серии Лафетки')) ?>"
         data-cms-en="<?= h($_lf_en('eyebrow_series', 'Five Lafetki series')) ?>"
         ><?= h($_lf('eyebrow_series', 'Пет серии Лафетки', 'Five Lafetki series')) ?></div>
    <h2 data-cms-field="tab_series" data-cms-section="lf_ui" data-cms-type="text"
        data-cms-bg="<?= h($_lf_bg('tab_series', 'Серии')) ?>"
        data-cms-en="<?= h($_lf_en('tab_series', 'Series')) ?>"
        ><?= h($_lf('tab_series', 'Серии', 'Series')) ?></h2>
    <p class="lf-series-intro"
       data-cms-field="series_intro" data-cms-section="lf_ui" data-cms-type="text"
       data-cms-bg="<?= h($_lf_bg('series_intro', 'Лафетки е замислена като серия от пет комплекта Лафетки. Започваме с Ледоразбивачи — а останалите серии зависят от вас.')) ?>"
       data-cms-en="<?= h($_lf_en('series_intro', 'Lafetki is designed as five Lafetki series. We start with Icebreakers — and the rest depends on you.')) ?>"
       ><?= h($_lf('series_intro', 'Лафетки е замислена като серия от пет комплекта Лафетки. Започваме с Ледоразбивачи — а останалите серии зависят от вас.', 'Lafetki is designed as five Lafetki series. We start with Icebreakers — and the rest depends on you.')) ?></p>
    <?php
    $series = [
      ['k' => 'series_1', 'emoji' => '🧊', 'name' => 'Icebreakers', 'name_bg' => 'Ледоразбивачи', 'desc_bg' => 'Разговорни Лафетки за разчупване на леда', 'desc_en' => 'Conversation napkins for breaking the ice', 'bg' => 'linear-gradient(135deg,#e4f0f5,#b8dde9)', 'current' => true],
      ['k' => 'series_2', 'emoji' => '🎭', 'name' => 'No Filter', 'name_bg' => 'Без филтър', 'desc_bg' => 'Без филтри, без маски', 'desc_en' => 'No filters, no masks', 'bg' => 'linear-gradient(135deg,#fef3ec,#fde8d8)', 'current' => false],
      ['k' => 'series_3', 'emoji' => '👁️', 'name' => 'Mum in my Eyes', 'name_bg' => 'Мама през моите очи', 'desc_bg' => 'Майката през погледа на детето', 'desc_en' => 'A mother through a child\'s eyes', 'bg' => 'linear-gradient(135deg,#fef9ec,#fdefd8)', 'current' => false],
      ['k' => 'series_4', 'emoji' => '🤝', 'name' => 'Eye to Eye', 'name_bg' => 'Очи в очи', 'desc_bg' => 'Среща с различието', 'desc_en' => 'Meeting with difference', 'bg' => 'linear-gradient(135deg,#f0f9f4,#d4edd9)', 'current' => false],
      ['k' => 'series_5', 'emoji' => '👨‍👩‍👧', 'name' => 'Family Chronicles', 'name_bg' => 'Семейни хроники', 'desc_bg' => 'Историите, които свързват', 'desc_en' => 'The stories that connect us', 'bg' => 'linear-gradient(135deg,#faf0f9,#f0d8ed)', 'current' => false],
    ];
    // Per-language default name (BG falls back to en `name` when no `name_bg`).
    $lf_name = fn(array $s) => (!$is_en && !empty($s['name_bg'])) ? $s['name_bg'] : $s['name'];
    // CMS-overridable name/desc, keyed off each series' `k`. Defaults = the array literals.
    $lf_s_name = fn(array $s) => $_lf($s['k'] . '_name', (!empty($s['name_bg']) ? $s['name_bg'] : $s['name']), $s['name']);
    $lf_s_desc = fn(array $s) => $_lf($s['k'] . '_desc', $s['desc_bg'], $s['desc_en']);
    $lf_s_name_bg = fn(array $s) => $_lf_bg($s['k'] . '_name', !empty($s['name_bg']) ? $s['name_bg'] : $s['name']);
    $lf_s_name_en = fn(array $s) => $_lf_en($s['k'] . '_name', $s['name']);
    $lf_s_desc_bg = fn(array $s) => $_lf_bg($s['k'] . '_desc', $s['desc_bg']);
    $lf_s_desc_en = fn(array $s) => $_lf_en($s['k'] . '_desc', $s['desc_en']);
    // Feature the available "current" series as a hero; the rest go in the numbered strip.
    $featured = null; $upcoming = [];
    foreach ($series as $s) {
      if ($s['current'] && $product_avail && $featured === null) $featured = $s;
      else $upcoming[] = $s;
    }
    ?>
    <?php if ($featured): ?>
    <div class="lf-series-feat">
      <a class="lf-series-feat-img" href="<?= h($product_url) ?>"<?= $product_img ? '' : ' style="background:' . $featured['bg'] . ';"' ?>>
        <?php if ($product_img): ?><img src="<?= h($product_img) ?>" alt="<?= h($lf_s_name($featured)) ?>" loading="lazy"><?php else: ?><span class="lf-series-feat-emoji"><?= $featured['emoji'] ?></span><?php endif; ?>
      </a>
      <div class="lf-series-feat-body">
        <div class="lf-series-feat-eyebrow"><span class="lf-feat-dot"></span> <span
             data-cms-field="feat_eyebrow" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($_lf_bg('feat_eyebrow', 'Серия 1 от 5 · Налична сега')) ?>"
             data-cms-en="<?= h($_lf_en('feat_eyebrow', 'Series 1 of 5 · Available now')) ?>"
             ><?= h($_lf('feat_eyebrow', 'Серия 1 от 5 · Налична сега', 'Series 1 of 5 · Available now')) ?></span></div>
        <div class="lf-series-feat-name"
             data-cms-field="<?= $featured['k'] ?>_name" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($lf_s_name_bg($featured)) ?>"
             data-cms-en="<?= h($lf_s_name_en($featured)) ?>"
             ><?= h($lf_s_name($featured)) ?></div>
        <p class="lf-series-feat-desc"
           data-cms-field="<?= $featured['k'] ?>_desc" data-cms-section="lf_ui" data-cms-type="text"
           data-cms-bg="<?= h($lf_s_desc_bg($featured)) ?>"
           data-cms-en="<?= h($lf_s_desc_en($featured)) ?>"
           ><?= h($lf_s_desc($featured)) ?></p>
        <a class="lf-series-feat-btn" href="<?= h($product_url) ?>"><span
           data-cms-field="buy_cta" data-cms-section="lf_ui" data-cms-type="text"
           data-cms-bg="<?= h($_lf_bg('buy_cta', 'Вземи Лафетки')) ?>"
           data-cms-en="<?= h($_lf_en('buy_cta', 'Get Lafetki')) ?>"
           ><?= h($_lf('buy_cta', 'Вземи Лафетки', 'Get Lafetki')) ?></span> →</a>
      </div>
    </div>
    <?php endif; ?>
    <div class="lf-up-grid">
      <?php foreach (($featured ? $upcoming : $series) as $i => $s): $n = $featured ? $i + 2 : $i + 1; ?>
      <div class="lf-up-card<?= $s['current'] ? ' current' : '' ?>">
        <div class="lf-up-num"><?= sprintf('%02d', $n) ?></div>
        <div class="lf-up-emoji" style="background:<?= $s['bg'] ?>;"><?= $s['emoji'] ?></div>
        <div class="lf-up-name"
             data-cms-field="<?= $s['k'] ?>_name" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($lf_s_name_bg($s)) ?>"
             data-cms-en="<?= h($lf_s_name_en($s)) ?>"
             ><?= h($lf_s_name($s)) ?></div>
        <div class="lf-up-desc"
             data-cms-field="<?= $s['k'] ?>_desc" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($lf_s_desc_bg($s)) ?>"
             data-cms-en="<?= h($lf_s_desc_en($s)) ?>"
             ><?= h($lf_s_desc($s)) ?></div>
        <?php if ($s['current']): ?>
        <span class="lf-up-badge avail"
              data-cms-field="badge_avail" data-cms-section="lf_ui" data-cms-type="text"
              data-cms-bg="<?= h($_lf_bg('badge_avail', 'Налична')) ?>"
              data-cms-en="<?= h($_lf_en('badge_avail', 'Available')) ?>"
              ><?= h($_lf('badge_avail', 'Налична', 'Available')) ?></span>
        <?php else: ?>
        <span class="lf-up-badge soon"
              data-cms-field="badge_soon" data-cms-section="lf_ui" data-cms-type="text"
              data-cms-bg="<?= h($_lf_bg('badge_soon', 'Предстои')) ?>"
              data-cms-en="<?= h($_lf_en('badge_soon', 'Coming soon')) ?>"
              ><?= h($_lf('badge_soon', 'Предстои', 'Coming soon')) ?></span>
        <?php endif; ?>
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
          <div class="lf-info-icon">📦</div>
          <div class="lf-info-text">
            <strong><?= $packs_sold ?></strong>
            <?= $is_en ? 'packs sold' : 'продадени пакета' ?>
          </div>
        </div>
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
          : 'Без задължение — разгледай наградите и избери ниво на подкрепа' ?>
      </p>
      <?php if ($product_avail): ?>
      <div class="lf-buy-wrap">
        <div class="lf-buy-head">
          <?php if ($product_img): ?>
          <a href="<?= h($product_url) ?>" class="lf-buy-photo" tabindex="-1" aria-hidden="true">
            <img src="<?= h($product_img) ?>" alt="" loading="lazy">
          </a>
          <?php endif; ?>
          <p class="lf-buy-eyebrow"
             data-cms-field="buy_eyebrow" data-cms-section="lf_ui" data-cms-type="text"
             data-cms-bg="<?= h($_lf_bg('buy_eyebrow', 'Първата серия Ледоразбивачи е вече тук')) ?>"
             data-cms-en="<?= h($_lf_en('buy_eyebrow', 'The first series — Icebreakers — is here now')) ?>"
             ><?= h($_lf('buy_eyebrow', 'Първата серия Ледоразбивачи е вече тук', 'The first series — Icebreakers — is here now')) ?></p>
        </div>
        <a href="<?= h($product_url) ?>" class="lf-buy-cta">
          <span data-cms-field="buy_cta" data-cms-section="lf_ui" data-cms-type="text"
                data-cms-bg="<?= h($_lf_bg('buy_cta', 'Вземи Лафетки')) ?>"
                data-cms-en="<?= h($_lf_en('buy_cta', 'Get Lafetki')) ?>"
                ><?= h($_lf('buy_cta', 'Вземи Лафетки', 'Get Lafetki')) ?></span>
          <span class="lf-buy-arrow" aria-hidden="true">→</span>
        </a>
      </div>
      <?php endif; ?>
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
  <span class="lf-strip-title"
        data-cms-field="title"
        data-cms-section="campaign_settings"
        data-cms-type="text"
        data-cms-bg="<?= h($title_bg) ?>"
        data-cms-en="<?= h($title_en) ?>"><?= h($is_en ? $title_en ?: $title_bg : $title_bg) ?></span>
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
  <button onclick="lfSwitchPanel('rewards');window.scrollTo({top:0,behavior:'smooth'});"
          style="flex-shrink:0;padding:.45rem 1.2rem;background:#0387A5;color:#fff;border:none;border-radius:7px;font-size:.875rem;font-weight:700;font-family:inherit;cursor:pointer;white-space:nowrap;">
    <?= $is_en ? 'Support →' : 'Подкрепи →' ?>
  </button>
</div>

<script>
(function () {
  var infoCard   = document.getElementById('lf-info-card');
  var ticketCard = document.getElementById('lf-ticket-card');

  // ── Tab switching ─────────────────────────────────────────────────────────
  // Valid panels = those actually rendered on the page (e.g. `event` only when active).
  var lfPanels = Array.prototype.map.call(
    document.querySelectorAll('.lf-tab'),
    function (t) { return t.dataset.panel; }
  );
  var LF_DEFAULT_PANEL = 'idea';

  function lfIsValidPanel(panelId) {
    return lfPanels.indexOf(panelId) !== -1;
  }

  // Map a panel to its shareable URL hash. `idea` (default) uses the bare path, no hash.
  function lfHashToPanel(hash) {
    var panelId = (hash || '').replace(/^#/, '');
    return lfIsValidPanel(panelId) ? panelId : LF_DEFAULT_PANEL;
  }

  // Switch to a panel. By default updates the URL via pushState (shareable + Back button).
  // Pass updateHistory=false for initial load / popstate (no new history entry).
  function lfSwitchPanel(panelId, updateHistory) {
    if (!lfIsValidPanel(panelId)) panelId = LF_DEFAULT_PANEL;

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

    if (updateHistory !== false) {
      // Build the full URL ourselves and pushState so the browser does NOT
      // jump-scroll to the anchor (which setting location.hash would do).
      var url = panelId === LF_DEFAULT_PANEL
        ? location.pathname + location.search          // bare path, clears hash
        : location.pathname + location.search + '#' + panelId;
      history.pushState({ lfPanel: panelId }, '', url);
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.lfSwitchPanel = lfSwitchPanel;

  document.querySelectorAll('.lf-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      if (document.body.classList.contains('om-edit-mode')) return;
      lfSwitchPanel(this.dataset.panel);
    });
  });

  // Re-activate the correct tab when navigating Back/forward through history.
  window.addEventListener('popstate', function () {
    lfSwitchPanel(lfHashToPanel(location.hash), false);
  });

  // On load: open the tab named in the URL hash (if valid), else the default.
  var lfInitialPanel = lfHashToPanel(location.hash);
  if (lfInitialPanel !== LF_DEFAULT_PANEL) {
    lfSwitchPanel(lfInitialPanel, false);
  }

  // ── FAQ accordion ─────────────────────────────────────────────────────────
  document.querySelectorAll('.lf-faq-q').forEach(function (q) {
    q.addEventListener('click', function () {
      // In CMS edit mode the question is editable in place — don't toggle the accordion.
      if (document.body.classList.contains('om-edit-mode')) return;
      this.parentElement.classList.toggle('open');
    });
  });

  // ── Reward card selection ─────────────────────────────────────────────────
  function lfSelectReward(card) {
    document.querySelectorAll('.lf-reward').forEach(function (c) { c.classList.remove('selected'); });
    card.classList.add('selected');
    document.getElementById('lf-selected-label').textContent = card.dataset.label;
    document.getElementById('lfRewardIdInput').value  = card.dataset.rewardId;
    document.getElementById('lfAmountInput').value    = parseFloat(card.dataset.amount).toFixed(2);

    var delivery = document.getElementById('lfDeliverySection');
    if (delivery) delivery.style.display = 'block';

    var checkout = document.getElementById('lf-checkout');
    checkout.classList.add('open');
    checkout.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  window.lfSelectReward = lfSelectReward;

  // ── Photo carousel + lightbox ─────────────────────────────────────────────
  var photos = <?= json_encode(array_map(fn($p) => str_starts_with($p, '/') ? SITE_URL . $p : $p, $photos)) ?>;
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

