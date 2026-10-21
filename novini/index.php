<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';

// Route: /novini/SLUG/ → single article; /novini/ → listing
$raw  = basename(str_replace(['..', "\0"], '', rawurldecode(explode('/', trim(strtok($_SERVER['REQUEST_URI'], '?'), '/'))[1] ?? '')));
$slug = $raw !== '' ? $raw : null;

// ── SINGLE ARTICLE ────────────────────────────────────────────────────────────
if ($slug) {
    start_session();
    $article = get_article($slug, 'bg');

    if (!$article) {
        http_response_code(404);
        $page_title = 'Страницата не е намерена';
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
        echo '<section class="section"><div class="container"><h1>404 — Страницата не е намерена</h1><p><a href="/novini/">Обратно към новини</a></p></div></section>';
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
        exit;
    }

    $page_title       = $article['title'] ?? '';
    $page_description = $article['excerpt'] ?? '';

    // ── SEO: OG image, article schema, BG/EN alternates ──
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/seo.php';
    $seo_type = 'article';
    $og_image = $article['image'] ?? null;
    $seo_alternates = ['bg' => '/novini/' . rawurlencode($article['slug']) . '/'];
    if (!empty($article['slug_en']) && get_article($article['slug_en'], 'en')) {
        $seo_alternates['en'] = '/en/news/' . rawurlencode($article['slug_en']) . '/';
    }
    $seo_jsonld = [[
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => $article['title'] ?? '',
        'description'   => $article['excerpt'] ?? '',
        'image'         => seo_abs_url($article['image'] ?: '/assets/images/logo.png'),
        'datePublished' => !empty($article['date']) ? date('c', strtotime($article['date'])) : null,
        'author'        => ['@type' => 'Organization', 'name' => SITE_NAME_BG],
        'publisher'     => seo_org_jsonld(),
    ]];
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<?php // Only this language's file is saved: the EN article is a separate file with its own text. ?>
<article
  data-cms-article-slug-bg="<?= h($article['slug'] ?? '') ?>"
  data-cms-article-slug-en="">
  <section class="section">
    <div class="container container--narrow">

      <div style="margin-bottom:2rem;">
        <a href="/novini/" style="font-size:0.85rem;color:var(--text-muted);"><?= te('news.back', '← Обратно към новини', '← Back to news') ?></a>
      </div>

      <div class="article-meta" style="margin-bottom:1.5rem;">
        <?php if (!empty($article['date'])): ?>
          <time datetime="<?= h($article['date']) ?>"<?= cms_admin('articles') ?>>
            <?= h(format_date($article['date'], 'bg')) ?>
          </time>
        <?php endif; ?>
        <?php if (!empty($article['author'])): ?>
          <span><?= te('news.by', 'от', 'by') ?> <span<?= cms_admin('articles') ?>><?= h($article['author']) ?></span></span>
        <?php endif; ?>
      </div>

      <h1 style="margin-bottom:2rem;"
          data-cms-field="title"
          data-cms-section="article"
          data-cms-type="text"
          data-cms-bg="<?= h($article['title'] ?? '') ?>"
          data-cms-en=""><?= h($article['title']) ?></h1>

      <?php $lang = 'bg'; ?>
      <?php if (count(article_photos($article)) <= 1 && !empty($article['image'])): ?>
        <span class="om-img-wrap" data-cms-field="image" data-cms-section="article">
          <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-carousel.php'; ?>
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      <?php else: ?>
        <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-carousel.php'; ?>
      <?php endif; ?>

      <div class="article-content"
           style="line-height:1.9;font-size:1.05rem;"
           data-cms-field="content"
           data-cms-section="article"
           data-cms-type="richtext"
           data-cms-bg="<?= h($article['content'] ?? '') ?>"
           data-cms-en="">
        <?= $article['content'] ?? '' ?>
      </div>

      <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-downloads.php'; ?>

      <?php if (!empty($article['sources'])): ?>
        <div style="margin-top:3rem;padding-top:2rem;border-top:1px solid var(--border);">
          <h4 style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:1rem;"><?= te('news.sources', 'Източници', 'Sources') ?></h4>
          <ol style="font-size:0.85rem;color:var(--text-muted);padding-left:1.5rem;"<?= cms_admin('articles') ?>>
            <?php foreach ($article['sources'] as $source): ?>
              <li style="margin-bottom:0.5rem;"><?= h($source) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      <?php endif; ?>

      <!-- Share this story -->
      <div style="margin-top:2.5rem;padding-top:1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
        <span style="font-size:0.85rem;color:var(--text-muted);font-weight:600;"><?= te('news.share', 'Сподели тази история:', 'Share this story:') ?></span>
        <?php
          $share_url   = rtrim(SITE_URL, '/') . '/novini/' . rawurlencode($article['slug'] ?? '') . '/';
          $share_title = $article['title'] ?? '';
          require $_SERVER['DOCUMENT_ROOT'] . '/templates/share-links.php';
        ?>
      </div>

    </div>
  </section>
</article>

<!-- ── Conversion bridge ──────────────────────────────────────────────────── -->
<section style="background:#f8fafb;border-top:1px solid var(--border);padding:3.5rem 1rem;">
  <div style="max-width:900px;margin:0 auto;text-align:center;">
    <p style="font-size:.8rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin:0 0 .6rem;font-weight:600;"><?= te('news.bridge.label', 'Подкрепете ни', 'Support us') ?></p>
    <h2 style="font-size:1.6rem;margin:0 0 .75rem;line-height:1.3;"><?= te('news.bridge.heading', 'Истории като тази са възможни с вашата помощ.', 'Stories like this are possible with your help.') ?></h2>
    <p style="color:var(--text-muted);font-size:1rem;margin:0 0 2.5rem;max-width:560px;margin-left:auto;margin-right:auto;"><?= te('news.bridge.text', 'Всеки принос — голям или малък — ни помага да продължим работата си.', 'Every contribution — large or small — helps us continue our work.') ?></p>
    <div style="display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:center;align-items:stretch;">

      <?php if (feature_enabled('donations')): ?>
      <!-- Donate -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">❤️</div>
        <h3 style="margin:0;font-size:1.05rem;"><?= te('news.bridge.donate_title', 'Направи дарение', 'Make a donation') ?></h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;"><?= te('news.bridge.donate_text', 'Подкрепете пряко работата ни.', 'Support our work directly.') ?></p>
        <a href="/donation/" class="btn btn--primary" style="margin-top:auto;width:100%;text-align:center;justify-content:center;"><?= te('news.bridge.donate_btn', 'Дари сега', 'Donate now') ?></a>
      </div>
      <?php endif; ?>

      <?php if (module_enabled_with_needs('shop')): ?>
      <!-- Shop -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">🛍️</div>
        <h3 style="margin:0;font-size:1.05rem;"><?= te('news.bridge.shop_title', 'Подкрепи мисията ни', 'Support our mission') ?></h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;"><?= te('news.bridge.shop_text', 'Купете продукт от нашия магазин и помогнете за финансирането на програмите ни.', 'Buy from our shop and help fund the programmes that make a difference.') ?></p>
        <a href="/magazin/" class="btn btn--outline" style="margin-top:auto;width:100%;text-align:center;justify-content:center;"><?= te('news.bridge.shop_btn', 'Към магазина', 'Visit the shop') ?></a>
      </div>
      <?php endif; ?>

      <!-- Partner -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">🤝</div>
        <h3 style="margin:0;font-size:1.05rem;"><?= te('news.bridge.partner_title', 'Станете партньор', 'Become a partner') ?></h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;"><?= te('news.bridge.partner_text', 'Организация, компания или институция? Нека работим заедно.', 'An organisation, company or institution? Let\'s work together.') ?></p>
        <a href="/kontakti/" class="btn btn--outline" style="margin-top:auto;width:100%;text-align:center;justify-content:center;"><?= te('news.bridge.partner_btn', 'Свържете се с нас', 'Get in touch') ?></a>
      </div>

    </div>
  </div>
</section>

<?php
    // Comments are part of „Коментари и отзиви“ (Admin → Модули).
    if (module_enabled_with_needs('comments_reviews')) require $_SERVER['DOCUMENT_ROOT'] . '/templates/comments.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
    exit;
}

// ── LISTING ───────────────────────────────────────────────────────────────────
$page_title       = 'Новини';
$page_description = 'Последни новини от ' . SITE_NAME_BG . '.';
$articles = get_articles('bg');
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1 style="margin:0;"><?= te('news.heading', 'Новини и истории', 'News & stories') ?></h1>
    <p class="lead" style="margin-top:1rem;"><?= te('news.lead', 'Новини и истории от нашата работа.', 'News and stories from our work.') ?></p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?php if (empty($articles)): ?>
      <p style="color:var(--text-muted);"><?= te('news.empty', 'Все още няма публикувани новини.', 'No published articles yet.') ?></p>
    <?php else: ?>
      <div class="article-grid">
        <?php foreach ($articles as $article): ?>
          <?php $article_url = '/novini/' . h($article['slug']) . '/'; ?>
          <article class="card om-removable"
                   data-cms-remove-type="article"
                   data-cms-remove-id="<?= h($article['slug'] ?? '') ?>"
                   onclick="window.location='<?= $article_url ?>'"
                   style="cursor:pointer;">
            <?php if (!empty($article['image'])): ?>
              <div class="card__image">
                <a href="<?= $article_url ?>" tabindex="-1" aria-hidden="true">
                  <span class="om-img-wrap"
                        data-cms-field="image"
                        data-cms-section="article"
                        data-cms-id="<?= h($article['slug'] ?? '') ?>"
                        data-cms-remove-id="<?= h($article['slug'] ?? '') ?>">
                    <img src="<?= asset_url($article['image']) ?>" alt="<?= h($article['title']) ?>" loading="lazy">
                    <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
                  </span>
                </a>
              </div>
            <?php endif; ?>
            <div class="card__body">
              <div class="article-meta">
                <time datetime="<?= h($article['date'] ?? '') ?>"<?= cms_admin('articles') ?>>
                  <?= h(format_date($article['date'] ?? '', 'bg')) ?>
                </time>
                <?php if (!empty($article['author'])): ?>
                  <span><?= te('news.by', 'от', 'by') ?> <span<?= cms_admin('articles') ?>><?= h($article['author']) ?></span></span>
                <?php endif; ?>
              </div>
              <h2 class="card__title"
                  style="font-size:1.15rem;"
                  data-cms-field="title"
                  data-cms-section="article"
                  data-cms-type="text"
                  data-cms-id="<?= h($article['slug'] ?? '') ?>"
                  data-cms-bg="<?= h($article['title'] ?? '') ?>"
                  data-cms-en="">
                <a href="<?= $article_url ?>"><?= h($article['title']) ?></a>
              </h2>
              <?php if (!empty($article['excerpt'])): ?>
                <p class="card__excerpt"
                   data-cms-field="excerpt"
                   data-cms-section="article"
                   data-cms-type="text"
                   data-cms-id="<?= h($article['slug'] ?? '') ?>"
                   data-cms-bg="<?= h($article['excerpt'] ?? '') ?>"
                   data-cms-en=""><?= h($article['excerpt']) ?></p>
              <?php endif; ?>
              <a href="<?= $article_url ?>" class="btn btn--outline" style="margin-top:1rem;font-size:0.8rem;">
                <?= te('news.read_more', 'Прочети повече', 'Read more') ?>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if (admin_logged_in()): ?>
      <a href="/admin/article-edit.php?new=1" class="om-add-btn" style="display:none">+ Add article</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
