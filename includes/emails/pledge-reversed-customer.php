<?php
// Variables: $pledge (array), $tpl (rendered subject/intro/outro from email_tpl_get)
echo $tpl['intro'];
?>

<div class="box">
  <strong>Референтен номер:</strong> <?= htmlspecialchars($pledge['pledge_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Сума:</strong> <?= htmlspecialchars(number_format((float)$pledge['amount_eur'], 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?> EUR
</div>

<?php echo $tpl['outro']; ?>
