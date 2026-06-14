<?php
http_response_code(500);
$code       = 500;
$title      = 'Нещо се обърка';
$title_en   = 'Something went wrong';
$message    = 'Възникна грешка. Тя е регистрирана и ще бъде поправена. Опитай отново след малко.';
$message_en = 'An error occurred. It has been logged and will be fixed. Please try again shortly.';
require __DIR__ . '/_layout.php';
