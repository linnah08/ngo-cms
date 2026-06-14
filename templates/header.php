<?php
// templates/header.php
// Variables expected: $page_title (string), $lang (string)
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$lang = get_lang();
$site_name = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;
$other = other_lang();
$other_label = $other === 'en' ? 'EN' : 'БГ';
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// BG path prefix → EN path prefix (longest match wins)
$_path_map_bg_to_en = [
    '/novini'                    => '/en/news',
    '/za-nas'                    => '/en/about',
    '/proekti'                   => '/en/projects',
    '/kak-da-pomogna'            => '/en/how-to-help',
    '/kontakti'                  => '/en/contacts',
    '/magazin'                   => '/en/shop',
    '/campaign'                  => '/en/campaign',
    '/politika-za-poveritelnost' => '/en/privacy-policy',
    '/politika-za-biskvitki'     => '/en/cookie-policy',
    '/pravna-informaciya'        => '/en/legal',
    '/usloviya'                  => '/en/terms',
    '/'                          => '/en',
];

function _switch_lang(string $path, string $current_lang): string {
    global $_path_map_bg_to_en;

    $trailing = str_ends_with($path, '/') ? '/' : '';
    $p = rtrim($path, '/');

    if ($current_lang === 'bg') {
        // BG → EN: match longest prefix first
        arsort($_path_map_bg_to_en); // sort by value length, but key length matters
        $sorted = $_path_map_bg_to_en;
        uksort($sorted, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($sorted as $bg => $en) {
            $bg_clean = rtrim($bg, '/');
            if ($p === $bg_clean || str_starts_with($p, $bg_clean . '/')) {
                return $en . substr($p, strlen($bg_clean)) . $trailing;
            }
        }
        return '/en' . $path;
    } else {
        // EN → BG: strip /en prefix, reverse-map
        $without_en = substr($p, 3); // remove leading /en
        $sorted = $_path_map_bg_to_en;
        uksort($sorted, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($sorted as $bg => $en) {
            $en_clean = rtrim($en, '/');
            $en_part  = substr($en_clean, 3); // remove /en from map value
            if ($without_en === $en_part || str_starts_with($without_en, $en_part . '/')) {
                $bg_clean = rtrim($bg, '/');
                $result = $bg_clean . substr($without_en, strlen($en_part));
                $final  = $result . $trailing;
                return $final !== '' ? $final : '/';
            }
        }
        return $without_en . $trailing ?: '/';
    }
}

$_menus    = load_json(CONTENT_PATH . '/menus.json');
$nav       = $_menus['header'][$lang] ?? [];
$_nav_bg   = $_menus['header']['bg'] ?? [];
$_nav_en   = $_menus['header']['en'] ?? [];
$shop_url  = $lang === 'bg' ? '/magazin/' : '/en/shop/';
$cart_n    = cart_count();
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
    $_seo_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jura:wght@300;400;500;600&display=swap" rel="stylesheet">

  <!-- Styles -->
  <link rel="stylesheet" href="/assets/css/main.css">
  <?php if (!empty($extra_css)): ?>
    <link rel="stylesheet" href="<?= h($extra_css) ?>">
  <?php endif; ?>
  <?= $page_head_extra ?? '' ?>

  <!-- Favicon -->
  <link rel="icon" href="/assets/images/favicon.png">

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
  <!-- Top bar -->
  <div class="header-top">
    <div class="container">
      <div class="header-top__contact">
        <a href="tel:<?= SITE_PHONE ?>"><?= SITE_PHONE ?></a>
        <a href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a>
      </div>
      <div class="header-top__right">
        <span><?= t('header.donate_iban') ?>: <strong><?= SITE_IBAN ?></strong></span>
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
        <img src="/assets/images/logo.png" alt="<?= h($site_name) ?>">
      </a>

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
          <li class="nav-cta">
            <a href="<?= $shop_url ?>"><?= t('nav.shop') ?></a>
          </li>
          <li>
            <a href="/cart/" class="cart-link" aria-label="Количка">
              <span class="cart-link__icon" aria-hidden="true">🛒</span>
              <?php if ($cart_n > 0): ?>
                <span class="cart-link__count"><?= $cart_n ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li>
            <div class="lang-switcher">
              <a href="<?= $lang === 'bg' ? $current_path : _switch_lang($current_path, 'en') ?>"
                 class="<?= $lang === 'bg' ? 'active' : '' ?>">БГ</a>
              <a href="<?= $lang === 'en' ? $current_path : _switch_lang($current_path, 'bg') ?>"
                 class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
            </div>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</header>
