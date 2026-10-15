<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';
// The PHP built-in server (local development) has no .htaccess and answers an
// unknown address with the nearest index.php — this one. Hand it on to the
// created-pages front controller, which shows that page or the 404 page.
if (!in_array(url_request_path($_SERVER['REQUEST_URI'] ?? '/'), ['/', '/index.php'], true)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/page.php';
    return;
}
$lang = get_lang();
$page_title = 'Начало';
// Title for search engines, set in Админ → Начална страница (the site's name
// when empty). It replaces the "Page — Site" template.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_seo.php';
$page_title_full  = home_seo_title($lang, $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN);
$page_description = org_site_description($lang);   // Admin → Организация

// Sections, their order and their content are edited in admin/home-sections.php.
$home_doc = home_load()['doc'];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
home_render($home_doc, $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
