<?php
// Variables available (via extract in render_email):
//   $order – order array
//   $tpl   – rendered template fields: $tpl['intro'], $tpl['outro']
echo $tpl['intro'];
?>

<div class="box">
  <strong>Поръчка:</strong> <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Документ:</strong> прикачен PDF (кредитно известие)
</div>

<?php echo $tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  При въпроси: <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
