<?php /* Free rich-text block. Vars: $s $f $sid $lang $ctx $show_admin */ ?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container container--narrow">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <h2 style="margin-bottom:1.5rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    <?php endif; ?>
    <div class="home-rich"<?= home_cms_attrs($sid, 'body', $f, 'richtext') ?>><?= hf($f, 'body', $lang) /* cleaned by home_clean_html() on save */ ?></div>
  </div>
</section>
