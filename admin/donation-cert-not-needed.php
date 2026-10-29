<?php
/**
 * „Не е нужен“ on the dashboard list „Дарения без сертификат“: marks one paid
 * donation (the row's own button) or every ticked one as not needing a
 * certificate, so it leaves the list. Undone from the order's page.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_view.php';

admin_require_shop();
module_admin_guard(MODULES_ISSUING_CERTIFICATES);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    exit('Невалидна заявка.');
}

// The row's own button sends its order as `only`; the bulk button sends the ticked `ids[]`.
$ids = isset($_POST['only']) ? [$_POST['only']] : (array) ($_POST['ids'] ?? []);
$ids = array_filter($ids, 'is_scalar');

if (!$ids) {
    flash_set('error', 'Не сте избрали дарение.');
} else {
    $n = donation_cert_mark_not_needed(get_pdo(), $ids);
    if ($n === 0) {
        flash_set('error', 'Нищо не беше променено — избраните дарения вече имат сертификат или вече са отбелязани.');
    } else {
        flash_set('success', $n === 1
            ? '1 дарение е отбелязано като „без сертификат“ и вече не се показва в списъка. Можете да го върнете от страницата на поръчката.'
            : $n . ' дарения са отбелязани като „без сертификат“ и вече не се показват в списъка. Можете да ги върнете от страницата на всяка поръчка.');
    }
}

header('Location: /admin/dashboard.php');
exit;
