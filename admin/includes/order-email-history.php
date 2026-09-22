<?php
/**
 * "Комуникация с клиента" card — every email sent to the buyer of an order, newest first.
 * Expects $oeh_order_id (int; 0 = no order row yet). Included by order-view.php and pledge-view.php.
 */
$oeh_rows = [];
if (($oeh_order_id ?? 0) > 0) {
    $oeh_stmt = get_pdo()->prepare('SELECT * FROM order_emails WHERE order_id = ? ORDER BY created_at DESC, id DESC');
    $oeh_stmt->execute([$oeh_order_id]);
    $oeh_rows = $oeh_stmt->fetchAll();
}
?>
<div class="admin-card" id="email-history" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
  <h3 style="margin-top:0;font-size:1rem;">Комуникация с клиента (<?= count($oeh_rows) ?>)</h3>
  <?php if (!$oeh_rows): ?>
    <p style="font-size:.85rem;color:var(--text-muted);margin:.25rem 0 0;">Все още няма записани имейлове към тази поръчка.</p>
  <?php endif; ?>
  <?php foreach ($oeh_rows as $oe): ?>
    <details style="border-top:1px solid var(--border);padding:.6rem 0;font-size:.85rem;">
      <summary style="cursor:pointer;">
        <span style="display:inline-block;background:var(--off-white);border:1px solid var(--border);border-radius:4px;padding:.05rem .45rem;font-size:.7rem;font-weight:600;">
          <?= h(order_email_kind_label($oe['template_key'])) ?>
        </span>
        <?php if ($oe['status'] === 'failed'): ?>
          <span style="background:#fee2e2;color:#991b1b;border-radius:4px;padding:.05rem .45rem;font-size:.7rem;font-weight:600;">не е изпратен</span>
        <?php endif; ?>
        <strong style="font-weight:600;display:block;margin-top:.25rem;"><?= h($oe['subject']) ?></strong>
        <span style="color:var(--text-muted);font-size:.75rem;">
          <?= h(date('d.m.Y H:i', strtotime($oe['created_at']))) ?>
          · <?= !empty($oe['sent_by_name']) ? h($oe['sent_by_name']) : 'автоматично' ?>
          · до <?= h($oe['recipient']) ?>
        </span>
      </summary>
      <iframe sandbox title="<?= h($oe['subject']) ?>" srcdoc="<?= h($oe['body']) ?>"
              style="width:100%;height:340px;border:1px solid var(--border);border-radius:6px;margin-top:.5rem;background:#fff;"></iframe>
    </details>
  <?php endforeach; ?>
  <p style="font-size:.75rem;color:var(--text-muted);margin:.75rem 0 0;">Тук се виждат всички имейлове до клиента (потвърждение, изпращане и др.), изпратени след добавянето на тази история; по-старите не са налични.</p>
</div>
