<?php
$page_title_admin = 'Обновления';
$active_nav       = 'updates';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin(); // this page can rewrite the whole app's code — admin-only

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

// CSRF is checked before touching includes/updater.php on purpose — a bad
// token should be rejected outright, without depending on (or triggering)
// any of the update-checking/applying logic below.
if ($is_post && !csrf_verify()) {
    http_response_code(400);
    exit('Невалиден CSRF токен.');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';

$apply_result = null;
$did_apply    = false;

if ($is_post) {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_again') {
        updater_check_latest(true);
        header('Location: /admin/updates.php');
        exit;
    }

    if ($action === 'apply_update') {
        // Guard against double-submit / stale form: never apply while an
        // update is already in progress.
        if (!updater_is_maintenance_mode()) {
            $apply_result = updater_apply();
            $did_apply    = true;
        }
    }
}

// Force a fresh check right after an apply attempt so the page reflects the
// new state; otherwise use the ~1hr cache like any normal page load.
$check         = updater_check_latest($did_apply);
$maintenance   = updater_is_maintenance_mode();
$local_version = updater_get_local_version();

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Обновления</h1>
</div>

<?php if ($maintenance): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;">
    В момента се извършва обновяване на сайта. Моля, изчакайте — не затваряйте и не презареждайте тази страница.
  </div>
<?php endif; ?>

<?php if ($apply_result !== null): ?>
  <?php if ($apply_result['status'] === 'success'): ?>
    <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">
      Обновяването завърши успешно. Сайтът вече е на версия <?= h($apply_result['to_version'] ?? '') ?>.
    </div>
  <?php elseif ($apply_result['status'] === 'partial'): ?>
    <div class="admin-alert admin-alert--success" style="margin-bottom:1rem;">
      Обновяването завърши успешно. Сайтът вече е на версия <?= h($apply_result['to_version'] ?? '') ?>.
    </div>
    <div class="admin-card" style="margin-bottom:1.5rem;border-color:#e0a800;background:#fff9e6;">
      <h2 class="admin-card__title" style="margin-bottom:.5rem;">Някои файлове не бяха променени</h2>
      <p class="admin-meta" style="margin-bottom:.75rem;">
        Следните файлове са били персонализирани преди това, затова обновяването не ги е презаписало.
        Ако имате нужда от промените, включени в новата версия, за тези конкретни файлове, свържете се с поддръжката.
      </p>
      <ul style="margin:0;padding-left:1.25rem;font-size:.85rem;color:var(--text-muted);">
        <?php foreach (($apply_result['skipped'] ?? []) as $path): ?>
          <li><code><?= h((string)$path) ?></code></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php else: /* failed */ ?>
    <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;">
      Възникна проблем при обновяването. Сайтът не е останал в неработещо състояние — преди обновяването беше направено резервно копие.
      Моля, свържете се с поддръжката, ако проблемът продължи.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if (!empty($check['error'])): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;">
    Неуспешна проверка за нови версии. Опитайте отново по-късно.
  </div>
<?php endif; ?>

<div class="admin-card" style="max-width:640px;">
  <h2 class="admin-card__title">Текуща версия</h2>
  <p style="font-size:1.1rem;margin:.25rem 0 1rem;"><strong><?= h($local_version) ?></strong></p>

  <?php if (empty($check['error']) && !empty($check['update_available'])): ?>
    <p class="admin-meta" style="margin-bottom:.5rem;">
      Налична е нова версия: <strong><?= h((string)($check['latest_version'] ?? '')) ?></strong>
    </p>
    <?php if (!empty($check['notes'])): ?>
      <div style="background:#f6f7f8;border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1rem;white-space:pre-wrap;font-size:.85rem;color:var(--text-muted);max-height:260px;overflow-y:auto;"><?= h((string)$check['notes']) ?></div>
    <?php endif; ?>

    <?php if (!$maintenance): ?>
      <form method="post" id="applyUpdateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply_update">
        <button type="button" class="btn btn--primary"
                onclick="_adminConfirm(<?= h(json_encode(
                  'Ще обновите сайта до версия ' . (string)($check['latest_version'] ?? '') . '. '
                  . 'Преди това автоматично се прави резервно копие, но моля не затваряйте тази страница, докато обновяването не приключи.'
                , JSON_UNESCAPED_UNICODE)) ?>, 'Обнови сега').then(function(ok){ if(ok){ document.getElementById('applyUpdateForm').submit(); } })">
          Обнови сега
        </button>
      </form>
    <?php endif; ?>
  <?php elseif (empty($check['error'])): ?>
    <p class="admin-meta" style="margin-bottom:1rem;">Използвате най-новата налична версия.</p>
  <?php endif; ?>

  <form method="post" style="margin-top:1rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="check_again">
    <button type="submit" class="btn btn--outline">Провери отново</button>
  </form>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
