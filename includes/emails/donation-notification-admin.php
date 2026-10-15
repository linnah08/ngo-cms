<?php
// Variables: $donor_name, $donor_email, $amount_eur, $donation_message, $order_number, $payment_method
// This email goes out once a donation is paid (card or IRIS). Only a bank transfer
// still waits for the money to arrive, so only that one says so.
$_pm        = (string)($payment_method ?? '');
$_pm_labels = ['card' => 'карта', 'iris' => 'IRIS Pay by Bank'];
$_pm_label  = $_pm_labels[$_pm] ?? '';
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

<?php if ($_pm === 'bank_transfer'): ?>
<p>Очакваме потвърждение за банков превод.</p>
<?php else: ?>
<p>Плащането е потвърдено<?= $_pm_label !== '' ? ' (' . htmlspecialchars($_pm_label, ENT_QUOTES, 'UTF-8') . ')' : '' ?>.</p>
<?php endif; ?>
