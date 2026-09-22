<?php /* CTA banner block. Vars: $s $f $sid $lang $ctx $show_admin */
$bg   = home_bg_key($f, 'teal', HOME_BACKGROUNDS_CTA);
$dark = $bg === 'teal';
$text = hf($f, 'text', $lang);
?>
<section class="section<?= home_bg_class($f, 'teal', HOME_BACKGROUNDS_CTA) ?>">
  <div class="container" style="text-align:center;">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    <?php endif; ?>
    <?php if ($text !== '' || $show_admin): ?>
    <p class="lead" style="<?= $dark ? 'color:#fff;' : '' ?>margin:1.5rem auto;max-width:600px;"<?= home_cms_attrs($sid, 'text', $f) ?>><?= h($text) ?></p>
    <?php endif; ?>
    <?= home_buttons($sid, $f, $lang, $dark
        ? [['btn btn--white', ''], ['btn btn--outline btn--white-outline', 'border-color:white;color:white;']]
        : [['btn btn--primary', ''], ['btn btn--outline', '']], 'justify-content:center;') ?>
  </div>
</section>
