<?php
/**
 * Newsletter topic opt-in — one unticked checkbox per topic, with labels the
 * admin edits in place on the page (section `newsletter` of pages.json; the
 * defaults live in strings.json). Posts `wants_news` / `wants_education` = "1"
 * when ticked; read them back with newsletter_topics_from_post().
 *
 * Optional, set before the require:
 *   $nl_on_dark  true on a dark background (the footer band) — text takes the
 *                band's own text colour instead of the page's
 *   $nl_checked  ['news' => bool, 'education' => bool] — keep ticks after a
 *                form comes back with an error
 *   $nl_heading  plain text to use as the heading instead of the editable
 *                topics heading (the checkout words its own opt-in)
 *
 * Layout-critical styles are inline on purpose: this partial sits inside
 * several different forms and must not depend on main.css being fresh.
 */
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

$_nlt_lang  = get_lang();
$_nlt_saved = newsletter_pages_section();
// An explicit colour rather than `inherit`: the global label colour rule would
// otherwise win and turn the labels dark on the coloured footer band.
$_nlt_color = !empty($nl_on_dark) ? 'var(--newsletter-fg,#fff)' : 'var(--text,#1a1916)';
$_nlt_cms   = static function (string $topic) use ($_nlt_saved): string {
    $f = newsletter_topic_field($topic);
    return 'data-cms-field="' . h($f) . '" data-cms-section="newsletter" data-cms-type="text"'
         . ' data-cms-bg="' . h((string) ($_nlt_saved[$f] ?? '')) . '"'
         . ' data-cms-en="' . h((string) ($_nlt_saved[$f . '_en'] ?? '')) . '"';
};
?>
<fieldset class="nl-topics" style="border:none;padding:0;margin:1rem 0;min-width:0;color:<?= $_nlt_color ?>;">
  <legend style="padding:0;font-size:.95rem;font-weight:600;margin-bottom:.5rem;color:<?= $_nlt_color ?>;"><?php if (isset($nl_heading) && $nl_heading !== ''): ?><?= h($nl_heading) ?><?php else: ?><span <?= $_nlt_cms('heading') ?>><?= h(newsletter_topic_label('heading', $_nlt_lang)) ?></span><?php endif; ?></legend>
  <?php foreach (newsletter_topics() as $_nlt_topic => $_nlt_col): ?>
  <label style="display:flex;align-items:center;gap:.6rem;min-height:44px;margin:0;cursor:pointer;color:<?= $_nlt_color ?>;text-transform:none;letter-spacing:normal;font-size:.95rem;font-weight:400;">
    <input type="checkbox" name="<?= h($_nlt_col) ?>" value="1"<?= !empty($nl_checked[$_nlt_topic]) ? ' checked' : '' ?>
           style="width:1.15rem;height:1.15rem;margin:0;flex-shrink:0;accent-color:var(--teal,#0387A5);">
    <span <?= $_nlt_cms($_nlt_topic) ?>><?= h(newsletter_topic_label($_nlt_topic, $_nlt_lang)) ?></span>
  </label>
  <?php endforeach; ?>
</fieldset>
<?php unset($nl_on_dark, $nl_checked, $nl_heading); ?>
