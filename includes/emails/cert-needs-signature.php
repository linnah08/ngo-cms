<?php
// Variables: $order (array), $document (array with formatted_number), $order_id (int)
$sign_url = SITE_URL . '/admin/order-view.php?id=' . (int)$order_id;
$cert_num  = htmlspecialchars($document['formatted_number'], ENT_QUOTES, 'UTF-8');
$donor     = htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8');
$amount    = number_format((float)$order['total_eur'], 2, '.', ' ') . ' €';
?>
<h2>Нов сертификат за дарение — нужен подпис</h2>
<p>Здравейте,</p>
<p>Генериран е нов сертификат за дарение и очаква вашия подпис.</p>

<div class="box">
  <strong>Номер:</strong> <?= $cert_num ?><br>
  <strong>Дарител:</strong> <?= $donor ?><br>
  <strong>Сума:</strong> <?= $amount ?>
</div>

<p>
  <a href="<?= htmlspecialchars($sign_url, ENT_QUOTES, 'UTF-8') ?>"
     style="background:#1b998b;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">
    Подпишете сертификата →
  </a>
</p>

<p style="color:#6b6560;font-size:14px;">
  Намерете бутона „Подпиши" до сертификата в страницата на поръчката.
</p>
