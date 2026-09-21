<?php /* Built-in: latest news. Vars: $s $f $sid $lang $ctx $show_admin */
$articles = $ctx['articles'];
if (empty($articles)) return;
$base = $lang === 'bg' ? '/novini/' : '/en/news/';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
      <div><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
      <?= home_buttons($sid, $f, $lang, [['btn btn--outline', '']]) ?>
    </div>
    <div class="article-grid">
      <?php foreach ($articles as $article): $url = $base . rawurlencode((string) $article['slug']) . '/'; ?>
        <article class="card">
          <?php if (!empty($article['image'])): ?>
            <div class="card__image">
              <a href="<?= h($url) ?>" tabindex="-1" aria-hidden="true">
                <img src="<?= asset_url($article['image']) ?>" alt="" loading="lazy">
              </a>
            </div>
          <?php endif; ?>
          <div class="card__body">
            <div class="article-meta">
              <time datetime="<?= h($article['date'] ?? '') ?>"><?= h(format_date($article['date'] ?? '', $lang)) ?></time>
              <?php if (!empty($article['author'])): ?><span><?= t('news.by') ?> <?= h($article['author']) ?></span><?php endif; ?>
            </div>
            <h3 class="card__title"><a href="<?= h($url) ?>"><?= h($article['title']) ?></a></h3>
            <?php if (!empty($article['excerpt'])): ?><p class="card__excerpt"><?= h($article['excerpt']) ?></p><?php endif; ?>
            <a href="<?= h($url) ?>" class="btn btn--outline" style="margin-top:1rem;font-size:0.8rem;"><?= t('news.read_more') ?><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">: <?= h($article['title']) ?></span></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
