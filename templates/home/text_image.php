<?php /* Text + image block. Vars: $s $f $sid $lang $ctx $show_admin */
$photos = home_ti_photos($f);
$side   = ($f['image_side'] ?? 'left') === 'right' ? 'right' : 'left';
// One photo can be framed on the page ("Нагласи снимката"). With 2–4 photos only the
// framing's column width applies; every photo shows whole, one under the other.
$one    = count($photos) === 1;
$fp     = home_frame_parts($sid, $f, $side === 'left', null, $show_admin && $one);
$cols   = !$photos ? '1fr' : ($fp['cols'] !== '' ? $fp['cols'] : '1fr 1fr');
?>
<section class="section<?= home_bg_class($f) ?>">
  <?= $one ? $fp['css'] : '' ?>
  <div class="container">
    <div class="home-ti"<?= $fp['grid'] ?> style="display:grid;grid-template-columns:<?= $cols ?>;gap:2.5rem;align-items:<?= count($photos) >= 2 ? 'start' : 'center' ?>;">
      <?php if ($photos): ?>
      <div style="order:<?= $side === 'right' ? 2 : 0 ?>;display:flex;flex-direction:column;gap:1.25rem;min-width:0;">
        <?php foreach ($photos as $p): ?>
        <div<?= $one ? $fp['box'] : '' ?> style="border-radius:8px;overflow:hidden;">
          <span class="om-img-wrap"<?= home_img_attrs($sid, 'photos.' . $p['id'] . '.image') ?><?= $one ? $fp['admin'] . $fp['wrap'] : '' ?>>
            <img src="<?= asset_url($p['image']) ?>" alt="<?= h(hf($p, 'image_alt', $lang)) ?>" loading="lazy"
                 style="<?= $one && $fp['img'] !== '' ? $fp['img'] : 'width:100%;height:auto;display:block;border-radius:8px;' ?>">
            <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="home-ti__text" style="order:1;">
        <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
        <h2 style="margin-bottom:1.25rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
        <?php endif; ?>
        <div class="home-rich"<?= home_cms_attrs($sid, 'body', $f, 'richtext') ?>><?= hf($f, 'body', $lang) ?></div>
        <?= home_buttons($sid, $f, $lang, [['btn btn--primary', '']], 'margin-top:1.75rem;') ?>
      </div>
    </div>
  </div>
</section>
