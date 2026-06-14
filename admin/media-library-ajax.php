<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_login();

$dirs = [
    'articles' => '/assets/images/articles/',
    'products' => '/assets/images/products/',
    'partners' => '/assets/images/partners/',
    'pages'    => '/assets/images/pages/',
    'centres'  => '/assets/images/centres/',
    'team'     => '/assets/images/team/',
    'projects' => '/assets/images/projects/',
];

$allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
$images = [];

foreach ($dirs as $cat => $url_path) {
    $dir = $_SERVER['DOCUMENT_ROOT'] . $url_path;
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) continue;
        $images[] = [
            'path'  => $url_path . $f,
            'cat'   => $cat,
            'mtime' => @filemtime($dir . $f) ?: 0,
        ];
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'images' => $images]);
