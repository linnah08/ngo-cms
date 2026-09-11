<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/images.php';
$page_title_admin = 'Партньори';
$active_nav       = 'partners';

admin_require_login();
admin_require_admin();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    $partners = load_json(PARTNERS_FILE);

    if ($action === 'add') {
        $name   = trim($_POST['name'] ?? '');
        $url    = trim($_POST['url']  ?? '');
        $active = isset($_POST['active']);
        $id     = slug($name) ?: 'partner-' . time();
        $logo   = '';

        if (!$name) {
            $error = 'Името е задължително.';
        } else {
            if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','webp','svg'];
                if (in_array($ext, $allowed)) {
                    $dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/partners/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $filename = $id . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $filename)) {
                        image_resize_to_fit($dir . $filename);
                        $logo = '/assets/images/partners/' . $filename;
                    }
                }
            } elseif (!empty($_POST['logo_from_library'])) {
                $lib = $_POST['logo_from_library'];
                if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
                    $logo = $lib;
                }
            }
            $partners[] = [
                'id'     => $id,
                'name'   => $name,
                'logo'   => $logo,
                'url'    => $url,
                'active' => $active,
                'order'  => count($partners) + 1,
            ];
            save_json(PARTNERS_FILE, $partners);
            $success = 'Партньорът е добавен.';
        }

    } elseif ($action === 'toggle') {
        $id = $_POST['id'] ?? '';
        foreach ($partners as &$p) {
            if ($p['id'] === $id) { $p['active'] = !($p['active'] ?? true); break; }
        }
        unset($p);
        save_json(PARTNERS_FILE, $partners);
        header('Location: /admin/partners.php?toggled=1');
        exit;

    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $partners = array_values(array_filter($partners, fn($p) => $p['id'] !== $id));
        save_json(PARTNERS_FILE, $partners);
        header('Location: /admin/partners.php?deleted=1');
        exit;

    } elseif ($action === 'bulk_toggle') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        if ($ids) {
            $allActive = true;
            foreach ($partners as $p) {
                if (in_array($p['id'], $ids, true) && !($p['active'] ?? true)) { $allActive = false; break; }
            }
            $newActive = !$allActive;
            foreach ($partners as &$p) {
                if (in_array($p['id'], $ids, true)) { $p['active'] = $newActive; }
            }
            unset($p);
            save_json(PARTNERS_FILE, $partners);
        }
        header('Location: /admin/partners.php?toggled=1');
        exit;

    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        if ($ids) {
            $partners = array_values(array_filter($partners, fn($p) => !in_array($p['id'], $ids, true)));
            save_json(PARTNERS_FILE, $partners);
        }
        header('Location: /admin/partners.php?deleted=1');
        exit;
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
$partners = load_json(PARTNERS_FILE);
?>

<div class="admin-page-header">
  <h1>Партньори</h1>
</div>

<?php if ($success): ?><div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="admin-alert admin-alert--error"   style="margin-bottom:1.5rem;"><?= h($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Изтрит.</div><?php endif; ?>
<?php if (!empty($_GET['toggled'])): ?><div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Статусът е обновен.</div><?php endif; ?>

<!-- Partner list (drag to reorder) -->
<div class="admin-table-wrap" style="margin-bottom:2.5rem;overflow-x:hidden;">
  <table class="admin-table" style="table-layout:fixed;width:100%;">
    <colgroup>
      <col style="width:3%;">
      <col style="width:4%;">
      <col style="width:10%;">
      <col style="width:23%;">
      <col style="width:27%;">
      <col style="width:14%;">
      <col style="width:15%;">
    </colgroup>
    <thead>
      <tr>
        <th style="width:30px;"></th>
        <th style="overflow:hidden;"><input type="checkbox" id="select-all" aria-label="Избери всички" style="cursor:pointer;width:16px;height:16px;"></th>
        <th style="overflow:hidden;">Лого</th>
        <th data-sort style="overflow:hidden;">Име</th>
        <th data-sort style="overflow:hidden;">URL</th>
        <th data-sort style="overflow:hidden;">Статус</th>
        <th style="overflow:hidden;">Действия</th>
      </tr>
    </thead>
    <tbody id="partnersList">
      <?php foreach ($partners as $partner): ?>
        <tr draggable="true" data-id="<?= h($partner['id']) ?>">
          <td class="drag-handle" title="Влачете за пренареждане" style="cursor:grab;color:var(--text-muted);">&#8942;</td>
          <td style="text-align:center;vertical-align:middle;">
            <input type="checkbox" class="row-cb"
                   value="<?= h($partner['id']) ?>"
                   data-active="<?= ($partner['active'] ?? true) ? '1' : '0' ?>"
                   style="cursor:pointer;width:16px;height:16px;">
          </td>
          <td>
            <?php if (!empty($partner['logo'])): ?>
              <img src="<?= h($partner['logo']) ?>" alt="<?= h($partner['name']) ?>"
                   style="height:40px;width:auto;object-fit:contain;">
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:0.8rem;">Няма лого</span>
            <?php endif; ?>
          </td>
          <td><?= h($partner['name']) ?></td>
          <td>
            <?php if (!empty($partner['url'])): ?>
              <a href="<?= h($partner['url']) ?>" target="_blank" rel="noopener" style="font-size:0.85rem;"><?= h($partner['url']) ?></a>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= ($partner['active'] ?? true) ? 'badge--active' : 'badge--inactive' ?>">
              <?= ($partner['active'] ?? true) ? 'Активен' : 'Неактивен' ?>
            </span>
          </td>
          <td>
            <form method="POST" action="/admin/partners.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= h($partner['id']) ?>">
              <button type="submit" class="btn-link">
                <?= ($partner['active'] ?? true) ? 'Деактивирай' : 'Активирай' ?>
              </button>
            </form>
            <form method="POST" action="/admin/partners.php" style="display:inline;"
                  data-confirm="Изтриване на партньора?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h($partner['id']) ?>">
              <button type="submit" class="btn-link btn-link--danger">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($partners)): ?>
<div id="bulk-bar" style="
  position:fixed;bottom:0;left:0;right:0;
  background:#1a1a2e;color:#fff;
  padding:1rem 2rem;
  display:flex;align-items:center;gap:1rem;
  transform:translateY(100%);transition:transform 0.2s ease;
  z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,0.3);">
  <span id="bulk-count" style="font-weight:500;">0 избрани</span>
  <button id="bulk-toggle" type="button" class="btn btn--primary">Активирай</button>
  <button id="bulk-delete" type="button" class="btn btn--danger">Изтрий</button>
  <button id="bulk-clear" type="button" class="btn-link" style="color:#aaa;margin-left:auto;">✕ Изчисти</button>
</div>

<form id="form-bulk-toggle" method="POST" action="/admin/partners.php" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_toggle">
</form>

<form id="form-bulk-delete" method="POST" action="/admin/partners.php" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    var bulkBar   = document.getElementById('bulk-bar');
    var countEl   = document.getElementById('bulk-count');
    var toggleBtn = document.getElementById('bulk-toggle');
    var deleteBtn = document.getElementById('bulk-delete');
    var clearBtn  = document.getElementById('bulk-clear');
    var formToggle = document.getElementById('form-bulk-toggle');
    var formDelete = document.getElementById('form-bulk-delete');

    function getCheckboxes() {
        return Array.from(document.querySelectorAll('.row-cb'));
    }

    function getSelected() {
        return getCheckboxes().filter(function (cb) { return cb.checked; });
    }

    function updateToolbar() {
        var selected = getSelected();
        var count    = selected.length;

        countEl.textContent = count + ' избрани';
        bulkBar.style.transform = count > 0 ? 'translateY(0)' : 'translateY(100%)';

        var allActive = selected.length > 0 && selected.every(function (cb) { return cb.dataset.active === '1'; });
        toggleBtn.textContent = allActive ? 'Деактивирай' : 'Активирай';

        var all = getCheckboxes();
        selectAll.indeterminate = count > 0 && count < all.length;
        selectAll.checked       = all.length > 0 && count === all.length;
    }

    function injectIds(form, selected) {
        form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
        selected.forEach(function (cb) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });
    }

    selectAll.addEventListener('change', function () {
        getCheckboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
        updateToolbar();
    });

    getCheckboxes().forEach(function (cb) {
        cb.addEventListener('change', updateToolbar);
    });

    toggleBtn.addEventListener('click', function () {
        var selected = getSelected();
        if (!selected.length) return;
        injectIds(formToggle, selected);
        formToggle.submit();
    });

    deleteBtn.addEventListener('click', function () {
        var selected = getSelected();
        if (!selected.length) return;
        _adminConfirm(
            'Изтриване на ' + selected.length + ' партньора. Продължавате?',
            'Изтрий'
        ).then(function (confirmed) {
            if (!confirmed) return;
            injectIds(formDelete, selected);
            formDelete.submit();
        });
    });

    clearBtn.addEventListener('click', function () {
        getCheckboxes().forEach(function (cb) { cb.checked = false; });
        selectAll.checked       = false;
        selectAll.indeterminate = false;
        updateToolbar();
    });
})();
</script>
<?php endif; ?>

<!-- Add partner -->
<h2 style="margin-bottom:1.5rem;">Добавяне на партньор</h2>
<form method="POST" action="/admin/partners.php" enctype="multipart/form-data" class="admin-form" style="max-width:560px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="form-group">
    <label for="name">Име <span style="color:var(--teal);">*</span></label>
    <input type="text" id="name" name="name" required>
  </div>
  <div class="form-group">
    <label for="url">URL (незадължително)</label>
    <input type="url" id="url" name="url" placeholder="https://">
  </div>
  <div class="form-group">
    <label for="logo">Лого</label>
    <div id="logoPreview" style="display:none;margin-bottom:.5rem;">
      <img id="logoPreviewImg" src="" alt="" style="max-height:60px;border-radius:4px;display:block;">
    </div>
    <input type="hidden" name="logo_from_library" id="logoFromLib">
    <input type="file" id="logo" name="logo" accept="image/*" data-om-crop>
    <button type="button" class="btn btn--outline"
            style="margin-top:.5rem;font-size:.82rem;"
            onclick="_pickPartnerLogo(this)">Избери от библиотека</button>
  </div>
  <div class="form-group" style="display:flex;align-items:center;gap:0.75rem;">
    <input type="checkbox" id="active" name="active" checked style="width:auto;">
    <label for="active" style="margin:0;text-transform:none;font-size:1rem;">Активен</label>
  </div>
  <button type="submit" class="btn btn--primary">Добавяне</button>
</form>

<script>
function _pickPartnerLogo(btn) {
  openMediaPicker(function (p) {
    document.getElementById('logoFromLib').value = p;
    document.getElementById('logo').value = '';
    document.getElementById('logoPreviewImg').src = p;
    document.getElementById('logoPreview').style.display = '';
  });
}
</script>
<script>
// Drag-and-drop reorder
(function () {
  var list = document.getElementById('partnersList');
  var dragged = null;

  list.addEventListener('dragstart', function (e) {
    dragged = e.target.closest('tr');
    dragged.style.opacity = '0.5';
  });
  list.addEventListener('dragend', function () {
    dragged.style.opacity = '';
    saveOrder();
  });
  list.addEventListener('dragover', function (e) {
    e.preventDefault();
    var target = e.target.closest('tr');
    if (target && target !== dragged) {
      var rect = target.getBoundingClientRect();
      var next = (e.clientY - rect.top) > (rect.height / 2);
      list.insertBefore(dragged, next ? target.nextSibling : target);
    }
  });

  function saveOrder() {
    var rows  = list.querySelectorAll('tr[data-id]');
    var order = Array.from(rows).map(function (r) { return r.dataset.id; });
    fetch('/admin/partners-reorder.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ order: order, csrf_token: document.querySelector('[name=csrf_token]').value })
    });
  }
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
