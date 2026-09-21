<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';
$lang             = 'en';
$page_title       = 'Home';
$page_description = SITE_NAME_EN;

// Sections, their order and their content are edited in admin/home-sections.php.
$home_doc = home_load()['doc'];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
home_render($home_doc, $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
