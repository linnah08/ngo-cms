<?php
/**
 * "Who should the donation go to?" — shown only when the site lists more than
 * one donation recipient (includes/site_extensions.php). Expects $lang; optional
 * $_dr_selected (a recipient key) and $_dr_id (id prefix, for two forms on a page).
 * Posts `donation_recipient`.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';
if (!donation_recipients_choosable()) return;
$_dr_selected = donation_recipient_clean($_dr_selected ?? null);
$_dr_id       = $_dr_id ?? 'donRecipient';
?>
<fieldset style="border:none;padding:0;margin:0 0 1.25rem;">
  <legend style="font-weight:600;font-size:.875rem;display:block;margin-bottom:.5rem;padding:0;">
    <?= te('donation.recipient', 'За кого е дарението', 'Who is the donation for', $lang) ?>
  </legend>
  <div style="display:grid;gap:.5rem;">
<?php foreach (donation_recipients() as $_dr_key => $_dr): ?>
<?php   $_dr_hint = $lang === 'en' ? ($_dr['hint_en'] ?: $_dr['hint_bg']) : $_dr['hint_bg']; ?>
    <label for="<?= h($_dr_id . '-' . $_dr_key) ?>" style="display:flex;gap:.6rem;align-items:flex-start;padding:.7rem .85rem;border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;min-height:44px;">
      <input type="radio" id="<?= h($_dr_id . '-' . $_dr_key) ?>" name="donation_recipient" value="<?= h($_dr_key) ?>"
             <?= $_dr_key === $_dr_selected ? 'checked' : '' ?> style="accent-color:var(--teal);margin-top:.2rem;">
      <span>
        <strong style="display:block;font-size:.92rem;"><?= h($lang === 'en' ? $_dr['label_en'] : $_dr['label_bg']) ?></strong>
<?php   if ($_dr_hint !== ''): ?>
        <span style="display:block;font-size:.85rem;color:var(--text-muted);text-transform:none;letter-spacing:normal;font-weight:400;margin-top:.15rem;"><?= h($_dr_hint) ?>
<?php     if ($_dr['hint_url'] !== ''): ?>
          <a href="<?= h($_dr['hint_url']) ?>" target="_blank" rel="noopener"><?= te('donation.recipient_more', 'Научете повече', 'Learn more', $lang) ?></a>
<?php     endif; ?>
        </span>
<?php   endif; ?>
      </span>
    </label>
<?php endforeach; ?>
  </div>
</fieldset>
