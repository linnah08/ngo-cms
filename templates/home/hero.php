<?php /* Built-in: hero. Vars: $s $f $sid $lang $ctx $show_admin */
$img = home_valid_image_path((string) ($f['image'] ?? '')) ? $f['image'] : '';
?>
<section class="hero">
  <div class="container">
    <div class="hero__text">
      <span class="section-label"><?= h($lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN) ?></span>
      <h1<?= home_cms_attrs($sid, 'title', $f) ?>><?= h(hf($f, 'title', $lang)) ?></h1>
      <?php if (hf($f, 'text', $lang) !== '' || $show_admin): ?>
      <p<?= home_cms_attrs($sid, 'text', $f) ?>><?= h(hf($f, 'text', $lang)) ?></p>
      <?php endif; ?>
      <?= home_buttons($sid, $f, $lang, [['btn btn--primary', ''], ['btn btn--outline', '']]) ?>
    </div>
    <?php if ($img !== ''): ?>
    <div class="hero__image">
      <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?>>
        <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" width="560" height="420">
        <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</section>
