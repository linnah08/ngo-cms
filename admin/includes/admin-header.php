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
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <?= $page_head_extra ?? '' ?>
  <script>
  window._sessionExpiresAt = <?= ($current_user['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600 ?>;
  window._csrfToken = <?= json_encode(csrf_token()) ?>;
  </script>
  <script src="/admin/js/autosave.js"></script>
  <!-- ── Shared image cropper (Cropper.js vendored locally) -->
  <link rel="stylesheet" href="/assets/vendor/cropperjs/cropper.min.css">
  <script src="/assets/vendor/cropperjs/cropper.min.js"></script>
  <script src="/assets/js/image-cropper.js"></script>
  <!-- ── Shared TinyMCE config — must be in <head> so tinymce.init() calls can reference it -->
  <script>
  window._tinyBase = {
    menubar: false,
    promotion: false,
    branding: false,
    plugins: 'lists link hr code emoticons anchor image table charmap wordcount autoresize',
    min_height: 120,
    toolbar: 'bold italic underline strikethrough | fontsize | blocks | bullist numlist | link unlink anchor | image table | charmap emoticons | hr removeformat | code',
    toolbar_mode: 'wrap',
    font_size_formats: '10pt 12pt 14pt 16pt 18pt 24pt 30pt 36pt',
    paste_as_text: false,
    paste_remove_styles_if_webkit: true,
    smart_paste: true,
    link_assume_external_targets: true,
    link_default_target: '_blank',
    relative_urls: false,
    remove_script_host: false,
    images_upload_url: '/admin/upload-product-image.php',
    automatic_uploads: false,
    file_picker_types: 'image',
    file_picker_callback: function(cb, value, meta) {
      if (meta.filetype !== 'image') return;
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.onchange = function() {
        var f = input.files && input.files[0];
        if (!f) return;
        (window.OMCrop ? window.OMCrop.open(f) : Promise.resolve(f)).then(function(cropped) {
          if (!cropped) return;
          var fd = new FormData();
          fd.append('image', cropped);
          fd.append('csrf_token', window._csrfToken);
          fetch('/admin/upload-product-image.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (d && d.filename) cb('/assets/images/products/' + d.filename, { title: cropped.name });
              else alert((d && d.error) ? d.error : 'Грешка при качване.');
            })
            .catch(function() { alert('Грешка при качване.'); });
        });
      };
      input.click();
    },
    setup: function(editor) {
      editor.on('change', function() { editor.save(); });
    },
  };
  </script>
</head>
<body class="admin-body">

<div class="admin-wrapper">

  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar__top">
      <a href="/admin/dashboard.php" class="admin-logo">
        <img src="<?= logo_url() ?>" alt="<?= h(SITE_NAME_BG) ?>">
      </a>
    </div>

    <nav class="admin-nav">
      <a href="/admin/dashboard.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'dashboard' ? 'active' : '' ?>">
        Начало
      </a>
      <?php if (admin_can_editorial()): ?>
      <a href="/admin/articles.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'articles' ? 'active' : '' ?>">
        Статии
      </a>
      <?php
      $pending_comments = 0;
      try {
          $pending_comments = (int)get_pdo()->query("SELECT COUNT(*) FROM comments WHERE status='pending'")->fetchColumn();
      } catch (Throwable $e) {}
      ?>
      <a href="/admin/comments.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'comments' ? 'active' : '' ?>">
        Коментари<?php if ($pending_comments > 0): ?> <span style="background:var(--teal);color:#fff;border-radius:10px;padding:.05rem .4rem;font-size:.72rem;margin-left:.3rem;vertical-align:middle;"><?= $pending_comments ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <?php if (admin_is_admin()): ?>
      <a href="/admin/partners.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'partners' ? 'active' : '' ?>">
        Партньори
      </a>
      <a href="/admin/pages.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'pages' ? 'active' : '' ?>">
        Съдържание
      </a>
      <?php endif; ?>
      <?php if (admin_can_manage_shop()): ?>
      <a href="/admin/products.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'products' ? 'active' : '' ?>">
        Продукти
      </a>
      <a href="/admin/orders.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'orders' ? 'active' : '' ?>">
        Поръчки
      </a>
      <a href="/admin/monthly-report.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'monthly-report' ? 'active' : '' ?>">
        Месечен отчет
      </a>
      <?php endif; ?>
      <?php if (admin_is_admin()): ?>
      <a href="/admin/menus.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'menus' ? 'active' : '' ?>">
        Менюта
      </a>
      <a href="/admin/couriers.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'couriers' ? 'active' : '' ?>">
        Куриери
      </a>
      <a href="/admin/payment.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'payment' ? 'active' : '' ?>">
        Плащания
      </a>
      <a href="/admin/users.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'users' ? 'active' : '' ?>">
        Потребители
      </a>
      <a href="/admin/newsletter.php"
         class="admin-nav__link <?= in_array($active_nav ?? '', ['newsletter','newsletter-compose','newsletter-send','newsletter-subscribers']) ? 'active' : '' ?>">
        Бюлетин
      </a>
      <a href="/admin/campaign.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'campaign' ? 'active' : '' ?>">
        Кампания
      </a>
      <a href="/admin/email-templates.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'email-templates' ? 'active' : '' ?>">
        Имейл шаблони
      </a>
      <a href="/admin/error-alerts.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'error-alerts' ? 'active' : '' ?>">
        Известия за грешки
      </a>
      <?php if (admin_can_sign()): ?>
      <a href="/admin/signature.php"
         class="admin-nav__link <?= ($active_nav ?? '') === 'signature' ? 'active' : '' ?>">
        Подпис
      </a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="admin-sidebar__footer">
      <div class="admin-user">
        <div class="admin-user__name"><?= h($current_user['name'] ?? '') ?></div>
        <div class="admin-user__role"><?= h($current_user['role'] ?? '') ?></div>
      </div>
      <a href="/" target="_blank" class="admin-nav__link" style="font-size:.8rem;opacity:.7;">↗ Към сайта</a>
      <a href="/admin/logout.php" class="admin-nav__link admin-nav__link--logout">Изход</a>
    </div>
  </aside>

  <main class="admin-main">
    <div class="admin-topbar">
      <button class="admin-hamburger" id="adminHamburger" aria-label="Toggle menu">
        <span></span><span></span><span></span>
      </button>
      <div class="admin-topbar__title"><?= h($page_title_admin ?? '') ?></div>
      <a href="/admin/logout.php" class="admin-topbar__logout">Изход</a>
    </div>

    <div class="admin-content">
