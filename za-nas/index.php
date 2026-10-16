<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/about.php';
$pages = load_json(CONTENT_PATH . '/pages.json');
$about = $pages['about'] ?? [];
$team  = about_team($about);   // optional: a page that only tells the story has no team section
$page_title = ($about['title'] ?? '') ?: 'Кои сме ние?';
$page_description = $team
    ? 'Научете повече за ' . SITE_NAME_BG . ', нашия екип и нашата история.'
    : 'Научете повече за ' . SITE_NAME_BG . ' и нашата история.';
$page_head_extra = '<style>@media(max-width:640px){.who-are-we--list-wrapper>div{grid-template-columns:1fr!important;gap:1.5rem!important;}.who-are-we--list-item--thumbnail-wrapper{max-width:160px;}}</style>';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">За нас</span>
    <h1<?= about_cms_attrs('title', $about['title'] ?? '', $about['title_en'] ?? '') ?>><?= h($page_title) ?></h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div<?= about_cms_attrs('intro', $about['intro'] ?? '', $about['intro_en'] ?? '', 'richtext') ?>><?= $about['intro'] ?? '' ?></div>
  </div>
</section>

<?php if ($team): ?>
<section class="section section--grey">
  <div class="container">
    <div class="section-header section-header--center">
      <span class="section-label">Екипът</span>
      <h2>Защо го правим?</h2>
    </div>
    <div class="who-are-we--list-wrapper" style="display:flex;flex-direction:column;gap:4rem;margin-top:3rem;">
      <?php foreach ($team as $i => $member): ?>
        <div style="display:grid;grid-template-columns:200px 1fr;gap:3rem;align-items:start;">
          <div class="who-are-we--list-item--thumbnail-wrapper">
            <?php if (!empty($member['photo'])): ?>
              <img src="<?= h($member['photo']) ?>" alt="<?= h($member['name'] ?? '') ?>"
                   loading="lazy" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;">
            <?php else: ?>
              <div aria-hidden="true" style="width:100%;aspect-ratio:3/4;background:var(--teal-light);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--teal);font-size:3rem;">👤</div>
            <?php endif; ?>
          </div>
          <div>
            <h3 style="color:var(--teal);"><?= h($member['name'] ?? '') ?></h3>
            <p style="color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1.5rem;"><?= h($member['role'] ?? '') ?></p>
            <div<?= about_cms_attrs('bio_' . (int) $i, $member['bio'] ?? '', $member['bio_en'] ?? '', 'richtext') ?>><?= $member['bio'] ?? '' ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
