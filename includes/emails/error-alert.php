<?php
// Available via extract(): $row (array — one row from error_alerts table)
$_tpl = email_tpl_get('error-alert', 'bg', [
    'error_class' => htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8'),
    'url'         => htmlspecialchars($row['url']         ?? '', ENT_QUOTES, 'UTF-8'),
    'timestamp'   => htmlspecialchars($row['created_at']  ?? '', ENT_QUOTES, 'UTF-8'),
]);
?>
<?= $_tpl['intro'] ?>

<div class="box" style="font-family:monospace;font-size:.85rem;line-height:1.6;word-break:break-word;">
  <strong>Тип:</strong> <?= htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Съобщение:</strong><br>
  <?= nl2br(htmlspecialchars(mb_substr($row['message'] ?? '', 0, 500), ENT_QUOTES, 'UTF-8')) ?><br><br>
  <strong>Файл:</strong> <?= htmlspecialchars($row['file'] ?? '', ENT_QUOTES, 'UTF-8') ?>:<?= (int)($row['line'] ?? 0) ?><br>
  <?php if (!empty($row['url'])): ?>
  <strong>URL:</strong> <?= htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8') ?><br>
  <?php endif; ?>
  <strong>Дата/час:</strong> <?= htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
</div>

<?= $_tpl['outro'] ?>
