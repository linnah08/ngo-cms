<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/about.php';
$pages            = load_json(CONTENT_PATH . '/pages.json');
$about            = $pages['about'] ?? [];
$team             = about_team($about);   // optional: a page that only tells the story has no team section
$page_title       = 'About Us';
$page_description = $team
    ? 'Learn more about ' . SITE_NAME_EN . ', our team and our story.'
    : 'Learn more about ' . SITE_NAME_EN . ' and our story.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

// An English text not written yet falls back to the Bulgarian one.
$title = ($about['title_en'] ?? '') ?: 'Who are we?';
$intro = ($about['intro_en'] ?? '') ?: ($about['intro'] ?? '');
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">About us</span>
    <h1<?= about_cms_attrs('title', $about['title'] ?? '', $about['title_en'] ?? '') ?>><?= h($title) ?></h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div<?= about_cms_attrs('intro', $about['intro'] ?? '', $about['intro_en'] ?? '', 'richtext') ?>><?= $intro ?></div>
  </div>
</section>

<?php if ($team): ?>
<section class="section section--grey">
  <div class="container">
    <div class="section-header section-header--center">
      <span class="section-label">The team</span>
      <h2>Why we do it</h2>
    </div>
    <div class="who-are-we--list-wrapper" style="display:flex;flex-direction:column;gap:4rem;margin-top:3rem;">
      <?php foreach ($team as $i => $member):
        $name = ($member['name_en'] ?? '') ?: ($member['name'] ?? '');
      ?>
        <div style="display:grid;grid-template-columns:200px 1fr;gap:3rem;align-items:start;">
          <div class="who-are-we--list-item--thumbnail-wrapper">
            <?php if (!empty($member['photo'])): ?>
              <img src="<?= h($member['photo']) ?>" alt="<?= h($name) ?>"
                   loading="lazy" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;">
            <?php endif; ?>
          </div>
          <div>
            <h3 style="color:var(--teal);"><?= h($name) ?></h3>
            <p style="color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1.5rem;">
              <?= h(($member['role_en'] ?? '') ?: ($member['role'] ?? '')) ?>
            </p>
            <div<?= about_cms_attrs('bio_' . (int) $i, $member['bio'] ?? '', $member['bio_en'] ?? '', 'richtext') ?>><?= ($member['bio_en'] ?? '') ?: ($member['bio'] ?? '') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
