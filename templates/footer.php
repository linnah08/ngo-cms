<?php
// templates/footer.php
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$lang      = get_lang();
$site_name = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;
$_fmenus    = load_json(CONTENT_PATH . '/menus.json');
$_fnav      = $_fmenus['footer_nav'][$lang]   ?? [];
$_fnav_bg   = $_fmenus['footer_nav']['bg']    ?? [];
$_fnav_en   = $_fmenus['footer_nav']['en']    ?? [];
$_fhelp     = $_fmenus['footer_help'][$lang]  ?? [];
$_fhelp_bg  = $_fmenus['footer_help']['bg']   ?? [];
$_fhelp_en  = $_fmenus['footer_help']['en']   ?? [];
$pages      = $pages ?? load_json(CONTENT_PATH . '/pages.json');
?>
<?php
// Show newsletter banner unless visitor already subscribed (cookie) or is in admin
$_show_nl_banner = empty($_COOKIE['om_nl_sub']) && !str_starts_with($_SERVER['REQUEST_URI'], '/admin');
?>
<?php if ($_show_nl_banner): ?>
<?php foreach (flash_get() as $_f): ?>
<?php if ($_f['type'] === 'success' && str_contains($_f['message'], 'бюлетин') || str_contains($_f['message'], 'subscribed')): ?>
<div style="background:#e6f4ea;border-top:1px solid #a8d5b0;padding:1rem 0;text-align:center;font-size:.9rem;color:#2d6a35;">
  <?= h($_f['message']) ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
<section class="newsletter-banner" style="background:#0387A5;padding:3rem 0;">
  <div class="container" style="max-width:680px;text-align:center;">
    <h3 style="color:#fff;margin:0 0 .5rem;font-size:1.3rem;"><?= t('newsletter.banner.title') ?></h3>
    <p style="color:rgba(255,255,255,.85);margin:0 0 1.5rem;font-size:.95rem;"><?= t('newsletter.banner.text') ?></p>
    <form method="POST" action="/newsletter/subscribe.php"
          style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="email" name="email" required
             placeholder="<?= h(t('newsletter.banner.placeholder')) ?>"
             style="padding:.6rem 1rem;border:none;border-radius:4px;font-size:1rem;font-family:inherit;width:280px;max-width:100%;">
      <button type="submit" class="btn btn--primary"
              style="background:#fff;color:#0387A5;border:none;font-weight:700;padding:.6rem 1.5rem;">
        <?= t('newsletter.banner.submit') ?>
      </button>
    </form>
  </div>
</section>
<?php endif; ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">

      <!-- Brand -->
      <div class="footer-brand">
        <img src="/assets/images/logo.png" alt="<?= h($site_name) ?>">
        <p><span data-cms-field="tagline"
              data-cms-section="footer"
              data-cms-type="text"
              data-cms-bg="<?= h($pages['footer']['tagline'] ?? '') ?>"
              data-cms-en="<?= h($pages['footer']['tagline_en'] ?? '') ?>"><?= h($pages['footer']['tagline'] ?? t('footer.tagline')) ?></span></p>
        <div style="margin-top:1.5rem;">
          <div style="font-size:0.8rem;opacity:0.7;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.5rem;">
            <?= t('footer.donate') ?>
          </div>
          <div class="footer-iban"><?= SITE_IBAN ?></div>
        </div>
      </div>

      <!-- Navigation -->
      <div class="footer-col">
        <h4><?= t('footer.nav') ?></h4>
        <ul>
          <?php foreach ($_fnav as $i => $item): ?>
          <li><a href="<?= h($item['url']) ?>"
                 data-cms-field="label"
                 data-cms-section="menus"
                 data-cms-menu="footer_nav"
                 data-cms-index="<?= $i ?>"
                 data-cms-type="text"
                 data-cms-bg="<?= h($_fnav_bg[$i]['label'] ?? '') ?>"
                 data-cms-en="<?= h($_fnav_en[$i]['label'] ?? '') ?>"><?= h($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Help -->
      <div class="footer-col">
        <h4><?= t('footer.help') ?></h4>
        <ul>
          <?php foreach ($_fhelp as $i => $item): ?>
          <li><a href="<?= h($item['url']) ?>"
                 data-cms-field="label"
                 data-cms-section="menus"
                 data-cms-menu="footer_help"
                 data-cms-index="<?= $i ?>"
                 data-cms-type="text"
                 data-cms-bg="<?= h($_fhelp_bg[$i]['label'] ?? '') ?>"
                 data-cms-en="<?= h($_fhelp_en[$i]['label'] ?? '') ?>"><?= h($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Contact -->
      <div class="footer-col">
        <h4><?= t('footer.contact') ?></h4>
        <ul>
          <li><a href="tel:<?= SITE_PHONE ?>"><?= SITE_PHONE ?></a></li>
          <li><a href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a></li>
        </ul>
        <!-- Social profile URLs live in config.php (SOCIAL_* constants) -->
        <div style="margin-top:1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
          <?php $social_variant = 'footer'; require __DIR__ . '/social-links.php'; ?>
        </div>
      </div>

    </div>

    <!-- Bottom bar -->
    <div class="footer-bottom">
      <div>© <?= date('Y') ?> <?= h($site_name) ?></div>
      <div class="footer-legal">
        <a href="<?= $lang === 'bg' ? '/politika-za-poveritelnost/' : '/en/privacy-policy/' ?>"><?= t('footer.privacy') ?></a>
        <a href="<?= $lang === 'bg' ? '/pravna-informaciya/' : '/en/legal/' ?>"><?= t('footer.legal') ?></a>
        <a href="<?= $lang === 'bg' ? '/usloviya/' : '/en/terms/' ?>"><?= t('footer.terms') ?></a>
        <a href="<?= $lang === 'bg' ? '/politika-za-biskvitki/' : '/en/cookie-policy/' ?>"><?= t('footer.cookies') ?></a>
      </div>
    </div>
  </div>
</footer>

<script src="/assets/js/main.js"></script>

<?php if (empty($_COOKIE['om_cookie_consent']) && !str_starts_with($_SERVER['REQUEST_URI'], '/admin')): ?>
<div id="cookieBanner"
     style="position:fixed;bottom:0;left:0;right:0;z-index:9990;background:#1a1a1a;color:#f0f0f0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;box-shadow:0 -2px 12px rgba(0,0,0,.35);">
  <p style="margin:0;font-size:.875rem;line-height:1.5;flex:1;min-width:200px;">
    <?= h(t('cookies.banner.text')) ?>
    <a href="<?= $lang === 'bg' ? '/politika-za-biskvitki/' : '/en/cookie-policy/' ?>"
       style="color:#4dc8e0;white-space:nowrap;margin-left:.35rem;"><?= h(t('cookies.banner.learn_more')) ?></a>
  </p>
  <div style="display:flex;gap:.5rem;flex-shrink:0;">
    <button onclick="cookieConsent('essential')"
            style="padding:.45rem 1rem;border:1px solid #555;border-radius:5px;background:transparent;color:#f0f0f0;cursor:pointer;font-size:.85rem;font-family:inherit;">
      <?= h(t('cookies.banner.decline')) ?>
    </button>
    <button onclick="cookieConsent('all')"
            style="padding:.45rem 1rem;border:none;border-radius:5px;background:#0387A5;color:#fff;cursor:pointer;font-size:.85rem;font-weight:600;font-family:inherit;">
      <?= h(t('cookies.banner.accept')) ?>
    </button>
  </div>
</div>
<script>
function cookieConsent(choice) {
    document.cookie = 'om_cookie_consent=' + choice + '; max-age=31536000; path=/; samesite=Lax';
    document.getElementById('cookieBanner').style.display = 'none';
    if (choice === 'all') {
<?php $__gtm = defined('GTM_ID') ? GTM_ID : ''; $__ads = defined('GOOGLE_ADS_ID') ? GOOGLE_ADS_ID : ''; $__ga4 = defined('GA4_ID') ? GA4_ID : ''; ?>
<?php if ($__gtm !== ''): ?>
        // Load GTM immediately without requiring a page reload
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?= h($__gtm) ?>');
<?php endif; ?>
<?php if ($__ads !== '' || $__ga4 !== ''): ?>
        // Load GA4 + Google Ads gtag immediately — mirrors what the PHP header does on
        // subsequent page loads. Without this, the page where the user accepts is never tracked.
        (function(d){var s=d.createElement('script');s.async=true;
        s.src='https://www.googletagmanager.com/gtag/js?id=<?= h($__ads !== '' ? $__ads : $__ga4) ?>';
        d.head.appendChild(s);})(document);
        window.dataLayer=window.dataLayer||[];
        window.gtag=function(){dataLayer.push(arguments);};
        gtag('js',new Date());
<?php if ($__ads !== ''): ?>        gtag('config','<?= h($__ads) ?>');
<?php endif; ?>
<?php if ($__ga4 !== ''): ?>        gtag('config','<?= h($__ga4) ?>');
<?php endif; ?>
<?php endif; ?>
    }
}
</script>
<?php endif; ?>
</body>
</html>
