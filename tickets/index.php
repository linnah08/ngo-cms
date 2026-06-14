<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
start_session();

// Redirect if event is not active
if (setting_get('event_active', '0') !== '1') {
    header('Location: /campaign/');
    exit;
}

$ev_name  = setting_get('event_name',        '');
$ev_date  = setting_get('event_date',        '');
$ev_time  = setting_get('event_time',        '');
$ev_place = setting_get('event_place',       '');
$ev_fb    = setting_get('event_fb_url',      '');
$ev_price = (float)(setting_get('event_ticket_price', '0') ?: '0');
$ev_desc  = setting_get('event_description', '');

$lang    = get_lang();
$is_en   = $lang === 'en';

$ev_date_fmt = '';
if ($ev_date) {
    $ev_date_fmt = (new DateTimeImmutable($ev_date))->format($is_en ? 'F j, Y' : 'd.m.Y');
}
$ev_when = $ev_date_fmt . ($ev_time ? ', ' . $ev_time . ($is_en ? '' : ' ч.') : '');
$price_eur     = number_format($ev_price, 2, '.', ' ') . ' EUR';
$price_bgn_val = number_format($ev_price * EUR_BGN_RATE, 2, '.', ' ');

$error = $_SESSION['campaign_error'] ?? null;
unset($_SESSION['campaign_error']);

$page_title       = $ev_name ?: ($is_en ? 'Buy a ticket' : 'Купи билет');
$page_description = $is_en ? 'Buy your ticket for ' . $ev_name : 'Купи билет за ' . $ev_name;
$page_head_extra  = '<style>
  .tk-wrap {
    max-width: 560px;
    margin: 0 auto;
    padding: 2.5rem 1.25rem 4rem;
  }
  .tk-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 2px 20px rgba(0,0,0,.07);
    overflow: hidden;
  }
  .tk-header {
    background: #0387A5;
    color: #fff;
    padding: 2rem 2rem 1.75rem;
  }
  .tk-header h1 {
    margin: 0 0 1rem;
    font-size: 1.55rem;
    line-height: 1.25;
  }
  .tk-meta {
    display: flex;
    flex-direction: column;
    gap: .45rem;
    font-size: .92rem;
    opacity: .92;
  }
  .tk-meta-row {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
  }
  .tk-meta-row svg { flex-shrink: 0; margin-top: 2px; }
  .tk-desc {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #f0ede9;
    font-size: .95rem;
    line-height: 1.75;
    color: #4a5568;
  }
  .tk-form { padding: 1.75rem 2rem 2rem; }
  .tk-form h2 { margin: 0 0 1.25rem; font-size: 1.05rem; color: #1a2e2c; }
  .tk-field { margin-bottom: 1rem; }
  .tk-field label {
    display: block;
    font-size: .8rem;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: .35rem;
  }
  .tk-field input {
    width: 100%;
    box-sizing: border-box;
    padding: .7rem .9rem;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    font-family: inherit;
    background: #fafafa;
    transition: border-color .15s;
  }
  .tk-field input:focus { outline: none; border-color: #0387A5; background: #fff; }
  .tk-price-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f0fafe;
    border: 1.5px solid #bae6f7;
    border-radius: 10px;
    padding: .9rem 1.1rem;
    margin-bottom: 1.25rem;
  }
  .tk-price-label { font-size: .88rem; color: #4a5568; }
  .tk-price-amount { font-size: 1.3rem; font-weight: 700; color: #0387A5; }
  .tk-btn {
    display: block;
    width: 100%;
    padding: .9rem;
    background: #0387A5;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s;
    font-family: inherit;
  }
  .tk-btn:hover { background: #026f89; }
  .tk-error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #dc2626;
    border-radius: 8px;
    padding: .75rem 1rem;
    font-size: .9rem;
    margin-bottom: 1rem;
  }
  .tk-fb {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    margin-top: 1rem;
    font-size: .85rem;
    text-decoration: none;
  }
  .tk-back {
    display: block;
    text-align: center;
    margin-top: 1.25rem;
    font-size: .85rem;
    color: #9ca3af;
    text-decoration: none;
  }
  .tk-back:hover { color: #0387A5; }
  @media (max-width: 600px) {
    .tk-header, .tk-desc, .tk-form { padding-left: 1.25rem; padding-right: 1.25rem; }
  }
</style>';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<div class="tk-wrap">
  <div class="tk-card">

    <!-- ── Event info ─────────────────────────────────────────── -->
    <div class="tk-header">
      <h1><?= h($ev_name) ?></h1>
      <div class="tk-meta">
        <?php if ($ev_when): ?>
        <div class="tk-meta-row">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span><?= h($ev_when) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($ev_place): ?>
        <div class="tk-meta-row">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span><?= h($ev_place) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($ev_fb): ?>
      <a href="<?= h($ev_fb) ?>" target="_blank" rel="noopener noreferrer"
         class="tk-fb" style="color:rgba(255,255,255,.8);">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
        <?= $is_en ? 'Facebook event' : 'Facebook събитие' ?>
      </a>
      <?php endif; ?>
    </div>

    <?php if ($ev_desc): ?>
    <div class="tk-desc">
      <div id="tkDescInner" style="overflow:hidden;position:relative;">
        <?= $ev_desc ?>
      </div>
      <div id="tkDescFade" style="display:none;position:relative;margin-top:-3rem;height:3rem;background:linear-gradient(to bottom,transparent,#fff);pointer-events:none;"></div>
      <button id="tkReadMore" type="button" onclick="
        document.getElementById('tkDescInner').style.maxHeight='none';
        document.getElementById('tkDescFade').style.display='none';
        this.style.display='none';
      " style="display:none;background:none;border:none;color:#0387A5;font-size:.88rem;font-weight:600;font-family:inherit;cursor:pointer;padding:.5rem 0 0;">
        <?= $is_en ? 'Read more ↓' : 'Прочети повече ↓' ?>
      </button>
      <script>
        (function(){
          var inner = document.getElementById('tkDescInner');
          var fade  = document.getElementById('tkDescFade');
          var btn   = document.getElementById('tkReadMore');
          if (inner.scrollHeight > 130) {
            inner.style.maxHeight = '120px';
            fade.style.display = 'block';
            btn.style.display  = 'block';
          }
        })();
      </script>
    </div>
    <?php endif; ?>

    <!-- ── Checkout form ──────────────────────────────────────── -->
    <div class="tk-form">
      <h2><?= $is_en ? 'Get your ticket' : 'Вземи билет' ?></h2>

      <?php if ($error): ?>
      <div class="tk-error"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="/campaign/checkout.php">
        <?= csrf_field() ?>
        <input type="hidden" name="pledge_type" value="ticket">
        <input type="hidden" name="lang" value="<?= h($lang) ?>">

        <div class="tk-field">
          <label><?= $is_en ? 'Full name' : 'Три имена' ?></label>
          <input type="text" name="name" required autocomplete="name"
                 placeholder="<?= $is_en ? 'Jane Doe' : 'Иван Иванов Иванов' ?>">
        </div>

        <div class="tk-field">
          <label><?= $is_en ? 'Email' : 'Имейл' ?></label>
          <input type="email" name="email" required autocomplete="email"
                 placeholder="<?= $is_en ? 'you@example.com' : 'вашият@имейл.com' ?>">
        </div>

        <div class="tk-field">
          <label><?= $is_en ? 'Number of tickets' : 'Брой билети' ?></label>
          <div style="display:flex;align-items:center;gap:.5rem;">
            <button type="button" onclick="tkQtyChange(-1)"
                    style="width:2.4rem;height:2.4rem;border:1.5px solid #d1d5db;border-radius:8px;background:#f9fafb;font-size:1.3rem;line-height:1;cursor:pointer;font-family:inherit;">−</button>
            <input type="number" id="tkQty" name="ticket_qty" value="1" min="1" max="10"
                   style="width:3.5rem;text-align:center;padding:.55rem .5rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:1rem;font-family:inherit;"
                   oninput="tkQtySync()">
            <button type="button" onclick="tkQtyChange(1)"
                    style="width:2.4rem;height:2.4rem;border:1.5px solid #d1d5db;border-radius:8px;background:#f9fafb;font-size:1.3rem;line-height:1;cursor:pointer;font-family:inherit;">+</button>
          </div>
        </div>

        <div class="tk-price-row">
          <span class="tk-price-label"><?= $is_en ? 'Total' : 'Общо' ?></span>
          <span class="tk-price-amount" id="tkTotal">
            <?= $price_eur ?>
            <?php if (!$is_en): ?>
            <span id="tkTotalBgn" style="font-size:.75em;font-weight:500;color:#4a5568;margin-left:.35em;"><?= $price_bgn_val ?> лв</span>
            <?php endif; ?>
          </span>
        </div>

        <button type="submit" class="tk-btn">
          <?= $is_en ? 'Pay & get ticket →' : 'Плати и вземи билет →' ?>
        </button>
      </form>
      <script>
      var _tkUnitPrice = <?= (float)$ev_price ?>;
      var _tkShowBgn   = <?= !$is_en ? 'true' : 'false' ?>;
      var _tkRate      = <?= EUR_BGN_RATE ?>;
      function tkQtyChange(delta) {
        var inp = document.getElementById('tkQty');
        inp.value = Math.min(10, Math.max(1, (parseInt(inp.value) || 1) + delta));
        tkQtySync();
      }
      function tkQtySync() {
        var qty   = Math.min(10, Math.max(1, parseInt(document.getElementById('tkQty').value) || 1));
        document.getElementById('tkQty').value = qty;
        var total = (_tkUnitPrice * qty).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
        document.getElementById('tkTotal').firstChild.textContent = total + ' EUR';
        if (_tkShowBgn) {
          var bgn = (_tkUnitPrice * qty * _tkRate).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
          var bgnEl = document.getElementById('tkTotalBgn');
          if (bgnEl) bgnEl.textContent = bgn + ' лв';
        }
      }
      </script>

      <a href="/campaign/" class="tk-back">
        ← <?= $is_en ? 'Back to campaign' : 'Към кампанията' ?>
      </a>
    </div>

  </div><!-- /tk-card -->
</div><!-- /tk-wrap -->

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
