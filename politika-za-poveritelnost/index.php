<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Политика за поверителност';
$page_description = 'Политика за поверителност на ' . SITE_NAME_BG . '.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['privacy'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Правна информация</span>
    <h1>Политика за поверителност</h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <?= $content ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
