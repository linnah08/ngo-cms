<?php
/**
 * Variant block on the public product page (magazin/index.php, BG and EN).
 * Expected variables:
 *   $prod_variants  array   the product's ACTIVE variant rows, in sort order (non-empty)
 *   $lang           string  'bg' | 'en'
 *   $p_preorder_on  bool    optional — the product takes pre-orders, so sold-out
 *                           variants stay choosable and say "Pre-order"
 *   $pv_wanted      ?string optional — the page's ?v= value (see variant_link_slug())
 *
 * Several variants → a photo grid of all of them, so a visitor (e.g. from an
 * ad) sees every variant at once; the product page puts it where the big photo
 * would be. It is a "Choose variant" group of native radio buttons (a
 * fieldset + legend, so keyboards use Tab / arrow keys and screen readers
 * announce "radio, 2 of 3, checked"). The first IN-STOCK variant starts
 * checked; sold-out ones are disabled radios that still say "Out of stock".
 * The hidden variant_id in the add-to-cart form follows the pick via
 * selectVariant() in magazin/index.php. The tile look lives in that page's
 * <style> (.pv-grid / .pv-option / .pv-radio).
 *
 * Exactly one variant → nothing to choose, so no "Choose variant" prompt: the
 * variant is shown as plain text (name, attributes, stock) so the buyer — and a
 * screen reader — still learns which one they are getting, and the add-to-cart
 * form already carries its id.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';

$_pv_describe = function (array $pv) use ($lang): array {
    $attrs = json_decode($pv['attributes'] ?? '{}', true);
    $attrs = array_filter(is_array($attrs) ? $attrs : [], fn($v) => trim((string)$v) !== '');
    return [
        'label'    => $lang === 'bg' ? $pv['label_bg'] : (($pv['label_en'] ?? '') ?: $pv['label_bg']),
        'attrs'    => implode(' · ', array_map(
            fn($k, $v) => h($k) . ': ' . h($v),
            array_keys($attrs), $attrs
        )),
        'in_stock' => (int)$pv['stock'] > 0,
        'img'      => $pv['image'] ? '/assets/images/products/' . $pv['image'] : '',
    ];
};

$_preorder_on = !empty($p_preorder_on);
$_single_pv   = product_single_variant($prod_variants);
// The options' names and details are the product's own: changed on its admin page.
$_pv_admin    = cms_admin(['Продукти', '/admin/product-edit.php?id=' . (int) ($p['id'] ?? 0)]);
?>
<?php if ($_single_pv): ?>
  <?php $d = $_pv_describe($_single_pv); ?>
  <div class="pv-single" style="margin-bottom:1rem;">
    <div style="font-size:.85rem;font-weight:600;margin-bottom:.6rem;">
      <?= te('shop.variant', 'Вариант', 'Variant', $lang) ?>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;border:2px solid var(--border);border-radius:8px;">
      <?php if ($d['img']): ?>
        <img class="pv-thumb" src="<?= h($d['img']) ?>" alt="">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:.95rem;"<?= $_pv_admin ?>><?= h($d['label']) ?></div>
        <?php if ($d['attrs']): ?>
          <div style="font-size:.78rem;color:var(--text-muted);"<?= $_pv_admin ?>><?= $d['attrs'] ?></div>
        <?php endif; ?>
      </div>
      <?php if ($d['in_stock']): ?>
        <div style="font-size:.8rem;flex-shrink:0;color:var(--teal);">
          <?= te('shop.stock_left', 'Налични: {n} бр.', '{n} left', $lang, ['n' => (int) $_single_pv['stock']]) ?>
        </div>
      <?php elseif ($_preorder_on): ?>
        <div style="font-size:.8rem;flex-shrink:0;color:#92400e;font-weight:600;">⏳ <?= te('shop.preorder.label', 'Предварителна поръчка', 'Pre-order', $lang) ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <?php $_default_pv = product_default_variant($prod_variants, $_preorder_on, $pv_wanted ?? null); ?>
  <?php $_pv_cols = count($prod_variants) === 4 ? 2 : min(count($prod_variants), 3); ?>
  <fieldset class="pv-choices">
    <legend><?= te('shop.choose_variant', 'Избери вариант', 'Choose variant', $lang) ?></legend>
    <div class="pv-grid" style="display:grid;grid-template-columns:repeat(<?= (int)$_pv_cols ?>,minmax(0,1fr));gap:.6rem;">
      <?php foreach ($prod_variants as $pv): ?>
        <?php
          $d       = $_pv_describe($pv);
          $pv_id   = (int)$pv['id'];
          $checked = $_default_pv && (int)$_default_pv['id'] === $pv_id;
          $pv_buy  = $d['in_stock'] || $_preorder_on;
        ?>
        <label class="pv-option<?= $checked ? ' active' : '' ?><?= !$pv_buy ? ' disabled' : '' ?>"
               id="pvo-<?= $pv_id ?>" for="pvr-<?= $pv_id ?>">
          <input type="radio" class="pv-radio" name="pv_choice" id="pvr-<?= $pv_id ?>" value="<?= $pv_id ?>"
                 <?= $checked ? 'checked' : '' ?>
                 <?= $pv_buy ? 'onchange="selectVariant(' . $pv_id . ')"' : 'disabled' ?>>
          <span class="pv-tile__img" style="display:block;position:relative;width:100%;aspect-ratio:1;border-radius:var(--radius);overflow:hidden;background:var(--off-white);">
            <?php if ($d['img']): ?>
              <img src="<?= h($d['img']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php endif; ?>
          </span>
          <span style="display:block;min-width:0;">
            <span style="display:block;font-weight:600;font-size:clamp(.78rem,3.4vw,.95rem);line-height:1.25;overflow-wrap:break-word;hyphens:auto;"<?= $_pv_admin ?>><?= h($d['label']) ?></span>
            <?php /* Visible, non-colour "selected" marker; the radio's own checked state is what a screen reader announces. */ ?>
            <span class="pv-selected" aria-hidden="true">✓ <?= te('shop.variant_selected', 'Избран', 'Selected', $lang) ?></span>
            <?php if ($d['attrs']): ?>
              <span style="display:block;font-size:.75rem;color:var(--text-muted);"<?= $_pv_admin ?>><?= $d['attrs'] ?></span>
            <?php endif; ?>
          </span>
          <span style="display:block;font-size:.75rem;line-height:1.2;<?= $d['in_stock'] ? 'color:#1b5e63;' : ($_preorder_on ? 'color:#92400e;font-weight:600;' : 'color:#b03a2e;font-weight:600;') ?>">
            <?= $d['in_stock']
                ? te('shop.in_stock', 'В наличност', 'In stock', $lang)
                : ($_preorder_on ? '⏳ ' . te('shop.preorder.label', 'Предварителна поръчка', 'Pre-order', $lang) : te('shop.out_of_stock', 'Изчерпан', 'Out of stock', $lang)) ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
<?php endif; ?>
