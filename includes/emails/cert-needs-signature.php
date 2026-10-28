<?php
// Variables: $order (array), $document (array with formatted_number), $order_id (int)
// Sent to the admin who signs, in Bulgarian. Its sentences are wording keys (email.cert_sign.*),
// so a site can address its admins differently in strings.site.json.
$sign_url = SITE_URL . '/admin/order-view.php?id=' . (int)$order_id;
$cert_num  = htmlspecialchars($document['formatted_number'], ENT_QUOTES, 'UTF-8');
$donor     = htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../order_view.php';
$amount    = number_format(order_donation_amount(json_decode($order['items'] ?? '[]', true) ?? [], (float)$order['total_eur']), 2, '.', ' ') . ' €';
?>
<h2>Нов сертификат за дарение — нужен подпис</h2>
<p><?= h(t_or('email.cert_sign.greeting', 'Здравейте,', 'Hello,', 'bg')) ?></p>
<p><?= h(t_or('email.cert_sign.intro', 'Генериран е нов сертификат за дарение и очаква вашия подпис.', 'A new donation certificate has been created and is waiting for your signature.', 'bg')) ?></p>

<div class="box">
  <strong>Номер:</strong> <?= $cert_num ?><br>
  <strong>Дарител:</strong> <?= $donor ?><br>
  <strong>Сума:</strong> <?= $amount ?>
</div>

<p>
  <a href="<?= htmlspecialchars($sign_url, ENT_QUOTES, 'UTF-8') ?>"
     style="background:#1b998b;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">
    <?= h(t_or('email.cert_sign.button', 'Подпишете сертификата', 'Sign the certificate', 'bg')) ?> →
  </a>
</p>

<p style="color:#6b6560;font-size:14px;">
  <?= h(t_or('email.cert_sign.hint', 'Намерете бутона „Подпиши“ до сертификата в страницата на поръчката.', 'Find the "Sign" button next to the certificate on the order page.', 'bg')) ?>
</p>
