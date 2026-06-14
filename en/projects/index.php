<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$pages            = load_json(CONTENT_PATH . '/pages.json');
$projects         = $pages['projects'] ?? [];
$page_title       = 'Projects';
$page_description = 'Active projects of Odd Minds Foundation.';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Projects</span>
    <h1>Our projects</h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div style="display:flex;flex-direction:column;gap:0;">
      <?php foreach ($projects as $i => $project): ?>
        <div style="padding:3rem 0;<?= $i > 0 ? 'border-top:1px solid var(--border);' : '' ?>">
          <h2 style="margin-bottom:1.5rem;"><?= h($project['title_en'] ?? $project['title']) ?></h2>
          <div style="max-width:800px;"><?= $project['text_en'] ?? $project['text'] ?></div>
          <?php if (!empty($project['images'])): ?>
            <div style="display:flex;gap:1rem;margin-top:1.5rem;flex-wrap:wrap;">
              <?php foreach ($project['images'] as $img): ?>
                <img src="<?= h($img) ?>" loading="lazy"
                     style="height:220px;width:auto;border-radius:8px;object-fit:cover;">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
