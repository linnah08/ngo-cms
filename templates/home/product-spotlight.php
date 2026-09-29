<?php
/**
 * Homepage spotlight for a single featured product — included by
 * templates/home/products.php in place of the card grid when exactly one
 * product is featured. Large image on one side, the story and the actions on
 * the other; stacks on phones.
 *
 * Styling lives in home_shared_head(). A site's theme stylesheet (e.g.
 * assets/css/theme-lafetki.css) restyles it through the --spot-* variables or
 * with `.home-spot .home-spot__<part>` rules. Keep styles out of inline
 * attributes here, or themes can no longer override them.
 *
 * Vars: $p $sid $f $lang $variant_images $variant_stock $card_redirect $show_admin
 */
$name     = h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg']));
$desc     = $lang === 'bg' ? $p['description_bg'] : ($p['description_en'] ?: $p['description_bg']);
$prod_url = ($lang === 'bg' ? '/magazin/' : '/en/shop/') . h($p['slug']) . '/';
$img      = $p['type'] === 'variant' ? ($variant_images[$p['id']] ?? '') : $p['image'];
$stock    = $p['type'] === 'variant' ? (int) ($variant_stock[$p['id']] ?? 0) : (int) $p['stock'];
// Rich text → plain paragraphs: block ends become blank lines, which pre-line keeps.
$desc_text = html_entity_decode(
    strip_tags(preg_replace('#</(p|div|li|h[1-6])>|<br\s*/?>#i', "\n\n", (string) $desc)),
    ENT_QUOTES, 'UTF-8'
);
$desc_text = trim(preg_replace("/[ \t]*\n\s*\n\s*/", "\n\n", str_replace("\u{00A0}", ' ', $desc_text)));
?>
<div class="home-spot">
  <a href="<?= $prod_url ?>" class="home-spot__media" tabindex="-1" aria-hidden="true">
    <span class="om-img-wrap" data-cms-field="image" data-cms-section="product" data-cms-id="<?= (int) $p['id'] ?>"
          style="display:block;width:100%;height:100%;position:relative;">
    <?php if ($img): ?>
      <img src="/assets/images/products/<?= h($img) ?>" alt="<?= $name ?>" loading="lazy">
    <?php else: ?>
      <span style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:4rem;">🖼</span>
    <?php endif; ?>
    <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
    </span>
  </a>

  <div class="home-spot__body">
    <h2 class="section-label home-spot__label"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    <h3 class="home-spot__title">
      <a href="<?= $prod_url ?>"
         data-cms-field="name" data-cms-section="product" data-cms-type="text" data-cms-id="<?= (int) $p['id'] ?>"
         <?php if ($show_admin): ?>data-cms-bg="<?= h($p['name_bg'] ?? '') ?>" data-cms-en="<?= h($p['name_en'] ?? '') ?>"<?php endif; ?>><?= $name ?></a>
    </h3>

    <?php if ($desc_text !== ''): ?>
    <p class="home-spot__desc"
       data-cms-field="description" data-cms-section="product" data-cms-type="richtext" data-cms-id="<?= (int) $p['id'] ?>"
       <?php if ($show_admin): ?>data-cms-bg="<?= h($p['description_bg'] ?? '') ?>" data-cms-en="<?= h($p['description_en'] ?? '') ?>"<?php endif; ?>><?= h($desc_text) ?></p>
    <?php endif; ?>

    <div class="home-spot__price"><?= price_html((float) $p['price_eur']) ?></div>
    <?php if ($stock > 0 && $stock <= 5): ?>
      <p class="home-spot__low">⚠ <?= $lang === 'bg' ? "Само {$stock} бр. налични" : "Only {$stock} left" ?></p>
    <?php endif; ?>

    <div class="home-spot__actions">
      <?php if ($p['type'] === 'variant'): ?>
        <a href="<?= $prod_url ?>" class="btn btn--primary"><?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?></a>
      <?php elseif ($stock > 0): ?>
        <form method="POST" action="/cart/add.php">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="redirect" value="<?= h($card_redirect) ?>">
          <input type="hidden" name="_lang" value="<?= h($lang) ?>">
          <button type="submit" class="btn btn--primary"><?= $lang === 'bg' ? 'Добави в количката' : 'Add to cart' ?></button>
        </form>
      <?php else: ?>
        <p class="home-spot__soldout"><?= $lang === 'bg' ? 'Изчерпан' : 'Out of stock' ?></p>
      <?php endif; ?>
      <a href="<?= $prod_url ?>" class="btn btn--outline"><?= $lang === 'bg' ? 'Виж детайли' : 'View details' ?></a>
    </div>
  </div>
</div>
