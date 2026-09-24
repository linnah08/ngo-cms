<?php
/**
 * Sold-out state on the public product page (magazin/index.php, BG and EN),
 * shown in place of the add-to-cart form.
 * Expected variables:
 *   $is_variant     bool    the product is a variant-type product
 *   $prod_variants  array   its ACTIVE variant rows (empty for other types)
 *   $lang           string  'bg' | 'en'
 *
 * The reason is spelled out in words (not just a red box), and the "Add to
 * cart" button stays where the buyer expects it, disabled and pointing at that
 * reason via aria-describedby — so it reads "Add to cart, dimmed, All variants
 * are sold out" rather than the button silently vanishing.
 */
$_oos_reason = ($is_variant && count($prod_variants) > 1)
    ? ($lang === 'bg' ? 'Всички варианти са изчерпани.' : 'All variants are sold out.')
    : ($lang === 'bg' ? 'Продуктът в момента е изчерпан.' : 'This product is currently sold out.');
?>
<div class="product-oos" style="display:flex;flex-direction:column;gap:.75rem;align-items:flex-start;">
  <div id="oosReason" style="align-self:stretch;padding:.75rem 1.25rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#b03a2e;border-radius:var(--radius);">
    <strong><span aria-hidden="true">⚠ </span><?= $lang === 'bg' ? 'Изчерпан' : 'Out of stock' ?></strong>
    <span style="display:block;font-size:.9rem;"><?= h($_oos_reason) ?></span>
  </div>
  <button type="button" class="btn btn--primary" disabled aria-describedby="oosReason"
          style="font-size:1rem;padding:.8rem 2rem;opacity:.55;cursor:not-allowed;">
    <?= $lang === 'bg' ? 'Добави в количката' : 'Add to cart' ?>
  </button>
</div>
