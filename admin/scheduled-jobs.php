<?php
$page_title_admin = 'Автоматични задачи';
$active_nav       = 'scheduled-jobs';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php';
admin_require_admin();

$jobs = scheduled_jobs();
$root = scheduled_jobs_site_root();
$php  = scheduled_jobs_cli_php() ?? scheduled_jobs_php_binary();

// „Добави в графика“ / „Махни от графика“ — the site edits the server's
// schedule itself; only its own labelled lines are ever touched.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $job    = is_string($_POST['job'] ?? null) ? $_POST['job'] : '';
    $keys   = ($action === 'add' && $job === 'all') ? array_keys($jobs) : (isset($jobs[$job]) ? [$job] : []);

    if (!in_array($action, ['add', 'remove'], true) || !$keys) {
        flash_set('error', 'Непозната задача. Презаредете страницата и опитайте отново.');
    } elseif ($action === 'add') {
        $r = scheduled_jobs_crontab_change(fn (string $c) => scheduled_jobs_crontab_with($c, $keys, $root, $php));
        if ($r['ok']) {
            foreach ($keys as $k) setting_set('job_cron_added_' . $k, (string) time());
            flash_set('success', count($keys) > 1
                ? 'Задачите са добавени в графика на сървъра. Всяка ще се изпълнява по своя график.'
                : '„' . $jobs[$keys[0]]['label'] . '“ е добавена в графика на сървъра. Ще се изпълнява ' . $jobs[$keys[0]]['every'] . '.');
        } else {
            flash_set('error', $r['message']);
        }
    } else {
        $r = scheduled_jobs_crontab_change(fn (string $c) => scheduled_jobs_crontab_without($c, $keys, $root));
        if ($r['ok']) {
            setting_delete('job_cron_added_' . $keys[0]);
            flash_set('success', '„' . $jobs[$keys[0]]['label'] . '“ е махната от графика на сървъра. Докато е нужна, сайтът ще я изпълнява при посещения.');
        } else {
            flash_set('error', $r['message']);
        }
    }
    header('Location: /admin/scheduled-jobs.php' . (count($keys) === 1 ? '#job-' . rawurlencode($keys[0]) : ''));
    exit;
}

$now     = time();
$since   = scheduled_jobs_tracking_since();
$crontab = scheduled_jobs_crontab_read(); // null: this host doesn't let the site read it

$rows = [];
foreach ($jobs as $key => $job) {
    $last   = scheduled_job_last_run($key);
    $cron   = scheduled_job_last_cron($key);
    $manual = scheduled_job_last_manual($key);
    $added  = scheduled_job_setting_time('job_cron_added_' . $key);
    $in     = $crontab === null ? null : scheduled_job_in_crontab($crontab, $key, $job, $root);
    $grace  = $job['grace'];

    if ($cron !== null && $now - $cron <= $grace) {
        $state = 'cron_ok';
    } elseif ($last !== null && $now - $last <= $grace) {
        $state = 'visit_ok';
    } elseif (scheduled_job_visit_run_in_progress($key, $last, $now)) {
        $state = 'running';
    } elseif ($in !== null && $cron === null && $now - max($added ?? 0, $since) <= $grace) {
        $state = 'cron_waiting';
    } else {
        $state = scheduled_job_status($grace, $last, $since, $now); // ok never reaches here
    }

    $rows[$key] = [
        'job'      => $job,
        'state'    => $state,
        'last'     => $last,
        'cron'     => $cron,
        'manual'   => $manual,
        'in'       => $in,
        'needed'   => scheduled_job_needed($key),
        'command'  => scheduled_job_command($job, $key, $root, $php),
        'terminal' => scheduled_job_terminal_command($job, $key, $root, $php),
    ];
}
$any_to_add = (bool) array_filter($rows, fn ($r) => $r['in'] === null);
$flashes    = flash_get();

$pill = static function (string $bg, string $fg, string $text): string {
    return '<span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.8rem;font-weight:600;white-space:nowrap;background:'
        . $bg . ';color:' . $fg . ';">' . h($text) . '</span>';
};
$cell = 'padding:.4rem .5rem;border:1px solid var(--border,#e5e7eb);text-align:center;font-family:monospace;font-size:.85rem;';
$head = 'padding:.35rem .5rem;border:1px solid var(--border,#e5e7eb);text-align:center;font-size:.7rem;font-weight:600;color:#4b5563;background:#f9fafb;';
$code = 'flex:1 1 240px;min-width:0;display:block;background:#f9fafb;border:1px solid var(--border,#e5e7eb);border-radius:6px;padding:.6rem .75rem;font-size:.78rem;line-height:1.5;word-break:break-all;user-select:all;';
$btn  = 'min-height:44px;';

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>
<?php foreach ($flashes as $_f): $_err = ($_f['type'] ?? '') === 'error'; ?>
  <div class="admin-alert admin-alert--<?= $_err ? 'error' : 'success' ?>" role="<?= $_err ? 'alert' : 'status' ?>" style="margin-bottom:1.5rem;"><?= $_err ? '⚠️ ' : '✅ ' ?><?= h($_f['message'] ?? '') ?></div>
<?php endforeach; ?>

<section class="admin-card" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Какво са автоматичните задачи</h2>
  <p style="margin:0 0 .75rem;line-height:1.6;">
    Някои неща сайтът прави сам, по график — например отказва неплатени поръчки или публикува планирани статии.
  </p>
  <p style="margin:0 0 .75rem;line-height:1.6;">
    Най-сигурно е задачите да са в <strong>графика на сървъра</strong> — тогава се изпълняват точно навреме.
    Натиснете „Добави в графика“ и сайтът ще ги добави сам.
  </p>
  <p style="margin:0 0 1rem;line-height:1.6;">
    Докато една задача не е в графика, сайтът я изпълнява, когато някой отвори страница от него.
    Тогава тя може да закъснее, ако дълго никой не посещава сайта.
  </p>
  <?php if ($any_to_add): ?>
  <form method="POST" style="margin:0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="job" value="all">
    <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Добави всички в графика</button>
  </form>
  <?php endif; ?>
</section>

<?php foreach ($rows as $key => $r): $job = $r['job']; ?>
<section class="admin-card" id="job-<?= h($key) ?>" style="margin-bottom:1.5rem;">
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem;">
    <h2 class="admin-card__title" style="margin:0;"><?= h($job['label']) ?></h2>
    <?php
      switch ($r['state']) {
          case 'cron_ok':
              echo $pill('#e6f4ea', '#2d6a35', '✅ Работи по графика на сървъра — последно ' . scheduled_jobs_ago((int) $r['cron'], $now));
              break;
          case 'visit_ok':
              echo $pill('#e6f4ea', '#2d6a35', '✅ Работи при посещения на сайта — последно ' . scheduled_jobs_ago((int) $r['last'], $now));
              break;
          case 'running':
              echo $pill('#f3f4f6', '#374151', '⏳ Изпълнява се в момента');
              break;
          case 'cron_waiting':
          case 'waiting':
              echo $pill('#f3f4f6', '#374151', '⏳ Очакваме първото изпълнение');
              break;
          default:
              if (!$r['needed']) {
                  echo $pill('#f3f4f6', '#374151', 'В момента не е нужна');
              } elseif ($r['state'] === 'late') {
                  echo $pill('#fdf0ef', '#a4243d', '⚠️ Спряла — последно ' . scheduled_jobs_ago((int) $r['last'], $now));
              } else {
                  echo $pill('#fdf0ef', '#a4243d', '⚠️ Не работи');
              }
      }
    ?>
  </div>
  <p style="margin:0 0 .35rem;line-height:1.6;"><?= h($job['what']) ?></p>
  <p class="admin-meta" style="margin:0 0 1rem;">Изпълнява се <?= h($job['every']) ?>. <?= h($job['needed_when']) ?></p>

  <?php if ($r['manual'] !== null && $r['manual'] > (int) $r['last'] && $now - $r['manual'] < 14 * 86400): ?>
  <p role="note" style="margin:0 0 1rem;padding:.6rem .8rem;background:#fff8eb;border:1px solid #f3d9a4;border-radius:6px;color:#5c3d06;font-size:.88rem;line-height:1.5;">
    ℹ️ Последно е пусната ръчно (<?= h(scheduled_jobs_ago((int) $r['manual'], $now)) ?>). Така тя се изпълнява само веднъж — не се добавя в графика.
  </p>
  <?php endif; ?>

  <div style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;margin-bottom:1rem;">
    <?php if ($r['in'] === 'ours'): ?>
      <span style="font-size:.9rem;">✔ В графика на сървъра.</span>
      <form method="POST" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="job" value="<?= h($key) ?>">
        <button type="submit" class="btn btn--outline" style="<?= $btn ?>">Махни от графика</button>
      </form>
    <?php elseif ($r['in'] === 'by_hand'): ?>
      <span style="font-size:.9rem;">✔ В графика на сървъра (добавена ръчно в cPanel → Cron Jobs — промени я там).</span>
    <?php else: ?>
      <form method="POST" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="job" value="<?= h($key) ?>">
        <button type="submit" class="btn btn--primary" style="<?= $btn ?>">Добави в графика</button>
      </form>
    <?php endif; ?>
  </div>

  <details>
    <summary style="cursor:pointer;font-weight:600;font-size:.9rem;padding:.7rem 0;">Ръчна настройка (ако бутонът не сработи)</summary>
    <div style="padding-top:.75rem;">
      <p style="margin:0 0 .5rem;font-weight:600;font-size:.88rem;">В cPanel</p>
      <ol style="margin:0 0 1rem;padding-left:1.25rem;line-height:1.8;font-size:.9rem;">
        <li>Влезте в cPanel и отворете <strong>„Cron Jobs“</strong> (раздел „Advanced“).</li>
        <li>В <strong>„Add New Cron Job“</strong> попълнете полетата Minute, Hour, Day, Month и Weekday точно както е показано тук.</li>
        <li>Натиснете <strong>„Копирай“</strong> до командата и я поставете в полето <strong>„Command“</strong> в cPanel — не в Terminal. В Terminal командата само се изпълнява веднъж.</li>
        <li>Натиснете <strong>„Add New Cron Job“</strong>.</li>
      </ol>
      <div style="overflow-x:auto;margin-bottom:1rem;">
        <table style="border-collapse:collapse;min-width:280px;">
          <tr>
            <?php foreach (['Minute', 'Hour', 'Day', 'Month', 'Weekday'] as $f): ?><th scope="col" style="<?= $head ?>"><?= $f ?></th><?php endforeach; ?>
          </tr>
          <tr>
            <?php foreach ($job['cron'] as $v): ?><td style="<?= $cell ?>"><?= h($v) ?></td><?php endforeach; ?>
          </tr>
        </table>
      </div>
      <div id="cmd-<?= h($key) ?>-label" style="font-weight:600;font-size:.85rem;margin-bottom:.35rem;">Command</div>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:stretch;margin-bottom:1.25rem;">
        <code id="cmd-<?= h($key) ?>" aria-labelledby="cmd-<?= h($key) ?>-label" style="<?= $code ?>"><?= h($r['command']) ?></code>
        <button type="button" class="btn btn--outline" data-copy="cmd-<?= h($key) ?>" style="flex:0 0 auto;<?= $btn ?>">Копирай</button>
      </div>

      <p style="margin:0 0 .35rem;font-weight:600;font-size:.88rem;" id="term-<?= h($key) ?>-label">Или в Terminal на хостинга</p>
      <p class="admin-meta" style="margin:0 0 .5rem;">Тази команда добавя задачата в графика, без да пипа другите задачи, и пази копие на графика преди промяната.</p>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:stretch;">
        <code id="term-<?= h($key) ?>" aria-labelledby="term-<?= h($key) ?>-label" style="<?= $code ?>"><?= h($r['terminal']) ?></code>
        <button type="button" class="btn btn--outline" data-copy="term-<?= h($key) ?>" style="flex:0 0 auto;<?= $btn ?>">Копирай</button>
      </div>
    </div>
  </details>
</section>
<?php endforeach; ?>

<p class="admin-meta" style="margin:0 0 2rem;">
  Резултатът от всяко изпълнение се записва в папка <code>logs/</code> на сайта, затова cPanel няма да ви изпраща имейл след всяко изпълнение.
</p>

<div id="copy-status" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"></div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var el = document.getElementById(btn.getAttribute('data-copy'));
    var text = el.textContent;
    function done(ok) {
      btn.textContent = ok ? 'Копирано ✓' : 'Маркирайте и копирайте ръчно';
      document.getElementById('copy-status').textContent = ok ? 'Командата е копирана.' : 'Копирането не стана — маркирайте командата и я копирайте ръчно.';
      setTimeout(function () { btn.textContent = 'Копирай'; }, 2500);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
    } else {
      var r = document.createRange(); r.selectNodeContents(el);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
      done(ok);
    }
  });
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
