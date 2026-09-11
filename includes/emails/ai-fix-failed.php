<?php
// Groundwork for a future AI auto-fix routine (not implemented in this repo).
// Available via extract(): $row (array — one row from error_alerts table).
// Not currently rendered by anything in this codebase.
$_tpl = email_tpl_get('ai-fix-failed', 'bg', [
    'error_class' => htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8'),
    'file'        => htmlspecialchars($row['file'] ?? '', ENT_QUOTES, 'UTF-8'),
    'line'        => (string)($row['line'] ?? 0),
]);
?>
<?= $_tpl['intro'] ?>

<div class="box" style="font-family:monospace;font-size:.85rem;line-height:1.6;word-break:break-word;">
  <strong>Тип:</strong> <?= htmlspecialchars($row['error_class'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
  <strong>Файл:</strong> <?= htmlspecialchars($row['file'] ?? '', ENT_QUOTES, 'UTF-8') ?>:<?= (int)($row['line'] ?? 0) ?><br>
  <?php if (!empty($row['ai_summary'])): ?>
  <strong>Бележки:</strong><br>
  <?= nl2br(htmlspecialchars(mb_substr($row['ai_summary'], 0, 2000), ENT_QUOTES, 'UTF-8')) ?>
  <?php endif; ?>
</div>

<p><a href="<?= htmlspecialchars((defined('SITE_URL') ? SITE_URL : '') . '/admin/error-alerts.php', ENT_QUOTES, 'UTF-8') ?>">Виж в админ панела →</a></p>

<?= $_tpl['outro'] ?>
