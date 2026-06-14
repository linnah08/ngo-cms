<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title       = 'Условия за ползване';
$page_description = 'Условия за ползване на онлайн магазина на Фондация Различни умове.';
$pages   = load_json(CONTENT_PATH . '/pages.json');
$content = $pages['legal']['terms'] ?? '';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label">Правна информация</span>
    <h1>Условия за ползване</h1>
  </div>
</section>

<section class="section">
  <div class="container container--narrow">
    <?= $content ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
