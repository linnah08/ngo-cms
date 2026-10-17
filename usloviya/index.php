<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/legal_pages.php';
$page_title       = 'Условия за ползване';
$page_description = 'Условия за ползване на онлайн магазина на ' . SITE_NAME_BG . '.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['terms'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <h1 style="margin:0;"><?= te('legal.terms.title', 'Условия за ползване', 'Terms of Use') ?></h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <div<?= legal_body_attrs('terms', $pages) ?>><?= $content ?></div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
