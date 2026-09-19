<?php
// Variables: $order (array from orders table), $tpl (rendered subject/intro/outro), $retry_url (string)
$_lang = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$_esc  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

echo $tpl['intro'];
?>

<div class="box">
<?php if (($order['type'] ?? '') === 'donation'): ?>
  <strong><?= $_lang === 'en' ? 'Donation' : 'Дарение' ?>:</strong> <?= $_esc(number_format((float)$order['total_eur'], 2, '.', '')) ?> €<br>
<?php else: ?>
  <strong><?= $_lang === 'en' ? 'Order' : 'Поръчка' ?>:</strong> <?= $_esc($order['order_number']) ?><br>
  <strong><?= $_lang === 'en' ? 'Total' : 'Сума' ?>:</strong> <?= $_esc(number_format((float)$order['total_eur'], 2, '.', '')) ?> €<br>
<?php endif; ?>
  <strong><?= $_lang === 'en' ? 'Payment method' : 'Начин на плащане' ?>:</strong>
  <?= ($order['payment_method'] ?? '') === 'iris'
      ? ($_lang === 'en' ? 'Bank transfer (IRIS)' : 'Банков превод (IRIS)')
      : ($_lang === 'en' ? 'Card' : 'Карта') ?>
</div>

<p style="text-align:center;margin:28px 0;">
  <a href="<?= $_esc($retry_url) ?>"
     style="display:inline-block;background:#0387A5;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 32px;border-radius:6px;font-size:16px;">
    <?= $_lang === 'en' ? 'Try again' : 'Опитай отново' ?>
  </a>
</p>

<?php echo $tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
