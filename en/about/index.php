<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$pages            = load_json(CONTENT_PATH . '/pages.json');
$about            = $pages['about'] ?? [];
$page_title       = 'About Us';
$page_description = 'Learn more about Odd Minds Foundation, our team and our story.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

$intro = $about['intro_en'] ?? $about['intro'] ?? '';
$team  = $about['team'] ?? [];
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">About us</span>
    <h1><?= h($about['title_en'] ?? 'Who are we?') ?></h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <?= $intro ?>
  </div>
</section>

<section class="section section--grey">
  <div class="container">
    <div class="section-header section-header--center">
      <span class="section-label">The team</span>
      <h2>Why we do it</h2>
    </div>
    <div class="who-are-we--list-wrapper" style="display:flex;flex-direction:column;gap:4rem;margin-top:3rem;">
      <?php foreach ($team as $member): ?>
        <div style="display:grid;grid-template-columns:200px 1fr;gap:3rem;align-items:start;">
          <div class="who-are-we--list-item--thumbnail-wrapper">
            <?php if (!empty($member['photo'])): ?>
              <img src="<?= h($member['photo']) ?>" alt="<?= h($member['name_en'] ?? $member['name']) ?>"
                   loading="lazy" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;">
            <?php endif; ?>
          </div>
          <div>
            <h3 style="color:var(--teal);"><?= h($member['name_en'] ?? $member['name']) ?></h3>
            <p style="color:var(--text-muted);font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1.5rem;">
              <?= h($member['role_en'] ?? $member['role']) ?>
            </p>
            <?= $member['bio_en'] ?? $member['bio'] ?? '' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
