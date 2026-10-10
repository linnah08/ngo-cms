<?php
/**
 * One-time login straight from the install wizard's finish screen. The link
 * works once, for 15 minutes (admin_redeem_install_login()); after that the
 * admin logs in with their email and password as usual.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

$user = null;
try {
    $user = admin_redeem_install_login(get_pdo(), (string) ($_GET['t'] ?? ''));
} catch (Throwable $e) {
    error_log('install-login: ' . $e->getMessage());
}
if ($user) {
    admin_start_session($user);
    header('Location: /admin/', true, 303);
    exit;
}
flash_set('error', 'Връзката за влизане вече е използвана или е изтекла. Влезте с имейла и паролата, които избрахте.');
header('Location: /admin/login.php', true, 303);
exit;
