<?php
// Variables: $rows — list of ['order_number','customer_name','parcel_id','locker','lines'=>[['name','qty','detail']]]
// Same order as the printed labels: label N on the sheets = block N here.
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<p><strong>Списък за опаковане — <?= count($rows) ?> BoxNow пратки.</strong><br>
Редът е същият като на отпечатаните етикети (отляво надясно, отгоре надолу).</p>

<?php foreach ($rows as $n => $r): ?>
<div class="box" style="margin-bottom:12px;">
  <strong style="font-size:1.05em;"><?= $n + 1 ?>. <?= $e($r['order_number']) ?> — <?= $e($r['customer_name']) ?></strong><br>
  <span style="color:#666;font-size:.9em;">Пратка <?= $e($r['parcel_id']) ?></span>
  <table style="width:100%;border-collapse:collapse;margin-top:6px;">
    <?php foreach ($r['lines'] as $l): ?>
    <tr>
      <td style="padding:3px 0;border-top:1px solid #eee;">
        ☐ <?= $e($l['name']) ?>
        <?php if ($l['detail'] !== ''): ?><br><span style="color:#666;font-size:.85em;padding-left:1.2em;"><?= $e($l['detail']) ?></span><?php endif; ?>
      </td>
      <td style="padding:3px 0;border-top:1px solid #eee;text-align:right;white-space:nowrap;font-weight:bold;">× <?= (int)$l['qty'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>
