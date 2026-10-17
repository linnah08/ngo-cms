<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/legal_pages.php';
$page_title       = 'Правна информация';
$page_description = 'Правна информация на ' . SITE_NAME_BG . '.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['legal_info'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1><?= te('legal.legal_info.title', 'Правна информация', 'Legal Information') ?></h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <div<?= legal_body_attrs('legal_info', $pages) ?>><?= $content ?></div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
