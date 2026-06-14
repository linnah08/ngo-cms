<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
$lang             = 'en';
$page_title       = 'Home';
$page_description = 'Odd Minds Foundation exists to support children with disabilities and children without parental care.';

$partners = get_partners();
$centres  = get_centres();
$impact   = get_impact();
$articles = get_articles('en', 3);
$pages    = load_json(CONTENT_PATH . '/pages.json');
$home     = $pages['home'] ?? [];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="hero__text">
      <span class="section-label">Odd Minds Foundation</span>
      <h1><?= h($home['hero_title_en'] ?? t('home.hero.title')) ?></h1>
      <p><?= h($home['hero_text_en'] ?? t('home.hero.text')) ?></p>
      <div class="btn-group">
        <a href="/en/how-to-help/" class="btn btn--primary"><?= h($home['hero_cta_primary_en'] ?: t('home.hero.cta_primary')) ?></a>
        <a href="/en/about/"       class="btn btn--outline"><?= h($home['hero_cta_secondary_en'] ?: t('home.hero.cta_secondary')) ?></a>
      </div>
    </div>
    <div class="hero__image">
      <img src="/assets/images/hero.webp"
           alt="Children from Odd Minds Foundation"
           width="560" height="420">
    </div>
  </div>
</section>

<?php
  $campaign_url = setting_get('campaign_url');
  $campaign     = $pages['campaign'] ?? [];
?>
<?php if ($campaign_url !== '' && !empty($campaign['title_en'])): ?>
<!-- CAMPAIGN -->
<section class="section section--warm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;align-items:center;">
      <?php if (!empty($campaign['image'])): ?>
      <div style="border-radius:8px;overflow:hidden;aspect-ratio:4/3;">
        <img src="<?= h($campaign['image']) ?>" alt="<?= h($campaign['title_en']) ?>" loading="lazy"
             style="width:100%;height:100%;object-fit:cover;">
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <span style="font-size:.75rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--amber);">Campaign</span>
        <h2 style="margin:0;"><?= h($campaign['title_en']) ?></h2>
        <p style="color:var(--text-muted);line-height:1.7;margin:0;"><?= h($campaign['text_en']) ?></p>
        <div>
          <a href="<?= h($campaign_url) ?>" target="_blank" rel="noopener noreferrer"
             class="btn btn--campaign"><?= h($campaign['cta_en'] ?: 'Support →') ?></a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- IMPACT NUMBERS -->
<section class="section section--sm section--teal">
  <div class="container">
    <div class="impact-grid">
      <?php foreach ($impact as $item): ?>
        <div class="impact-item">
          <div class="impact-item__number"><?= h($item['number']) ?></div>
          <div class="impact-item__label" style="color:rgba(255,255,255,0.8);">
            <?= h($item['label_en']) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- WHO WE WORK WITH -->
<section class="section">
  <div class="container">
    <div class="section-header">
      <span class="section-label"><?= h($home['section_centres_en'] ?: t('home.centres.title')) ?></span>
      <h2><?= h($home['section_centres_en'] ?: t('home.centres.title')) ?></h2>
    </div>
    <div class="grid grid--2">
      <?php foreach ($centres as $centre): ?>
        <div class="centre-card">
          <?php if (!empty($centre['image'])): ?>
            <div class="centre-card__image">
              <img src="<?= h($centre['image']) ?>"
                   alt="<?= h($centre['name_en']) ?>"
                   loading="lazy">
            </div>
          <?php endif; ?>
          <div class="centre-card__body">
            <h3><?= h($centre['name_en']) ?></h3>
            <p><?= h($centre['description_en']) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MISSION -->
<section class="section section--grey">
  <div class="container container--narrow" style="text-align:center;">
    <span class="section-label"><?= h($home['mission_title_en'] ?? t('home.mission.title')) ?></span>
    <h2><?= h($home['mission_title_en'] ?? t('home.mission.title')) ?></h2>
    <p class="lead" style="margin-top:1.5rem;">
      <?= h($home['mission_text_en'] ?? '') ?>
    </p>
    <div class="btn-group" style="justify-content:center;margin-top:2rem;">
      <a href="/en/about/"       class="btn btn--primary"><?= h($home['mission_cta_primary_en'] ?: 'Learn more about us') ?></a>
      <a href="/en/shop/"        class="btn btn--outline"><?= h($home['mission_cta_secondary_en'] ?: 'Support us') ?></a>
    </div>
  </div>
</section>

<!-- LATEST NEWS -->
<?php if (!empty($articles)): ?>
<section class="section">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div>
        <span class="section-label"><?= h($home['section_news_en'] ?: t('home.news.title')) ?></span>
        <h2><?= h($home['section_news_en'] ?: t('home.news.title')) ?></h2>
      </div>
      <a href="/en/news/" class="btn btn--outline"><?= h($home['news_btn_all_en'] ?: t('home.news.all')) ?></a>
    </div>
    <div class="article-grid">
      <?php foreach ($articles as $article): ?>
        <article class="card">
          <?php if (!empty($article['image'])): ?>
            <div class="card__image">
              <a href="/en/news/<?= h($article['slug']) ?>/" tabindex="-1" aria-hidden="true">
                <img src="<?= asset_url($article['image']) ?>"
                     alt="<?= h($article['title']) ?>"
                     loading="lazy">
              </a>
            </div>
          <?php endif; ?>
          <div class="card__body">
            <div class="article-meta">
              <time datetime="<?= h($article['date'] ?? '') ?>">
                <?= h(format_date($article['date'] ?? '', 'en')) ?>
              </time>
              <?php if (!empty($article['author'])): ?>
                <span><?= t('news.by') ?> <?= h($article['author']) ?></span>
              <?php endif; ?>
            </div>
            <h3 class="card__title">
              <a href="/en/news/<?= h($article['slug']) ?>/"><?= h($article['title']) ?></a>
            </h3>
            <?php if (!empty($article['excerpt'])): ?>
              <p class="card__excerpt"><?= h($article['excerpt']) ?></p>
            <?php endif; ?>
            <a href="/en/news/<?= h($article['slug']) ?>/"
               class="btn btn--outline" style="margin-top:1rem;font-size:0.8rem;">
              <?= t('news.read_more') ?>
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- PARTNERS -->
<?php if (!empty($partners)): ?>
<section class="section section--grey">
  <div class="container">
    <div class="section-header section-header--center">
      <span class="section-label"><?= h($home['section_partners_en'] ?: t('home.partners.title')) ?></span>
      <h2><?= h($home['section_partners_en'] ?: t('home.partners.title')) ?></h2>
    </div>
    <div class="partners-grid">
      <?php foreach ($partners as $partner): ?>
        <?php $tag = !empty($partner['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?>
          class="partner-item"
          <?php if (!empty($partner['url'])): ?>
            href="<?= h($partner['url']) ?>"
            target="_blank"
            rel="noopener"
          <?php endif; ?>
        >
          <img src="<?= h($partner['logo']) ?>"
               alt="<?= h($partner['name']) ?>"
               loading="lazy">
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- CTA BANNER -->
<section class="section section--teal">
  <div class="container" style="text-align:center;">
    <h2><?= h($home['cta_heading_en'] ?: 'Every child deserves a chance') ?></h2>
    <p class="lead" style="color:rgba(255,255,255,0.85);margin:1.5rem auto;max-width:600px;">
      <?= h($home['cta_body_en'] ?: 'With your support we can reach more children, fund more therapies, and build a better future for each of them.') ?>
    </p>
    <div class="btn-group" style="justify-content:center;">
      <a href="/en/shop/" class="btn btn--white"><?= h($home['cta_btn_donate_en'] ?: 'Donate now') ?></a>
      <a href="/en/how-to-help/" class="btn btn--outline btn--white-outline"
         style="border-color:white;color:white;"><?= h($home['cta_btn_help_en'] ?: 'How to help') ?></a>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
