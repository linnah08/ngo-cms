<?php
// Variables: $pledge (array from campaign_pledges table)
$amount_bgn = number_format($pledge['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ');
$amount_eur = number_format($pledge['amount_eur'], 2, '.', ' ');
$addr       = $pledge['delivery_address'] ? json_decode($pledge['delivery_address'], true) : null;
?>
<h2>Нов поддръжник на кампанията</h2>
<div class="box">
  <strong>Номер:</strong> <?= htmlspecialchars($pledge['pledge_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Поддръжник:</strong> <?= htmlspecialchars($pledge['name'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Email:</strong> <?= htmlspecialchars($pledge['email'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Сума:</strong> <?= $amount_bgn ?> лв (<?= $amount_eur ?> EUR)<br>
  <strong>Дата:</strong> <?= htmlspecialchars(substr($pledge['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
</div>

<?php if ($addr): ?>
<p>
  <strong>Адрес за доставка на награда:</strong><br>
  <?= htmlspecialchars(implode(', ', array_filter([$addr['address']??'', $addr['city']??'', $addr['postcode']??''])), ENT_QUOTES, 'UTF-8') ?>
  <?php if (!empty($addr['phone'])): ?>— <?= htmlspecialchars($addr['phone'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
</p>
<?php endif; ?>

<p><a href="<?= defined('SITE_URL') ? SITE_URL : '' ?>/admin/campaign-backers.php">Виж всички поддръжници →</a></p>
