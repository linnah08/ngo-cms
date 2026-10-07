<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/financial_reports.php';
// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('annual_reports');
$lang             = 'bg';
$page_title       = 'Годишни отчети';
$page_description = 'Годишни финансови отчети и доклади за дейността на ' . SITE_NAME_BG . '.';
$reports          = reports_public_list(reports_load(), $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/financial-reports.php';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
