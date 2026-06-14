<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
$page_title_admin = 'Абонати на бюлетина';
$active_nav       = 'newsletter-subscribers';
admin_require_admin();

$pdo = get_pdo();

// ── Manual add ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $email = trim($_POST['email'] ?? '');
    $name  = trim($_POST['name']  ?? '');
    $lang  = in_array($_POST['lang'] ?? '', ['bg','en']) ? $_POST['lang'] : 'bg';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $r = newsletter_subscribe($email, $name, $lang, 'manual');
        flash_set($r['duplicate'] ? 'error' : 'success',
            $r['duplicate'] ? "Имейлът {$email} вече е абонат." : "Добавен: {$email}");
    } else {
        flash_set('error', 'Невалиден имейл адрес.');
    }
    header('Location: /admin/newsletter-subscribers.php');
    exit;
}

// ── List ──────────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$filter_source = $_GET['source'] ?? 'all';
$page          = max(1, (int)($_GET['p'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

$where = ['1=1']; $params = [];
if ($filter_status !== 'all') { $where[] = 'status = ?'; $params[] = $filter_status; }
if ($filter_source !== 'all') { $where[] = 'source = ?'; $params[] = $filter_source; }
$where_sql = implode(' AND ', $where);

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE $where_sql")
    ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE $where_sql") : null;
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE $where_sql");
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT * FROM newsletter_subscribers WHERE $where_sql ORDER BY subscribed_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

$counts = newsletter_active_count();
$flash  = flash_get();

$source_labels = ['web_banner'=>'Банер','customer_import'=>'Клиент','manual'=>'Ръчно'];
$status_labels = ['active'=>['Активен','badge--published'],'unsubscribed'=>['Отписан','badge--draft']];

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flash as $f): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
  <?= $f['type']==='success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
  <div>
    <span style="font-size:.85rem;color:var(--text-muted);">
      Активни: <strong><?= $counts['bg'] ?></strong> БГ &nbsp;+&nbsp; <strong><?= $counts['en'] ?></strong> EN
      &nbsp;=&nbsp; <strong><?= $counts['total'] ?></strong>
    </span>
  </div>
  <div style="display:flex;gap:.75rem;">
    <a href="/admin/newsletter-import.php" class="btn btn--outline" style="font-size:.85rem;">↓ Импорт от клиенти</a>
    <a href="/admin/newsletter.php" class="btn btn--outline" style="font-size:.85rem;">← Кампании</a>
  </div>
</div>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;">
  <?php
  $f_pairs = [
    ['status','all','Всички',    'all'],
    ['status','active','Активни','active'],
    ['status','unsubscribed','Отписани','unsubscribed'],
    ['source','web_banner','Банер','web_banner'],
    ['source','customer_import','Клиенти','customer_import'],
    ['source','manual','Ръчно','manual'],
  ];
  foreach ($f_pairs as [$key, $val, $label, $cmp]):
    $active = ($key === 'status' ? $filter_status : $filter_source) === $cmp;
  ?>
  <a href="?<?= http_build_query(array_merge(['status'=>$filter_status,'source'=>$filter_source], [$key=>$val])) ?>"
     style="padding:.3rem .9rem;border-radius:20px;font-size:.8rem;text-decoration:none;
     <?= $active ? 'background:var(--teal);color:#fff;' : 'background:var(--off-white);color:var(--text-muted);border:1px solid var(--border);' ?>">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Table -->
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;">
  <table class="admin-table" style="width:100%;border-collapse:collapse;table-layout:fixed;">
    <colgroup>
      <col style="width:30%;">
      <col style="width:22%;">
      <col style="width:10%;">
      <col style="width:14%;">
      <col style="width:12%;">
      <col style="width:12%;">
    </colgroup>
    <thead>
      <tr style="background:var(--off-white);">
        <th data-sort style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Имейл</th>
        <th data-sort style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Имена</th>
        <th data-sort style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Език</th>
        <th data-sort style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Източник</th>
        <th data-sort style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Статус</th>
        <th data-sort style="padding:.65rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);overflow:hidden;">Записан</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($subscribers)): ?>
      <tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--text-muted);">Няма абонати.</td></tr>
      <?php else: foreach ($subscribers as $s): ?>
      <tr style="border-top:1px solid var(--border);">
        <td style="padding:.75rem 1rem;font-size:.9rem;"><?= h($s['email']) ?></td>
        <td style="padding:.75rem 1rem;font-size:.9rem;color:var(--text-muted);"><?= h($s['name'] ?? '—') ?></td>
        <td style="padding:.75rem 1rem;text-align:center;font-size:.85rem;text-transform:uppercase;"><?= h($s['lang']) ?></td>
        <td style="padding:.75rem 1rem;text-align:center;font-size:.8rem;color:var(--text-muted);"><?= $source_labels[$s['source']] ?? $s['source'] ?></td>
        <td style="padding:.75rem 1rem;text-align:center;">
          <?php [$lbl,$cls] = $status_labels[$s['status']] ?? [$s['status'],'badge--draft']; ?>
          <span class="badge <?= $cls ?>"><?= $lbl ?></span>
        </td>
        <td style="padding:.75rem 1rem;text-align:right;font-size:.8rem;color:var(--text-muted);"><?= date('d.m.Y', strtotime($s['subscribed_at'])) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;gap:.4rem;margin-bottom:2rem;">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?<?= http_build_query(['status'=>$filter_status,'source'=>$filter_source,'p'=>$i]) ?>"
     style="padding:.35rem .75rem;border-radius:4px;font-size:.85rem;text-decoration:none;
     <?= $i===$page ? 'background:var(--teal);color:#fff;' : 'background:var(--off-white);color:var(--text-muted);border:1px solid var(--border);' ?>">
    <?= $i ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Manual add form -->
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;max-width:480px;">
  <h3 style="margin:0 0 1rem;font-size:1rem;">Добави абонат ръчно</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <div style="display:grid;gap:.75rem;">
      <input type="email" name="email" required placeholder="имейл адрес *"
             style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
      <input type="text" name="name" placeholder="имена (по избор)"
             style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
      <select name="lang" style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
        <option value="bg">БГ</option>
        <option value="en">EN</option>
      </select>
      <button type="submit" class="btn btn--primary" style="align-self:start;">Добави</button>
    </div>
  </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
