<?php
// Variables: $order (array from orders table), $lang (optional)
if (!function_exists('email_tpl_get')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
}
$items = is_string($order['items']) ? json_decode($order['items'], true) : $order['items'];
$courier_labels = ['speedy' => 'Speedy', 'boxnow' => 'BoxNow'];
$type_labels_bg = ['office' => 'офис', 'address' => 'адрес', 'locker' => 'автомат'];
$type_labels_en = ['office' => 'office', 'address' => 'address', 'locker' => 'locker'];

$_lang = $lang ?? $order['lang'] ?? 'bg';
$_vars = ['customer_name' => $order['customer_name'], 'order_number' => $order['order_number']];
$_tpl  = email_tpl_get('order-confirmation-customer', $_lang, $_vars);

echo $_tpl['intro'];
?>

<div class="box">
  <strong><?= $_lang === 'en' ? 'Order' : 'Поръчка' ?>:</strong> <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Date' : 'Дата' ?>:</strong> <?= htmlspecialchars(substr($order['created_at'] ?? date('Y-m-d H:i:s'), 0, 16), ENT_QUOTES, 'UTF-8') ?>
</div>

<h3 style="color:#0387A5;"><?= $_lang === 'en' ? 'Items ordered' : 'Поръчани продукти' ?></h3>
<table>
  <thead>
    <tr>
      <th><?= $_lang === 'en' ? 'Product' : 'Продукт' ?></th>
      <th style="text-align:right;"><?= $_lang === 'en' ? 'Qty' : 'Бр.' ?></th>
      <th style="text-align:right;"><?= $_lang === 'en' ? 'Price' : 'Цена' ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
    <tr>
      <td>
        <?php
          $iname = $_lang === 'en' ? ($item['name_en'] ?? $item['name_bg'] ?? '') : ($item['name_bg'] ?? '');
          echo htmlspecialchars($iname, ENT_QUOTES, 'UTF-8');
          $variant_parts = [];
          if (!empty($item['colour'])) $variant_parts[] = ($_lang === 'en' ? 'Colour' : 'Цвят') . ': ' . ucfirst($item['colour']);
          if (!empty($item['size']))   $variant_parts[] = ($_lang === 'en' ? 'Size'   : 'Размер') . ': ' . $item['size'];
          if ($variant_parts) {
              echo '<br><span style="font-size:12px;color:#6b6560;">' . htmlspecialchars(implode(' · ', $variant_parts), ENT_QUOTES, 'UTF-8') . '</span>';
          }
          if (!empty($item['design_file'])) {
              $design_label = $_lang === 'en' ? 'Custom design' : 'Персонализиран дизайн';
              echo '<br><span style="font-size:12px;color:#6b6560;">' . $design_label . '</span>';
          }
        ?>
      </td>
      <td style="text-align:right;"><?= (int)($item['quantity'] ?? 1) ?></td>
      <td style="text-align:right;"><?= number_format((float)($item['subtotal_eur'] ?? $item['price_eur'] ?? 0), 2) ?> €</td>
    </tr>
    <?php endforeach; ?>
    <tr>
      <td colspan="2" style="text-align:right;color:#6b6560;"><?= $_lang === 'en' ? 'Shipping' : 'Доставка' ?>:</td>
      <td style="text-align:right;"><?= number_format((float)$order['shipping_eur'], 2) ?> €</td>
    </tr>
    <tr>
      <td colspan="2" style="text-align:right;font-weight:700;"><?= $_lang === 'en' ? 'Total' : 'Общо' ?>:</td>
      <td style="text-align:right;font-weight:700;color:#0387A5;"><?= number_format((float)$order['total_eur'], 2) ?> €</td>
    </tr>
  </tbody>
</table>

<h3 style="color:#0387A5;"><?= $_lang === 'en' ? 'Delivery' : 'Доставка' ?></h3>
<?php $type_labels = $_lang === 'en' ? $type_labels_en : $type_labels_bg; ?>
<?php if ($order['delivery_type'] === 'address'): ?>
<p>
  <strong><?= $_lang === 'en' ? 'Courier' : 'Куриер' ?>:</strong> <?= htmlspecialchars($courier_labels[$order['courier']] ?? $order['courier'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Type' : 'Тип' ?>:</strong> <?= $_lang === 'en' ? 'To address' : 'До адрес' ?><br>
  <strong><?= $_lang === 'en' ? 'Address' : 'Адрес' ?>:</strong> <?= htmlspecialchars($order['delivery_address'] . ', ' . $order['delivery_city'], ENT_QUOTES, 'UTF-8') ?>
</p>
<?php else: ?>
<p>
  <strong><?= $_lang === 'en' ? 'Courier' : 'Куриер' ?>:</strong> <?= htmlspecialchars($courier_labels[$order['courier']] ?? $order['courier'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Type' : 'Тип' ?>:</strong> <?= htmlspecialchars($type_labels[$order['delivery_type']] ?? $order['delivery_type'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Office/Locker' : 'Офис/Автомат' ?>:</strong> <?= htmlspecialchars($order['courier_office_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
</p>
<?php endif; ?>

<?php echo $_tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
