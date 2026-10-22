<?php /* Built-in: featured products. Vars: $s $f $sid $lang $ctx $show_admin */
if (empty($ctx['featured_products'])) return;
// templates/product-card.php expects these names.
$variant_images  = $ctx['variant_images'];
$variant_stock   = $ctx['variant_stock'];
$variant_single  = $ctx['variant_single'] ?? [];
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
    <?php /* Cards keep card width and sit centred: a wide container fits more columns than
             there are featured products, and they must not huddle left of the empty ones. */ ?>
    <div class="grid grid--3" style="gap:2rem;align-items:stretch;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),22rem));justify-content:center;">
      <?php foreach ($ctx['featured_products'] as $p): ?>
        <?php require ROOT_PATH . '/templates/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
