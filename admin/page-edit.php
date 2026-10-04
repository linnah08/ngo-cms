<?php
/**
 * Edit a page created in Admin → Страници: its title, addresses and status,
 * and its content — built from the same sections as the front page, with the
 * same editor (admin/home-sections.php), pointed at this page's file.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';

admin_require_login();
admin_require_admin();

$_cp_id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (cpage_get($_cp_id) === null) {
    flash_set('error', 'Страницата не е намерена — може би е изтрита междувременно.');
    header('Location: /admin/pages.php');
    exit;
}
home_target_page($_cp_id);
require __DIR__ . '/home-sections.php';
