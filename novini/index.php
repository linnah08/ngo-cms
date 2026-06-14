<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

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

<article
  data-cms-article-slug-bg="<?= h($article['slug'] ?? '') ?>"
  data-cms-article-slug-en="<?= h($article['slug'] ?? '') ?>">
  <section class="section">
    <div class="container container--narrow">

      <div style="margin-bottom:2rem;">
        <a href="/novini/" style="font-size:0.85rem;color:var(--text-muted);">← Обратно към новини</a>
      </div>

      <div class="article-meta" style="margin-bottom:1.5rem;">
        <?php if (!empty($article['date'])): ?>
          <time datetime="<?= h($article['date']) ?>">
            <?= h(format_date($article['date'], 'bg')) ?>
          </time>
        <?php endif; ?>
        <?php if (!empty($article['author'])): ?>
          <span>от <?= h($article['author']) ?></span>
        <?php endif; ?>
      </div>

      <h1 style="margin-bottom:2rem;"
          data-cms-field="title"
          data-cms-section="article"
          data-cms-type="text"
          data-cms-bg="<?= h($article['title'] ?? '') ?>"
          data-cms-en="<?= h($article['title'] ?? '') ?>"><?= h($article['title']) ?></h1>

      <?php if (!empty($article['image'])): ?>
        <span class="om-img-wrap" data-cms-field="image" data-cms-section="article">
          <img src="<?= asset_url($article['image']) ?>"
               alt="<?= h($article['title']) ?>"
               style="width:100%;height:auto;border-radius:var(--radius-lg);margin-bottom:2rem;display:block;">
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      <?php endif; ?>

      <div class="article-content"
           style="line-height:1.9;font-size:1.05rem;"
           data-cms-field="content"
           data-cms-section="article"
           data-cms-type="richtext"
           data-cms-bg="<?= h($article['content'] ?? '') ?>"
           data-cms-en="<?= h($article['content'] ?? '') ?>">
        <?= $article['content'] ?? '' ?>
      </div>

      <?php if (!empty($article['sources'])): ?>
        <div style="margin-top:3rem;padding-top:2rem;border-top:1px solid var(--border);">
          <h4 style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:1rem;">Източници</h4>
          <ol style="font-size:0.85rem;color:var(--text-muted);padding-left:1.5rem;">
            <?php foreach ($article['sources'] as $source): ?>
              <li style="margin-bottom:0.5rem;"><?= h($source) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      <?php endif; ?>

      <!-- Share this story -->
      <div style="margin-top:2.5rem;padding-top:1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
        <span style="font-size:0.85rem;color:var(--text-muted);font-weight:600;">Сподели тази история:</span>
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
    <p style="font-size:.8rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin:0 0 .6rem;font-weight:600;">Подкрепете ни</p>
    <h2 style="font-size:1.6rem;margin:0 0 .75rem;line-height:1.3;">Истории като тази са възможни с вашата помощ.</h2>
    <p style="color:var(--text-muted);font-size:1rem;margin:0 0 2.5rem;max-width:560px;margin-left:auto;margin-right:auto;">Всеки принос — голям или малък — помага на деца с различия в развитието да получат подкрепата, от която се нуждаят.</p>
    <div style="display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:center;align-items:stretch;">

      <!-- Donate -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">❤️</div>
        <h3 style="margin:0;font-size:1.05rem;">Направи дарение</h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;">Подкрепете директно децата и програмите на фондацията.</p>
        <a href="/magazin/#donation" class="btn btn--primary" style="margin-top:auto;width:100%;text-align:center;justify-content:center;">Дари сега</a>
      </div>

      <!-- Shop -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">🛍️</div>
        <h3 style="margin:0;font-size:1.05rem;">Подкрепи мисията ни</h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;">Купете продукт от нашия магазин и помогнете за финансирането на програмите ни.</p>
        <a href="/magazin/" class="btn btn--outline" style="margin-top:auto;width:100%;text-align:center;justify-content:center;">Към магазина</a>
      </div>

      <!-- Partner -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem 1.75rem;flex:1;min-width:220px;max-width:280px;display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <div style="font-size:2rem;">🤝</div>
        <h3 style="margin:0;font-size:1.05rem;">Станете партньор</h3>
        <p style="margin:0;font-size:.88rem;color:var(--text-muted);text-align:center;">Организация, компания или институция? Нека работим заедно.</p>
        <a href="/kontakti/" class="btn btn--outline" style="margin-top:auto;width:100%;text-align:center;justify-content:center;">Свържете се с нас</a>
      </div>

    </div>
  </div>
</section>

<?php
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/comments.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
    exit;
}

// ── LISTING ───────────────────────────────────────────────────────────────────
$page_title       = 'Новини';
$page_description = 'Последни новини от Фондация Различни умове.';
$articles = get_articles('bg');
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Новини</span>
    <h1>Новини и истории</h1>
    <p class="lead" style="margin-top:1rem;">Следете работата ни и историите на децата, които подкрепяме.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?php if (empty($articles)): ?>
      <p style="color:var(--text-muted);">Все още няма публикувани новини.</p>
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
                        data-cms-remove-id="<?= h($article['slug'] ?? '') ?>">
                    <img src="<?= asset_url($article['image']) ?>" alt="<?= h($article['title']) ?>" loading="lazy">
                    <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
                  </span>
                </a>
              </div>
            <?php endif; ?>
            <div class="card__body">
              <div class="article-meta">
                <time datetime="<?= h($article['date'] ?? '') ?>">
                  <?= h(format_date($article['date'] ?? '', 'bg')) ?>
                </time>
                <?php if (!empty($article['author'])): ?>
                  <span>от <?= h($article['author']) ?></span>
                <?php endif; ?>
              </div>
              <h2 class="card__title"
                  style="font-size:1.15rem;"
                  data-cms-field="title"
                  data-cms-section="article"
                  data-cms-type="text"
                  data-cms-bg="<?= h($article['title'] ?? '') ?>"
                  data-cms-en="<?= h($article['title_en'] ?? $article['title'] ?? '') ?>">
                <a href="<?= $article_url ?>"><?= h($article['title']) ?></a>
              </h2>
              <?php if (!empty($article['excerpt'])): ?>
                <p class="card__excerpt"
                   data-cms-field="excerpt"
                   data-cms-section="article"
                   data-cms-type="text"
                   data-cms-bg="<?= h($article['excerpt'] ?? '') ?>"
                   data-cms-en="<?= h($article['excerpt_en'] ?? $article['excerpt'] ?? '') ?>"><?= h($article['excerpt']) ?></p>
              <?php endif; ?>
              <a href="<?= $article_url ?>" class="btn btn--outline" style="margin-top:1rem;font-size:0.8rem;">
                Прочети повече
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
