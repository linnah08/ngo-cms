<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$lang         = get_lang();
$order_number = trim($_GET['order'] ?? '');
$err_msg      = trim($_GET['err']   ?? '');

if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? AND type = ?');
$stmt->execute([$order_number, 'physical']);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /');
    exit;
}

// If already paid somehow (race), send to confirmation
if ($order['payment_status'] === 'paid') {
    header('Location: /checkout/confirmation/?order=' . urlencode($order_number));
    exit;
}

$page_title = $lang === 'bg' ? 'Плащането не бе успешно' : 'Payment failed';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:1.5rem;">
  <div class="container" style="text-align:center;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1rem;">❌</div>
    <h1 style="color:#c0392b;"><?= $lang === 'bg' ? 'Плащането не бе успешно' : 'Payment failed' ?></h1>
    <p style="color:var(--text-muted);">
      <?= $lang === 'bg'
          ? 'Поръчка <strong>#' . h($order['order_number']) . '</strong> е запазена. Можете да опитате отново или да изберете друг начин на плащане.'
          : 'Order <strong>#' . h($order['order_number']) . '</strong> is saved. You can try again or choose a different payment method.' ?>
    </p>
    <?php if ($err_msg): ?>
    <p style="font-size:.85rem;color:#c0392b;margin-top:.5rem;"><?= h($err_msg) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:480px;text-align:center;">
    <div style="display:flex;flex-direction:column;gap:1rem;align-items:center;">

      <a href="/api/payment-return.php?retry=1&order=<?= urlencode($order_number) ?>"
         class="btn btn--primary" style="width:100%;justify-content:center;padding:.85rem 1.5rem;font-size:1rem;">
        Опитай отново с карта
      </a>

      <div style="color:var(--text-muted);font-size:.9rem;">— или —</div>

      <!-- Switch to COD: POST to a small handler -->
      <form method="POST" action="/api/payment-switch-cod.php" style="width:100%;">
        <?= csrf_field() ?>
        <input type="hidden" name="order_number" value="<?= h($order_number) ?>">
        <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;padding:.85rem 1.5rem;font-size:1rem;">
          Плати с наложен платеж
        </button>
      </form>

      <a href="/magazin/" style="font-size:.9rem;color:var(--text-muted);">← Към магазина</a>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
