<?php
// Safety net for created pages (Admin → Страници): a site whose owner edited
// .htaccess keeps its own copy through updates, so it may lack the rule that
// sends /<name>/ to page.php. Apache's ErrorDocument brings such an address
// here instead. page.php itself falls back to this file, hence the guard.
if (!defined('OM_CPAGE_ROUTED') && ($_SERVER['REDIRECT_STATUS'] ?? '') === '404'
    && preg_match('#^/(?:en/)?[a-z0-9-]+/?$#', (string) strtok($_SERVER['REQUEST_URI'] ?? '', '?#'))) {
    http_response_code(200);
    require dirname(__DIR__) . '/page.php';
    return;
}
http_response_code(404);
$code       = 404;
$title      = 'Страницата не е намерена';
$title_en   = 'Page not found';
$message    = 'Страницата, която търсиш, не съществува или е преместена на друг адрес.';
$message_en = 'The page you\'re looking for doesn\'t exist or has been moved.';
require __DIR__ . '/_layout.php';
