<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
$lang = get_lang();
$page_title = 'Начало';
// Keyword-rich homepage title (overrides the "Page — Site" template)
$page_title_full = $lang === 'bg'
    ? 'Фондация Различни умове — подкрепа за деца с увреждания и в риск'
    : 'Odd Minds Foundation — support for children with disabilities and at risk';
$page_description = $lang === 'bg'
    ? 'Фондация Различни умове съществува, за да подкрепя деца с увреждания и деца, лишени от родителска грижа.'
    : 'Odd Minds Foundation supports children with disabilities and children deprived of parental care.';
$page_head_extra = '<style>@media(max-width:640px){.campaign-block-grid{grid-template-columns:1fr!important;}}</style>';

$partners = get_partners();
$centres  = get_centres();
$impact   = get_impact();
$articles = get_articles('bg', 3);
$pages   = load_json(CONTENT_PATH . '/pages.json');
$home    = $pages['home'] ?? [];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="hero__text">
      <span class="section-label">Фондация Различни умове</span>
      <h1 data-cms-field="hero_title"
          data-cms-section="home"
          data-cms-type="text"
          data-cms-bg="<?= h($home['hero_title'] ?? '') ?>"
          data-cms-en="<?= h($home['hero_title_en'] ?? '') ?>">
        <?= h($lang === 'bg' ? ($home['hero_title'] ?? t('home.hero.title')) : ($home['hero_title_en'] ?? t('home.hero.title'))) ?>
      </h1>
      <p data-cms-field="hero_text"
         data-cms-section="home"
         data-cms-type="text"
         data-cms-bg="<?= h($home['hero_text'] ?? '') ?>"
         data-cms-en="<?= h($home['hero_text_en'] ?? '') ?>">
        <?= h($lang === 'bg' ? ($home['hero_text'] ?? t('home.hero.text')) : ($home['hero_text_en'] ?? t('home.hero.text'))) ?>
      </p>
      <div class="btn-group">
        <a href="/kak-da-pomogna/" class="btn btn--primary"
           data-cms-field="hero_cta_primary" data-cms-section="home" data-cms-type="text"
           data-cms-bg="<?= h($home['hero_cta_primary'] ?? '') ?>"
           data-cms-en="<?= h($home['hero_cta_primary_en'] ?? '') ?>"
        ><?= h($lang === 'bg' ? ($home['hero_cta_primary'] ?: t('home.hero.cta_primary')) : ($home['hero_cta_primary_en'] ?: t('home.hero.cta_primary'))) ?></a>
        <a href="/za-nas/" class="btn btn--outline"
           data-cms-field="hero_cta_secondary" data-cms-section="home" data-cms-type="text"
           data-cms-bg="<?= h($home['hero_cta_secondary'] ?? '') ?>"
           data-cms-en="<?= h($home['hero_cta_secondary_en'] ?? '') ?>"
        ><?= h($lang === 'bg' ? ($home['hero_cta_secondary'] ?: t('home.hero.cta_secondary')) : ($home['hero_cta_secondary_en'] ?: t('home.hero.cta_secondary'))) ?></a>
      </div>
    </div>
    <div class="hero__image">
      <span class="om-img-wrap"
            data-cms-field="hero_image"
            data-cms-section="home">
        <img src="/assets/images/hero.webp"
             alt="Деца от Фондация Различни умове"
             width="560" height="420">
        <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
      </span>
    </div>
  </div>
</section>

<!-- IMPACT NUMBERS -->
<?php if (!empty($impact) || admin_logged_in()): ?>
<section class="section section--sm section--teal">
  <div class="container">
    <?php if (!empty($home['section_impact']) || !empty($home['section_impact_en']) || admin_logged_in()): ?>
    <div class="section-header section-header--center" style="margin-bottom:1.5rem;">
      <span class="section-label"
            data-cms-field="section_impact" data-cms-section="home" data-cms-type="text"
            data-cms-bg="<?= h($home['section_impact'] ?? '') ?>"
            data-cms-en="<?= h($home['section_impact_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_impact'] ?: t('home.impact.title')) : ($home['section_impact_en'] ?: t('home.impact.title'))) ?></span>
      <h2 data-cms-field="section_impact" data-cms-section="home" data-cms-type="text"
          data-cms-bg="<?= h($home['section_impact'] ?? '') ?>"
          data-cms-en="<?= h($home['section_impact_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_impact'] ?: t('home.impact.title')) : ($home['section_impact_en'] ?: t('home.impact.title'))) ?></h2>
    </div>
    <?php endif; ?>
    <div class="impact-grid">
      <?php $i = 0; foreach ($impact as $item): ?>
        <div class="impact-item om-removable"
             data-cms-remove-type="impact"
             data-cms-remove-id="<?= $i ?>">
          <div class="impact-item__number"
               data-cms-field="number"
               data-cms-section="impact"
               data-cms-type="text"
               data-cms-bg="<?= h($item['number'] ?? '') ?>"
               data-cms-en="<?= h($item['number'] ?? '') ?>">
            <?= h($item['number']) ?>
          </div>
          <div class="impact-item__label" style="color:rgba(255,255,255,0.8);"
               data-cms-field="label"
               data-cms-section="impact"
               data-cms-type="text"
               data-cms-bg="<?= h($item['label_bg'] ?? '') ?>"
               data-cms-en="<?= h($item['label_en'] ?? '') ?>">
            <?= h($lang === 'bg' ? ($item['label_bg'] ?? '') : ($item['label_en'] ?? '')) ?>
          </div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if (admin_logged_in()): ?>
    <button class="om-add-btn" data-cms-add="impact">+ Add impact number</button>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php
  $campaign_url  = setting_get('campaign_url');
  $campaign      = $pages['campaign'] ?? [];
?>
<?php if ($campaign_url !== '' && !empty($campaign['title'])): ?>
<!-- CAMPAIGN -->
<section class="section section--warm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;align-items:center;" class="campaign-block-grid">
      <?php if (!empty($campaign['image'])): ?>
      <div style="border-radius:8px;overflow:hidden;aspect-ratio:4/3;">
        <span class="om-img-wrap"
              data-cms-field="image"
              data-cms-section="campaign">
          <img src="<?= h($campaign['image']) ?>" alt="<?= h($campaign['title']) ?>" loading="lazy"
               style="width:100%;height:100%;object-fit:cover;">
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <span style="font-size:.75rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--amber);">Кампания</span>
        <h2 style="margin:0;"
            data-cms-field="title"
            data-cms-section="campaign"
            data-cms-type="text"
            data-cms-bg="<?= h($campaign['title'] ?? '') ?>"
            data-cms-en="<?= h($campaign['title_en'] ?? '') ?>">
          <?= h($lang === 'bg' ? ($campaign['title'] ?? '') : ($campaign['title_en'] ?? '')) ?>
        </h2>
        <p style="color:var(--text-muted);line-height:1.7;margin:0;"
           data-cms-field="text"
           data-cms-section="campaign"
           data-cms-type="text"
           data-cms-bg="<?= h($campaign['text'] ?? '') ?>"
           data-cms-en="<?= h($campaign['text_en'] ?? '') ?>">
          <?= h($lang === 'bg' ? ($campaign['text'] ?? '') : ($campaign['text_en'] ?? '')) ?>
        </p>
        <div>
          <a href="<?= h($campaign_url) ?>" target="_blank" rel="noopener noreferrer"
             class="btn btn--campaign"
             data-cms-field="cta"
             data-cms-section="campaign"
             data-cms-type="text"
             data-cms-bg="<?= h($campaign['cta'] ?? 'Подкрепи →') ?>"
             data-cms-en="<?= h($campaign['cta_en'] ?? 'Support →') ?>">
            <?= h($lang === 'bg' ? ($campaign['cta'] ?: 'Подкрепи →') : ($campaign['cta_en'] ?: 'Support →')) ?>
          </a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- WHO WE WORK WITH -->
<?php if (!empty($centres) || admin_logged_in()): ?>
<section class="section">
  <div class="container">
    <div class="section-header">
      <span class="section-label"
            data-cms-field="section_centres" data-cms-section="home" data-cms-type="text"
            data-cms-bg="<?= h($home['section_centres'] ?? '') ?>"
            data-cms-en="<?= h($home['section_centres_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_centres'] ?: t('home.centres.title')) : ($home['section_centres_en'] ?: t('home.centres.title'))) ?></span>
      <h2 data-cms-field="section_centres" data-cms-section="home" data-cms-type="text"
          data-cms-bg="<?= h($home['section_centres'] ?? '') ?>"
          data-cms-en="<?= h($home['section_centres_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_centres'] ?: t('home.centres.title')) : ($home['section_centres_en'] ?: t('home.centres.title'))) ?></h2>
    </div>
    <div class="grid grid--2">
      <?php $i = 0; foreach ($centres as $centre): ?>
        <div class="centre-card om-removable"
             data-cms-remove-type="centre"
             data-cms-remove-id="<?= $i ?>">
          <?php if (!empty($centre['image'])): ?>
            <div class="centre-card__image">
              <span class="om-img-wrap"
                    data-cms-field="image_<?= $i ?>"
                    data-cms-section="centre">
                <img src="<?= h($centre['image']) ?>"
                     alt="<?= h($centre['name_bg']) ?>"
                     loading="lazy">
                <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
          <div class="centre-card__body">
            <h3 data-cms-field="name"
                data-cms-section="centre"
                data-cms-type="text"
                data-cms-bg="<?= h($centre['name_bg'] ?? '') ?>"
                data-cms-en="<?= h($centre['name_en'] ?? '') ?>">
              <?= h($lang === 'bg' ? ($centre['name_bg'] ?? '') : ($centre['name_en'] ?? '')) ?>
            </h3>
            <p data-cms-field="description"
               data-cms-section="centre"
               data-cms-type="text"
               data-cms-bg="<?= h($centre['description_bg'] ?? '') ?>"
               data-cms-en="<?= h($centre['description_en'] ?? '') ?>">
              <?= h($lang === 'bg' ? ($centre['description_bg'] ?? '') : ($centre['description_en'] ?? '')) ?>
            </p>
          </div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if (admin_logged_in()): ?>
    <button class="om-add-btn" data-cms-add="centre">+ Add centre</button>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- MISSION -->
<section class="section section--grey">
  <?php if (!empty($home['mission_image'])): ?>
  <div class="container">
    <div class="mission-split">
      <div class="mission-split__image">
        <span class="om-img-wrap"
              data-cms-field="mission_image"
              data-cms-section="home">
          <img src="<?= h($home['mission_image']) ?>"
               alt="Мисията на Фондация Различни умове"
               loading="lazy">
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <div class="mission-split__text">
        <span class="section-label"
              data-cms-field="section_mission" data-cms-section="home" data-cms-type="text"
              data-cms-bg="<?= h($home['section_mission'] ?? '') ?>"
              data-cms-en="<?= h($home['section_mission_en'] ?? '') ?>"
        ><?= h($lang === 'bg' ? ($home['section_mission'] ?: t('home.mission.title')) : ($home['section_mission_en'] ?: t('home.mission.title'))) ?></span>
        <p class="lead" style="margin-top:1.5rem;"
           data-cms-field="mission_text"
           data-cms-section="home"
           data-cms-type="richtext"
           data-cms-bg="<?= h($home['mission_text'] ?? '') ?>"
           data-cms-en="<?= h($home['mission_text_en'] ?? '') ?>">
          <?= $lang === 'bg' ? ($home['mission_text'] ?? '') : ($home['mission_text_en'] ?? '') ?>
        </p>
        <div class="btn-group" style="margin-top:2rem;">
          <a href="/za-nas/" class="btn btn--primary"
             data-cms-field="mission_cta_primary" data-cms-section="home" data-cms-type="text"
             data-cms-bg="<?= h($home['mission_cta_primary'] ?? '') ?>"
             data-cms-en="<?= h($home['mission_cta_primary_en'] ?? '') ?>"
          ><?= h($lang === 'bg' ? ($home['mission_cta_primary'] ?: 'Разберете повече за нас') : ($home['mission_cta_primary_en'] ?: 'Learn more about us')) ?></a>
          <a href="/magazin/" class="btn btn--outline"
             data-cms-field="mission_cta_secondary" data-cms-section="home" data-cms-type="text"
             data-cms-bg="<?= h($home['mission_cta_secondary'] ?? '') ?>"
             data-cms-en="<?= h($home['mission_cta_secondary_en'] ?? '') ?>"
          ><?= h($lang === 'bg' ? ($home['mission_cta_secondary'] ?: 'Подкрепете ни') : ($home['mission_cta_secondary_en'] ?: 'Support us')) ?></a>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="container container--narrow" style="text-align:center;">
    <span class="section-label"
          data-cms-field="section_mission" data-cms-section="home" data-cms-type="text"
          data-cms-bg="<?= h($home['section_mission'] ?? '') ?>"
          data-cms-en="<?= h($home['section_mission_en'] ?? '') ?>"
    ><?= h($lang === 'bg' ? ($home['section_mission'] ?: t('home.mission.title')) : ($home['section_mission_en'] ?: t('home.mission.title'))) ?></span>
    <h2 data-cms-field="section_mission" data-cms-section="home" data-cms-type="text"
        data-cms-bg="<?= h($home['section_mission'] ?? '') ?>"
        data-cms-en="<?= h($home['section_mission_en'] ?? '') ?>"
    ><?= h($lang === 'bg' ? ($home['section_mission'] ?: t('home.mission.title')) : ($home['section_mission_en'] ?: t('home.mission.title'))) ?></h2>
    <p class="lead" style="margin-top:1.5rem;"
       data-cms-field="mission_text"
       data-cms-section="home"
       data-cms-type="richtext"
       data-cms-bg="<?= h($home['mission_text'] ?? '') ?>"
       data-cms-en="<?= h($home['mission_text_en'] ?? '') ?>">
      <?= $lang === 'bg' ? ($home['mission_text'] ?? '') : ($home['mission_text_en'] ?? '') ?>
    </p>
    <div class="btn-group" style="justify-content:center;margin-top:2rem;">
      <a href="/za-nas/" class="btn btn--primary"
         data-cms-field="mission_cta_primary" data-cms-section="home" data-cms-type="text"
         data-cms-bg="<?= h($home['mission_cta_primary'] ?? '') ?>"
         data-cms-en="<?= h($home['mission_cta_primary_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['mission_cta_primary'] ?: 'Разберете повече за нас') : ($home['mission_cta_primary_en'] ?: 'Learn more about us')) ?></a>
      <a href="/magazin/" class="btn btn--outline"
         data-cms-field="mission_cta_secondary" data-cms-section="home" data-cms-type="text"
         data-cms-bg="<?= h($home['mission_cta_secondary'] ?? '') ?>"
         data-cms-en="<?= h($home['mission_cta_secondary_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['mission_cta_secondary'] ?: 'Подкрепете ни') : ($home['mission_cta_secondary_en'] ?: 'Support us')) ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<!-- LATEST NEWS -->
<?php if (!empty($articles)): ?>
<section class="section">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;">
      <div>
        <span class="section-label"
              data-cms-field="section_news" data-cms-section="home" data-cms-type="text"
              data-cms-bg="<?= h($home['section_news'] ?? '') ?>"
              data-cms-en="<?= h($home['section_news_en'] ?? '') ?>"
        ><?= h($lang === 'bg' ? ($home['section_news'] ?: t('home.news.title')) : ($home['section_news_en'] ?: t('home.news.title'))) ?></span>
        <h2 data-cms-field="section_news" data-cms-section="home" data-cms-type="text"
            data-cms-bg="<?= h($home['section_news'] ?? '') ?>"
            data-cms-en="<?= h($home['section_news_en'] ?? '') ?>"
        ><?= h($lang === 'bg' ? ($home['section_news'] ?: t('home.news.title')) : ($home['section_news_en'] ?: t('home.news.title'))) ?></h2>
      </div>
      <a href="/novini/" class="btn btn--outline"
         data-cms-field="news_btn_all" data-cms-section="home" data-cms-type="text"
         data-cms-bg="<?= h($home['news_btn_all'] ?? '') ?>"
         data-cms-en="<?= h($home['news_btn_all_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['news_btn_all'] ?: t('home.news.all')) : ($home['news_btn_all_en'] ?: t('home.news.all'))) ?></a>
    </div>
    <div class="article-grid">
      <?php foreach ($articles as $article): ?>
        <article class="card">
          <?php if (!empty($article['image'])): ?>
            <a href="/novini/<?= h($article['slug']) ?>/" class="card__image" style="display:block;">
              <img src="<?= asset_url($article['image']) ?>"
                   alt="<?= h($article['title']) ?>"
                   loading="lazy">
            </a>
          <?php endif; ?>
          <div class="card__body">
            <div class="article-meta">
              <time datetime="<?= h($article['date'] ?? '') ?>">
                <?= h(format_date($article['date'] ?? '', 'bg')) ?>
              </time>
              <?php if (!empty($article['author'])): ?>
                <span><?= t('news.by') ?> <?= h($article['author']) ?></span>
              <?php endif; ?>
            </div>
            <h3 class="card__title">
              <a href="/novini/<?= h($article['slug']) ?>/"><?= h($article['title']) ?></a>
            </h3>
            <?php if (!empty($article['excerpt'])): ?>
              <p class="card__excerpt"><?= h($article['excerpt']) ?></p>
            <?php endif; ?>
            <a href="/novini/<?= h($article['slug']) ?>/"
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
<?php if (!empty($partners) || admin_logged_in()): ?>
<section class="section section--grey">
  <div class="container">
    <div class="section-header section-header--center">
      <span class="section-label"
            data-cms-field="section_partners" data-cms-section="home" data-cms-type="text"
            data-cms-bg="<?= h($home['section_partners'] ?? '') ?>"
            data-cms-en="<?= h($home['section_partners_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_partners'] ?: t('home.partners.title')) : ($home['section_partners_en'] ?: t('home.partners.title'))) ?></span>
      <h2 data-cms-field="section_partners" data-cms-section="home" data-cms-type="text"
          data-cms-bg="<?= h($home['section_partners'] ?? '') ?>"
          data-cms-en="<?= h($home['section_partners_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['section_partners'] ?: t('home.partners.title')) : ($home['section_partners_en'] ?: t('home.partners.title'))) ?></h2>
    </div>
    <div class="partners-grid">
      <?php $i = 0; foreach ($partners as $partner): ?>
        <?php $tag = !empty($partner['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?>
          class="partner-item om-removable"
          data-cms-remove-type="partner"
          data-cms-remove-id="<?= $i ?>"
          <?php if (!empty($partner['url'])): ?>
            href="<?= h($partner['url']) ?>"
            target="_blank"
            rel="noopener"
          <?php endif; ?>
        >
          <span class="om-img-wrap"
                data-cms-field="logo_<?= $i ?>"
                data-cms-section="partner">
            <img src="<?= h($partner['logo']) ?>"
                 alt="<?= h($partner['name']) ?>"
                 loading="lazy">
            <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
          </span>
        </<?= $tag ?>>
      <?php $i++; endforeach; ?>
    </div>
    <?php if (admin_logged_in()): ?>
    <button class="om-add-btn" data-cms-add="partner">+ Add partner</button>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- CTA BANNER -->
<section class="section section--teal">
  <div class="container" style="text-align:center;">
    <h2 data-cms-field="cta_heading" data-cms-section="home" data-cms-type="text"
        data-cms-bg="<?= h($home['cta_heading'] ?? '') ?>"
        data-cms-en="<?= h($home['cta_heading_en'] ?? '') ?>"
    ><?= h($lang === 'bg' ? ($home['cta_heading'] ?: 'Всяко дете заслужава шанс') : ($home['cta_heading_en'] ?: 'Every child deserves a chance')) ?></h2>
    <p class="lead" style="color:rgba(255,255,255,0.85);margin:1.5rem auto;max-width:600px;"
       data-cms-field="cta_body" data-cms-section="home" data-cms-type="text"
       data-cms-bg="<?= h($home['cta_body'] ?? '') ?>"
       data-cms-en="<?= h($home['cta_body_en'] ?? '') ?>"
    ><?= h($lang === 'bg' ? ($home['cta_body'] ?: 'С вашата подкрепа можем да достигнем до повече деца, да финансираме повече терапии и да изградим по-добро бъдеще за всяко от тях.') : ($home['cta_body_en'] ?: 'With your support we can reach more children, fund more therapies, and build a better future for each of them.')) ?></p>
    <div class="btn-group" style="justify-content:center;">
      <a href="/magazin/" class="btn btn--white"
         data-cms-field="cta_btn_donate" data-cms-section="home" data-cms-type="text"
         data-cms-bg="<?= h($home['cta_btn_donate'] ?? '') ?>"
         data-cms-en="<?= h($home['cta_btn_donate_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['cta_btn_donate'] ?: 'Дарете сега') : ($home['cta_btn_donate_en'] ?: 'Donate now')) ?></a>
      <a href="/kak-da-pomogna/" class="btn btn--outline btn--white-outline"
         style="border-color:white;color:white;"
         data-cms-field="cta_btn_help" data-cms-section="home" data-cms-type="text"
         data-cms-bg="<?= h($home['cta_btn_help'] ?? '') ?>"
         data-cms-en="<?= h($home['cta_btn_help_en'] ?? '') ?>"
      ><?= h($lang === 'bg' ? ($home['cta_btn_help'] ?: 'Как да помогна') : ($home['cta_btn_help_en'] ?: 'How to help')) ?></a>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
