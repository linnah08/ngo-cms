<?php
$page_title_admin = 'Автоматични задачи';
$active_nav       = 'scheduled-jobs';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php';
admin_require_admin();

$now   = time();
$since = scheduled_jobs_tracking_since();
$root  = rtrim((string) (realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');

$rows = [];
foreach (scheduled_jobs() as $key => $job) {
    $last = scheduled_job_last_run($key);
    $rows[$key] = [
        'job'     => $job,
        'last'    => $last,
        'status'  => scheduled_job_status($job['grace'], $last, $since, $now),
        'needed'  => scheduled_job_needed($key),
        'command' => scheduled_job_command($job, $key, $root),
    ];
}

$pill = static function (string $bg, string $fg, string $text): string {
    return '<span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.8rem;font-weight:600;white-space:nowrap;background:'
        . $bg . ';color:' . $fg . ';">' . h($text) . '</span>';
};
$cell = 'padding:.4rem .5rem;border:1px solid var(--border,#e5e7eb);text-align:center;font-family:monospace;font-size:.85rem;';
$head = 'padding:.35rem .5rem;border:1px solid var(--border,#e5e7eb);text-align:center;font-size:.7rem;font-weight:600;color:#6b7280;background:#f9fafb;';

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<section class="admin-card" style="margin-bottom:1.5rem;">
  <h2 class="admin-card__title">Какво са автоматичните задачи</h2>
  <p style="margin:0 0 .75rem;line-height:1.6;">
    Някои неща сайтът прави сам, по график — например отказва неплатени поръчки или публикува планирани статии.
    За да работят, всяка задача трябва да се добави веднъж в контролния панел на хостинга (<strong>cPanel</strong>).
  </p>
  <ol style="margin:0;padding-left:1.25rem;line-height:1.8;">
    <li>Влезте в cPanel и отворете <strong>„Cron Jobs“</strong> (раздел „Advanced“).</li>
    <li>В <strong>„Add New Cron Job“</strong> попълнете полетата Minute, Hour, Day, Month и Weekday точно както е показано при задачата по-долу.</li>
    <li>Натиснете <strong>„Копирай“</strong> до командата и я поставете в полето <strong>„Command“</strong>.</li>
    <li>Натиснете <strong>„Add New Cron Job“</strong>. След първото изпълнение тук ще се появи зелено „Работи“.</li>
  </ol>
</section>

<?php foreach ($rows as $key => $r): $job = $r['job']; ?>
<section class="admin-card" id="job-<?= h($key) ?>" style="margin-bottom:1.5rem;">
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem;">
    <h2 class="admin-card__title" style="margin:0;"><?= h($job['label']) ?></h2>
    <?php
      if ($r['status'] === 'ok') {
          echo $pill('#e6f4ea', '#2d6a35', '✅ Работи — ' . scheduled_jobs_ago((int) $r['last'], $now));
      } elseif ($r['status'] === 'waiting') {
          echo $pill('#f3f4f6', '#4b5563', 'Очакваме първото изпълнение');
      } elseif (!$r['needed']) {
          echo $pill('#f3f4f6', '#4b5563', $r['status'] === 'late' ? 'Спряла — в момента не е нужна' : 'Не е настроена — в момента не е нужна');
      } elseif ($r['status'] === 'late') {
          echo $pill('#fdf0ef', '#c0392b', '⚠️ Спряла — последно ' . scheduled_jobs_ago((int) $r['last'], $now));
      } else {
          echo $pill('#fdf0ef', '#c0392b', '⚠️ Не е настроена');
      }
    ?>
  </div>
  <p style="margin:0 0 .35rem;line-height:1.6;"><?= h($job['what']) ?></p>
  <p class="admin-meta" style="margin:0 0 1rem;">Изпълнява се <?= h($job['every']) ?>. <?= h($job['needed_when']) ?></p>

  <div style="overflow-x:auto;margin-bottom:1rem;">
    <table style="border-collapse:collapse;min-width:280px;">
      <tr>
        <?php foreach (['Minute', 'Hour', 'Day', 'Month', 'Weekday'] as $f): ?><th style="<?= $head ?>"><?= $f ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($job['cron'] as $v): ?><td style="<?= $cell ?>"><?= h($v) ?></td><?php endforeach; ?>
      </tr>
    </table>
  </div>

  <div style="font-weight:600;font-size:.85rem;margin-bottom:.35rem;">Command</div>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:stretch;">
    <code id="cmd-<?= h($key) ?>" style="flex:1 1 240px;min-width:0;display:block;background:#f9fafb;border:1px solid var(--border,#e5e7eb);border-radius:6px;padding:.6rem .75rem;font-size:.78rem;line-height:1.5;word-break:break-all;user-select:all;"><?= h($r['command']) ?></code>
    <button type="button" class="btn btn--outline" data-copy="cmd-<?= h($key) ?>" style="flex:0 0 auto;">Копирай</button>
  </div>
</section>
<?php endforeach; ?>

<p class="admin-meta" style="margin:0 0 2rem;">
  Резултатът от всяко изпълнение се записва в папка <code>logs/</code> на сайта, затова cPanel няма да ви изпраща имейл след всяко изпълнение.
</p>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var el = document.getElementById(btn.getAttribute('data-copy'));
    var text = el.textContent;
    function done(ok) {
      btn.textContent = ok ? 'Копирано ✓' : 'Маркирайте и копирайте ръчно';
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
