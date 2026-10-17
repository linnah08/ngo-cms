<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Cookie Policy';
$page_description = 'Information about cookies used by the ' . SITE_NAME_EN . ' website.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['cookie_policy_en'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1 style="margin:0;">Cookie Policy</h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <?= $content ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
