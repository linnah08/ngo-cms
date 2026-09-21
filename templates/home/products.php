<?php /* Built-in: featured products. Vars: $s $f $sid $lang $ctx $show_admin */
if (empty($ctx['featured_products'])) return;
// templates/product-card.php expects these names.
$variant_images  = $ctx['variant_images'];
$variant_stock   = $ctx['variant_stock'];
$_show_admin_bar = $show_admin;
$card_removable  = false;
$card_redirect   = 'home';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
      <div><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
      <?= home_buttons($sid, $f, $lang, [['btn btn--outline', '']]) ?>
    </div>
    <div class="grid grid--3" style="gap:2rem;align-items:stretch;">
      <?php foreach ($ctx['featured_products'] as $p): ?>
        <?php require ROOT_PATH . '/templates/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
