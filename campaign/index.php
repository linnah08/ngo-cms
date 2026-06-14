<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

// Redirect if campaign is not active
if (setting_get('campaign_active', '0') !== '1') {
    header('Location: /');
    exit;
}

$pdo  = get_pdo();
$boxnow_partner_id = setting_resolve('boxnow_partner_id', 'BOXNOW_PARTNER_ID');
$lang = get_lang();
$is_en = $lang === 'en';

function _campaign_pick(string $en_val, string $bg_val): string {
    return ($en_val !== '') ? $en_val : $bg_val;
}

// Settings — pick EN if available, fall back to BG
$title_bg   = setting_get('campaign_title',       'Помогни на Лафетки');
$title_en   = setting_get('campaign_title_en',    '');
$desc_bg    = setting_get('campaign_description', '');
$desc_en    = setting_get('campaign_description_en', '');
$risks_bg   = setting_get('campaign_risks', '');
$risks_en   = setting_get('campaign_risks_en', '');

$title      = $is_en ? _campaign_pick($title_en, $title_bg) : $title_bg;
$desc       = $is_en ? _campaign_pick($desc_en,  $desc_bg)  : $desc_bg;
$risks      = $is_en ? _campaign_pick($risks_en, $risks_bg) : $risks_bg;

$target_eur = (float)(setting_get('campaign_target_eur', '5000') ?: '5000');
$end_date   = setting_get('campaign_end_date', '');
$photos     = json_decode(setting_get('campaign_photos', '[]'), true) ?: [];

// Event / ticket settings
$ev_active  = setting_get('event_active',       '0') === '1';
$ev_name    = setting_get('event_name',         '');
$ev_date    = setting_get('event_date',         '');
$ev_time    = setting_get('event_time',         '');
$ev_place   = setting_get('event_place',        '');
$ev_fb_url  = setting_get('event_fb_url',       '');
$ev_price   = (float)(setting_get('event_ticket_price', '0') ?: '0');
$ev_desc    = setting_get('event_description',  '');
$ev_date_fmt = $ev_date ? (new DateTimeImmutable($ev_date))->format($is_en ? 'F j, Y' : 'd.m.Y') : '';

// Budget — pick label_en when available
$budget_raw = json_decode(setting_get('campaign_budget', '[]'), true) ?: [];
$budget = array_map(function ($row) use ($is_en) {
    return [
        'label'      => ($is_en && ($row['label_en'] ?? '') !== '') ? $row['label_en'] : $row['label'],
        'amount_eur' => $row['amount_eur'],
    ];
}, $budget_raw);

// FAQ — pick q_en/a_en when available
$faq_raw = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
$faq = array_map(function ($row) use ($is_en) {
    return [
        'q' => ($is_en && ($row['q_en'] ?? '') !== '') ? $row['q_en'] : $row['q'],
        'a' => ($is_en && ($row['a_en'] ?? '') !== '') ? $row['a_en'] : $row['a'],
    ];
}, $faq_raw);

// Rewards — pick title_en/description_en when available
$rewards_raw = $pdo->query("SELECT * FROM campaign_rewards WHERE active=1 ORDER BY position")->fetchAll();
$rewards = array_map(function ($r) use ($is_en) {
    $r['title']       = ($is_en && ($r['title_en'] ?? '') !== '') ? $r['title_en'] : $r['title'];
    $r['description'] = ($is_en && ($r['description_en'] ?? '') !== '') ? $r['description_en'] : $r['description'];
    return $r;
}, $rewards_raw);

// Stats
$stats = $pdo->query("
    SELECT COUNT(*) AS funders, COALESCE(SUM(amount_eur),0) AS raised
    FROM campaign_pledges WHERE payment_status='paid'
")->fetch();
$raised_eur = (float)$stats['raised'];
$funders    = (int)$stats['funders'];
$pct        = $target_eur > 0 ? min(100, $raised_eur / $target_eur * 100) : 0;

// Days remaining
$days_left = null;
if ($end_date) {
    $diff = (int)ceil((strtotime($end_date) - time()) / 86400);
    $days_left = max(0, $diff);
}

// Until end of June 2026 show BGN equivalent alongside EUR
$show_bgn = date('Y-m') < '2026-06';
function fmt_campaign_amount(float $eur, bool $show_bgn): string {
    $eur_str = number_format($eur, 0, '.', ' ') . ' EUR';
    if ($show_bgn) {
        $bgn_str = number_format($eur * EUR_BGN_RATE, 0, '.', ' ') . ' лв';
        return $eur_str . ' <small style="opacity:.75;font-weight:400;">(' . $bgn_str . ')</small>';
    }
    return $eur_str;
}

$page_title = h($title) . ' — Фондация Различни умове';
$page_head_extra = '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<style>
.campaign-hero{background:var(--teal-light,#e4f0f5);color:#1a2e2c;padding:3.5rem 0 2.5rem;}
.campaign-hero h1{font-size:2.2rem;margin:0 0 1rem;line-height:1.25;}
.campaign-hero p{font-size:1.05rem;line-height:1.75;opacity:.92;margin:0;}
.progress-bar{height:14px;border-radius:7px;background:rgba(3,135,165,.18);overflow:hidden;margin:1.5rem 0 .5rem;}
.progress-bar__fill{height:100%;background:var(--teal,#0387A5);border-radius:7px;transition:width .6s ease;}
.stat-row{display:flex;gap:2rem;flex-wrap:wrap;margin-top:.75rem;}
.stat{display:flex;flex-direction:column;}
.stat__value{font-size:1.5rem;font-weight:700;color:var(--teal,#0387A5);}
.stat__label{font-size:.8rem;opacity:.7;text-transform:uppercase;letter-spacing:.05em;}
.campaign-section{padding:3rem 0;}
.reward-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin:1.5rem 0;}
.reward-card{border:2px solid #e8ddd5;border-radius:10px;padding:1.25rem;cursor:pointer;transition:border-color .2s,box-shadow .2s;position:relative;}
.reward-card:hover,.reward-card.selected{border-color:var(--teal,#1b998b);box-shadow:0 0 0 3px rgba(27,153,139,.15);}
.reward-card.selected{background:#f0faf9;}
.reward-card__amount{font-size:1.35rem;font-weight:700;color:var(--teal,#1b998b);margin-bottom:.5rem;}
.reward-card__title{font-weight:700;margin-bottom:.4rem;}
.reward-card__desc{font-size:.85rem;color:#6b6560;line-height:1.55;}
.faq-item{border-bottom:1px solid #e8ddd5;padding:1.1rem 0;}
.faq-item:last-child{border-bottom:none;}
.faq-q{font-weight:700;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:1rem;}
.faq-a{font-size:.92rem;line-height:1.7;color:#444;margin-top:.75rem;display:none;}
.faq-item.open .faq-a{display:block;}
.faq-item.open .faq-arrow{transform:rotate(180deg);}
.faq-arrow{transition:transform .2s;flex-shrink:0;}
.c-carousel{position:relative;margin:1.5rem 0;border-radius:10px;overflow:hidden;background:#000;user-select:none;}
.c-carousel__track{display:flex;transition:transform .35s cubic-bezier(.4,0,.2,1);}
.c-carousel__slide{flex:0 0 100%;position:relative;}
.c-carousel__slide img{width:100%;max-height:480px;object-fit:contain;display:block;background:#111;}
.c-carousel__btn{position:absolute;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.5);color:#fff;border:none;border-radius:50%;width:42px;height:42px;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;transition:background .15s;}
.c-carousel__btn:hover{background:rgba(0,0,0,.75);}
.c-carousel__btn--prev{left:.75rem;}
.c-carousel__btn--next{right:.75rem;}
.c-carousel__dots{display:flex;justify-content:center;gap:.5rem;padding:.75rem 0 .25rem;background:#1a1a1a;}
.c-carousel__dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.35);border:none;cursor:pointer;padding:0;transition:background .2s;}
.c-carousel__dot.active{background:#fff;}
.c-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9000;align-items:center;justify-content:center;}
.c-lightbox.open{display:flex;}
.c-lightbox img{max-width:94vw;max-height:90vh;object-fit:contain;border-radius:6px;}
.c-lightbox__close{position:fixed;top:1.25rem;right:1.5rem;color:#fff;font-size:2rem;cursor:pointer;background:none;border:none;line-height:1;z-index:9001;}
.c-lightbox__prev,.c-lightbox__next{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:50%;width:48px;height:48px;font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:9001;transition:background .15s;}
.c-lightbox__prev:hover,.c-lightbox__next:hover{background:rgba(255,255,255,.3);}
.c-lightbox__prev{left:1rem;}
.c-lightbox__next{right:1rem;}
.form-card{background:#fff;border:1px solid #e8ddd5;border-radius:12px;padding:2rem;}
.form-field{margin-bottom:1rem;}
.form-field label{display:block;font-weight:600;font-size:.88rem;margin-bottom:.35rem;}
.form-field input,.form-field textarea{width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.92rem;font-family:inherit;}
.form-field input:focus,.form-field textarea:focus{outline:none;border-color:var(--teal,#1b998b);box-shadow:0 0 0 3px rgba(27,153,139,.15);}
.budget-table{width:100%;border-collapse:collapse;margin:1.25rem 0;}
.budget-table td{padding:.6rem .75rem;font-size:.92rem;border-top:1px solid #e8ddd5;}
.budget-table td:last-child{text-align:right;font-weight:600;}
.budget-table tr:last-child td{border-top:2px solid #1b998b;font-weight:700;font-size:1rem;}
.share-row{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:1.5rem;}
.share-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:6px;font-size:.85rem;text-decoration:none;font-weight:600;border:none;cursor:pointer;transition:opacity .15s;}
.share-btn:hover{opacity:.85;}
@media(max-width:640px){
  .campaign-hero{padding:2rem 0 1.5rem;}
  .campaign-hero h1{font-size:1.5rem;}
  .stat-row{gap:1rem;}
  .reward-grid{grid-template-columns:1fr;}
  .form-card{padding:1.25rem;}
  .campaign-layout{grid-template-columns:1fr!important;}
  .event-layout{grid-template-columns:1fr!important;}
}
</style>

<!-- ── Hero / Progress ────────────────────────────────────────────────────────── -->
<section class="campaign-hero">
  <div class="container">
    <span style="display:inline-block;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;opacity:.8;margin-bottom:.75rem;"><?= $is_en ? 'Fundraising campaign' : 'Кампания за набиране на средства' ?></span>
    <h1><?= h($title) ?></h1>

    <div class="progress-bar">
      <div class="progress-bar__fill" style="width:<?= round($pct) ?>%;"></div>
    </div>

    <div class="stat-row">
      <div class="stat">
        <span class="stat__value"><?= fmt_campaign_amount($raised_eur, $show_bgn) ?></span>
        <span class="stat__label"><?= $is_en ? 'raised of ' : 'набрано от ' ?><?= fmt_campaign_amount($target_eur, $show_bgn) ?></span>
      </div>
      <div class="stat">
        <span class="stat__value"><?= $funders ?></span>
        <span class="stat__label"><?= $is_en ? 'supporters' : 'поддръжника' ?></span>
      </div>
      <?php if ($days_left !== null): ?>
      <div class="stat">
        <span class="stat__value"><?= $days_left ?></span>
        <span class="stat__label"><?= $is_en ? 'days remaining' : 'дни оставащи' ?></span>
      </div>
      <?php endif; ?>
      <div class="stat">
        <span class="stat__value"><?= round($pct) ?>%</span>
        <span class="stat__label"><?= $is_en ? 'of goal' : 'от целта' ?></span>
      </div>
    </div>
  </div>
</section>

<div class="container" style="padding-top:2.5rem;padding-bottom:3rem;">
  <div style="display:grid;grid-template-columns:1fr 380px;gap:3rem;align-items:start;" class="campaign-layout">

    <!-- ── Left column ──────────────────────────────────────────────────────── -->
    <div>

      <!-- Description -->
      <?php if ($desc): ?>
      <div style="font-size:1rem;line-height:1.8;color:#333;margin-bottom:2rem;">
        <?= $desc ?>
      </div>
      <?php endif; ?>

      <!-- Photos -->
      <?php if ($photos): ?>
      <h2 style="font-size:1.2rem;margin-bottom:0;"><?= $is_en ? 'Photos' : 'Снимки' ?></h2>
      <div class="c-carousel" id="photoCarousel">
        <div class="c-carousel__track" id="carouselTrack">
          <?php foreach ($photos as $p): ?>
          <div class="c-carousel__slide">
            <img src="<?= h($p) ?>" alt="" loading="lazy" style="cursor:zoom-in;">
          </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($photos) > 1): ?>
        <button class="c-carousel__btn c-carousel__btn--prev" id="carouselPrev" aria-label="Previous">&#8249;</button>
        <button class="c-carousel__btn c-carousel__btn--next" id="carouselNext" aria-label="Next">&#8250;</button>
        <div class="c-carousel__dots" id="carouselDots">
          <?php foreach ($photos as $i => $p): ?>
          <button class="c-carousel__dot<?= $i === 0 ? ' active' : '' ?>" data-idx="<?= $i ?>"></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Lightbox -->
      <div class="c-lightbox" id="lightbox" role="dialog" aria-modal="true">
        <button class="c-lightbox__close" id="lightboxClose" aria-label="Close">&#x2715;</button>
        <button class="c-lightbox__prev" id="lightboxPrev" aria-label="Previous">&#8249;</button>
        <img id="lightboxImg" src="" alt="">
        <button class="c-lightbox__next" id="lightboxNext" aria-label="Next">&#8250;</button>
      </div>
      <?php endif; ?>

      <!-- Budget -->
      <?php if ($budget): ?>
      <h2 style="font-size:1.2rem;margin:2rem 0 0;"><?= $is_en ? 'Where the money goes' : 'За какво са парите' ?></h2>
      <table class="budget-table">
        <tbody>
          <?php foreach ($budget as $row): ?>
          <tr>
            <td><?= h($row['label']) ?></td>
            <td><?= fmt_campaign_amount((float)$row['amount_eur'], $show_bgn) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td><?= $is_en ? 'Total' : 'Общо' ?></td>
            <td><?= fmt_campaign_amount(array_sum(array_column($budget, 'amount_eur')), $show_bgn) ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>

      <!-- FAQ -->
      <?php if ($faq): ?>
      <h2 style="font-size:1.2rem;margin:2.5rem 0 .5rem;"><?= $is_en ? 'FAQ' : 'Въпроси и отговори' ?></h2>
      <div>
        <?php foreach ($faq as $item): ?>
        <div class="faq-item">
          <div class="faq-q">
            <?= h($item['q']) ?>
            <svg class="faq-arrow" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="faq-a"><?= $item['a'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Share -->
      <?php
        $share_url  = SITE_URL . '/campaign/';
        $share_enc  = urlencode($share_url);
        $share_text = urlencode($title . ' — ' . $share_url);
      ?>
      <div class="share-row">
        <span style="font-size:.85rem;font-weight:600;color:#6b6560;"><?= $is_en ? 'Share:' : 'Сподели:' ?></span>

        <!-- Facebook -->
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $share_enc ?>"
           target="_blank" rel="noopener noreferrer" class="share-btn"
           style="background:#1877f2;color:#fff;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
          Facebook
        </a>

        <!-- LinkedIn -->
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $share_enc ?>"
           target="_blank" rel="noopener noreferrer" class="share-btn"
           style="background:#0a66c2;color:#fff;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
          LinkedIn
        </a>

        <!-- X / Twitter -->
        <a href="https://x.com/intent/tweet?text=<?= $share_text ?>"
           target="_blank" rel="noopener noreferrer" class="share-btn"
           style="background:#000;color:#fff;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.261 5.632L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
          X
        </a>

        <!-- Threads -->
        <a href="https://www.threads.net/intent/post?text=<?= $share_text ?>"
           target="_blank" rel="noopener noreferrer" class="share-btn"
           style="background:#101010;color:#fff;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.5 12.01v-.017c0-3.58.85-6.432 2.496-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.51 5.467l-2.94.817c-.913-3.26-3.057-4.92-6.396-4.942-2.65.018-4.647.87-5.935 2.533-1.24 1.6-1.87 3.919-1.87 6.94v.016c0 3.016.63 5.335 1.87 6.936 1.288 1.664 3.286 2.516 5.94 2.534 2.43-.017 4.062-.71 5.072-2.12.664-.92 1.056-2.154 1.162-3.66l-.09-.006c-.84.49-1.817.74-2.905.74-1.493 0-2.714-.478-3.612-1.42-.925-.972-1.395-2.316-1.395-3.993 0-1.742.51-3.15 1.518-4.183.979-1.003 2.306-1.52 3.836-1.52 1.748 0 3.107.628 4.036 1.867.919 1.227 1.384 3.01 1.384 5.303 0 .137-.003.27-.007.403.005.122.007.244.007.367 0 2.297-.611 4.13-1.817 5.447-1.238 1.352-3.025 2.038-5.313 2.038zm1.844-9.04c.652 0 1.223-.175 1.696-.519.476-.347.79-.823.93-1.41.09-.38.135-.79.135-1.223 0-1.267-.267-2.222-.793-2.84-.517-.606-1.217-.913-2.082-.913-.8 0-1.445.272-1.917.808-.468.532-.705 1.287-.705 2.243 0 .912.22 1.64.654 2.163.43.52 1.03.783 1.782.783l.3-.092z"/></svg>
          Threads
        </a>

        <!-- Instagram — no web share API; copies link instead -->
        <button id="igShareBtn" class="share-btn"
                style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);color:#fff;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
          Instagram
        </button>

        <!-- Copy link -->
        <button id="copyLinkBtn" class="share-btn"
                style="background:#fff;color:#333;border:1px solid #d1d5db;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
          <?= $is_en ? 'Copy link' : 'Копирай линк' ?>
        </button>
      </div>

    </div><!-- /left -->

    <!-- ── Right column: funding form ────────────────────────────────────────── -->
    <div style="position:sticky;top:1.5rem;">
      <div class="form-card">
        <h2 style="margin:0 0 1.25rem;font-size:1.15rem;"><?= $is_en ? 'Support the campaign' : 'Подкрепи кампанията' ?></h2>

        <!-- Reward selection -->
        <?php if ($rewards): ?>
        <div class="reward-grid" id="rewardGrid">
          <?php foreach ($rewards as $r): ?>
          <div class="reward-card" data-reward-id="<?= $r['id'] ?>" data-amount="<?= $r['amount_eur'] ?>">
            <div class="reward-card__amount"><?= fmt_campaign_amount((float)$r['amount_eur'], $show_bgn) ?></div>
            <div class="reward-card__title"><?= h($r['title']) ?></div>
            <div class="reward-card__desc"><?= $r['description'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="text-align:center;font-size:.82rem;color:#9b9590;margin-bottom:.75rem;">— <?= $is_en ? 'or' : 'или' ?> —</div>
        <?php endif; ?>

        <form method="POST" action="/campaign/checkout.php" id="pledgeForm">
          <?= csrf_field() ?>
          <input type="hidden" name="lang" value="<?= $lang ?>">
          <input type="hidden" name="reward_id" id="rewardIdInput" value="0">

          <div class="form-field">
            <label><?= $is_en ? 'Amount (EUR)' : 'Сума (EUR)' ?></label>
            <input type="number" name="amount_eur" id="amountEurInput"
                   min="1" step="0.01" placeholder="<?= $is_en ? 'e.g. 25.00' : 'напр. 25.00' ?>" required>
            <?php if ($show_bgn): ?>
            <div style="font-size:.78rem;color:#9b9590;margin-top:.3rem;" id="bgnHint"></div>
            <?php endif; ?>
          </div>

          <div class="form-field">
            <label><?= $is_en ? 'Your name' : 'Вашето име' ?></label>
            <input type="text" name="name" required autocomplete="name"
                   placeholder="<?= $is_en ? 'First and last name' : 'Собствено и фамилно' ?>">
          </div>

          <div class="form-field">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email"
                   placeholder="<?= $is_en ? 'your@email.com' : 'вашият@email.com' ?>">
          </div>

          <!-- Reward delivery note — delivery is selected at checkout step 2 -->
          <div id="deliverySection" style="display:none;">
            <div style="background:#f0faf9;border:1px solid #b2dbd7;border-radius:6px;padding:.85rem;margin-bottom:1rem;font-size:.85rem;color:#2d6a35;">
              <?= $is_en ? 'You will choose the delivery method in the next step.' : 'В следващата стъпка ще изберете начин на доставка.' ?>
            </div>
          </div>

          <button type="submit" class="btn btn--primary" style="width:100%;padding:.8rem;font-size:1rem;margin-top:.5rem;">
            <?= $is_en ? 'Continue to payment →' : 'Продължи към плащане →' ?>
          </button>
          <p style="font-size:.76rem;color:#9b9590;text-align:center;margin:.75rem 0 0;">
            <?= $is_en
              ? 'Payment is processed securely by DSK Bank.<br>You will receive a donation certificate by email.'
              : 'Плащането се обработва сигурно от DSK Bank.<br>Ще получите сертификат за дарение по имейл.' ?>
          </p>
        </form>
      </div>
    </div><!-- /right -->

  </div><!-- /grid -->
</div>

<?php if ($ev_active && $ev_name): ?>
<?php
  $ev_when = $ev_date_fmt . ($ev_time ? ($is_en ? ', ' : ', ') . $ev_time . ($is_en ? '' : ' ч.') : '');
  $ev_price_fmt = number_format($ev_price, 2, '.', ' ') . ' EUR';
  $ev_price_bgn = $show_bgn ? ' <small style="opacity:.7;font-weight:400;">(≈ ' . number_format($ev_price * EUR_BGN_RATE, 0, '.', ' ') . ' лв)</small>' : '';
?>
<section style="background:linear-gradient(135deg,#e4f0f5 0%,#f0f9f8 100%);border-top:1px solid #c8e0e8;border-bottom:1px solid #c8e0e8;padding:3.5rem 0;">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 360px;gap:3rem;align-items:start;" class="event-layout">

      <!-- Event info -->
      <div>
        <span style="display:inline-block;font-size:.73rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--teal,#0387A5);margin-bottom:.75rem;">
          <?= $is_en ? 'Launch event' : 'Събитие по повод' ?>
        </span>
        <h2 style="font-size:1.7rem;margin:0 0 1.1rem;line-height:1.25;color:#1a2e2c;"><?= h($ev_name) ?></h2>

        <?php if ($ev_when || $ev_place): ?>
        <div style="display:flex;flex-direction:column;gap:.55rem;margin-bottom:1.5rem;">
          <?php if ($ev_when): ?>
          <div style="display:flex;align-items:center;gap:.6rem;font-size:.95rem;color:#2d5a60;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--teal,#0387A5);"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <strong><?= h($ev_when) ?></strong>
          </div>
          <?php endif; ?>
          <?php if ($ev_place): ?>
          <div style="display:flex;align-items:center;gap:.6rem;font-size:.95rem;color:#2d5a60;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--teal,#0387A5);"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span><?= h($ev_place) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($ev_desc): ?>
        <div style="font-size:.97rem;line-height:1.8;color:#3d4f50;margin-bottom:1.75rem;"><?= $ev_desc ?></div>
        <?php endif; ?>

        <?php if ($ev_fb_url): ?>
        <a href="<?= h($ev_fb_url) ?>" target="_blank" rel="noopener noreferrer"
           style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.25rem;background:#1877f2;color:#fff;border-radius:7px;font-weight:600;font-size:.92rem;text-decoration:none;transition:opacity .15s;"
           onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
          <?= $is_en ? 'Facebook event' : 'Facebook събитие' ?>
        </a>
        <?php endif; ?>
      </div>

      <!-- Ticket purchase form -->
      <div>
        <div class="form-card" style="border-color:#b2dbd7;">
          <div style="text-align:center;margin-bottom:1.25rem;">
            <span style="font-size:2rem;font-weight:800;color:var(--teal,#0387A5);"><?= $ev_price_fmt ?><?= $ev_price_bgn ?></span>
            <div style="font-size:.82rem;color:#6b6560;margin-top:.2rem;"><?= $is_en ? 'per ticket · digital delivery' : 'на билет · изпращане по имейл' ?></div>
          </div>

          <form method="POST" action="/campaign/checkout.php" id="ticketForm">
            <?= csrf_field() ?>
            <input type="hidden" name="pledge_type" value="ticket">
            <input type="hidden" name="lang" value="<?= $lang ?>">

            <div class="form-field">
              <label><?= $is_en ? 'Your name' : 'Вашето име' ?></label>
              <input type="text" name="name" required autocomplete="name"
                     placeholder="<?= $is_en ? 'First and last name' : 'Собствено и фамилно' ?>">
            </div>

            <div class="form-field">
              <label>Email</label>
              <input type="email" name="email" required autocomplete="email"
                     placeholder="<?= $is_en ? 'your@email.com' : 'вашият@email.com' ?>">
            </div>

            <div class="form-field" style="margin-bottom:.75rem;">
              <label><?= $is_en ? 'Number of tickets' : 'Брой билети' ?></label>
              <div style="display:flex;align-items:center;gap:.5rem;">
                <button type="button" onclick="campaignQtyChange(-1)"
                        style="width:2.2rem;height:2.2rem;border:1.5px solid #d1d5db;border-radius:6px;background:#f9fafb;font-size:1.2rem;line-height:1;cursor:pointer;font-family:inherit;">−</button>
                <input type="number" id="campaignTicketQty" name="ticket_qty" value="1" min="1" max="10"
                       style="width:3.5rem;text-align:center;padding:.45rem .5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:1rem;font-family:inherit;"
                       oninput="campaignQtySync()">
                <button type="button" onclick="campaignQtyChange(1)"
                        style="width:2.2rem;height:2.2rem;border:1.5px solid #d1d5db;border-radius:6px;background:#f9fafb;font-size:1.2rem;line-height:1;cursor:pointer;font-family:inherit;">+</button>
                <span style="margin-left:.5rem;font-size:.9rem;color:#6b6560;">
                  <?= $is_en ? '× ' : '× ' ?><span style="font-weight:600;"><?= number_format($ev_price, 2, '.', ' ') ?> EUR</span>
                </span>
              </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;background:#f0fafe;border:1.5px solid #bae6f7;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;">
              <span style="font-size:.88rem;color:#4a5568;"><?= $is_en ? 'Total' : 'Общо' ?></span>
              <span style="font-size:1.25rem;font-weight:700;color:#0387A5;" id="campaignTicketTotal"><?= number_format($ev_price, 2, '.', ' ') ?> EUR</span>
            </div>

            <button type="submit" class="btn btn--primary" style="width:100%;padding:.8rem;font-size:1rem;margin-top:.25rem;">
              <?= $is_en ? 'Buy ticket →' : 'Купи билет →' ?>
            </button>
            <p style="font-size:.75rem;color:#9b9590;text-align:center;margin:.65rem 0 0;">
              <?= $is_en
                ? 'Payment via DSK Bank. Your ticket will be emailed after payment.'
                : 'Плащане през DSK Bank. Билетът се изпраща по имейл след плащане.' ?>
            </p>
          </form>
          <script>
          var _tkPrice = <?= (float)$ev_price ?>;
          function campaignQtyChange(delta) {
            var inp = document.getElementById('campaignTicketQty');
            var v = Math.min(10, Math.max(1, (parseInt(inp.value) || 1) + delta));
            inp.value = v;
            campaignQtySync();
          }
          function campaignQtySync() {
            var v = Math.min(10, Math.max(1, parseInt(document.getElementById('campaignTicketQty').value) || 1));
            document.getElementById('campaignTicketQty').value = v;
            var total = (_tkPrice * v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            document.getElementById('campaignTicketTotal').textContent = total + ' EUR';
          }
          </script>
        </div>
      </div>

    </div>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var showBgn     = <?= $show_bgn ? 'true' : 'false' ?>;
  var rate        = <?= EUR_BGN_RATE ?>;
  var cards       = document.querySelectorAll('.reward-card');
  var rewardInput = document.getElementById('rewardIdInput');
  var amtEur      = document.getElementById('amountEurInput');
  var bgnHint     = document.getElementById('bgnHint');
  var delivery    = document.getElementById('deliverySection');

  function updateBgnHint() {
    if (!bgnHint) return;
    var eur = parseFloat(amtEur.value) || 0;
    var bgnLabel = <?= $is_en ? "'BGN'" : "'лв'" ?>;
    bgnHint.textContent = eur > 0 ? '≈ ' + (eur * rate).toFixed(2) + ' ' + bgnLabel : '';
  }

  function selectReward(card) {
    cards.forEach(function (c) { c.classList.remove('selected'); });
    if (card) {
      card.classList.add('selected');
      rewardInput.value = card.dataset.rewardId;
      amtEur.value = parseFloat(card.dataset.amount).toFixed(2);
      updateBgnHint();
      delivery.style.display = 'block';
    } else {
      rewardInput.value = '0';
      delivery.style.display = 'none';
    }
  }

  cards.forEach(function (card) {
    card.addEventListener('click', function () {
      selectReward(card.classList.contains('selected') ? null : card);
    });
  });

  if (amtEur) amtEur.addEventListener('input', updateBgnHint);
  if (showBgn) updateBgnHint();

  // ── Carousel + Lightbox ───────────────────────────────────────────────────
  var photos = <?= json_encode($photos) ?>;
  (function () {
    if (!photos.length) return;
    var track   = document.getElementById('carouselTrack');
    var dots    = document.querySelectorAll('.c-carousel__dot');
    var prevBtn = document.getElementById('carouselPrev');
    var nextBtn = document.getElementById('carouselNext');
    var slides  = document.querySelectorAll('.c-carousel__slide');
    var current = 0;

    function goTo(idx) {
      current = (idx + photos.length) % photos.length;
      track.style.transform = 'translateX(-' + (current * 100) + '%)';
      dots.forEach(function (d, i) { d.classList.toggle('active', i === current); });
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1); });
    dots.forEach(function (d) { d.addEventListener('click', function () { goTo(+d.dataset.idx); }); });

    // Touch swipe
    var startX = null;
    track.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, {passive:true});
    track.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var dx = e.changedTouches[0].clientX - startX;
      if (Math.abs(dx) > 40) goTo(dx < 0 ? current + 1 : current - 1);
      startX = null;
    }, {passive:true});

    // Lightbox
    var lb       = document.getElementById('lightbox');
    var lbImg    = document.getElementById('lightboxImg');
    var lbClose  = document.getElementById('lightboxClose');
    var lbPrev   = document.getElementById('lightboxPrev');
    var lbNext   = document.getElementById('lightboxNext');
    var lbIndex  = 0;

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
    function lbClose_fn() {
      lb.classList.remove('open');
      document.body.style.overflow = '';
    }

    slides.forEach(function (slide, i) {
      slide.querySelector('img').addEventListener('click', function () { lbShow(i); });
    });
    if (lbClose) lbClose.addEventListener('click', lbClose_fn);
    lb.addEventListener('click', function (e) { if (e.target === lb) lbClose_fn(); });
    if (lbPrev) lbPrev.addEventListener('click', function () { lbShow(lbIndex - 1); goTo(lbIndex); });
    if (lbNext) lbNext.addEventListener('click', function () { lbShow(lbIndex + 1); goTo(lbIndex); });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('open')) return;
      if (e.key === 'Escape') lbClose_fn();
      if (e.key === 'ArrowLeft')  { lbShow(lbIndex - 1); goTo(lbIndex); }
      if (e.key === 'ArrowRight') { lbShow(lbIndex + 1); goTo(lbIndex); }
    });
  })();

  // Copy link helper
  function copyToClipboard(btn, successText, resetText) {
    navigator.clipboard.writeText(window.location.href).then(function () {
      btn.textContent = successText;
      setTimeout(function () { btn.innerHTML = resetText; }, 2500);
    });
  }

  var copyBtn = document.getElementById('copyLinkBtn');
  if (copyBtn) {
    var copyInitial = copyBtn.innerHTML;
    copyBtn.addEventListener('click', function () {
      copyToClipboard(copyBtn, <?= $is_en ? "'✓ Copied!'" : "'✓ Копирано!'" ?>, copyInitial);
    });
  }

  var igBtn = document.getElementById('igShareBtn');
  if (igBtn) {
    var igInitial = igBtn.innerHTML;
    igBtn.addEventListener('click', function () {
      var msg = <?= $is_en
        ? "'Link copied! Paste it in your Instagram story or bio.'"
        : "'Линкът е копиран! Постави го в Instagram story или bio.'" ?>;
      navigator.clipboard.writeText(window.location.href).then(function () {
        igBtn.textContent = <?= $is_en ? "'✓ Link copied'" : "'✓ Линкът е копиран'" ?>;
        setTimeout(function () { igBtn.innerHTML = igInitial; }, 2500);
        // Brief tooltip
        var tip = document.createElement('div');
        tip.textContent = msg;
        tip.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:.6rem 1.1rem;border-radius:8px;font-size:.85rem;z-index:9999;pointer-events:none;max-width:90vw;text-align:center;';
        document.body.appendChild(tip);
        setTimeout(function () { tip.remove(); }, 3000);
      });
    });
  }

  window.campaignCourierChange = campaignCourierChange;
  window.campaignTypeChange    = campaignTypeChange;
  window.campaignOpenBoxnow    = campaignOpenBoxnow;
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
