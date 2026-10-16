<?php
// About us: a built-in page built from blocks (includes/about.php), edited in Admin → Страници → За нас.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/about.php';
$lang     = 'en';
$_bp_page = about_page();
$page_title = cpage_title($_bp_page, $lang);
$page_description = about_has_team($_bp_page)
    ? 'Learn more about ' . SITE_NAME_EN . ', our team and our story.'
    : 'Learn more about ' . SITE_NAME_EN . ' and our story.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

$_bp_label = 'About us';
$_bp_draft = false;
require $_SERVER['DOCUMENT_ROOT'] . '/templates/block-page.php';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
