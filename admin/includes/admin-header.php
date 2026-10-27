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
</head>
<body class="admin-body">

<div class="admin-wrapper">

  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar__top">
      <a href="/admin/dashboard.php" class="admin-logo">
        <img src="<?= logo_url() ?>" alt="<?= h(SITE_NAME_BG) ?>">
      </a>
    </div>

    <?php /* Each link to a page that belongs to an optional module is hidden while
             that module is off — module_admin_page_visible() reads admin_pages in
             modules_registry() (includes/modules.php). */ ?>
    <nav class="admin-nav">
      <a href="/admin/dashboard.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'dashboard' ? 'active' : '' ?>">
        Начало
      </a>
      <?php if (admin_can_editorial()): ?>
      <?php if (module_admin_page_visible('articles.php')): ?>
      <a href="/admin/articles.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'articles' ? 'active' : '' ?>">
        Статии
      </a>
      <?php endif; ?>
      <?php
      // The page holds comments and contact messages: it stays while either
      // module is on. With „Коментари и отзиви“ off it is just „Контакти“ and the
      // waiting-comments badge goes; with „Контактна форма“ off, just „Коментари“.
      $comments_on      = module_enabled_with_needs('comments_reviews');
      $pending_comments = 0;
      if ($comments_on) {
          try {
              $pending_comments = (int)get_pdo()->query("SELECT COUNT(*) FROM comments WHERE status='pending'")->fetchColumn();
          } catch (Throwable $e) {}
      }
      ?>
      <?php if (module_any_enabled('comments_reviews', 'contact_form')): ?>
      <a href="/admin/comments.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'comments' ? 'active' : '' ?>">
        <?= $comments_on ? 'Коментари' : 'Контакти' ?><?php if ($pending_comments > 0): ?> <span style="background:var(--teal);color:#fff;border-radius:10px;padding:.05rem .4rem;font-size:.72rem;margin-left:.3rem;vertical-align:middle;"><?= $pending_comments ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (admin_is_admin()): ?>
      <?php
      // Partners only ever appear in the front page's Партньори section, so the menu
      // item follows that section: switch it on in Начална страница and it is back.
      // The page itself always works at /admin/partners.php.
      $_nav_partners = ($active_nav ?? '') === 'partners';
      if (!$_nav_partners) {
          try {
              require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';
              $_nav_partners = home_section_on('partners');
          } catch (Throwable $e) {
              $_nav_partners = true;
          }
      }
      ?>
      <?php if ($_nav_partners): ?>
      <a href="/admin/partners.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'partners' ? 'active' : '' ?>">
        Партньори
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('pages.php')): ?>
      <a href="/admin/pages.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'pages' ? 'active' : '' ?>">
        Съдържание
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (admin_can_manage_shop()): ?>
      <?php
      $pending_reviews = 0;
      if (module_admin_page_visible('product-reviews.php')) {
          try {
              $pending_reviews = (int)get_pdo()->query("SELECT COUNT(*) FROM product_reviews WHERE status='pending'")->fetchColumn();
          } catch (Throwable $e) { /* table may not exist yet */ }
      }
      ?>
      <?php if (module_admin_page_visible('products.php')): ?>
      <a href="/admin/products.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'products' ? 'active' : '' ?>">
        Продукти<?php if ($pending_reviews > 0): ?> <span title="Чакащи отзиви" style="background:var(--teal);color:#fff;border-radius:10px;padding:.05rem .4rem;font-size:.72rem;margin-left:.3rem;vertical-align:middle;"><?= $pending_reviews ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('orders.php')): ?>
      <a href="/admin/orders.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'orders' ? 'active' : '' ?>">
        Поръчки
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('sumup.php')): ?>
      <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sumup.php'; $_sumup_waiting = sumup_attention_count(get_pdo()); ?>
      <a href="/admin/sumup.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'sumup' ? 'active' : '' ?>">
        SumUp<?php if ($_sumup_waiting > 0): ?> <span title="Продажби, които чакат да изберете продукт" style="background:var(--teal);color:#fff;border-radius:10px;padding:.05rem .4rem;font-size:.72rem;margin-left:.3rem;vertical-align:middle;"><?= $_sumup_waiting ?><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);"> чакат избор</span></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('distribution.php')): ?>
      <a href="/admin/distribution.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'distribution' ? 'active' : '' ?>">
        Разпространение
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('events.php')): ?>
      <a href="/admin/events.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'events' ? 'active' : '' ?>">
        Събития
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('monthly-report.php')): ?>
      <a href="/admin/monthly-report.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'monthly-report' ? 'active' : '' ?>">
        Месечен отчет
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (admin_is_admin()): ?>
      <?php if (module_admin_page_visible('menus.php')): ?>
      <a href="/admin/menus.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'menus' ? 'active' : '' ?>">
        Менюта
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('couriers.php')): ?>
      <a href="/admin/couriers.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'couriers' ? 'active' : '' ?>">
        Куриери
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('organisation.php')): ?>
      <a href="/admin/organisation.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'organisation' ? 'active' : '' ?>">
        Организация
      </a>
      <?php endif; ?>
      <a href="/admin/modules.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'modules' ? 'active' : '' ?>">
        Модули
      </a>
      <?php if (module_admin_page_visible('payment.php')): ?>
      <a href="/admin/payment.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'payment' ? 'active' : '' ?>">
        Плащания
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('users.php')): ?>
      <a href="/admin/users.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'users' ? 'active' : '' ?>">
        Потребители
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('newsletter.php')): ?>
      <a href="/admin/newsletter.php"
         class="admin-nav__link <?= in_array($active_nav ?? '', ['newsletter','newsletter-compose','newsletter-send','newsletter-subscribers','newsletter-topics']) ? 'active' : '' ?>">
        Бюлетин
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('campaign.php')): ?>
      <a href="/admin/campaign.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'campaign' ? 'active' : '' ?>">
        Кампания
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('email-templates.php')): ?>
      <a href="/admin/email-templates.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'email-templates' ? 'active' : '' ?>">
        Имейл шаблони
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('error-alerts.php')): ?>
      <a href="/admin/error-alerts.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'error-alerts' ? 'active' : '' ?>">
        Известия за грешки
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('scheduled-jobs.php')): ?>
      <a href="/admin/scheduled-jobs.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'scheduled-jobs' ? 'active' : '' ?>">
        Автоматични задачи
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('updates.php')): ?>
      <a href="/admin/updates.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'updates' ? 'active' : '' ?>">
        Обновления
      </a>
      <?php endif; ?>
      <?php if (module_admin_page_visible('email-settings.php')): ?>
      <a href="/admin/email-settings.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'email-settings' ? 'active' : '' ?>">
        Имейл
      </a>
      <?php endif; ?>
      <?php if (admin_can_sign()): ?>
      <?php if (module_certificates_enabled()): ?>
      <a href="/admin/signature.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'signature' ? 'active' : '' ?>">
        Подпис
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
      <?php
      // The site's own links (includes/site-extensions.php 'admin_nav'), for the roles it names.
      require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';   // also for a site that kept an older config.php
      foreach (site_admin_nav((string) ($current_user['role'] ?? '')) as $_site_nav):
      ?>
      <a href="<?= h($_site_nav['href']) ?>"
         class="admin-nav__link <?= ($active_nav ?? '') === $_site_nav['active_nav'] ? 'active' : '' ?>"
         <?= ($active_nav ?? '') === $_site_nav['active_nav'] ? 'aria-current="page"' : '' ?>>
        <?= h($_site_nav['label']) ?>
      </a>
      <?php endforeach; ?>
      <a href="/admin/support.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'support' ? 'active' : '' ?>">
        Поддръжка
      </a>
    </nav>

    <div class="admin-sidebar__footer">
      <div class="admin-user">
        <div class="admin-user__name"><?= h($current_user['name'] ?? '') ?></div>
        <div class="admin-user__role"><?= h($current_user['role'] ?? '') ?></div>
      </div>
      <a href="/admin/account.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'account' ? 'active' : '' ?>"
         <?= ($active_nav ?? '') === 'account' ? 'aria-current="page"' : '' ?>>
        Моят профил и парола
      </a>
      <a href="/" target="_blank" class="admin-nav__link" style="font-size:.8rem;opacity:.7;">↗ Към сайта</a>
      <a href="/admin/logout.php" class="admin-nav__link admin-nav__link--logout">Изход</a>
    </div>
  </aside>

  <?php /* min-width:0 lets this flex item shrink to the viewport instead of growing to fit its widest child (inline because admin.css may be stale-cached) */ ?>
  <main class="admin-main" style="min-width:0;">
    <div class="admin-topbar">
      <button class="admin-hamburger" id="adminHamburger" aria-label="Toggle menu">
        <span></span><span></span><span></span>
      </button>
      <div class="admin-topbar__title" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($page_title_admin ?? '') ?></div>
      <?php if (($active_nav ?? '') !== 'support'): ?>
      <a href="/admin/support.php?from=<?= h(rawurlencode($_SERVER['REQUEST_URI'] ?? '')) ?>"
         style="font-size:.85rem;line-height:1.2;color:var(--text-muted);text-decoration:none;text-align:right;flex-shrink:1;">Докладвай проблем</a>
      <?php endif; ?>
      <a href="/admin/logout.php" class="admin-topbar__logout">Изход</a>
    </div>

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
