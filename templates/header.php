<?php
// templates/header.php
// Variables expected: $page_title (string), $lang (string)
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$lang = get_lang();
$site_name = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;
$other = other_lang();
$other_label = $other === 'en' ? 'EN' : 'БГ';
$current_path = url_request_path($_SERVER['REQUEST_URI'] ?? '/');

// BG ↔ EN page addresses — one map, shared with the menu editor.
require_once __DIR__ . '/../includes/menus.php';

function _switch_lang(string $path, string $current_lang): string {
    return $current_lang === 'bg' ? path_bg_to_en($path) : path_en_to_bg($path);
}

$_menus    = load_json(CONTENT_PATH . '/menus.json');
// Links to a draft or a missing page, or to a switched-off module (Admin → Модули),
// are left out (keys kept for the on-page editor).
$nav       = module_filter_links(menu_public_items(is_array($_menus['header'][$lang] ?? null) ? $_menus['header'][$lang] : []));
$_nav_bg   = $_menus['header']['bg'] ?? [];
$_nav_en   = $_menus['header']['en'] ?? [];
$shop_url  = shop_path('shop', $lang);
$cart_n    = cart_count();
// The icon-only cart link says what it is and how many items are in it —
// its aria-label replaces the visible count for screen readers.
$cart_label = match (true) {
    $cart_n === 1 => t_or('nav.cart_one', 'Количка, 1 артикул', 'Cart, 1 item', $lang),
    $cart_n > 1   => t_or('nav.cart_count', 'Количка, {n} артикула', 'Cart, {n} items', $lang, ['n' => $cart_n]),
    default       => t_or('nav.cart', 'Количка', 'Cart', $lang),
};

// Language switcher target URLs (shared by the in-nav and mobile switchers)
$bg_href = $lang === 'bg' ? $current_path : _switch_lang($current_path, 'en');
$en_href = $lang === 'en' ? $current_path : _switch_lang($current_path, 'bg');
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'bg' ? 'bg' : 'en' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php $_doc_title = $page_title_full ?? (($page_title ?? $site_name) . ' — ' . $site_name); ?>
  <title><?= h($_doc_title) ?></title>
  <meta name="description" content="<?= h($page_description ?? '') ?>">

  <?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/seo.php';
    $_seo_path = url_request_path($_SERVER['REQUEST_URI'] ?? '/');
    seo_render_meta([
        'title'       => $_doc_title,
        'description' => $page_description ?? '',
        'url'         => $seo_canonical ?? (rtrim(SITE_URL, '/') . $_seo_path),
        'type'        => $seo_type ?? 'website',
        'image'       => $og_image ?? null,
        'alternates'  => seo_resolve_alternates($_seo_path, $seo_alternates ?? []),
        'lang'        => $lang,
    ]);
    seo_render_jsonld(array_merge([seo_org_jsonld()], $seo_jsonld ?? []));
  ?>

  <!-- Fonts + styles (the theme's fonts, main.css, the theme's own stylesheet) -->
<?= theme_stylesheet_links(current_theme()) ?>
  <?php if (!empty($extra_css)): ?>
    <link rel="stylesheet" href="<?= h($extra_css) ?>">
  <?php endif; ?>
  <?= $page_head_extra ?? '' ?>

  <!-- Favicon -->
  <link rel="icon" href="<?= favicon_url() ?>">

  <?php if (($_COOKIE['om_cookie_consent'] ?? '') === 'all'): ?>
  <?php $__gtm = defined('GTM_ID') ? GTM_ID : ''; ?>
  <?php $__ads = defined('GOOGLE_ADS_ID') ? GOOGLE_ADS_ID : ''; ?>
  <?php $__ga4 = defined('GA4_ID') ? GA4_ID : ''; ?>
  <?php $__ads_label = defined('GOOGLE_ADS_PURCHASE_LABEL') ? GOOGLE_ADS_PURCHASE_LABEL : ''; ?>
  <?php if ($__gtm !== ''): ?>
  <!-- Google Tag Manager (consent given) -->
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
  new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
  j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
  'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
  })(window,document,'script','dataLayer','<?= h($__gtm) ?>');</script>
  <!-- End Google Tag Manager -->
  <?php endif; ?>

  <?php if ($__ads !== '' || $__ga4 !== ''): ?>
  <!-- Google Ads / GA4 (consent given) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($__ads !== '' ? $__ads : $__ga4) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    <?php if ($__ads !== ''): ?>gtag('config', '<?= h($__ads) ?>');<?php endif; ?>
    <?php if ($__ga4 !== ''): ?>gtag('config', '<?= h($__ga4) ?>');<?php endif; ?>
  </script>
  <!-- End Google Ads / GA4 -->

  <?php if (($_GET['gads'] ?? '') === 'atc' && $__ads !== '' && $__ads_label !== ''): ?>
  <!-- Google Ads: Add to cart conversion event -->
  <script>gtag('event', 'conversion', {'send_to': '<?= h($__ads) ?>/<?= h($__ads_label) ?>'});</script>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
  <?php $_show_admin_bar = admin_logged_in() || admin_bar_token_verify(); ?>
  <?php if ($_show_admin_bar): ?>
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/inline-cms.css">
  <?php endif; ?>
  <?php
    $_theme   = current_theme();
    $_primary = (defined('BRAND_PRIMARY') && BRAND_PRIMARY !== '') ? BRAND_PRIMARY : $_theme['primary'];
    $_accent  = (defined('BRAND_ACCENT')  && BRAND_ACCENT  !== '') ? BRAND_ACCENT  : $_theme['accent'];
    // A theme may set separate display and body faces; older ones use one font
    // for both roles, which is what these fallbacks preserve.
    $_font_body    = $_theme['font_body']    ?? $_theme['font'];
    $_font_display = $_theme['font_display'] ?? $_theme['font'];
  ?>
  <!-- Theme + brand (install wizard) -->
  <style>:root{
    --teal:<?= h($_primary) ?>;
    --teal-dark:<?= h($_accent) ?>;
    --teal-light:color-mix(in srgb, <?= h($_primary) ?> 12%, #ffffff);
    --font-body:'<?= h($_font_body) ?>',sans-serif;
    --font-display:'<?= h($_font_display) ?>',sans-serif;
    --radius:<?= h($_theme['radius']) ?>;
    --radius-lg:<?= h($_theme['radius_lg']) ?>;
<?= theme_palette_css($_theme, site_newsletter_band($_primary, $_accent)) ?>
  }</style>
</head>
<body<?= $_show_admin_bar ? ' class="om-admin"' : '' ?>>
<?php if ($_show_admin_bar): ?>
<?php $_admin_base = SITE_URL . '/admin'; ?>
<div id="om-admin-bar">
  <a href="<?= $_admin_base ?>/" class="om-bar-brand">Admin</a>
  <div class="om-bar-divider"></div>
  <button id="om-edit-toggle" onclick="OmCMS.toggleEdit()">
    <span class="om-dot"></span>
    <span id="om-edit-label">Edit mode off</span>
  </button>
  <div id="om-lang-switcher">
    <button class="om-lang-btn<?= get_lang() === 'bg' ? ' om-active' : '' ?>" id="om-btn-bg" onclick="OmCMS.setLang('bg')">BG</button>
    <button class="om-lang-btn<?= get_lang() === 'en' ? ' om-active' : '' ?>" id="om-btn-en" onclick="OmCMS.setLang('en')">EN</button>
  </div>
  <div class="om-bar-spacer"></div>
  <a href="<?= $_admin_base ?>/" class="om-exit-link">← Admin</a>
</div>
<?php require_once ROOT_PATH . '/includes/launch.php'; if (!site_launched()): ?>
<?php /* Part of the admin bar, so it can't be taken for the public "Съобщение в началото на сайта" (Организация). */ ?>
<div role="status" style="background:#1a1a2e;color:#f3f4f6;border-top:1px solid rgba(255,255,255,.15);padding:.5rem 1rem;font-size:.85rem;text-align:center;font-family:system-ui,sans-serif;">
  <strong style="color:#fcd34d;">Само вие виждате това:</strong> сайтът още не е отворен, посетителите виждат страница „Скоро отваряме“.
  <a href="<?= $_admin_base ?>/#go-live" style="color:#fcd34d;font-weight:600;">Какво остава</a>
</div>
<?php endif; ?>
<button id="om-save-btn" onclick="OmCMS.save()">💾 Save changes</button>
<div id="om-add-modal">
  <div class="om-modal-box">
    <h3 id="om-modal-title">Add item</h3>
    <div id="om-modal-fields"></div>
    <div class="om-modal-actions">
      <button class="om-btn-cancel" onclick="OmCMS.closeModal()">Cancel</button>
      <button class="om-btn-save" onclick="OmCMS.submitModal()">Save</button>
    </div>
  </div>
</div>
<script>
  window._omCsrf = <?= json_encode(csrf_token()) ?>;
  window._omLang = <?= json_encode(get_lang()) ?>;
  window._omAdminBase = <?= json_encode(SITE_URL . '/admin') ?>;
</script>
<script src="<?= SITE_URL ?>/assets/js/inline-cms.js"></script>
<?php endif; ?>

<header class="site-header">
  <?php if (launch_banner_enabled()): ?>
  <!-- Pre-launch notice.
       Rendered inside .site-header on purpose: that element is already
       position:sticky, so the band travels with it and needs no z-index or
       top-offset maths of its own.
       The theme's amber field with its body-text colour: a strong band that
       stays readable (a theme sets both through --amber and --text). A pale
       band on a light page would be invisible, which was the first version's
       whole problem.
       No dismiss control: a notice worth showing is not worth losing to a
       stray tap, and it must not go missing mid-review.
       Styles are inline — main.css can be stale-cached on the server. -->
  <div id="om-launch-banner" role="status"
       style="background:var(--amber,#e8a020);
              color:var(--text,#1a1916);">
    <p style="max-width:1200px;margin:0 auto;padding:.75rem 1rem;
              font-size:.95rem;font-weight:600;line-height:1.4;text-align:center;">
      <?= h(launch_banner_text(get_lang())) ?>
    </p>
  </div>
  <?php endif; ?>
  <!-- Top bar -->
  <div class="header-top">
    <div class="container">
      <div class="header-top__contact">
        <?php if (site_phone_public() !== ''): ?>
        <a href="tel:<?= h(site_phone_public()) ?>"><?= h(site_phone_public()) ?></a>
        <?php endif; ?>
        <a href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a>
      </div>
      <div class="header-top__right">
        <?php if (feature_enabled('donations') && trim((string) SITE_IBAN) !== ''): ?>
        <span><?= t('header.donate_iban') ?>: <strong><?= h(SITE_IBAN) ?></strong></span>
        <?php endif; ?>
        <span class="header-top__social">
          <?php $social_variant = 'bar'; require __DIR__ . '/social-links.php'; ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Main nav -->
  <div class="header-main">
    <div class="container">
      <a href="<?= $lang === 'bg' ? '/' : '/en/' ?>" class="site-logo">
        <img src="<?= logo_url() ?>" alt="<?= h($site_name) ?>">
      </a>

      <?php $switcher_class = 'lang-switcher--mobile'; require __DIR__ . '/lang-switcher.php'; unset($switcher_class); ?>

      <button
        class="nav-toggle"
        id="navToggle"
        aria-label="<?= t('nav.toggle') ?>"
        aria-expanded="false"
        aria-controls="siteNav"
      >
        <span></span><span></span><span></span>
      </button>

      <nav aria-label="<?= t('nav.main') ?>">
        <ul class="site-nav" id="siteNav">
          <?php foreach ($nav as $i => $item): ?>
            <?php $active = rtrim($current_path, '/') === rtrim($item['url'], '/'); ?>
            <li>
              <a href="<?= h($item['url']) ?>"
                 <?= $active ? 'class="active" aria-current="page"' : '' ?>
                 data-cms-field="label"
                 data-cms-section="menus"
                 data-cms-menu="header"
                 data-cms-index="<?= $i ?>"
                 data-cms-type="text"
                 data-cms-bg="<?= h($_nav_bg[$i]['label'] ?? '') ?>"
                 data-cms-en="<?= h($_nav_en[$i]['label'] ?? '') ?>">
                <?= h($item['label']) ?>
              </a>
            </li>
          <?php endforeach; ?>
          <?php // Shop link and cart only while „Магазин“ is on (Admin → Модули). ?>
          <?php if (module_enabled_with_needs('shop')): ?>
          <li class="nav-cta">
            <a href="<?= $shop_url ?>"><?= t('nav.shop') ?></a>
          </li>
          <li>
            <a href="<?= h(shop_path('cart', $lang)) ?>" class="cart-link" aria-label="<?= h($cart_label) ?>">
              <span class="cart-link__icon" aria-hidden="true">🛒</span>
              <?php if ($cart_n > 0): ?>
                <span class="cart-link__count"><?= $cart_n ?></span>
              <?php endif; ?>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-lang-item">
            <?php require __DIR__ . '/lang-switcher.php'; ?>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</header>
