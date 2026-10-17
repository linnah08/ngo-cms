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

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

// Draft bar, title and sections: shared with the built-in pages built from blocks (За нас).
$_bp_page  = $_cp_page;
$_bp_draft = $_cp_draft;
require $_SERVER['DOCUMENT_ROOT'] . '/templates/block-page.php';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
