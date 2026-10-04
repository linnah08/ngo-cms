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
<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <h1><?= $_en ? 'Annual reports' : 'Годишни отчети' ?></h1>
    <p class="lead" style="max-width:640px;margin-top:1rem;"><?= $_en
      ? 'Our yearly financial statements and activity reports. The documents are in Bulgarian.'
      : 'Годишните ни финансови отчети и доклади за дейността.' ?></p>
  </div>
</section>
<section class="section">
  <div class="container container--narrow">
  <?php if (!$reports): ?>
    <p><?= $_en ? 'No reports have been published yet.' : 'Още няма публикувани отчети.' ?></p>
  <?php endif; ?>
  <?php foreach ($reports as $y): ?>
    <h2 style="margin:2rem 0 1rem;"><?= (int) $y['year'] ?></h2>
    <?php foreach ($y['documents'] as $doc): ?>
      <article style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;">
        <h3 style="margin:0 0 .5rem;font-size:1.1rem;"><?= h($doc['title']) ?></h3>
        <?php if ($doc['description'] !== ''): ?><p style="margin:0 0 1rem;"><?= nl2br(h($doc['description'])) ?></p><?php endif; ?>
        <?php if (!$doc['exists']): ?>
          <p style="margin:0;color:#7f1d1d;font-weight:600;"><span aria-hidden="true">⚠</span> <?= $_en ? 'File not available.' : 'Файлът не е наличен.' ?></p>
        <?php else: ?>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn--primary" style="<?= $_btn ?>"
                    data-report-open="<?= h($doc['url']) ?>" data-report-title="<?= h($doc['title']) ?>"
                    aria-expanded="false" aria-controls="rv-<?= h($doc['id']) ?>"><?= $_en ? 'Open' : 'Отвори' ?></button>
            <a href="<?= h($doc['url']) ?>" class="btn btn--outline" style="<?= $_btn ?>" download>
              <?= $_en ? 'Download (PDF, ' . $_mb($doc['bytes']) . ', in Bulgarian)' : 'Изтегли (PDF, ' . $_mb($doc['bytes']) . ')' ?></a>
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
