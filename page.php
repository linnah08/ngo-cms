<?php
/**
 * Front controller for the pages admins create in Admin → Страници.
 *
 * .htaccess sends /<name>/ and /en/<name>/ here only when no real file or
 * folder has that name, so a built-in page always wins. (The PHP built-in
 * server has no .htaccess: index.php and en/index.php hand such addresses on
 * to this file instead.) The address is read from REQUEST_URI.
 *
 * Visitors see published pages only; anything else is the site's 404 page.
 * A logged-in admin (Admin role) also sees drafts, with a bar saying so and a Publish button.
 */
if (!defined('OM_CPAGE_ROUTED')) define('OM_CPAGE_ROUTED', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';

$_cp_path   = url_request_path($_SERVER['REQUEST_URI'] ?? '/');
$_cp_parsed = cpage_parse_path($_cp_path);
$_cp_page   = $_cp_parsed !== null ? cpage_by_slug($_cp_parsed[0], $_cp_parsed[1]) : null;
$_cp_draft  = $_cp_page !== null && $_cp_page['status'] !== 'published';

// Drafts: only for the people who can edit and publish pages (Admin role).
if ($_cp_page === null || ($_cp_draft && !(admin_logged_in() && admin_is_admin()))) {
    require $_SERVER['DOCUMENT_ROOT'] . '/errors/404.php';
    exit;
}

$lang = $_cp_parsed[0];
// One address per page: /story → /story/ (a draft is not indexed, so it needs no tidy address).
if (!str_ends_with($_cp_path, '/') && !$_cp_draft) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . cpage_url($_cp_page, $lang) . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

if ($_cp_draft) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
} else {
    $seo_alternates = ['bg' => cpage_url($_cp_page, 'bg'), 'en' => cpage_url($_cp_page, 'en')];
}
$seo_canonical    = rtrim(SITE_URL, '/') . cpage_url($_cp_page, $lang);
$page_title       = cpage_title($_cp_page, $lang);
$page_description = cpage_description($_cp_page, $lang);

home_target_page($_cp_page['id']);
$_cp_doc = home_load()['doc'];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

if ($_cp_draft): ?>
<div role="status" style="background:#fef3c7;border-bottom:3px solid #b45309;color:#78350f;">
  <div style="max-width:1200px;margin:0 auto;padding:.85rem 1rem;display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;align-items:center;justify-content:space-between;">
    <p style="margin:0;font-weight:700;font-size:1rem;line-height:1.4;">
      <span aria-hidden="true">✎ </span>Чернова — не се вижда от посетителите
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;">
      <a href="/admin/page-edit.php?id=<?= h($_cp_page['id']) ?>"
         style="display:inline-flex;align-items:center;min-height:44px;padding:0 1rem;border:2px solid #78350f;border-radius:6px;color:#78350f;font-weight:600;text-decoration:none;background:#fff;">Редактирай</a>
      <form method="POST" action="/admin/created-pages.php" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="publish">
        <input type="hidden" name="id" value="<?= h($_cp_page['id']) ?>">
        <input type="hidden" name="back" value="view">
        <button type="submit" style="min-height:44px;padding:0 1.1rem;border:2px solid #166534;border-radius:6px;background:#166534;color:#fff;font-weight:700;font-size:1rem;cursor:pointer;">Публикувай</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <h1 style="margin:0;"><?= h($page_title) ?></h1>
  </div>
</section>

<?php
if (!$_cp_doc['sections'] && $_cp_draft): ?>
<section class="section">
  <div class="container container--narrow">
    <p style="color:var(--text-muted);">Страницата още няма съдържание. Добавете секции от „Редактирай“.</p>
  </div>
</section>
<?php endif;

home_render($_cp_doc, $lang);
home_target_reset();
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
