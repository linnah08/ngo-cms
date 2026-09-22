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
        if (!updater_is_maintenance_mode() && updater_self_update_allowed()) {
            $apply_result = updater_apply();
            $did_apply    = true;
        }
    }
}

// The apply normally runs over AJAX now (admin/update-apply-ajax.php), so the
// page that shows the outcome is a plain GET with no POST result to render.
// Pick the finished state up from the progress file exactly once and clear it,
// which lets the success/partial/failed blocks below stay exactly as they were.
if ($apply_result === null) {
    $finished = updater_progress_read();
    $status   = is_array($finished) ? (string) ($finished['status'] ?? '') : '';
    if (in_array($status, ['success', 'partial', 'failed'], true)) {
        $apply_result = [
            'status'     => $status,
            'to_version' => (string) ($finished['to_version'] ?? ''),
            'skipped'    => array_values((array) ($finished['skipped'] ?? [])),
        ];
        $did_apply = true;
        updater_progress_clear();
    }
}

// Force a fresh check right after an apply attempt so the page reflects the
// new state; otherwise use the ~1hr cache like any normal page load.
$check         = updater_check_latest($did_apply);
$maintenance   = updater_is_maintenance_mode();
$self_update   = updater_self_update_allowed();
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

  <?php if (!$self_update): ?>
    <p class="admin-meta" style="margin-bottom:1rem;padding:.75rem 1rem;border:1px solid var(--border);border-radius:8px;background:#f6f7f8;">
      Този сайт се обновява от разработчика, затова обновяването от тази страница е изключено.
      Така промените, направени специално за вашия сайт, не се губят.
    </p>
  <?php endif; ?>

  <?php if (empty($check['error']) && !empty($check['update_available'])): ?>
    <p class="admin-meta" style="margin-bottom:.5rem;">
      Налична е нова версия: <strong><?= h((string)($check['latest_version'] ?? '')) ?></strong>
    </p>
    <?php if (!empty($check['notes'])): ?>
      <div style="background:#f6f7f8;border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1rem;white-space:pre-wrap;font-size:.85rem;color:var(--text-muted);max-height:260px;overflow-y:auto;"><?= h((string)$check['notes']) ?></div>
    <?php endif; ?>

    <?php if ($self_update && !$maintenance): ?>
      <form method="post" id="applyUpdateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply_update">
        <button type="button" class="btn btn--primary"
                onclick="_adminConfirm(<?= h(json_encode(
                  'Ще обновите сайта до версия ' . (string)($check['latest_version'] ?? '') . '. '
                  . 'Преди това автоматично се прави резервно копие, но моля не затваряйте тази страница, докато обновяването не приключи.'
                , JSON_UNESCAPED_UNICODE)) ?>, 'Обнови сега').then(function(ok){ if(ok){ startUpdate(); } })">
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

<!--
  Update progress overlay.

  Every layout-critical rule is inline on purpose: admin.css can be stale-cached
  on the server, and this is the one screen where that matters most — an update
  is in the middle of rewriting admin.css itself.

  It is always rendered (hidden) rather than injected on demand, so a page
  reloaded mid-update can attach to the update already running.
-->
<div id="updateOverlay" role="dialog" aria-modal="true"
     aria-labelledby="updateOverlayTitle" aria-describedby="updateOverlayStatus"
     style="display:none;position:fixed;top:0;right:0;bottom:0;left:0;z-index:9999;
            background:rgba(17,17,17,.72);align-items:center;justify-content:center;padding:1rem;">
  <div id="updateOverlayDialog" tabindex="-1"
       style="background:#fff;border-radius:10px;max-width:520px;width:100%;
              padding:1.75rem;box-shadow:0 10px 40px rgba(0,0,0,.35);outline:none;">
    <h2 id="updateOverlayTitle" style="margin:0 0 .5rem;font-size:1.15rem;line-height:1.3;">
      Обновяване на сайта
    </h2>
    <p id="updateOverlayStatus" role="status"
       style="margin:0 0 1rem;font-size:.95rem;line-height:1.45;color:#333;">Подготовка…</p>

    <div id="updateOverlayBar" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
         aria-labelledby="updateOverlayTitle"
         style="height:14px;border-radius:7px;background:#e6e8ea;border:1px solid #cfd4d9;overflow:hidden;">
      <div id="updateOverlayFill"
           style="height:100%;width:0%;background:#1f7a3d;transition:width .3s ease;"></div>
    </div>
    <p id="updateOverlayPercent" style="margin:.5rem 0 0;font-size:.85rem;color:#555;">0%</p>

    <p id="updateOverlayNote" style="margin:1rem 0 0;font-size:.85rem;line-height:1.45;color:#666;">
      Моля, не затваряйте този прозорец. Ако все пак го затворите, обновяването ще продължи.
    </p>

    <div id="updateOverlayActions" style="display:none;margin-top:1.25rem;">
      <button type="button" id="updateOverlayClose" class="btn btn--primary">Продължи</button>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('updateOverlay');
  if (!overlay) return;

  var dialog   = document.getElementById('updateOverlayDialog');
  var bar      = document.getElementById('updateOverlayBar');
  var fill     = document.getElementById('updateOverlayFill');
  var statusEl = document.getElementById('updateOverlayStatus');
  var pctEl    = document.getElementById('updateOverlayPercent');
  var noteEl   = document.getElementById('updateOverlayNote');
  var actions  = document.getElementById('updateOverlayActions');
  var closeBtn = document.getElementById('updateOverlayClose');

  var CSRF      = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
  var POLL_MS   = 1000;
  var NO_STATE_MS = 10000;

  var pollTimer = null, indetTimer = null, indetPos = -30;
  var lastPercent = 0, gotState = false, finished = false, returnFocus = null;

  // ── Display ────────────────────────────────────────────────────────────────

  function setStatus(text) {
    if (text && statusEl.textContent !== text) statusEl.textContent = text;
  }

  // Never let the bar run backwards: the phases are weighted estimates, and a
  // bar that jumps back reads as "something went wrong" when nothing has.
  function setPercent(p) {
    p = Math.max(0, Math.min(100, p | 0));
    if (p < lastPercent) p = lastPercent;
    lastPercent = p;
    fill.style.width = p + '%';
    bar.setAttribute('aria-valuenow', String(p));
    pctEl.textContent = p + '%';
  }

  // Used when the server can't report progress at all (an unwritable logs/
  // directory). Dropping aria-valuenow is how a progressbar says "indeterminate".
  function startIndeterminate() {
    if (indetTimer || finished) return;
    bar.removeAttribute('aria-valuenow');
    pctEl.textContent = '';
    fill.style.transition = 'none';
    fill.style.width = '30%';
    indetTimer = setInterval(function () {
      indetPos += 2;
      if (indetPos > 100) indetPos = -30;
      fill.style.marginLeft = indetPos + '%';
    }, 60);
  }

  function stopIndeterminate() {
    if (!indetTimer) return;
    clearInterval(indetTimer);
    indetTimer = null;
    fill.style.marginLeft = '0';
    fill.style.transition = 'width .3s ease';
  }

  // ── Open / close ───────────────────────────────────────────────────────────

  function onKeydown(e) {
    if (e.key === 'Tab') {
      // Nothing outside the dialog is reachable while this is open. Until the
      // update ends there is nothing to tab to inside it either.
      e.preventDefault();
      (finished ? closeBtn : dialog).focus();
      return;
    }
    // Escape must not dismiss a running update — there is nothing to cancel.
    if (e.key === 'Escape') {
      e.preventDefault();
      if (finished) closeBtn.click();
    }
  }

  function open() {
    if (overlay.style.display === 'flex') return;
    returnFocus = document.activeElement;
    overlay.style.display = 'flex';
    dialog.focus();
    document.addEventListener('keydown', onKeydown, true);
  }

  closeBtn.addEventListener('click', function () {
    // A reload is what renders the outcome: admin/updates.php reads the
    // finished state once and shows the same alerts the POST flow always did.
    window.location.href = '/admin/updates.php';
  });

  // ── Polling ────────────────────────────────────────────────────────────────

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  /**
   * kind: 'success' | 'failed' | 'stalled'. The wording carries the outcome on
   * its own — the bar colour is only ever a second signal, never the only one.
   */
  function finish(kind) {
    if (finished) return;
    finished = true;
    stopPolling();
    stopIndeterminate();

    if (kind === 'success') {
      setPercent(100);
      setStatus('Обновяването завърши успешно.');
    } else {
      fill.style.background = '#b3261e';
      bar.setAttribute('aria-valuenow', String(lastPercent));
      pctEl.textContent = lastPercent + '%';
      setStatus(kind === 'stalled'
        ? 'Обновяването спря неочаквано и не беше довършено.'
        : 'Обновяването не беше приложено.');
    }

    noteEl.textContent = (kind === 'success')
      ? 'Натиснете „Продължи“, за да видите резултата.'
      : 'Сайтът работи нормално — преди обновяването е направено резервно копие. '
        + 'Ако проблемът продължи, свържете се с поддръжката.';

    actions.style.display = 'block';
    closeBtn.focus();
  }

  function applyState(st) {
    if (!st || st.ok !== true || st.phase === 'idle') return;

    gotState = true;
    stopIndeterminate();

    if (st.status === 'success' || st.status === 'partial') { finish('success'); return; }
    if (st.status === 'failed') { finish('failed'); return; }
    if (st.stale) { finish('stalled'); return; }

    setPercent(st.percent);
    setStatus(st.message);
  }

  function pollOnce() {
    fetch('/admin/update-progress-ajax.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        var ct = res.headers.get('content-type') || '';
        if (!res.ok || ct.indexOf('application/json') === -1) return null;
        return res.json();
      })
      .then(applyState)
      .catch(function () { /* a dropped poll is not a failed update — try again */ });
  }

  function startPolling() {
    if (pollTimer) return;
    pollOnce();
    pollTimer = setInterval(pollOnce, POLL_MS);
  }

  // ── Entry point (called from the confirm dialog on "Обнови сега") ──────────

  window.startUpdate = function () {
    if (finished || pollTimer) return;
    open();
    setStatus('Подготовка…');
    startPolling();

    var noStateTimer = setTimeout(function () {
      if (!gotState) startIndeterminate();
    }, NO_STATE_MS);

    fetch('/admin/update-apply-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: CSRF })
    }).then(function (res) {
      // 409 means another tab or admin already started it — the poll is
      // already showing that run, so there is nothing to report here.
      if (res.status === 409) return null;
      var ct = res.headers.get('content-type') || '';
      if (!res.ok || ct.indexOf('application/json') === -1) throw new Error('unexpected response');
      return res.json();
    }).then(function (data) {
      if (!data) return;
      finish(data.ok === true && data.status !== 'failed' ? 'success' : 'failed');
    }).catch(function () {
      // The request died (dropped connection, recycled worker) but the update
      // itself may still be running, so let the poll decide: it reports the
      // real outcome, or staleness once nothing is finishing it.
    }).then(function () {
      clearTimeout(noStateTimer);
    });
  };

  // A page reloaded while an update is running attaches to it rather than
  // showing a bare "please wait" banner with no sense of how far along it is.
<?php if ($maintenance): ?>
  open();
  setStatus('Обновяването е в ход…');
  startPolling();
<?php endif; ?>
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
