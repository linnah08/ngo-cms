<?php
/**
 * Newsletter topic opt-in — one unticked checkbox per topic. The topics are
 * managed in Admin → Бюлетин → Теми; their names (and the heading above them)
 * can also be edited in place on the page. Posts the ticked topics' ids as
 * nl_topics[]; read them back with newsletter_signup_topics() /
 * newsletter_topics_from_post().
 *
 * Checkboxes only make sense with a choice to make: with fewer than two topics
 * nothing is shown, and submitting the form itself is the sign-up — except
 * where ticking is the consent (the checkout), which then gets one opt-in box
 * of its own (nl_optin).
 *
 * Optional, set before the require:
 *   $nl_on_dark  true on a dark background (the footer band) — text takes the
 *                band's own text colour instead of the page's
 *   $nl_checked  ids of the topics to show ticked — keeps ticks after a form
 *                comes back with an error
 *   $nl_heading  plain text to use as the heading instead of the editable
 *                topics heading (the checkout words its own opt-in); it also
 *                labels the single opt-in box
 *   $nl_optin    set (to whether the box is ticked) on a form where ticking is
 *                the consent, so a site with fewer than two topics still gets
 *                a box to tick
 *
 * Layout-critical styles are inline on purpose: this partial sits inside
 * several different forms and must not depend on main.css being fresh.
 */
if (!defined('SITE_NAME_BG')) require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

$_nlt_lang   = get_lang();
$_nlt_saved  = newsletter_pages_section();
$_nlt_topics = newsletter_topic_list();
$_nlt_ticked = array_map('intval', (array) ($nl_checked ?? []));
// An explicit colour rather than `inherit`: the global label colour rule would
// otherwise win and turn the labels dark on the coloured footer band.
$_nlt_color = !empty($nl_on_dark) ? 'var(--newsletter-fg,#fff)' : 'var(--text,#1a1916)';
$_nlt_label_style = 'display:flex;align-items:center;gap:.6rem;min-height:44px;margin:0;cursor:pointer;color:' . $_nlt_color
                  . ';text-transform:none;letter-spacing:normal;font-size:.95rem;font-weight:400;';
$_nlt_box_style   = 'width:1.15rem;height:1.15rem;margin:0;flex-shrink:0;accent-color:var(--teal,#0387A5);';
// In edit mode, a way to the screen where topics are added and removed.
$_nlt_manage = admin_logged_in() && admin_is_admin();
?>
<?php if (count($_nlt_topics) >= 2): ?>
<fieldset class="nl-topics" style="border:none;padding:0;margin:1rem 0;min-width:0;color:<?= $_nlt_color ?>;">
  <legend style="padding:0;font-size:.95rem;font-weight:600;margin-bottom:.5rem;color:<?= $_nlt_color ?>;"><?php if (isset($nl_heading) && $nl_heading !== ''): ?><?= h($nl_heading) ?><?php else: ?><span data-cms-field="topics_heading" data-cms-section="newsletter" data-cms-type="text" data-cms-bg="<?= h((string) ($_nlt_saved['topics_heading'] ?? '')) ?>" data-cms-en="<?= h((string) ($_nlt_saved['topics_heading_en'] ?? '')) ?>"><?= h(newsletter_topics_heading($_nlt_lang)) ?></span><?php endif; ?></legend>
  <?php foreach ($_nlt_topics as $_nlt_t): $_nlt_id = (int) $_nlt_t['id']; ?>
  <label style="<?= $_nlt_label_style ?>">
    <input type="checkbox" name="nl_topics[]" value="<?= $_nlt_id ?>"<?= in_array($_nlt_id, $_nlt_ticked, true) ? ' checked' : '' ?>
           style="<?= $_nlt_box_style ?>">
    <span data-cms-field="topic_<?= $_nlt_id ?>_name" data-cms-section="newsletter" data-cms-type="text"
          data-cms-bg="<?= h(newsletter_topic_name($_nlt_t, 'bg')) ?>" data-cms-en="<?= h(trim((string) $_nlt_t['name_en']) !== '' || !empty($_nlt_t['legacy_key']) ? newsletter_topic_name($_nlt_t, 'en') : '') ?>"><?= h(newsletter_topic_name($_nlt_t, $_nlt_lang)) ?></span>
  </label>
  <?php endforeach; ?>
</fieldset>
<?php elseif (isset($nl_optin)): ?>
<div class="nl-topics" style="margin:1rem 0;">
  <label style="<?= $_nlt_label_style ?>">
    <input type="checkbox" name="nl_optin" value="1"<?= $nl_optin ? ' checked' : '' ?> style="<?= $_nlt_box_style ?>">
    <span><?= h(isset($nl_heading) && $nl_heading !== '' ? $nl_heading : newsletter_topics_heading($_nlt_lang)) ?></span>
  </label>
</div>
<?php endif; ?>
<?php if ($_nlt_manage): ?>
<?php // Shown only in edit mode; the rule travels with the link so it never
      // depends on inline-cms.css having loaded. ?>
<style>.nl-topics-manage{display:none}body.om-edit-mode .nl-topics-manage{display:inline-flex}</style>
<a href="/admin/newsletter-topics.php" class="nl-topics-manage"
   style="align-items:center;min-height:44px;box-sizing:border-box;margin:.5rem 0;padding:.5rem 1rem;border:2px dashed currentColor;border-radius:8px;color:<?= $_nlt_color ?>;font-size:.9rem;text-decoration:none;">⚙ Управление на темите на бюлетина</a>
<?php endif; ?>
<?php unset($nl_on_dark, $nl_checked, $nl_heading, $nl_optin); ?>
