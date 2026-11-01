<?php
// templates/footer.php
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$lang      = get_lang();
$site_name = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;
$_fmenus    = load_json(CONTENT_PATH . '/menus.json');
// Links to a draft or a missing page, or to a switched-off module (Admin → Модули),
// are left out (keys kept for the on-page editor).
require_once __DIR__ . '/../includes/menus.php';
$_fnav      = module_filter_links(menu_public_items(is_array($_fmenus['footer_nav'][$lang] ?? null) ? $_fmenus['footer_nav'][$lang] : []));
$_fnav_bg   = $_fmenus['footer_nav']['bg']    ?? [];
$_fnav_en   = $_fmenus['footer_nav']['en']    ?? [];
$_fhelp     = module_filter_links(menu_public_items(is_array($_fmenus['footer_help'][$lang] ?? null) ? $_fmenus['footer_help'][$lang] : []));
$_fhelp_bg  = $_fmenus['footer_help']['bg']   ?? [];
$_fhelp_en  = $_fmenus['footer_help']['en']   ?? [];
$pages      = $pages ?? load_json(CONTENT_PATH . '/pages.json');
?>
<?php
// Show newsletter banner unless visitor already subscribed (cookie) or is in admin
$_is_admin_page  = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin');
// No sign-up band while „Бюлетин“ is off (Admin → Модули).
$_show_nl_banner = module_enabled_with_needs('newsletter') && empty($_COOKIE['om_nl_sub']) && !$_is_admin_page;
?>
<?php // Messages nothing on the page has shown yet — above all the sign-up
      // band's own result. Shown whether or not the band is: a successful
      // sign-up sets the cookie that hides the band on the very next page.
      // Never auto-dismissed, and an error is announced as one. ?>
<?php if (!$_is_admin_page): foreach (flash_get() as $_f): $_f_err = ($_f['type'] ?? '') === 'error'; ?>
<div role="<?= $_f_err ? 'alert' : 'status' ?>" style="<?= $_f_err
      ? 'background:#fdf0ef;border-top:1px solid #f0c4c0;color:#a4243d;'
      : 'background:#e6f4ea;border-top:1px solid #a8d5b0;color:#2d6a35;' ?>padding:1rem 16px;text-align:center;font-size:.95rem;">
  <?= h($_f['message'] ?? '') ?>
</div>
<?php endforeach; endif; ?>
<?php // The site's own blocks (includes/site-extensions.php 'footer_partial'), e.g. a quiz invitation.
if (!$_is_admin_page) {
    require_once __DIR__ . '/../includes/site_extensions.php';   // also for a site that kept an older config.php
    foreach (site_footer_partials() as $_site_partial) site_extension_render($_site_partial, ['lang' => $lang, 'pages' => $pages]);
} ?>
<?php if ($_show_nl_banner): ?>
<?php // The band colour is chosen in Admin → Организация → Визия (text colour follows
      // for contrast); else a theme's palette sets --newsletter-bg / --newsletter-fg;
      // else the original teal and white. Full-strength text, never faded: a faded
      // line would no longer meet the contrast the admin preview promises. ?>
<section class="newsletter-banner" style="background:var(--newsletter-bg,#037F9B);padding:3rem 0;">
  <div class="container" style="max-width:680px;text-align:center;">
    <?php // Heading and line are editable in Edit mode (newsletter section of pages.json).
          require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
          $_nlb_saved = newsletter_pages_section();
          $_nlb_cms = static fn(string $f): string =>
              'data-cms-field="' . h($f) . '" data-cms-section="newsletter" data-cms-type="text"'
              . ' data-cms-bg="' . h((string) ($_nlb_saved[$f] ?? '')) . '"'
              . ' data-cms-en="' . h((string) ($_nlb_saved[$f . '_en'] ?? '')) . '"'; ?>
    <h3 style="color:var(--newsletter-fg,#fff);margin:0 0 .5rem;font-size:1.3rem;" <?= $_nlb_cms('banner_title') ?>><?= h(newsletter_banner_text('title', $lang)) ?></h3>
    <p style="color:var(--newsletter-fg,#fff);margin:0 0 1.5rem;font-size:.95rem;" <?= $_nlb_cms('banner_text') ?>><?= h(newsletter_banner_text('text', $lang)) ?></p>
    <form method="POST" action="/newsletter/subscribe.php" id="nlBannerForm"
          style="display:flex;gap:.5rem 1.5rem;justify-content:center;align-items:center;flex-wrap:wrap;text-align:left;">
      <?= csrf_field() ?>
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <?php $nl_on_dark = true; require $_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-topics.php'; ?>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:center;">
        <label for="nlBannerEmail" class="sr-only"><?= te('newsletter.banner.placeholder') ?></label>
        <input type="email" name="email" id="nlBannerEmail" required autocomplete="email"
               placeholder="<?= h(t('newsletter.banner.placeholder')) ?>"
               style="padding:.6rem 1rem;border:none;border-radius:4px;font-size:1rem;font-family:inherit;width:280px;max-width:100%;box-sizing:border-box;">
        <button type="submit" class="btn btn--primary"
                style="background:var(--newsletter-fg,#fff);color:var(--newsletter-bg,#037F9B);border:none;font-weight:700;padding:.6rem 1.5rem;">
          <?= te('newsletter.banner.submit') ?>
        </button>
      </div>
    </form>
    <?php // The background sign-up's answer lands here, read out by screen readers:
          // a success politely (role=status), a problem at once (role=alert). Both are
          // empty until then, so nothing is announced on page load. ?>
    <div id="nlBannerOk" role="status" tabindex="-1"
         style="margin:0;font-size:1rem;font-weight:700;color:var(--newsletter-fg,#fff);outline-offset:4px;"></div>
    <div id="nlBannerErr" role="alert"
         style="margin:0;font-size:.95rem;"></div>
  </div>
</section>
<script>
// Footer sign-up without leaving the page. Without JavaScript (or if the request
// fails) the form simply posts as before and the result shows after a reload.
(function () {
  var form = document.getElementById('nlBannerForm');
  var ok   = document.getElementById('nlBannerOk');
  var err  = document.getElementById('nlBannerErr');
  if (!form || !ok || !err || !window.fetch || !window.FormData) return;
  var busy = false;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    busy = true;
    form.setAttribute('aria-busy', 'true');
    var data = new FormData(form);
    data.append('ajax', '1');
    fetch(form.action, {
      method: 'POST', body: data, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      busy = false;
      form.removeAttribute('aria-busy');
      if (res && res.ok) {
        err.textContent = '';
        err.removeAttribute('style');
        err.style.margin = '0';
        form.style.display = 'none';
        ok.textContent = res.message || '';
        ok.style.margin = '1rem 0 0';
        // The button the visitor pressed is gone — keep their place on the answer.
        ok.focus();
      } else {
        err.textContent = (res && res.message) || '';
        err.style.cssText = 'margin:1rem auto 0;max-width:30rem;padding:.6rem 1rem;border-radius:4px;'
          + 'background:#fdf0ef;color:#a4243d;font-size:.95rem;font-weight:600;';
        var target = res && res.field === 'email' ? form.querySelector('input[name="email"]')
                   : res && res.field === 'topics' ? form.querySelector('.nl-topics input[type="checkbox"]')
                   : null;
        if (target) target.focus();
      }
    }).catch(function () {
      // Network trouble or a non-JSON reply: fall back to the normal full-page post.
      busy = false;
      form.submit();
    });
  });
})();
</script>
<?php endif; ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">

      <!-- Brand -->
      <div class="footer-brand">
        <img src="<?= logo_url() ?>" alt="<?= h($site_name) ?>">
        <p><span data-cms-field="tagline"
              data-cms-section="footer"
              data-cms-type="text"
              data-cms-bg="<?= h($pages['footer']['tagline'] ?? '') ?>"
              data-cms-en="<?= h($pages['footer']['tagline_en'] ?? '') ?>"><?= h($pages['footer']['tagline'] ?? t('footer.tagline')) ?></span></p>
        <?php // No bank account to show when donations are off, and an empty
              // IBAN would otherwise render as a blank labelled box. ?>
        <?php if (feature_enabled('donations') && trim((string) SITE_IBAN) !== ''): ?>
        <div style="margin-top:1.5rem;">
          <div style="font-size:0.8rem;opacity:0.7;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.5rem;">
            <?= te('footer.donate') ?>
          </div>
          <div class="footer-iban"<?= cms_admin('organisation') ?>><?= h(SITE_IBAN) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php // A link column with no links in this language is left out, heading and
            // all: emptying it in Admin → Менюта is how an admin removes it. ?>
      <!-- Navigation -->
      <?php if ($_fnav): ?>
      <div class="footer-col">
        <h4><?= te('footer.nav') ?></h4>
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
      <?php endif; ?>

      <!-- Help -->
      <?php if ($_fhelp): ?>
      <div class="footer-col">
        <h4><?= te('footer.help') ?></h4>
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
      <?php endif; ?>

      <!-- Contact -->
      <div class="footer-col">
        <h4><?= te('footer.contact') ?></h4>
        <ul>
          <?php if (site_phone_public() !== ''): ?>
          <li><a href="tel:<?= h(site_phone_public()) ?>"<?= cms_admin('organisation') ?>><?= h(site_phone_public()) ?></a></li>
          <?php endif; ?>
          <li><a href="mailto:<?= SITE_EMAIL ?>"<?= cms_admin('organisation') ?>><?= SITE_EMAIL ?></a></li>
        </ul>
        <!-- Social profile URLs live in config.php (SOCIAL_* constants) -->
        <div style="margin-top:1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
          <?php $social_variant = 'footer'; require __DIR__ . '/social-links.php'; ?>
        </div>
      </div>

    </div>

    <!-- Bottom bar -->
    <div class="footer-bottom">
      <div>© <?= date('Y') ?> <span<?= cms_admin('organisation') ?>><?= h($site_name) ?></span></div>
      <div class="footer-legal">
        <a href="<?= $lang === 'bg' ? '/politika-za-poveritelnost/' : '/en/privacy-policy/' ?>"><?= te('footer.privacy') ?></a>
        <a href="<?= $lang === 'bg' ? '/pravna-informaciya/' : '/en/legal/' ?>"><?= te('footer.legal') ?></a>
        <a href="<?= $lang === 'bg' ? '/usloviya/' : '/en/terms/' ?>"><?= te('footer.terms') ?></a>
        <?php require_once ROOT_PATH . '/includes/financial_reports.php'; if (module_enabled_with_needs('annual_reports') && reports_any_published()): ?>
        <a href="<?= $lang === 'bg' ? '/finansovi-otcheti/' : '/en/financial-reports/' ?>"><?= te('footer.reports') ?></a>
        <?php endif; ?>
        <a href="<?= $lang === 'bg' ? '/politika-za-biskvitki/' : '/en/cookie-policy/' ?>"><?= te('footer.cookies') ?></a>
        <?php // Reopens the cookie banner, so a choice can be changed as easily as it was made. ?>
        <button type="button" onclick="cookieSettings()" aria-controls="cookieBanner"
                style="background:none;border:0;padding:0;margin:0;font:inherit;cursor:pointer;text-decoration:inherit;"><?= te('cookies.settings') ?></button>
      </div>
    </div>
  </div>
</footer>

<script src="/assets/js/main.js"></script>

<?php if (!str_starts_with($_SERVER['REQUEST_URI'], '/admin')): ?>
<?php require_once ROOT_PATH . '/includes/ga4.php'; $__choice = (string) ($_COOKIE['om_cookie_consent'] ?? ''); ?>
<div id="cookieBanner" role="region" aria-label="<?= h(t('cookies.settings')) ?>" tabindex="-1"<?= $__choice !== '' ? ' hidden' : '' ?>
     style="position:fixed;bottom:0;left:0;right:0;z-index:9990;background:#1a1a1a;color:#f0f0f0;padding:1rem 1.5rem;display:<?= $__choice !== '' ? 'none' : 'flex' ?>;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;box-shadow:0 -2px 12px rgba(0,0,0,.35);">
  <p style="margin:0;font-size:.875rem;line-height:1.5;flex:1;min-width:200px;">
    <?= te('cookies.banner.text') ?>
    <a href="<?= $lang === 'bg' ? '/politika-za-biskvitki/' : '/en/cookie-policy/' ?>"
       style="color:var(--banner-accent,#4dc8e0);white-space:nowrap;margin-left:.35rem;"><?= te('cookies.banner.learn_more') ?></a>
  </p>
  <div style="display:flex;gap:.5rem;flex-shrink:0;">
    <button type="button" onclick="cookieConsent('essential')"<?= $__choice === 'essential' ? ' aria-pressed="true"' : '' ?>
            style="min-height:44px;padding:.45rem 1rem;border:1px solid #888;border-radius:5px;background:transparent;color:#f0f0f0;cursor:pointer;font-size:.85rem;font-family:inherit;">
      <?= te('cookies.banner.decline') ?>
    </button>
    <?php // The banner sits on near-black: a theme with a dark primary sets
          // --banner-accent / --banner-accent-fg so the button does not vanish into it. ?>
    <button type="button" onclick="cookieConsent('all')"<?= $__choice === 'all' ? ' aria-pressed="true"' : '' ?>
            style="min-height:44px;padding:.45rem 1rem;border:none;border-radius:5px;background:var(--banner-accent,#0387A5);color:var(--banner-accent-fg,#fff);cursor:pointer;font-size:.85rem;font-weight:600;font-family:inherit;">
      <?= te('cookies.banner.accept') ?>
    </button>
  </div>
</div>
<script>
// The Google tags are already on the page under Consent Mode (templates/header.php):
// a choice only tells them what they may store — nothing is loaded or reloaded here.
var _cookieOpener = null;
function cookieSettings() {
    var b = document.getElementById('cookieBanner');
    _cookieOpener = document.activeElement;
    b.hidden = false; b.style.display = 'flex';
    b.focus();
}
function cookieConsent(choice) {
    document.cookie = 'om_cookie_consent=' + choice + '; max-age=31536000; path=/; samesite=Lax';
    var b = document.getElementById('cookieBanner');
    b.hidden = true; b.style.display = 'none';
    if (_cookieOpener && _cookieOpener.focus) { _cookieOpener.focus(); _cookieOpener = null; }
    var all = choice === 'all' ? 'granted' : 'denied';
    if (typeof gtag === 'function') {
        gtag('consent', 'update', {ad_storage: all, ad_user_data: all, ad_personalization: all, analytics_storage: all});
    }
    if (choice === 'all') {
        // A Google Ads click id on this landing page is kept for 90 days (includes/ga4.php).
        var m = /[?&]gclid=([A-Za-z0-9_\-]{10,255})/.exec(location.search);
        if (m) document.cookie = '<?= GA4_GCLID_COOKIE ?>=' + m[1] + '; max-age=<?= GA4_GCLID_DAYS * 86400 ?>; path=/; samesite=Lax' + (location.protocol === 'https:' ? '; secure' : '');
    } else {
        // Taking consent back removes what the Google tags and the click id stored.
        var host = location.hostname, parent = host.split('.').slice(-2).join('.');
        document.cookie.split(';').forEach(function (c) {
            var name = c.split('=')[0].trim();
            if (!/^(_ga|_gid|_gcl_|_gac_|<?= GA4_GCLID_COOKIE ?>$)/.test(name)) return;
            ['', '; domain=' + host, '; domain=.' + host, '; domain=.' + parent].forEach(function (d) {
                document.cookie = name + '=; max-age=0; path=/' + d;
            });
        });
    }
}
</script>
<?php endif; ?>
<?php // Jobs the server's schedule isn't running are started after the page is sent (includes/scheduled_jobs.php).
try { require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php'; scheduled_jobs_run_on_visit(); }
catch (Throwable $e) { error_log('scheduled_jobs_run_on_visit: ' . $e->getMessage()); } ?>
</body>
</html>
