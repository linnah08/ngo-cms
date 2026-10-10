<?php
/**
 * Admin → Модули: switch the optional modules (includes/modules.php) on and off.
 *
 * Saves into the same store as the rest of Admin → Организация —
 * content/organisation.json, feature_<name> = '1' / '0' — through
 * org_save_overrides(), which keeps every other saved field as it is.
 * Switching a module off hides it everywhere; nothing is deleted.
 */
$page_title_admin = 'Модули';
$active_nav       = 'modules';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modules.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

$registry = modules_registry();
$errors   = [];   // field => message, plus '_form'

// Each module's own switch as it is now (not counting what it needs).
$current = [];
foreach ($registry as $name => $m) $current[$name] = feature_enabled($name);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Страницата беше отворена твърде дълго и сесията изтече. Презаредете страницата и опитайте отново.';
    } else {
        $result = modules_validate_switches($_POST, $current);
        $errors = $result['errors'];
        if (!$errors) {
            if (org_save_overrides($result['values'])) {
                $on = $off = [];
                foreach ($registry as $name => $m) {
                    if ($result['values'][module_field($name)] === '1') $on[] = $m['label'];
                    else $off[] = $m['label'];
                }
                $msg = 'Промените са запазени и вече важат за сайта.';
                if ($on)  $msg .= ' Включени: ' . implode(', ', $on) . '.';
                if ($off) $msg .= ' Изключени: ' . implode(', ', $off) . '.';
                flash_set('success', $msg);
                header('Location: /admin/modules.php');
                exit;
            }
            $errors['_form'] = 'Промените не можаха да се запишат на сървъра. Опитайте отново или се свържете с поддръжката.';
        }
    }
}

$pdo = null;
try { $pdo = get_pdo(); } catch (Throwable $e) { /* pending work just isn't shown */ }

$flashes = flash_get();
$box_ok  = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.95rem;line-height:1.5;margin-bottom:1rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#1e5128;';
$box_err = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.95rem;line-height:1.5;margin-bottom:1rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#9b2c1f;';

$quote = static fn(array $names): string => implode(', ', array_map(static fn($n) => '„' . module_label($n) . '“', $names));

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php foreach ($flashes as $f): ?>
  <div class="admin-alert admin-alert--<?= ($f['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"
       style="<?= ($f['type'] ?? '') === 'success' ? $box_ok : $box_err ?>"><?= ($f['type'] ?? '') === 'success' ? '✓ ' : '' ?><?= h((string) ($f['message'] ?? '')) ?></div>
<?php endforeach; ?>

<?php if ($errors): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="<?= $box_err ?>">
    <strong>Промените не бяха запазени.</strong>
    <?= h(implode(' ', $errors)) ?>
  </div>
<?php endif; ?>

<p style="margin:0 0 1.5rem;line-height:1.65;max-width:46rem;font-size:1rem;">
  Включете само частите от сайта, които организацията ви наистина ползва — така менюто остава кратко и ясно.
  Изключеният модул изчезва от сайта и от менюто, но <strong>нищо не се изтрива</strong>:
  когато го включите отново, всичко си е на мястото. Страниците и новините са винаги включени.
</p>

<form method="post" id="modulesForm" novalidate>
  <?= csrf_field() ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:1rem;margin-bottom:1.5rem;">
  <?php foreach ($registry as $name => $m):
      $field     = module_field($name);
      $own_on    = $current[$name];
      $effective = module_enabled_with_needs($name);
      $missing   = module_missing_needs($name);
      $pending   = $effective ? module_pending($name, $pdo) : null;
      $accent    = $effective ? 'var(--teal,#0387A5)' : '#c7cbd1'; ?>
    <article id="card-<?= h($name) ?>" aria-labelledby="title-<?= h($name) ?>"
             style="display:flex;flex-direction:column;gap:.6rem;background:#fff;border:1px solid var(--border,#e2e0db);border-top:5px solid <?= $accent ?>;border-radius:12px;padding:1.15rem 1.25rem 1.25rem;min-width:0;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
        <h2 id="title-<?= h($name) ?>" style="margin:0;font-size:1.15rem;line-height:1.3;"><?= h($m['label']) ?></h2>
        <span id="state-<?= h($name) ?>"
              style="font-size:.82rem;font-weight:600;padding:.2rem .6rem;border-radius:999px;white-space:nowrap;<?= $effective ? 'background:#e6f4ea;color:#1e5128;' : 'background:#f1f2f4;color:#374151;' ?>">
          <?= $effective ? '✓ Включен' : '✕ Изключен' ?>
        </span>
      </div>
      <p id="desc-<?= h($name) ?>" style="margin:0;line-height:1.55;color:#374151;"><?= h($m['description']) ?></p>

      <?php if ($m['needs']): ?>
        <p id="needs-<?= h($name) ?>" style="margin:0;font-size:.88rem;line-height:1.5;color:#4b5563;">
          Нуждае се от модул <?= h($quote($m['needs'])) ?>.
          <span data-needs-missing <?= $missing ? '' : 'hidden' ?> style="color:#92400e;font-weight:600;">
            Сега не може да се включи, защото <?= h($quote($m['needs'])) ?> е изключен. Включете първо него.
          </span>
        </p>
      <?php endif; ?>

      <label for="mod-<?= h($name) ?>"
             style="display:flex;align-items:center;gap:.7rem;min-height:44px;margin:.25rem 0 0;padding:.35rem .6rem;border-radius:8px;background:#f8f6f2;cursor:pointer;font-weight:600;text-transform:none;letter-spacing:normal;font-size:.95rem;">
        <input type="checkbox" role="switch" id="mod-<?= h($name) ?>" name="<?= h($field) ?>" value="1"
               <?= $own_on ? 'checked' : '' ?> <?= $missing ? 'disabled' : '' ?>
               data-module="<?= h($name) ?>"
               data-label="<?= h($m['label']) ?>"
               data-was-on="<?= $effective ? '1' : '0' ?>"
               data-needs="<?= h(implode(',', $m['needs'])) ?>"
               data-off-warning="<?= h($m['off_warning']) ?>"
               data-pending="<?= h((string) $pending) ?>"
               aria-describedby="desc-<?= h($name) ?><?= $m['needs'] ? ' needs-' . h($name) : '' ?> change-<?= h($name) ?>"
               style="width:22px;height:22px;margin:0;flex-shrink:0;cursor:pointer;accent-color:var(--teal,#0387A5);">
        <span>Включи модула</span>
      </label>
      <?php if ($pending): ?>
        <p style="margin:0;font-size:.88rem;line-height:1.5;color:#92400e;">Незавършена работа: <?= h($pending) ?></p>
      <?php endif; ?>
      <p id="change-<?= h($name) ?>" data-module-change style="margin:0;font-size:.88rem;line-height:1.5;color:#92400e;font-weight:600;"></p>
    </article>
  <?php endforeach; ?>
  </div>

  <p id="modules-live" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"></p>

  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;margin-bottom:2rem;">
    <button type="submit" class="btn btn--primary" style="min-height:44px;">Запази промените</button>
  </div>
</form>

<script>
(function () {
    var form = document.getElementById('modulesForm'), live = document.getElementById('modules-live');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-module]'));
    var byName = {};
    boxes.forEach(function (cb) { byName[cb.dataset.module] = cb; });
    function list(s) { return s ? s.split(',') : []; }
    // On after this save, counting what it needs?
    function willBeOn(name, seen) {
        var cb = byName[name];
        if (!cb || !cb.checked) return false;
        seen = seen || {};
        if (seen[name]) return true;
        seen[name] = true;
        return list(cb.dataset.needs).every(function (n) { return willBeOn(n, seen); });
    }
    function refresh() {
        boxes.forEach(function (cb) {
            var needsOk = list(cb.dataset.needs).every(function (n) { return willBeOn(n); });
            cb.disabled = !needsOk;
            var card = document.getElementById('card-' + cb.dataset.module);
            var note = card.querySelector('[data-needs-missing]');
            if (note) note.hidden = needsOk;
            var wasOn = cb.dataset.wasOn === '1', on = willBeOn(cb.dataset.module), msg = '';
            if (wasOn && !on) msg = 'Ще се изключи, когато натиснете „Запази промените“.';
            if (!wasOn && on) msg = 'Ще се включи, когато натиснете „Запази промените“.';
            card.querySelector('[data-module-change]').textContent = msg;
        });
    }
    boxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            refresh();
            var msg = document.getElementById('change-' + cb.dataset.module).textContent;
            if (live) live.textContent = msg ? cb.dataset.label + ': ' + msg : '';
        });
    });
    refresh();

    var confirmed = false;
    form.addEventListener('submit', function (e) {
        if (confirmed) return;
        var goingOff = boxes.filter(function (cb) { return cb.dataset.wasOn === '1' && !willBeOn(cb.dataset.module); });
        if (!goingOff.length || !window._adminConfirm) return;
        e.preventDefault();
        var parts = goingOff.map(function (cb) {
            var t = cb.dataset.offWarning;
            if (cb.dataset.pending) t += ' Внимание: ' + cb.dataset.pending;
            return t;
        });
        var msg = parts.join(' ') + ' Нищо не се изтрива — можете да включите модула отново по всяко време. Да запазим ли?';
        window._adminConfirm(msg, 'Да, изключи').then(function (ok) {
            if (!ok) return;
            confirmed = true;
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    });
})();
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
