<?php
http_response_code(503);
$code       = 503;
$title      = 'Временно недостъпен';
$title_en   = 'Temporarily unavailable';
$message    = 'Сайтът е временно недостъпен поради техническа поддръжка. Ще се върнем скоро.';
$message_en = 'The site is temporarily down for maintenance. We\'ll be back shortly.';
$show_home  = false;
require __DIR__ . '/_layout.php';
