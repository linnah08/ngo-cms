<?php /* Built-in: mission. Vars: $s $f $sid $lang $ctx $show_admin */
$img = home_valid_image_path((string) ($f['image'] ?? '')) ? $f['image'] : '';
$title_html = '<h2' . home_cms_attrs($sid, 'title', $f) . '>' . h(hf($f, 'title', $lang)) . '</h2>';
$text_html  = '<div class="lead home-rich" style="margin-top:1.5rem;"' . home_cms_attrs($sid, 'text', $f, 'richtext') . '>' . hf($f, 'text', $lang) . '</div>';
$btn_styles = [['btn btn--primary', ''], ['btn btn--outline', '']];
$fp = home_frame_parts($sid, $f, true, 900, $show_admin);
?>
<section class="section<?= home_bg_class($f, 'grey') ?>">
  <?php if ($img !== ''): ?>
  <?= $fp['css'] ?>
  <div class="container">
    <div class="mission-split"<?= $fp['grid'] ?>>
      <div class="mission-split__image"<?= $fp['box'] ?>>
        <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?><?= $fp['admin'] ?><?= $fp['wrap'] ?>>
          <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" loading="lazy"<?= $fp['img'] !== '' ? ' style="' . $fp['img'] . '"' : '' ?>>
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <div class="mission-split__text">
        <?= $title_html ?><?= $text_html ?>
        <?= home_buttons($sid, $f, $lang, $btn_styles, 'margin-top:2rem;') ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="container container--narrow" style="text-align:center;">
    <?= $title_html ?><?= $text_html ?>
    <?= home_buttons($sid, $f, $lang, $btn_styles, 'justify-content:center;margin-top:2rem;') ?>
  </div>
  <?php endif; ?>
</section>
