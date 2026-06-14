<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
$page_title_admin = 'Бюлетин';
$active_nav       = 'newsletter';
admin_require_admin();

$pdo = get_pdo();

// ── POST: delete or reset-to-draft ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    $action      = $_POST['action'] ?? '';

    if ($campaign_id) {
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM newsletter_campaigns WHERE id = ? AND status = 'draft'")->execute([$campaign_id]);
            flash_set('success', 'Кампанията е изтрита.');
        } elseif ($action === 'reset') {
            $pdo->prepare("UPDATE newsletter_campaigns SET status = 'draft' WHERE id = ? AND status = 'sending'")->execute([$campaign_id]);
            flash_set('success', 'Кампанията е върната в чернова.');
        }
    }
    header('Location: /admin/newsletter.php');
    exit;
}

try {
    $campaigns = $pdo->query("
        SELECT c.*,
               COUNT(s.id)                  AS sends_tracked,
               SUM(s.open_count  > 0)       AS opens_unique,
               SUM(s.click_count > 0)       AS clicks_unique
        FROM newsletter_campaigns c
        LEFT JOIN newsletter_sends s ON s.campaign_id = c.id
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    // newsletter_sends table not yet created — fall back to basic query
    $campaigns = $pdo->query("SELECT * FROM newsletter_campaigns ORDER BY created_at DESC")->fetchAll();
}
$counts    = newsletter_active_count();
$flash     = flash_get();

$status_labels = [
    'draft'   => ['Чернова',    'badge--draft'],
    'sending' => ['Изпращане',  'badge--warning'],
    'sent'    => ['Изпратена',  'badge--published'],
];

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flash as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div style="font-size:.875rem;color:var(--text-muted);">
    Активни абонати: <strong><?= $counts['bg'] ?></strong> БГ + <strong><?= $counts['en'] ?></strong> EN = <strong><?= $counts['total'] ?></strong>
    &nbsp;·&nbsp;
    <a href="/admin/newsletter-subscribers.php" style="color:var(--teal);">Управление →</a>
  </div>
  <a href="/admin/newsletter-compose.php" class="btn btn--primary">+ Нова кампания</a>
</div>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;min-width:700px;">
    <thead>
      <tr style="background:var(--off-white);">
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">#</th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Тема (БГ)</th>
        <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Тема (EN)</th>
        <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Статус</th>
        <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Получатели</th>
        <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Отворени</th>
        <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Кликове</th>
        <th style="padding:.65rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Дата</th>
        <th style="padding:.65rem 1rem;width:160px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($campaigns)): ?>
      <tr><td colspan="9" style="padding:2.5rem;text-align:center;color:var(--text-muted);">Все още няма кампании. <a href="/admin/newsletter-compose.php">Създайте първата →</a></td></tr>
      <?php else: foreach ($campaigns as $c): ?>
      <?php [$lbl,$cls] = $status_labels[$c['status']] ?? [$c['status'],'badge--draft']; ?>
      <tr style="border-top:1px solid var(--border);">
        <td style="padding:.75rem 1rem;font-size:.85rem;color:var(--text-muted);"><?= (int)$c['id'] ?></td>
        <td style="padding:.75rem 1rem;font-weight:600;font-size:.9rem;">
          <?= h($c['subject_bg'] ?: '—') ?>
        </td>
        <td style="padding:.75rem 1rem;font-size:.9rem;color:var(--text-muted);">
          <?= h($c['subject_en'] ?: '—') ?>
        </td>
        <td style="padding:.75rem 1rem;text-align:center;">
          <span class="badge <?= $cls ?>"><?= $lbl ?></span>
        </td>
        <td style="padding:.75rem 1rem;text-align:center;font-size:.9rem;">
          <?= $c['recipient_count'] !== null ? (int)$c['recipient_count'] : '—' ?>
        </td>
        <?php
            $tracked   = (int)($c['sends_tracked'] ?? 0);
            $opens     = (int)($c['opens_unique']  ?? 0);
            $clicks    = (int)($c['clicks_unique'] ?? 0);
            $open_pct  = $tracked > 0 ? round($opens  / $tracked * 100) : null;
            $click_pct = $tracked > 0 ? round($clicks / $tracked * 100) : null;
        ?>
        <td style="padding:.75rem 1rem;text-align:center;font-size:.9rem;">
          <?php if ($c['status'] === 'sent' && $tracked > 0): ?>
            <?= $opens ?> <span style="font-size:.78rem;color:var(--text-muted);">(<?= $open_pct ?>%)</span>
          <?php else: ?>
            <span style="color:var(--text-muted);">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem;text-align:center;font-size:.9rem;">
          <?php if ($c['status'] === 'sent' && $tracked > 0): ?>
            <?= $clicks ?> <span style="font-size:.78rem;color:var(--text-muted);">(<?= $click_pct ?>%)</span>
          <?php else: ?>
            <span style="color:var(--text-muted);">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem;text-align:right;font-size:.85rem;color:var(--text-muted);">
          <?= $c['sent_at'] ? date('d.m.Y', strtotime($c['sent_at'])) : date('d.m.Y', strtotime($c['created_at'])) ?>
        </td>
        <td style="padding:.75rem 1rem;text-align:right;">
          <div style="display:flex;gap:.4rem;justify-content:flex-end;flex-wrap:wrap;">
            <?php if ($c['status'] === 'draft'): ?>
              <a href="/admin/newsletter-compose.php?id=<?= $c['id'] ?>" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Редактирай</a>
              <a href="/admin/newsletter-send.php?id=<?= $c['id'] ?>" class="btn btn--primary" style="font-size:.78rem;padding:.3rem .7rem;">Изпрати →</a>
            <?php elseif ($c['status'] === 'sending'): ?>
              <span style="font-size:.8rem;color:var(--text-muted);align-self:center;">В процес…</span>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                <input type="hidden" name="action" value="reset">
                <button class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;"
                        data-confirm="Връщане в чернова?" data-confirm-ok="Нулирай">Нулирай</button>
              </form>
            <?php else: ?>
              <a href="/admin/newsletter-preview.php?id=<?= $c['id'] ?>&lang=bg" target="_blank" class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;">Преглед ↗</a>
            <?php endif; ?>
            <?php if ($c['status'] === 'draft'): ?>
            <form method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn btn--outline" style="font-size:.78rem;padding:.3rem .7rem;color:#c0392b;border-color:#f0c4c0;"
                      data-confirm="Изтриване на кампанията?">Изтрий</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
