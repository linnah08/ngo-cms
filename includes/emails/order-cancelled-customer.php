<?php
// Variables: $order (array), $tpl (rendered subject/intro/outro)
echo $tpl['intro'];
?>

<div class="box">
  <strong><?= ($order['lang'] ?? 'bg') === 'en' ? 'Order' : 'Поръчка' ?>:</strong> <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?>
</div>

<?php echo $tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= ($order['lang'] ?? 'bg') === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
