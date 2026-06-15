<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Cookie Policy';
$page_description = 'Information about cookies used by the ' . SITE_NAME_EN . ' website.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['cookie_policy_en'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Legal</span>
    <h1>Cookie Policy</h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <?= $content ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
