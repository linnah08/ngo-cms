<?php
http_response_code(403);
$code       = 403;
$title      = 'Достъпът е забранен';
$title_en   = 'Access denied';
$message    = 'Нямаш разрешение да видиш тази страница.';
$message_en = 'You don\'t have permission to access this page.';
require __DIR__ . '/_layout.php';
