<?php
http_response_code(404);
$code       = 404;
$title      = 'Страницата не е намерена';
$title_en   = 'Page not found';
$message    = 'Страницата, която търсиш, не съществува или е преместена на друг адрес.';
$message_en = 'The page you\'re looking for doesn\'t exist or has been moved.';
require __DIR__ . '/_layout.php';
