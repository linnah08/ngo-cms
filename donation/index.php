<?php
/**
 * The donation page — /donation/ (BG) and /en/donation/ (a thin wrapper).
 * The one place a donor gives: an editable title and intro, then the shared
 * donation form (templates/donation-form.php).
 *
 * Title and intro are edited on the page itself (inline editing) or in
 * Admin → Съдържание → Страница за дарение. They are stored where the shop's
 * donation block kept them (pages.json: donation.title / shop.donation_text_*),
 * so a site's existing text carries over to this page unchanged.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('donations');

$lang  = get_lang();
$pages = load_json(CONTENT_PATH . '/pages.json');
$_dp   = donation_page_content($pages, $lang);

$page_title       = $_dp['title'];
$page_description = t_or('donation.page.description', 'Направете дарение в подкрепа на {site}.',
    'Make a donation in support of {site}.', $lang, ['site' => $lang === 'en' ? SITE_NAME_EN : SITE_NAME_BG]);

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container" style="max-width:760px;">
    <span class="section-label"><?= h(t_or('donation.label', 'Дарение', 'Donation', $lang)) ?></span>
    <h1 data-cms-field="title"
        data-cms-section="donation"
        data-cms-type="text"
        data-cms-bg="<?= h($pages['donation']['title'] ?? '') ?>"
        data-cms-en="<?= h($pages['donation']['title_en'] ?? '') ?>"><?= h($_dp['title']) ?></h1>
    <div class="rich-text lead" style="margin-top:1rem;"
         data-cms-field="donation_text"
         data-cms-section="shop"
         data-cms-type="richtext"
         data-cms-bg="<?= h($pages['shop']['donation_text_bg'] ?? '') ?>"
         data-cms-en="<?= h($pages['shop']['donation_text_en'] ?? '') ?>">
      <?= $_dp['intro_html'] ?>
    </div>
  </div>
</section>

<section id="donation" class="section section--teal" aria-label="<?= h(t_or('donation.form_label', 'Форма за дарение', 'Donation form', $lang)) ?>">
  <div class="container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/donation-form.php'; ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
