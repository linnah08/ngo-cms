<?php
// To the organisation when a ticket is paid. Variables: $pledge (campaign_pledges row), $event (events row).
$_e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_qty = max(1, (int) ($pledge['ticket_qty'] ?? 1));
$_url = (defined('SITE_URL') ? SITE_URL : '') . (!empty($event['id']) ? '/admin/event-edit.php?id=' . (int) $event['id'] : '/admin/events.php');
?>
<h2>Продаден билет</h2>
<div class="box">
  <strong>Събитие:</strong> <?= $_e($event['title'] ?? '') ?><br>
  <strong>Купувач:</strong> <?= $_e($pledge['name']) ?><br>
  <strong>Имейл:</strong> <?= $_e($pledge['email']) ?><br>
  <strong>Брой билети:</strong> <?= $_qty ?><br>
  <strong>Сума:</strong> <?= number_format((float) $pledge['amount_eur'], 2, '.', ' ') ?> EUR<br>
  <strong>Номер:</strong> <?= $_e($pledge['pledge_number']) ?>
</div>
<p>Билетите са изпратени на купувача по имейл.</p>
<p><a href="<?= $_e($_url) ?>">Виж събитието и купувачите →</a></p>
