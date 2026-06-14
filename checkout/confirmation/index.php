<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$lang         = get_lang();
$order_number = trim($_GET['order'] ?? '');

// Sanitise order number
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

$items          = json_decode($order['items'], true) ?? [];
$courier_labels = ['speedy'=>'Speedy','boxnow'=>'BoxNow'];
$type_labels    = ['office'=>'До офис','apt'=>'До автомат','address'=>'До врата','locker'=>'До автомат (BoxNow)'];

$page_title = $lang === 'bg' ? 'Поръчката е потвърдена' : 'Order confirmed';

$_dl_items = array_map(fn($item) => [
    'item_name' => $item['name_bg'] ?? ($item['name_en'] ?? ''),
    'price'     => (float)($item['price_eur'] ?? 0),
    'quantity'  => (int)($item['quantity'] ?? 1),
], $items);

$page_head_extra = '<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    event: "purchase",
    ecommerce: {
        transaction_id: ' . json_encode($order['order_number']) . ',
        value: ' . (float)$order['total_eur'] . ',
        currency: "EUR",
        items: ' . json_encode($_dl_items, JSON_UNESCAPED_UNICODE) . '
    }
});
</script>';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:1.5rem;">
  <div class="container" style="text-align:center;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1rem;">✅</div>
    <h1 style="color:var(--teal);"><?= $lang === 'bg' ? 'Благодарим за поръчката!' : 'Thank you for your order!' ?></h1>
    <p style="font-size:1.1rem;color:var(--text-muted);">
      <?= $lang === 'bg' ? 'Поръчка' : 'Order' ?> <strong>#<?= h($order['order_number']) ?></strong>
    </p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:640px;">

    <!-- Order summary -->
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:2rem;">
      <div style="padding:1rem 1.25rem;background:var(--off-white);font-size:.75rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">
        Продукти
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr style="border-top:1px solid var(--border);">
            <td style="padding:.65rem 1rem;"><?= h($item['name_bg'] ?? '') ?></td>
            <td style="padding:.65rem 1rem;text-align:center;color:var(--text-muted);">× <?= (int)($item['quantity'] ?? 1) ?></td>
            <td style="padding:.65rem 1rem;text-align:right;"><?= number_format((float)($item['subtotal_eur'] ?? 0), 2) ?> €</td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-top:1px solid var(--border);">
            <td colspan="2" style="padding:.65rem 1rem;text-align:right;color:var(--text-muted);">Доставка:</td>
            <td style="padding:.65rem 1rem;text-align:right;"><?= number_format((float)$order['shipping_eur'], 2) ?> €</td>
          </tr>
          <tr style="border-top:2px solid var(--border);background:var(--off-white);">
            <td colspan="2" style="padding:.75rem 1rem;text-align:right;font-weight:700;">Общо:</td>
            <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:var(--teal);"><?= number_format((float)$order['total_eur'], 2) ?> €</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- What happens next -->
    <div style="padding:1.5rem;background:var(--teal-light);border-radius:var(--radius-lg);margin-bottom:2rem;">
      <h3 style="margin-top:0;color:var(--teal);">Какво следва?</h3>
      <ol style="margin:0;padding-left:1.5rem;line-height:2;">
        <li>Ще потвърдим поръчката ви по имейл в рамките на 24 часа.</li>
        <li>Изпращаме с <?= h($courier_labels[$order['courier']] ?? '') ?> (<?= h($type_labels[$order['delivery_type']] ?? '') ?>).</li>
        <?php if ($order['delivery_type'] !== 'address'): ?>
          <li>Офис/автомат: <?= h($order['courier_office_name'] ?? '') ?></li>
        <?php else: ?>
          <li>Адрес: <?= h($order['delivery_address'] . ', ' . $order['delivery_city']) ?></li>
        <?php endif; ?>
        <?php if ($order['payment_method'] === 'card'): ?>
          <li>Платихте с карта — поръчката е потвърдена.</li>
        <?php else: ?>
          <li>Плащате с наложен платеж при получаване.</li>
        <?php endif; ?>
      </ol>
    </div>

    <div style="text-align:center;">
      <a href="/magazin/" class="btn btn--outline">← Към магазина</a>
      <a href="/" class="btn btn--primary" style="margin-left:1rem;">Начало</a>
    </div>
  </div>
</section>

<!-- Follow / share -->
<section class="section" style="padding-top:0;">
  <div class="container" style="max-width:560px;text-align:center;">
    <p style="color:var(--text-muted);font-size:.95rem;margin-bottom:1rem;">Последвайте ни и споделете мисията ни:</p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
