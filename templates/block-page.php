<?php
/**
 * The body of a page built from blocks: a created page (page.php) or a built-in
 * one such as За нас (za-nas/index.php, en/about/index.php). Required after
 * templates/header.php.
 *
 * Expects:
 *   $_bp_page   the page (cpage_normalize() shape)
 *   $lang       'bg' | 'en'
 *   $_bp_label  small label above the title ('За нас'), or '' for none
 *   $_bp_draft  true: show the draft bar with the Publish button (Admin role only)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';

$_bp_label = (string) ($_bp_label ?? '');
$_bp_title = cpage_title($_bp_page, $lang);

if (!empty($_bp_draft)): ?>
<div role="status" style="background:#fef3c7;border-bottom:3px solid #b45309;color:#78350f;">
  <div style="max-width:1200px;margin:0 auto;padding:.85rem 1rem;display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;align-items:center;justify-content:space-between;">
    <p style="margin:0;font-weight:700;font-size:1rem;line-height:1.4;">
      <span aria-hidden="true">✎ </span>Чернова — не се вижда от посетителите
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;">
      <a href="/admin/page-edit.php?id=<?= h($_bp_page['id']) ?>"
         style="display:inline-flex;align-items:center;min-height:44px;padding:0 1rem;border:2px solid #78350f;border-radius:6px;color:#78350f;font-weight:600;text-decoration:none;background:#fff;">Редактирай</a>
      <form method="POST" action="/admin/created-pages.php" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="publish">
        <input type="hidden" name="id" value="<?= h($_bp_page['id']) ?>">
        <input type="hidden" name="back" value="view">
        <button type="submit" style="min-height:44px;padding:0 1.1rem;border:2px solid #166534;border-radius:6px;background:#166534;color:#fff;font-weight:700;font-size:1rem;cursor:pointer;">Публикувай</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<section class="section section--grey page-hero" style="padding-top:3rem;padding-bottom:1.5rem;">
  <div class="container">
    <?php if ($_bp_label !== ''): ?><span class="section-label"><?= h($_bp_label) ?></span><?php endif; ?>
    <h1 data-cms-section="pagetitle:<?= h($_bp_page['id']) ?>" data-cms-field="title" data-cms-type="text"<?php
      // The stored BG/EN titles ride along only for an admin, so saving one language never blanks the other.
      if ($GLOBALS['_show_admin_bar'] ?? false): ?> data-cms-bg="<?= h($_bp_page['title_bg']) ?>" data-cms-en="<?= h($_bp_page['title_en']) ?>"<?php endif;
      // Under a label the title keeps its usual spacing (as the old За нас page had it).
      if ($_bp_label === ''): ?> style="margin:0;"<?php endif;
    ?>><?= h($_bp_title) ?></h1>
  </div>
</section>

<?php
home_target_page($_bp_page['id']);
$_bp_doc = home_load()['doc'];

if (!$_bp_doc['sections'] && !empty($_bp_draft)): ?>
<section class="section">
  <div class="container container--narrow">
    <p style="color:var(--text-muted);">Страницата още няма съдържание. Добавете секции от „Редактирай“.</p>
  </div>
</section>
<?php endif;

home_render($_bp_doc, $lang);
home_target_reset();
