<?php
// Variables: $donor_name, $donor_email, $amount_eur, $donation_message, $order_number
?>
<h2>Ново дарение</h2>
<div class="box">
  <strong>Дарител:</strong> <?= htmlspecialchars($donor_name, ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Имейл:</strong> <a href="mailto:<?= htmlspecialchars($donor_email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($donor_email, ENT_QUOTES, 'UTF-8') ?></a><br>
  <strong>Сума:</strong> <?= number_format((float)$amount_eur, 2) ?> €<br>
  <strong>Поръчка №:</strong> <?= htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8') ?>
</div>

<?php if (!empty($donation_message)): ?>
<p><strong>Послание / посвещение:</strong><br>
<?= nl2br(htmlspecialchars($donation_message, ENT_QUOTES, 'UTF-8')) ?></p>
<?php endif; ?>

<p>Очакваме потвърждение за банков превод.</p>
