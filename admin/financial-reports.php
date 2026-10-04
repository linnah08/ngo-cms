<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/financial_reports.php';

admin_require_editorial();

$messages = [
    'year_added' => 'Годината е добавена.',
    'doc_added'  => 'Документът е добавен.',
    'doc_saved'  => 'Документът е запазен.',
    'doc_moved'  => 'Редът е променен.',
    'doc_deleted'=> 'Документът е изтрит.',
    'year_deleted' => 'Годината е изтрита.',
];
$error = '';
$form  = null;   // re-shown add/edit form after a failed upload: ['mode','year','id','fields']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $d      = reports_load();
    $action = (string) ($_POST['action'] ?? '');
    $fields = reports_clean_fields($_POST);
    $id     = preg_match('/^[0-9a-f]{8}$/', (string) ($_POST['id'] ?? '')) ? $_POST['id'] : '';
    $year   = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $done   = null;

    // Every save is checked: a failed write says so, keeps the files that were there, and
    // removes a just-uploaded file nothing points to.
    $save_failed = 'Промените не можаха да се запазят. Опитайте отново; ако се повтаря, обърнете се към човека, който поддържа сайта.';
    $drop = static function (?string $f): void { if ($f && reports_file_name_ok($f)) @unlink(REPORTS_DIR . '/' . $f); };

    if ($action === 'add_year') {
        $r = reports_add_year($d, $year);
        if (is_string($r)) $error = $r;
        elseif (!reports_save($r)) $error = $save_failed;
        else $done = 'year_added';
    } elseif ($action === 'add_doc' || $action === 'edit_doc') {
        $form = ['mode' => $action, 'year' => $year, 'id' => $id, 'fields' => $fields];
        $has_file = ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $year_there = in_array($year, array_map('intval', array_column($d['years'], 'year')), true);
        $doc_there  = false;
        foreach ($d['years'] as $y) foreach ($y['documents'] ?? [] as $doc) if (($doc['id'] ?? '') === $id) $doc_there = true;
        if ($fields['title_bg'] === '') {
            $error = 'Заглавието е задължително.';
        } elseif ($action === 'add_doc' && !$year_there) {
            // Checked before the upload is stored, so nothing is left behind.
            $error = "Годината {$year} вече не съществува — може да е изтрита междувременно. Добавете я отново и опитайте пак.";
        } elseif ($action === 'edit_doc' && !$doc_there) {
            $error = 'Документът вече не съществува — може да е изтрит междувременно.';
        } elseif ($action === 'add_doc' || $has_file) {
            $up = reports_store_upload($_FILES['pdf'] ?? [], $year ?: (int) date('Y'));
            if ($up['error'] !== null) {
                $error = $up['error'];
            } elseif ($action === 'add_doc') {
                if (reports_save(reports_add_document($d, $year, $fields, $up['file']))) $done = 'doc_added';
                else { $drop($up['file']); $error = $save_failed; }
            } else {
                $old = reports_delete_document($d, $id)['file'];
                if (reports_save(reports_update_document($d, $id, $fields, $up['file']))) {
                    $drop($old);   // only after the new file and the JSON are in place
                    $done = 'doc_saved';
                } else { $drop($up['file']); $error = $save_failed; }
            }
        } elseif (reports_save(reports_update_document($d, $id, $fields))) {
            $done = 'doc_saved';
        } else {
            $error = $save_failed;
        }
    } elseif ($action === 'move_doc') {
        if (reports_save(reports_move_document($d, $id, ($_POST['dir'] ?? '') === 'up' ? -1 : 1))) $done = 'doc_moved';
        else $error = $save_failed;
    } elseif ($action === 'delete_doc') {
        $r = reports_delete_document($d, $id);
        if (reports_save($r['data'])) { $drop($r['file']); $done = 'doc_deleted'; }
        else $error = $save_failed;
    } elseif ($action === 'delete_year') {
        $r = reports_delete_year($d, $year);
        if (reports_save($r['data'])) { array_map($drop, $r['files']); $done = 'year_deleted'; }
        else $error = $save_failed;
    }

    if ($done !== null) { header('Location: /admin/financial-reports.php?msg=' . $done); exit; }
}

$d       = reports_load();
$msg     = $messages[$_GET['msg'] ?? ''] ?? '';
$editing = $form ?? null;
if (!$editing && isset($_GET['edit']) && preg_match('/^[0-9a-f]{8}$/', $_GET['edit'])) {
    foreach ($d['years'] as $y) foreach ($y['documents'] as $doc) {
        if ($doc['id'] === $_GET['edit']) $editing = ['mode' => 'edit_doc', 'year' => $y['year'], 'id' => $doc['id'], 'fields' => $doc];
    }
}
if (!$editing && isset($_GET['add'])) $editing = ['mode' => 'add_doc', 'year' => (int) $_GET['add'], 'id' => '', 'fields' => reports_clean_fields([])];

$page_title_admin = 'Финансови отчети';
$active_nav       = 'pages';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
$btn = 'min-height:44px;';
?>
<h1 style="margin-bottom:.5rem;">Финансови отчети</h1>
<p style="margin:0 0 1.5rem;color:var(--text-muted);">Годишните отчети се показват на <a href="/finansovi-otcheti/" target="_blank" rel="noopener">/finansovi-otcheti/</a>. Връзката във футъра се появява, щом има поне един документ.</p>

<?php if ($msg): ?><div class="admin-alert admin-alert--success" role="status" style="margin-bottom:1rem;"><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert admin-alert--error" role="alert" style="margin-bottom:1rem;"><?= h($error) ?></div><?php endif; ?>

<?php if ($editing): $f = $editing['fields']; ?>
<form method="POST" enctype="multipart/form-data" class="admin-form"
      style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:2rem;max-width:720px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= h($editing['mode']) ?>">
  <input type="hidden" name="year" value="<?= (int) $editing['year'] ?>">
  <input type="hidden" name="id" value="<?= h($editing['id']) ?>">
  <h2 style="font-size:1.1rem;margin:0 0 1rem;"><?= $editing['mode'] === 'add_doc' ? 'Нов документ за ' . (int) $editing['year'] : 'Редактиране на документ' ?></h2>
  <div class="form-group"><label for="title_bg">Заглавие *</label>
    <input type="text" id="title_bg" name="title_bg" required maxlength="200" value="<?= h($f['title_bg'] ?? '') ?>" style="width:100%;min-height:44px;"></div>
  <div class="form-group"><label for="title_en">Title (EN)</label>
    <input type="text" id="title_en" name="title_en" maxlength="200" data-translate-from="title_bg" value="<?= h($f['title_en'] ?? '') ?>" style="width:100%;min-height:44px;"></div>
  <div class="form-group"><label for="description_bg">Описание</label>
    <p id="descHelp" style="margin:.25rem 0 .5rem;color:var(--text-muted);font-size:.9rem;">Сканираният PDF не може да се чете от екранни четци и търсачки — напишете накратко какво съдържа документът.</p>
    <textarea id="description_bg" name="description_bg" rows="3" maxlength="2000" aria-describedby="descHelp" style="width:100%;"><?= h($f['description_bg'] ?? '') ?></textarea></div>
  <div class="form-group"><label for="description_en">Description (EN)</label>
    <textarea id="description_en" name="description_en" rows="3" maxlength="2000" data-translate-from="description_bg" style="width:100%;"><?= h($f['description_en'] ?? '') ?></textarea></div>
  <div class="form-group"><label for="pdf"><?= $editing['mode'] === 'add_doc' ? 'PDF файл *' : 'Нов PDF файл (по желание — заменя сегашния)' ?></label>
    <input type="file" id="pdf" name="pdf" accept="application/pdf" <?= $editing['mode'] === 'add_doc' ? 'required' : '' ?> style="min-height:44px;"></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Запази</button>
    <a href="/admin/financial-reports.php" class="btn btn--outline" style="<?= $btn ?>">Отказ</a>
  </div>
</form>
<?php endif; ?>

<form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:2rem;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_year">
  <div><label for="newYear" style="display:block;font-weight:600;">Нова година</label>
    <input type="number" id="newYear" name="year" min="1990" max="<?= (int) date('Y') + 1 ?>" value="<?= (int) date('Y') - 1 ?>" required style="min-height:44px;width:8rem;"></div>
  <button type="submit" class="btn btn--primary" style="<?= $btn ?>">+ Нова година</button>
</form>

<?php if (!$d['years']): ?>
  <p>Още няма отчети. Добавете година, после документите за нея.</p>
<?php endif; ?>

<?php foreach ($d['years'] as $y): $n = count($y['documents']); ?>
<section aria-labelledby="y<?= (int) $y['year'] ?>" style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.25rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
    <h2 id="y<?= (int) $y['year'] ?>" style="font-size:1.2rem;margin:0;"><?= (int) $y['year'] ?></h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
      <a href="/admin/financial-reports.php?add=<?= (int) $y['year'] ?>" class="btn btn--primary" style="<?= $btn ?>">+ Добави документ</a>
      <form method="POST" data-confirm="<?= h($n ? "Да изтрия ли {$y['year']} и всичките {$n} документа? Файловете се изтриват завинаги." : "Да изтрия ли година {$y['year']}?") ?>" data-confirm-ok="Да, изтрий">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_year"><input type="hidden" name="year" value="<?= (int) $y['year'] ?>">
        <button type="submit" class="btn btn--outline" style="<?= $btn ?>color:#b91c1c;border-color:#b91c1c;" aria-label="Изтрий година <?= (int) $y['year'] ?>">Изтрий година</button>
      </form>
    </div>
  </div>
  <?php if (!$n): ?><p style="margin:.75rem 0 0;color:var(--text-muted);">Няма документи за тази година.</p><?php endif; ?>
  <ol style="list-style:none;padding:0;margin:.75rem 0 0;">
  <?php foreach ($y['documents'] as $i => $doc): $missing = !reports_file_name_ok($doc['file']) || !is_file(REPORTS_DIR . '/' . $doc['file']); ?>
    <li style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;padding:.75rem 0;border-top:1px solid var(--border);">
      <div><strong><?= h($doc['title_bg']) ?></strong>
        <?php if ($missing): ?><span style="color:#b91c1c;font-weight:600;"> — <span aria-hidden="true">⚠</span> файлът липсва</span><?php endif; ?></div>
      <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
        <?php foreach (['up' => ['↑', 'нагоре', $i > 0], 'down' => ['↓', 'надолу', $i < $n - 1]] as $dir => [$arrow, $word, $can]): if (!$can) continue; ?>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move_doc"><input type="hidden" name="id" value="<?= h($doc['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
          <button type="submit" class="btn btn--outline" style="<?= $btn ?>min-width:44px;" aria-label="Премести „<?= h($doc['title_bg']) ?>“ <?= $word ?>"><?= $arrow ?></button></form>
        <?php endforeach; ?>
        <a href="/admin/financial-reports.php?edit=<?= h($doc['id']) ?>" class="btn btn--outline" style="<?= $btn ?>" aria-label="Редактирай „<?= h($doc['title_bg']) ?>“">Редактирай</a>
        <form method="POST" data-confirm="<?= h('Да изтрия ли „' . $doc['title_bg'] . '“? Файлът се изтрива завинаги.') ?>" data-confirm-ok="Да, изтрий">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_doc"><input type="hidden" name="id" value="<?= h($doc['id']) ?>">
          <button type="submit" class="btn btn--outline" style="<?= $btn ?>color:#b91c1c;border-color:#b91c1c;" aria-label="Изтрий „<?= h($doc['title_bg']) ?>“">Изтрий</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
</section>
<?php endforeach; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
