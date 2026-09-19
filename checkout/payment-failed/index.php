<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$order_number = trim($_GET['order'] ?? '');
$err_msg      = trim($_GET['err']   ?? '');

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
    header('Location: ' . ($is_donation ? '/donation/confirmation/' : '/checkout/confirmation/') . '?order=' . urlencode($order_number));
    exit;
}

// This page is reached from the bank / our payment endpoints, not a language-prefixed URL,
// so the order's own language decides the text.
$lang    = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$expired = !empty($order['unpaid_cancelled_at']);
$is_iris = $order['payment_method'] === 'iris';

$retry_url = ($is_iris ? '/api/iris-payment-return.php' : '/api/payment-return.php')
           . '?retry=1&order=' . urlencode($order_number);
$back_url  = $lang === 'en' ? '/en/shop/' : '/magazin/';

$t = $lang === 'en' ? [
    'title'      => 'Payment failed',
    'saved'      => $is_donation
        ? 'Your donation was not completed. You can try the payment again.'
        : 'Order <strong>#' . h($order['order_number']) . '</strong> is saved. You can try the payment again.',
    'expired'    => $is_donation
        ? 'This donation was not completed in time. Please start a new donation.'
        : 'Order <strong>#' . h($order['order_number']) . '</strong> was not paid within 24 hours and has been cancelled. Please place a new order.',
    'retry'      => $is_iris ? 'Try again with bank transfer' : 'Try again with card',
    'back'       => '← Back to the shop',
] : [
    'title'      => 'Плащането не бе успешно',
    'saved'      => $is_donation
        ? 'Дарението не беше завършено. Можете да опитате плащането отново.'
        : 'Поръчка <strong>#' . h($order['order_number']) . '</strong> е запазена. Можете да опитате плащането отново.',
    'expired'    => $is_donation
        ? 'Дарението не беше завършено навреме. Моля, направете ново дарение.'
        : 'Поръчка <strong>#' . h($order['order_number']) . '</strong> не беше платена в рамките на 24 часа и е отменена. Моля, направете нова поръчка.',
    'retry'      => $is_iris ? 'Опитай отново с банков превод' : 'Опитай отново с карта',
    'back'       => '← Към магазина',
];

$page_title = $t['title'];
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:1.5rem;">
  <div class="container" style="text-align:center;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1rem;">❌</div>
    <h1 style="color:#c0392b;"><?= h($t['title']) ?></h1>
    <p style="color:var(--text-muted);">
      <?= $expired ? $t['expired'] : $t['saved'] ?>
    </p>
    <?php if ($err_msg && !$expired): ?>
    <p style="font-size:.85rem;color:#c0392b;margin-top:.5rem;"><?= h($err_msg) ?></p>
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
