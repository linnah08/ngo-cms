<?php
/**
 * The yearly reports page (BG and EN). Vars: $lang, $reports (reports_public_list()).
 * A document's "Отвори" opens it in the viewer below it (assets/js/report-viewer.js);
 * the download link always works, also without JavaScript. Inline styles only.
 */
$_en  = $lang === 'en';
$_mb  = static fn(?int $b): string => $b === null ? '' : ($b >= 1048576 ? round($b / 1048576) . ' MB' : max(1, (int) round($b / 1024)) . ' KB');
$_btn = 'min-height:44px;min-width:44px;';
?>
<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1><?= te('reports.heading', 'Годишни отчети', 'Annual reports', $lang) ?></h1>
    <p class="lead" style="max-width:640px;margin-top:1rem;"><?= te('reports.lead', 'Годишните ни финансови отчети и доклади за дейността.', 'Our yearly financial statements and activity reports. The documents are in Bulgarian.', $lang) ?></p>
  </div>
</section>
<section class="section">
  <div class="container container--narrow">
  <?php if (!$reports): ?>
    <p><?= te('reports.empty', 'Още няма публикувани отчети.', 'No reports have been published yet.', $lang) ?></p>
  <?php endif; ?>
  <?php foreach ($reports as $y): ?>
    <h2 style="margin:2rem 0 1rem;"><?= (int) $y['year'] ?></h2>
    <?php foreach ($y['documents'] as $doc): ?>
      <article style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;">
        <h3 style="margin:0 0 .5rem;font-size:1.1rem;"<?= cms_admin(['Годишни отчети', '/admin/financial-reports.php']) ?>><?= h($doc['title']) ?></h3>
        <?php if ($doc['description'] !== ''): ?><p style="margin:0 0 1rem;"<?= cms_admin(['Годишни отчети', '/admin/financial-reports.php']) ?>><?= nl2br(h($doc['description'])) ?></p><?php endif; ?>
        <?php if (!$doc['exists']): ?>
          <p style="margin:0;color:#7f1d1d;font-weight:600;"><span aria-hidden="true">⚠</span> <?= te('reports.missing', 'Файлът не е наличен.', 'File not available.', $lang) ?></p>
        <?php else: ?>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn--primary" style="<?= $_btn ?>"
                    data-report-open="<?= h($doc['url']) ?>" data-report-title="<?= h($doc['title']) ?>"
                    aria-expanded="false" aria-controls="rv-<?= h($doc['id']) ?>"><?= te('reports.open', 'Отвори', 'Open', $lang) ?></button>
            <a href="<?= h($doc['url']) ?>" class="btn btn--outline" style="<?= $_btn ?>" download>
              <?= te('reports.download', 'Изтегли', 'Download', $lang) ?> (PDF, <?= h($_mb($doc['bytes'])) ?><?= $_en ? ', ' . te('reports.in_bulgarian', 'на български', 'in Bulgarian', $lang) : '' ?>)</a>
          </div>
          <div id="rv-<?= h($doc['id']) ?>" data-report-viewer hidden style="margin-top:1rem;"></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </div>
</section>
<?php if ($reports): ?>
<script>window.OM_REPORT_LANG = <?= json_encode($lang) ?>;</script>
<script type="module" src="<?= h(versioned_asset('assets/js/report-viewer.js')) ?>"></script>
<?php endif; ?>
