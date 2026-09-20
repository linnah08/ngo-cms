<?php
/**
 * Shop product card — include once per product inside a foreach loop.
 * Expected variables:
 *   $p                  array  product row (from `products`)
 *   $lang               string 'bg' | 'en'
 *   $variant_images     array  product_id => image filename (variant-type products)
 *   $variant_stock      array  product_id => total stock across active variants
 *   $_show_admin_bar    bool   set by templates/header.php
 *   $card_removable     bool   optional, default false (opt-in). The shop listing sets
 *                              this true to render the CMS "×" remove control — but that
 *                              control deactivates the product store-wide (see
 *                              admin/inline-remove.php's 'product' case), so any other
 *                              page including this card (e.g. the homepage featured
 *                              picks) must leave it off to avoid that surprising side effect.
 *   $card_redirect      string optional, default 'shop'. Where cart/add.php sends the
 *                              shopper back to on a failed add (out of stock, invalid
 *                              variant, etc.) — pass 'home' on pages other than the shop
 *                              listing so an error doesn't silently teleport them there.
 */
$name           = h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg']));
$desc           = $lang === 'bg' ? $p['description_bg'] : ($p['description_en'] ?: $p['description_bg']);
$prod_url       = ($lang === 'bg' ? '/magazin/' : '/en/shop/') . h($p['slug']) . '/';
$card_img       = $p['type'] === 'variant'
    ? ($variant_images[$p['id']] ?? '')
    : $p['image'];
$card_removable = $card_removable ?? false;
$card_redirect  = $card_redirect ?? 'shop';
?>
<div class="card<?= $card_removable ? ' om-removable' : '' ?>" id="<?= h($p['slug']) ?>"
     <?php if ($card_removable): ?>
     data-cms-remove-type="product"
     data-cms-remove-id="<?= $p['id'] ?>"
     <?php endif; ?>
     data-cms-id="<?= $p['id'] ?>"
     onclick="if(!event.target.closest('a,button,form'))window.location='<?= $prod_url ?>'"
     style="display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);cursor:pointer;">
  <!-- image -->
  <a href="<?= $prod_url ?>" tabindex="-1" aria-hidden="true" style="display:block;aspect-ratio:1;overflow:hidden;background:var(--off-white);">
    <span class="om-img-wrap"
          data-cms-field="image"
          data-cms-section="product"
          data-cms-id="<?= $p['id'] ?>"
          style="display:block;width:100%;height:100%;position:relative;">
    <?php if ($card_img): ?>
      <img src="/assets/images/products/<?= h($card_img) ?>"
           alt="<?= $name ?>"
           loading="lazy"
           style="width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s;"
           onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform=''">
    <?php else: ?>
      <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:3rem;">🖼</div>
    <?php endif; ?>
    <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
    </span>
  </a>

  <!-- body -->
  <div style="padding:1.25rem 1.25rem 1.5rem;display:flex;flex-direction:column;flex:1;">
    <h3 style="font-size:1rem;font-weight:700;margin:0 0 .6rem;line-height:1.35;">
      <a href="<?= $prod_url ?>" style="color:inherit;text-decoration:none;"
         data-cms-field="name"
         data-cms-section="product"
         data-cms-type="text"
         data-cms-bg="<?= h($p['name_bg'] ?? '') ?>"
         data-cms-en="<?= h($p['name_en'] ?? '') ?>"
         data-cms-id="<?= $p['id'] ?>"><?= $name ?></a>
    </h3>

    <?php if ($desc): ?>
    <p style="font-size:.875rem;color:var(--text-muted);line-height:1.65;margin:0 0 1rem;
              display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"
       data-cms-field="description"
       data-cms-section="product"
       data-cms-type="richtext"
       data-cms-bg="<?= h($p['description_bg'] ?? '') ?>"
       data-cms-en="<?= h($p['description_en'] ?? '') ?>"
       data-cms-id="<?= $p['id'] ?>">
      <?= h(html_entity_decode(strip_tags($desc), ENT_QUOTES, 'UTF-8')) ?>
    </p>
    <?php endif; ?>

    <!-- stock signal -->
    <?php
      if ($p['type'] === 'variant') {
          $in_stock_count = $variant_stock[$p['id']] ?? 0;
      } else {
          $in_stock_count = (int)$p['stock'];
      }
    ?>
    <?php if ($in_stock_count > 0 && $in_stock_count <= 5): ?>
      <p style="font-size:.78rem;color:#b45309;font-weight:600;margin:0 0 .5rem;">
        ⚠ <?= $lang === 'bg' ? "Само {$in_stock_count} бр. налични" : "Only {$in_stock_count} left" ?>
      </p>
    <?php elseif ($in_stock_count === 0 && $p['type'] !== 'variant'): ?>
      <?php /* out-of-stock handled by button below */ ?>
    <?php endif; ?>

    <!-- price -->
    <div style="margin-top:auto;margin-bottom:1rem;">
      <?= price_html((float)$p['price_eur']) ?>
    </div>

    <!-- actions -->
    <div style="display:flex;flex-direction:column;gap:.5rem;">
      <?php if ($p['type'] === 'variant'): ?>
        <a href="<?= $prod_url ?>" class="btn btn--primary"
           style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;text-align:center;box-sizing:border-box;">
          <?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?>
        </a>
      <?php elseif ((int)$p['stock'] > 0): ?>
        <form method="POST" action="/cart/add.php">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="redirect" value="<?= h($card_redirect) ?>">
          <input type="hidden" name="_lang" value="<?= h($lang) ?>">
          <button type="submit" class="btn btn--primary"
                  style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;">
            <?= $lang === 'bg' ? 'Добави в количката' : 'Add to cart' ?>
          </button>
        </form>
      <?php else: ?>
        <div style="width:100%;text-align:center;padding:.65rem 1rem;border-radius:var(--radius);
                    background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;font-size:.875rem;font-weight:600;">
          <?= $lang === 'bg' ? 'Изчерпан' : 'Out of stock' ?>
        </div>
      <?php endif; ?>
      <a href="<?= $prod_url ?>" class="btn btn--outline"
         style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;text-align:center;box-sizing:border-box;">
        <?= $lang === 'bg' ? 'Виж детайли' : 'View details' ?>
      </a>
    </div>
  </div>
</div>
