<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_admin();

if (!admin_can_sign()) {
    http_response_code(403);
    exit('Нямате достъп до тази страница.');
}

$pdo        = get_pdo();
$user_id    = (int) admin_user()['id'];
$success    = '';
$error      = '';

// Handle POST: save new signature or delete existing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(400);
        exit('Невалиден токен.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $raw = trim($_POST['signature_data'] ?? '');
        // Expect a data URI: data:image/png;base64,<...>
        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $raw, $m)) {
            $error = 'Невалидни данни за подпис. Моля, опитайте отново.';
        } else {
            $b64 = $m[1];
            $pdo->prepare('
                INSERT INTO admin_signatures (admin_user_id, signature_data)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE signature_data = VALUES(signature_data), updated_at = NOW()
            ')->execute([$user_id, $b64]);
            $success = 'Подписът е запазен.';
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM admin_signatures WHERE admin_user_id = ?')->execute([$user_id]);
        $success = 'Подписът е изтрит.';
    }
}

// Load current signature
$sig_stmt = $pdo->prepare('SELECT signature_data, updated_at FROM admin_signatures WHERE admin_user_id = ?');
$sig_stmt->execute([$user_id]);
$saved_sig = $sig_stmt->fetch();

$page_title_admin = 'Моят подпис';
$active_nav       = 'signature';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Електронен подпис</h1>
</div>

<?php if ($success): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;"><?= h($error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:2rem;align-items:start;max-width:900px;">

  <!-- Canvas pad -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
    <h3 style="margin-top:0;font-size:1rem;">Нарисувайте подписа си</h3>
    <p style="font-size:.85rem;color:var(--text-muted);margin-top:0;">
      Задръжте и плъзнете с мишката (или пръст на сензорен екран) в полето по-долу.
    </p>
    <canvas id="sigCanvas"
            width="560" height="180"
            style="display:block;border:2px solid var(--border);border-radius:8px;background:#fff;cursor:crosshair;touch-action:none;width:100%;max-width:560px;"></canvas>
    <div style="display:flex;gap:.75rem;margin-top:1rem;">
      <button type="button" id="clearBtn"
              style="padding:.5rem 1.1rem;border:1px solid var(--border);border-radius:6px;background:#fff;cursor:pointer;font-size:.875rem;">
        Изчисти
      </button>
      <button type="button" id="saveBtn"
              style="padding:.5rem 1.25rem;border:none;border-radius:6px;background:var(--teal);color:#fff;cursor:pointer;font-size:.875rem;font-weight:600;">
        Запази подписа
      </button>
    </div>

    <!-- Hidden form that receives the canvas data URI -->
    <form id="sigForm" method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="signature_data" id="sigData">
    </form>
  </div>

  <!-- Saved signature -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
    <h3 style="margin-top:0;font-size:1rem;">Запазен подпис</h3>
    <?php if ($saved_sig): ?>
      <img src="data:image/png;base64,<?= h($saved_sig['signature_data']) ?>"
           style="display:block;max-width:100%;border:1px solid var(--border);border-radius:6px;padding:8px;background:#fafafa;">
      <p style="font-size:.75rem;color:var(--text-muted);margin:.75rem 0 1rem;">
        Обновен: <?= h(substr($saved_sig['updated_at'], 0, 16)) ?>
      </p>
      <form method="POST"
            data-confirm="Изтриване на запазения подпис. Сертификатите, подписани с него, няма да бъдат засегнати."
            data-confirm-ok="Изтрий">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit"
                style="padding:.45rem 1rem;border:1px solid #c0392b;border-radius:6px;background:#fff;color:#c0392b;cursor:pointer;font-size:.85rem;">
          Изтрий подписа
        </button>
      </form>
    <?php else: ?>
      <p style="font-size:.875rem;color:var(--text-muted);">Нямате запазен подпис. Нарисувайте го вляво и натиснете „Запази подписа".</p>
    <?php endif; ?>
  </div>

</div>

<script>
(function () {
  var canvas  = document.getElementById('sigCanvas');
  var ctx     = canvas.getContext('2d');
  var drawing = false;
  var empty   = true;

  // Scale canvas resolution to match its CSS size for sharp rendering
  function resize() {
    var rect = canvas.getBoundingClientRect();
    canvas.width  = rect.width  * window.devicePixelRatio;
    canvas.height = rect.height * window.devicePixelRatio;
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    ctx.strokeStyle = '#1a1a2e';
    ctx.lineWidth   = 2.2;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    empty = true;
  }
  resize();

  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  }

  canvas.addEventListener('mousedown',  function(e) { drawing = true; empty = false; ctx.beginPath(); var p = pos(e); ctx.moveTo(p.x, p.y); });
  canvas.addEventListener('mousemove',  function(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
  canvas.addEventListener('mouseup',    function()  { drawing = false; });
  canvas.addEventListener('mouseleave', function()  { drawing = false; });

  canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; empty = false; ctx.beginPath(); var p = pos(e); ctx.moveTo(p.x, p.y); }, { passive: false });
  canvas.addEventListener('touchmove',  function(e) { e.preventDefault(); if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }, { passive: false });
  canvas.addEventListener('touchend',   function()  { drawing = false; });

  document.getElementById('clearBtn').addEventListener('click', function () {
    var rect = canvas.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    empty = true;
  });

  document.getElementById('saveBtn').addEventListener('click', function () {
    if (empty) { alert('Нарисувайте подписа си преди да запазите.'); return; }
    // Export at natural resolution
    var dataURL = canvas.toDataURL('image/png');
    document.getElementById('sigData').value = dataURL;
    document.getElementById('sigForm').submit();
  });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
