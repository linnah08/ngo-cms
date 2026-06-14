<?php
// Available via extract(): $rows (array of error_alerts rows), $period, $from_date, $to_date
$_count = count($rows);
$_tpl = email_tpl_get('error-digest', 'bg', [
    'count'     => $_count,
    'period'    => htmlspecialchars($period    ?? '', ENT_QUOTES, 'UTF-8'),
    'from_date' => htmlspecialchars($from_date ?? '', ENT_QUOTES, 'UTF-8'),
    'to_date'   => htmlspecialchars($to_date   ?? '', ENT_QUOTES, 'UTF-8'),
]);
?>
<?= $_tpl['intro'] ?>

<table>
  <thead>
    <tr>
      <th>Дата/час</th>
      <th>Тип</th>
      <th>Съобщение</th>
      <th>Файл:ред</th>
      <th>URL</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $_r): ?>
    <tr>
      <td style="white-space:nowrap;font-size:.8rem;"><?= htmlspecialchars($_r['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-size:.8rem;"><?= htmlspecialchars($_r['error_class'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars(mb_substr($_r['message'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-family:monospace;font-size:.78rem;white-space:nowrap;"><?= htmlspecialchars(basename($_r['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?>:<?= (int)($_r['line'] ?? 0) ?></td>
      <td style="font-size:.78rem;"><?= htmlspecialchars(mb_substr($_r['url'] ?? '', 0, 60), ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?= $_tpl['outro'] ?>
