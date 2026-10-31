<?php
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_login();
$current_user = admin_user();
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($page_title_admin ?? 'Admin') ?> — <?= h(SITE_NAME_BG) ?> Admin</title>
  <link rel="stylesheet" href="<?= h(versioned_asset('/assets/css/main.css')) ?>">
  <link rel="stylesheet" href="<?= h(versioned_asset('/admin/assets/admin.css')) ?>">
  <?php /* Phone layout: stack the pages' inline multi-column grids (BG/EN field pairs,
           sidebar + editor) so nothing forces sideways scrolling at 375px. Inline here,
           not in admin.css, because admin.css may be stale-cached on the server. */ ?>
  <style>
  @media (max-width: 600px) {
    .admin-content [style*="grid-template-columns:1fr 1fr;"],
    .admin-content [style*="grid-template-columns:1fr 1fr 1fr;"],
    .admin-content [style*="grid-template-columns:repeat(3,1fr)"],
    .admin-content [style*="grid-template-columns:260px 1fr"],
    .admin-content [style*="grid-template-columns:1fr 320px"],
    .admin-content [style*="grid-template-columns:1fr 340px"] {
      grid-template-columns: minmax(0, 1fr) !important;
    }
  }
  </style>
  <?= $page_head_extra ?? '' ?>
  <script>
  window._sessionExpiresAt = <?= ($current_user['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600 ?>;
  window._csrfToken = <?= json_encode(csrf_token()) ?>;
  // „Помощ от изкуствен интелект“ (Admin → Модули): off = no translate / AI buttons anywhere.
  window._aiHelpersOn = <?= module_enabled_with_needs('ai_helpers') ? 'true' : 'false' ?>;
  </script>
  <script src="<?= h(versioned_asset('/admin/js/autosave.js')) ?>"></script>
  <!-- ── Shared image cropper (Cropper.js vendored locally) -->
  <link rel="stylesheet" href="<?= h(versioned_asset('/assets/vendor/cropperjs/cropper.min.css')) ?>">
  <script src="<?= h(versioned_asset('/assets/vendor/cropperjs/cropper.min.js')) ?>"></script>
  <script src="<?= h(versioned_asset('/assets/js/image-cropper.js')) ?>"></script>
  <!-- ── Shared TinyMCE config — must be in <head> so tinymce.init() calls can reference it -->
  <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/tinymce-base.php'; ?>
  <?php /* The side menu, top bar and their panels (admin/includes/admin-nav.php). Inline
           here, not in admin.css, because admin.css may be stale-cached on the server. */ ?>
  <style>
  :root { --admin-nav-bg: var(--teal); }
  /* A shade darker than the site colour, so white menu text passes 4.5:1. */
  @supports (color: color-mix(in srgb, red, blue)) {
    :root { --admin-nav-bg: color-mix(in srgb, var(--teal) 80%, #000); }
  }
  .admin-sidebar { background: var(--admin-nav-bg); }
  .admin-sidebar__top { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
  .admin-sidebar__close { display: none; width: 44px; height: 44px; flex: none; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: none; color: #fff; cursor: pointer; }
  .admin-nav { padding: .5rem 0 1rem; }
  .admin-nav__group { margin: 1rem 1.5rem .25rem; font-size: .75rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.9); }
  .admin-nav__row { display: flex; align-items: stretch; }
  .admin-nav .admin-nav__link { display: flex; align-items: center; gap: .75rem; min-height: 44px; padding: 0 1rem 0 calc(1.5rem - 3px); color: #fff; font-size: .95rem; line-height: 1.25; flex: 1; min-width: 0; }
  .admin-nav .admin-nav__link.active { font-weight: 600; }
  .admin-nav__count { margin-left: auto; background: #fff; color: #024f61; border-radius: 10px; padding: .05rem .5rem; font-size: .75rem; font-weight: 700; line-height: 1.4; }
  .admin-nav__count-link { display: flex; align-items: center; padding: 0 1rem 0 .25rem; text-decoration: none; }
  .admin-nav__count-link:hover { background: rgba(255,255,255,.12); }
  .admin-nav .admin-nav__phone-only { display: none; }
  .admin-sidebar a:focus-visible, .admin-sidebar button:focus-visible { outline: 3px solid #ffd166; outline-offset: -3px; }

  .admin-topbar { gap: .25rem; padding: .5rem 1rem .5rem 2rem; min-height: 60px; box-sizing: border-box; }
  .admin-hamburger { min-width: 44px; min-height: 44px; align-items: center; justify-content: center; }
  .admin-tb__btn { display: inline-flex; align-items: center; gap: .5rem; min-height: 44px; padding: 0 .75rem; border-radius: 8px; border: 1px solid transparent; background: none; color: var(--text); font: inherit; font-size: .9rem; text-decoration: none; cursor: pointer; white-space: nowrap; }
  .admin-tb__btn:hover { background: #eef3f5; color: var(--text); }
  .admin-tb__btn[aria-expanded="true"], .admin-tb__btn[aria-current="page"], .admin-tb__btn.is-current { background: #e4f0f5; border-color: var(--admin-nav-bg); }
  .admin-tb__sep { width: 1px; height: 28px; background: var(--border); margin: 0 .25rem; flex: none; }
  .admin-tb__count { background: var(--admin-nav-bg); color: #fff; border-radius: 10px; padding: .05rem .45rem; font-size: .75rem; font-weight: 700; line-height: 1.4; }
  .admin-tb__avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--admin-nav-bg); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; flex: none; }
  .admin-tb__account { padding-left: .3rem; border-radius: 22px; }
  .admin-tb__update { display: inline-flex; align-items: center; gap: .45rem; min-height: 36px; margin-right: .5rem; padding: 0 .9rem 0 .65rem; border-radius: 18px; border: 1px solid #e8b64c; background: #fff4dc; color: #5c3d06; font-size: .875rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
  .admin-tb__update:hover { background: #ffe9b8; color: #5c3d06; }
  .admin-tb__short { display: none; }
  .admin-topbar a:focus-visible, .admin-topbar button:focus-visible { outline: 3px solid var(--admin-nav-bg); outline-offset: 2px; }

  .admin-panel { position: absolute; top: 100%; right: 1rem; margin-top: 4px; z-index: 150; box-sizing: border-box; background: #fff; border: 1px solid #c5d1d6; border-radius: 12px; box-shadow: 0 12px 32px rgba(16,42,52,.16); padding: 1rem; max-height: calc(100vh - 80px); overflow-y: auto; }
  .admin-panel[hidden] { display: none; }
  .admin-panel--settings { width: 600px; max-width: calc(100vw - 2rem); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.5rem; }
  .admin-panel--account { width: 280px; padding: .5rem; }
  .admin-panel__group h2 { margin: 0 0 .25rem .6rem; font-size: .75rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #4a5a61; }
  .admin-panel__who { padding: .6rem .75rem .75rem; border-bottom: 1px solid #e3e9ec; margin-bottom: .35rem; }
  .admin-panel .admin-nav__link { display: flex; align-items: center; gap: .6rem; min-height: 44px; padding: 0 .6rem; border-radius: 8px; border-left: 0; color: var(--text); font-size: .95rem; text-decoration: none; }
  .admin-panel .admin-nav__link svg { color: var(--admin-nav-bg); }
  .admin-panel .admin-nav__link:hover { background: #eef3f5; color: var(--text); }
  .admin-panel .admin-nav__link.active { background: #e4f0f5; color: var(--text); font-weight: 600; }
  .admin-panel .admin-nav__count { background: var(--admin-nav-bg); color: #fff; }
  .admin-panel .admin-nav__link--logout { color: #a3261a; opacity: 1; }
  .admin-panel .admin-nav__link--logout svg { color: currentColor; }
  .admin-nav__tag { margin-left: auto; background: #fff4dc; color: #5c3d06; border: 1px solid #e8b64c; border-radius: 10px; padding: 0 .5rem; font-size: .75rem; font-weight: 600; }
  .admin-panel a:focus-visible { outline: 3px solid var(--admin-nav-bg); outline-offset: -3px; }

  @media (max-width: 900px) {
    /* Off-screen means out of the Tab order too, not just out of sight. */
    .admin-sidebar { visibility: hidden; transition: transform .25s ease, visibility .25s; }
    .admin-sidebar.open { visibility: visible; }
    .admin-sidebar__close { display: flex; }
    .admin-topbar { padding: .25rem .5rem; }
  }
  @media (max-width: 600px) {
    .admin-tb__label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .admin-tb__btn { padding: 0 .6rem; }
    .admin-tb__site, .admin-tb__sep { display: none; }
    .admin-tb__short { display: inline; }
    .admin-tb__update { margin-right: .15rem; padding: 0 .6rem 0 .45rem; }
    .admin-tb__settings .admin-tb__count { margin-left: -.4rem; }
    .admin-nav .admin-nav__phone-only { display: flex; }
    .admin-panel { left: .5rem; right: .5rem; width: auto; max-height: calc(100vh - 70px); }
    .admin-panel--settings { grid-template-columns: minmax(0, 1fr); }
  }
  @media (prefers-reduced-motion: reduce) {
    .admin-sidebar { transition: none; }
  }
  </style>
</head>
<body class="admin-body">

<div class="admin-wrapper">

<?php
  // The whole menu is defined once in admin/includes/admin-nav.php.
  require_once __DIR__ . '/admin-nav.php';
  $_nav_items    = admin_nav_items($active_nav ?? null);
  $_nav_current  = admin_nav_current($_nav_items, $active_nav ?? null);
  $_nav_side     = admin_nav_grouped($_nav_items, 'side');
  $_nav_settings = admin_nav_grouped($_nav_items, 'settings');
  $_nav_waiting  = admin_nav_settings_count($_nav_items);
  $_nav_update   = admin_nav_update_version();
  $_nav_on_set   = false;
  foreach ($_nav_settings as $_g) foreach ($_g as $_i) if ($_i['key'] === $_nav_current) $_nav_on_set = true;

  /** One menu link: icon, label, waiting count or tag. */
  $_nav_link = static function (array $item, ?string $current): string {
      $on    = $item['key'] === $current;
      $count = $item['count'] > 0
          ? '<span class="admin-nav__count">' . (int) $item['count'] . admin_nav_sr(' ' . $item['count_label']) . '</span>'
          : '';
      $link  = '<a href="' . h($item['href']) . '"' . "\n"
             . '         class="admin-nav__link' . ($on ? ' active' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
             . admin_nav_icon($item['icon']) . '<span>' . h($item['label']) . '</span>'
             . ($item['count_href'] === null ? $count : '')
             . ($item['tag'] !== null ? '<span class="admin-nav__tag">' . h($item['tag']) . '</span>' : '')
             . '</a>';
      if ($item['count_href'] === null || $count === '') return $link;
      // The count opens its own page (Продукти → Отзиви за продукти), so it is a link of its own.
      return '<div class="admin-nav__row">' . $link
           . '<a href="' . h($item['count_href']) . '" class="admin-nav__count-link">' . $count . '</a></div>';
  };
  ?>

  <aside class="admin-sidebar" id="adminSidebar" aria-label="Основно меню">
    <div class="admin-sidebar__top">
      <a href="/admin/dashboard.php" class="admin-logo">
        <?php $_logo_style = function_exists('org_admin_logo_style') ? org_admin_logo_style() : ''; ?>
        <img src="<?= logo_url() ?>" alt="<?= h(SITE_NAME_BG) ?>"<?= $_logo_style !== '' ? ' style="' . h($_logo_style) . '"' : '' ?>>
      </a>
      <button type="button" class="admin-sidebar__close" id="adminSidebarClose" aria-label="Затвори менюто"><?= admin_nav_icon('close', 22) ?></button>
    </div>

    <nav class="admin-nav" aria-label="Основно меню">
      <?php foreach ($_nav_side as $_group => $_items): ?>
      <?php if ($_group !== ''): ?>
      <h2 class="admin-nav__group"><?= h($_group) ?></h2>
      <?php endif; ?>
      <?php foreach ($_items as $_item): ?>
      <?= $_nav_link($_item, $_nav_current) ?>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php /* On a phone the top bar has no room for „Към сайта“, so it is here. */ ?>
      <a href="/" target="_blank" rel="noopener" class="admin-nav__link admin-nav__phone-only"><?= admin_nav_icon('external') ?><span>Към сайта</span><?= admin_nav_sr(' (отваря се в нов раздел)') ?></a>
    </nav>
  </aside>

  <?php /* min-width:0 lets this flex item shrink to the viewport instead of growing to fit its widest child (inline because admin.css may be stale-cached) */ ?>
  <main class="admin-main" style="min-width:0;">
    <header class="admin-topbar">
      <button type="button" class="admin-hamburger" id="adminHamburger" aria-label="Меню" aria-expanded="false" aria-controls="adminSidebar">
        <span></span><span></span><span></span>
      </button>
      <div class="admin-topbar__title" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($page_title_admin ?? '') ?></div>

      <?php if ($_nav_update !== null): ?>
      <a href="/admin/updates.php" class="admin-tb__update">
        <?= admin_nav_icon('refresh', 16) ?>
        <span class="admin-tb__label">Нова версия <?= h($_nav_update) ?> — обнови</span><span class="admin-tb__short" aria-hidden="true">Ново</span>
      </a>
      <?php endif; ?>

      <a href="/" target="_blank" rel="noopener" class="admin-tb__btn admin-tb__site">
        <?= admin_nav_icon('external', 18) ?><span>Към сайта</span><?= admin_nav_sr(' (отваря се в нов раздел)') ?>
      </a>
      <?php if (($active_nav ?? '') === 'support'): ?>
      <a href="/admin/support.php" class="admin-tb__btn" aria-current="page">
      <?php else: ?>
      <a href="/admin/support.php?from=<?= h(rawurlencode($_SERVER['REQUEST_URI'] ?? '')) ?>" class="admin-tb__btn">
      <?php endif; ?>
        <?= admin_nav_icon('help', 18) ?><span class="admin-tb__label">Помощ</span>
      </a>

      <span class="admin-tb__sep" aria-hidden="true"></span>

      <?php if ($_nav_settings): ?>
      <button type="button" class="admin-tb__btn admin-tb__settings<?= $_nav_on_set ? ' is-current' : '' ?>" data-admin-panel aria-expanded="false" aria-controls="adminPanelSettings">
        <?= admin_nav_icon('gear', 18) ?><span class="admin-tb__label">Настройки</span>
        <?php if ($_nav_waiting > 0): ?><span class="admin-tb__count"><?= $_nav_waiting ?><?= admin_nav_sr(' чака') ?></span><?php endif; ?>
        <?= admin_nav_icon('chevron', 16) ?>
      </button>
      <?php endif; ?>

      <button type="button" class="admin-tb__btn admin-tb__account<?= ($active_nav ?? '') === 'account' ? ' is-current' : '' ?>" data-admin-panel aria-expanded="false" aria-controls="adminPanelAccount">
        <span class="admin-tb__avatar" aria-hidden="true"><?= h(admin_nav_initials((string) ($current_user['name'] ?? ''))) ?></span>
        <span class="admin-tb__label"><?= h($current_user['name'] ?? '') ?></span>
        <?= admin_nav_icon('chevron', 16) ?>
      </button>

      <?php if ($_nav_settings): ?>
      <nav class="admin-panel admin-panel--settings" id="adminPanelSettings" aria-label="Настройки" hidden>
        <?php foreach ($_nav_settings as $_group => $_items): ?>
        <div class="admin-panel__group">
          <h2><?= h($_group) ?></h2>
          <?php foreach ($_items as $_item): ?>
          <?= $_nav_link($_item, $_nav_current) ?>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>

      <div class="admin-panel admin-panel--account" id="adminPanelAccount" hidden>
        <div class="admin-panel__who">
          <div style="font-weight:600;"><?= h($current_user['name'] ?? '') ?></div>
          <div style="font-size:.85rem;color:#4a5a61;overflow-wrap:anywhere;">
            <?= h($current_user['email'] ?? '') ?><?php $_role = (string) ($current_user['role'] ?? ''); ?>
            <?= $_role !== '' ? ' · ' . h(admin_roles()[$_role] ?? $_role) : '' ?>
          </div>
        </div>
        <a href="/admin/account.php"
           class="admin-nav__link<?= ($active_nav ?? '') === 'account' ? ' active' : '' ?>"
           <?= ($active_nav ?? '') === 'account' ? 'aria-current="page"' : '' ?>><?= admin_nav_icon('user', 18) ?><span>Моят профил и парола</span></a>
        <a href="/admin/logout.php" class="admin-nav__link admin-nav__link--logout"><?= admin_nav_icon('logout', 18) ?><span>Изход</span></a>
      </div>
    </header>
    <?php if (admin_nav_update_check_due()): ?>
    <script>
    // Look for a new version in the background; the notice above shows from the next page on.
    window.addEventListener('load', function () {
      var f = new FormData();
      f.append('csrf_token', window._csrfToken);
      fetch('/admin/update-check-ajax.php', { method: 'POST', body: f, credentials: 'same-origin' }).catch(function () {});
    });
    </script>
    <?php endif; ?>
    <div class="admin-content">
<?php
    // Go-live reminder on every page but the dashboard, which has the full list.
    require_once dirname(__DIR__, 2) . '/includes/launch.php';   // also for a site that kept an older config.php
    if (($active_nav ?? '') !== 'dashboard' && admin_is_admin()):
        $_lr_open = launch_required_open();
        if (!site_launched() || $_lr_open > 0):
?>
      <div role="status" style="display:flex;flex-wrap:wrap;gap:.4rem 1rem;align-items:center;background:#fff8eb;border:1px solid #f3d9a4;border-radius:8px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.88rem;line-height:1.4;color:#5c3d06;">
        <span style="flex:1 1 260px;min-width:0;">
          <?= !site_launched() ? 'Сайтът още не е отворен за посетители.' : 'Липсват неща, които законът изисква.' ?>
          <?= $_lr_open > 0 ? launch_tasks_left($_lr_open) : 'Всички задължителни задачи са готови.' ?>
        </span>
        <a href="/admin/#go-live" style="font-weight:600;color:#7a4e00;white-space:nowrap;">Към списъка</a>
      </div>
<?php endif; endif; ?>
