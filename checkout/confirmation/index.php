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

// Show the confirmation in the language the order was placed in. Payment
// returns and older links may land on the BG path, so move an English order
// to /en/ — header, footer and <html lang> follow the URL.
$order_lang = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
if ($order_lang !== $lang) {
    header('Location: ' . shop_path('confirmation', $order_lang) . '?order=' . urlencode($order['order_number']));
    exit;
}

$items          = json_decode($order['items'], true) ?? [];
$courier_labels = ['speedy'=>'Speedy','boxnow'=>'BoxNow'];
$type_labels    = checkout_delivery_type_labels($lang);
$item_name      = static fn (array $item): string => $lang === 'en'
    ? (($item['name_en'] ?? '') ?: ($item['name_bg'] ?? ''))
    : ($item['name_bg'] ?? '');

$page_title = t_or('confirm.title', 'Поръчката е потвърдена', 'Order confirmed');

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
    <div style="font-size:3rem;margin-bottom:1rem;" aria-hidden="true">✅</div>
    <h1 style="color:var(--teal);"><?= h(t_or('confirm.thanks', 'Благодарим за поръчката!', 'Thank you for your order!')) ?></h1>
    <p style="font-size:1.1rem;color:var(--text-muted);">
      <?= h(t_or('confirm.order', 'Поръчка', 'Order')) ?> <strong>#<?= h($order['order_number']) ?></strong>
    </p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:640px;">

    <!-- Order summary -->
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:2rem;">
      <div style="padding:1rem 1.25rem;background:var(--off-white);font-size:.75rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">
        <?= h(t_or('confirm.items', 'Продукти', 'Items')) ?>
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr style="border-top:1px solid var(--border);">
            <td style="padding:.65rem 1rem;"><?= h($item_name($item)) ?></td>
            <td style="padding:.65rem 1rem;text-align:center;color:var(--text-muted);">× <?= (int)($item['quantity'] ?? 1) ?></td>
            <td style="padding:.65rem 1rem;text-align:right;"><?= number_format((float)($item['subtotal_eur'] ?? 0), 2) ?> €</td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-top:1px solid var(--border);">
            <td colspan="2" style="padding:.65rem 1rem;text-align:right;color:var(--text-muted);"><?= h(t_or('checkout.shipping', 'Доставка', 'Delivery')) ?>:</td>
            <td style="padding:.65rem 1rem;text-align:right;"><?= number_format((float)$order['shipping_eur'], 2) ?> €</td>
          </tr>
          <tr style="border-top:2px solid var(--border);background:var(--off-white);">
            <td colspan="2" style="padding:.75rem 1rem;text-align:right;font-weight:700;"><?= h(t_or('checkout.total', 'Общо', 'Total')) ?>:</td>
            <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:var(--teal);"><?= number_format((float)$order['total_eur'], 2) ?> €</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- What happens next -->
    <div style="padding:1.5rem;background:var(--teal-light);border-radius:var(--radius-lg);margin-bottom:2rem;">
      <h3 style="margin-top:0;color:var(--teal);"><?= h(t_or('confirm.next', 'Какво следва?', 'What happens next?')) ?></h3>
      <ol style="margin:0;padding-left:1.5rem;line-height:2;">
        <li><?= h(t_or('confirm.next.email', 'Ще потвърдим поръчката ви по имейл в рамките на 24 часа.', 'We will confirm your order by email within 24 hours.')) ?></li>
        <li><?= h(t_or('confirm.next.courier', 'Изпращаме с {courier} ({type}).', 'We ship with {courier} ({type}).', vars: ['courier' => $courier_labels[$order['courier']] ?? '', 'type' => $type_labels[$order['delivery_type']] ?? ''])) ?></li>
        <?php if ($order['delivery_type'] !== 'address'): ?>
          <li><?= h(t_or('confirm.next.office', 'Офис/автомат', 'Office/locker')) ?>: <?= h($order['courier_office_name'] ?? '') ?></li>
        <?php else: ?>
          <li><?= h(t_or('confirm.next.address', 'Адрес', 'Address')) ?>: <?= h($order['delivery_address'] . ', ' . $order['delivery_city']) ?></li>
        <?php endif; ?>
        <?php if ($order['payment_status'] === 'paid'): ?>
          <li><?= h(t_or('confirm.next.paid', 'Плащането е получено — поръчката е потвърдена.', 'Payment received — your order is confirmed.')) ?></li>
        <?php else: ?>
          <li><?= h(t_or('confirm.next.pending', 'Очакваме потвърждение на плащането.', 'We are waiting for the payment to be confirmed.')) ?></li>
        <?php endif; ?>
      </ol>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <a href="<?= h(shop_path('shop')) ?>" class="btn btn--outline">← <?= h(t_or('confirm.back_to_shop', 'Към магазина', 'Back to the shop')) ?></a>
      <a href="<?= $lang === 'en' ? '/en/' : '/' ?>" class="btn btn--primary"><?= h(t_or('confirm.home', 'Начало', 'Home')) ?></a>
    </div>
  </div>
</section>

<!-- Follow / share -->
<section class="section" style="padding-top:0;">
  <div class="container" style="max-width:560px;text-align:center;">
    <p style="color:var(--text-muted);font-size:.95rem;margin-bottom:1rem;"><?= h(t_or('confirm.follow', 'Последвайте ни и споделете мисията ни:', 'Follow us and share our mission:')) ?></p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
