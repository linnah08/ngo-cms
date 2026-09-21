<?php /* Built-in: campaign (edited at admin/pages.php?page=home_campaign). Vars: $s $f $sid $lang $ctx $show_admin */
if (!feature_enabled('campaign')) return;
$campaign     = $ctx['campaign'];
$campaign_url = $ctx['campaign_url'];
$pick = fn(string $k) => $lang === 'bg' ? (string) ($campaign[$k] ?? '') : ((string) ($campaign[$k . '_en'] ?? '') ?: (string) ($campaign[$k] ?? ''));
$title = $pick('title');
if ($campaign_url === '' || $title === '') return;
$cta = $pick('cta') ?: ($lang === 'bg' ? 'Подкрепи →' : 'Support →');
?>
<section class="section section--warm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;align-items:center;" class="campaign-block-grid">
      <?php if (!empty($campaign['image'])): ?>
      <div style="border-radius:8px;overflow:hidden;aspect-ratio:4/3;">
        <span class="om-img-wrap" data-cms-field="image" data-cms-section="campaign">
          <img src="<?= h($campaign['image']) ?>" alt="<?= h($title) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <span style="font-size:.75rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--amber);"><?= $lang === 'bg' ? 'Кампания' : 'Campaign' ?></span>
        <h2 style="margin:0;" data-cms-field="title" data-cms-section="campaign" data-cms-type="text"
            data-cms-bg="<?= h($campaign['title'] ?? '') ?>" data-cms-en="<?= h($campaign['title_en'] ?? '') ?>"><?= h($title) ?></h2>
        <p style="color:var(--text-muted);line-height:1.7;margin:0;" data-cms-field="text" data-cms-section="campaign" data-cms-type="text"
           data-cms-bg="<?= h($campaign['text'] ?? '') ?>" data-cms-en="<?= h($campaign['text_en'] ?? '') ?>"><?= h($pick('text')) ?></p>
        <div>
          <a href="<?= h($campaign_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn--campaign"
             data-cms-field="cta" data-cms-section="campaign" data-cms-type="text"
             data-cms-bg="<?= h($campaign['cta'] ?? 'Подкрепи →') ?>" data-cms-en="<?= h($campaign['cta_en'] ?? 'Support →') ?>"><?= h($cta) ?></a>
        </div>
      </div>
    </div>
  </div>
</section>
