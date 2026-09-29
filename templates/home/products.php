<?php /* Built-in: featured products. Vars: $s $f $sid $lang $ctx $show_admin */
if (empty($ctx['featured_products'])) return;
// templates/product-card.php expects these names.
$variant_images  = $ctx['variant_images'];
$variant_stock   = $ctx['variant_stock'];
$_show_admin_bar = $show_admin;
$card_removable  = false;
$card_redirect   = 'home';
$featured_count  = count($ctx['featured_products']);
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <?php if ($featured_count === 1): ?>
      <?php /* One product: a wide spotlight instead of a lone card in a three-column row.
               "View all products" would lead back to the same single product, so it is left out. */
      $p = $ctx['featured_products'][0];
      require ROOT_PATH . '/templates/home/product-spotlight.php'; ?>
    <?php else: ?>
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
      <div><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
      <?= home_buttons($sid, $f, $lang, [['btn btn--outline', '']]) ?>
    </div>
    <?php /* Two products sit centred at card width rather than leaving an empty third column. */ ?>
    <div class="grid grid--3" style="gap:2rem;align-items:stretch;<?= $featured_count === 2 ? 'grid-template-columns:repeat(auto-fit,minmax(240px,1fr));max-width:52rem;margin-inline:auto;' : '' ?>">
      <?php foreach ($ctx['featured_products'] as $p): ?>
        <?php require ROOT_PATH . '/templates/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
