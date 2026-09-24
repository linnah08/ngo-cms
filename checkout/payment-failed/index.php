<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$order_number = trim($_GET['order'] ?? '');
// Set when the bank page couldn't be opened. Only a flag — the technical reason is
// in the server log, never shown to the customer.
$bank_error   = !empty($_GET['err']);

if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? AND type IN ('physical', 'donation')");
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /');
    exit;
}

$is_donation = $order['type'] === 'donation';

// If already paid somehow (race), send to confirmation
if ($order['payment_status'] === 'paid') {
    header('Location: ' . ($is_donation ? '/donation/confirmation/' : shop_path('confirmation', $order['lang'] ?? 'bg')) . '?order=' . urlencode($order_number));
    exit;
}

// The order's own language decides the text. The bank and our payment
// endpoints may land here on the BG path, so an English order is moved to the
// /en/ path — that way the header, footer and <html lang> are English too.
$lang = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
if ($lang !== get_lang()) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . shop_path('payment-failed', $lang) . ($qs !== '' ? '?' . $qs : ''));
    exit;
}
$expired = !empty($order['unpaid_cancelled_at']) || !empty($order['stock_returned_at']);
$is_iris = $order['payment_method'] === 'iris';

$retry_url = ($is_iris ? '/api/iris-payment-return.php' : '/api/payment-return.php')
           . '?retry=1&order=' . urlencode($order_number);
$back_url  = shop_path('shop', $lang);

$_num = '<strong>#' . h($order['order_number']) . '</strong>';
$t = [
    'title'      => t_or('payfail.title', 'Плащането не бе успешно', 'Payment failed'),
    'saved'      => $is_donation
        ? h(t_or('payfail.saved_donation', 'Дарението не беше завършено. Можете да опитате плащането отново.', 'Your donation was not completed. You can try the payment again.'))
        : strtr(h(t_or('payfail.saved', 'Поръчка {order} е запазена. Можете да опитате плащането отново.', 'Order {order} is saved. You can try the payment again.')), ['{order}' => $_num]),
    'expired'    => $is_donation
        ? h(t_or('payfail.expired_donation', 'Дарението не беше завършено навреме. Моля, направете ново дарение.', 'This donation was not completed in time. Please start a new donation.'))
        : strtr(h(t_or('payfail.expired', 'Поръчка {order} е отменена и вече не може да бъде платена. Моля, направете нова поръчка.', 'Order {order} has been cancelled and can no longer be paid. Please place a new order.')), ['{order}' => $_num]),
    'bank_error' => t_or('payfail.bank_error', 'Не успяхме да отворим страницата за плащане на банката. Моля, опитайте отново след малко.', 'We couldn’t open the bank’s payment page. Please try again in a moment.'),
    'retry'      => $is_iris
        ? t_or('payfail.retry_iris', 'Опитай отново с банков превод', 'Try again with bank transfer')
        : t_or('payfail.retry_card', 'Опитай отново с карта', 'Try again with card'),
    'back'       => '← ' . t_or('confirm.back_to_shop', 'Към магазина', 'Back to the shop'),
];

$page_title = $t['title'];
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:1.5rem;">
  <div class="container" style="text-align:center;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1rem;" aria-hidden="true">❌</div>
    <h1 style="color:#c0392b;"><?= h($t['title']) ?></h1>
    <p style="color:var(--text-muted);">
      <?= $expired ? $t['expired'] : $t['saved'] ?>
    </p>
    <?php if ($bank_error && !$expired): ?>
    <p style="font-size:.9rem;color:#c0392b;margin-top:.5rem;"><?= h($t['bank_error']) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:480px;text-align:center;">
    <div style="display:flex;flex-direction:column;gap:1rem;align-items:center;">

<?php if (!$expired): ?>
      <a href="<?= h($retry_url) ?>"
         class="btn btn--primary" style="width:100%;justify-content:center;padding:.85rem 1.5rem;font-size:1rem;">
        <?= h($t['retry']) ?>
      </a>
<?php endif; ?>

      <a href="<?= h($back_url) ?>" style="font-size:.9rem;color:var(--text-muted);"><?= h($t['back']) ?></a>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
