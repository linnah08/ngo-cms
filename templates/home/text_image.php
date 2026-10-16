<?php /* Text + image block. Vars: $s $f $sid $lang $ctx $show_admin */
$img  = (string) ($f['image'] ?? '');
$img  = home_valid_image_path($img) ? $img : '';
$side = ($f['image_side'] ?? 'left') === 'right' ? 'right' : 'left';
$fp   = home_frame_parts($sid, $f, $side === 'left', null, $show_admin);
$cols = $img === '' ? '1fr' : ($fp['cols'] !== '' ? $fp['cols'] : '1fr 1fr');
?>
<section class="section<?= home_bg_class($f) ?>">
  <?= $img !== '' ? $fp['css'] : '' ?>
  <div class="container">
    <div class="home-ti"<?= $fp['grid'] ?> style="display:grid;grid-template-columns:<?= $cols ?>;gap:2.5rem;align-items:center;">
      <?php if ($img !== ''): ?>
      <div<?= $fp['box'] ?> style="order:<?= $side === 'right' ? 2 : 0 ?>;border-radius:8px;overflow:hidden;">
        <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?><?= $fp['admin'] ?><?= $fp['wrap'] ?>>
          <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" loading="lazy"
               style="<?= $fp['img'] !== '' ? $fp['img'] : 'width:100%;height:auto;display:block;' ?>">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <div style="order:1;">
        <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
        <h2 style="margin-bottom:1.25rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
        <?php endif; ?>
        <div class="home-rich"<?= home_cms_attrs($sid, 'body', $f, 'richtext') ?>><?= hf($f, 'body', $lang) ?></div>
        <?= home_buttons($sid, $f, $lang, [['btn btn--primary', '']], 'margin-top:1.75rem;') ?>
      </div>
    </div>
  </div>
</section>
