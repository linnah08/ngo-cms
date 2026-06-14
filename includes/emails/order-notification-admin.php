<?php
// Variables: $order (array), $admin_url (string)
$items = is_string($order['items']) ? json_decode($order['items'], true) : $order['items'];
$courier_labels = ['speedy' => 'Speedy', 'boxnow' => 'BoxNow'];
$type_labels    = ['office' => 'Офис', 'address' => 'До адрес', 'locker' => 'Автомат'];
?>
<h2>Нова поръчка <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></h2>

<div class="box">
  <strong>Клиент:</strong> <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Имейл:</strong> <a href="mailto:<?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></a><br>
  <strong>Телефон:</strong> <?= htmlspecialchars($order['customer_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
</div>

<h3 style="color:#0387A5;">Продукти</h3>
<table>
  <thead>
    <tr><th>Продукт</th><th>Бр.</th><th>Цена</th></tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
    <tr>
      <td><?= htmlspecialchars($item['name_bg'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= (int)($item['quantity'] ?? 1) ?></td>
      <td><?= number_format((float)($item['subtotal_eur'] ?? 0), 2) ?> €</td>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td colspan="2" style="text-align:right;">Доставка:</td>
      <td><?= number_format((float)$order['shipping_eur'], 2) ?> €</td>
    </tr>
    <tr>
      <td colspan="2" style="text-align:right;font-weight:700;">Общо:</td>
      <td style="font-weight:700;color:#0387A5;"><?= number_format((float)$order['total_eur'], 2) ?> €</td>
    </tr>
  </tbody>
</table>

<h3 style="color:#0387A5;">Доставка</h3>
<?php if ($order['delivery_type'] === 'address'): ?>
<p>
  <?= htmlspecialchars($courier_labels[$order['courier']] ?? $order['courier'], ENT_QUOTES, 'UTF-8') ?> —
  До адрес: <?= htmlspecialchars($order['delivery_address'] . ', ' . $order['delivery_city'], ENT_QUOTES, 'UTF-8') ?>
</p>
<?php else: ?>
<p>
  <?= htmlspecialchars($courier_labels[$order['courier']] ?? $order['courier'], ENT_QUOTES, 'UTF-8') ?> —
  <?= htmlspecialchars($type_labels[$order['delivery_type']] ?? '', ENT_QUOTES, 'UTF-8') ?>:
  <?= htmlspecialchars($order['courier_office_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
</p>
<?php endif; ?>

<p style="margin-top:1.5rem;">
  <a href="<?= htmlspecialchars($admin_url ?? '', ENT_QUOTES, 'UTF-8') ?>"
     style="background:#0387A5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block;">
    Виж поръчката в Admin панела
  </a>
</p>
