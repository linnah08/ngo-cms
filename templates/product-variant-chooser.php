<?php
/**
 * Variant block on the public product page (magazin/index.php, BG and EN).
 * Expected variables:
 *   $prod_variants  array   the product's ACTIVE variant rows, in sort order (non-empty)
 *   $lang           string  'bg' | 'en'
 *   $p_preorder_on  bool    optional — the product takes pre-orders, so sold-out
 *                           variants stay choosable and say "Pre-order"
 *
 * Several variants → a "Choose variant" group of native radio buttons (a
 * fieldset + legend, so keyboards use Tab / arrow keys and screen readers
 * announce "radio, 2 of 3, checked"). The first IN-STOCK variant starts
 * checked; sold-out ones are disabled radios that still say "Out of stock".
 * The hidden variant_id in the add-to-cart form follows the pick via
 * selectVariant() in magazin/index.php. The tile look lives in that page's
 * <style> (.pv-option / .pv-radio).
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
?>
<?php if ($_single_pv): ?>
  <?php $d = $_pv_describe($_single_pv); ?>
  <div class="pv-single" style="margin-bottom:1rem;">
    <div style="font-size:.85rem;font-weight:600;margin-bottom:.6rem;">
      <?= $lang === 'bg' ? 'Вариант' : 'Variant' ?>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;border:2px solid var(--border);border-radius:8px;">
      <?php if ($d['img']): ?>
        <img class="pv-thumb" src="<?= h($d['img']) ?>" alt="">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:.95rem;"><?= h($d['label']) ?></div>
        <?php if ($d['attrs']): ?>
          <div style="font-size:.78rem;color:var(--text-muted);"><?= $d['attrs'] ?></div>
        <?php endif; ?>
      </div>
      <?php if ($d['in_stock']): ?>
        <div style="font-size:.8rem;flex-shrink:0;color:var(--teal);">
          <?= (int)$_single_pv['stock'] . ($lang === 'bg' ? ' бр.' : ' left') ?>
        </div>
      <?php elseif ($_preorder_on): ?>
        <div style="font-size:.8rem;flex-shrink:0;color:#92400e;font-weight:600;">⏳ <?= h(product_preorder_label($lang)) ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <?php $_default_pv = product_default_variant($prod_variants, $_preorder_on); ?>
  <fieldset class="pv-choices">
    <legend><?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?></legend>
    <div style="display:flex;flex-direction:column;gap:.5rem;">
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
          <?php if ($d['img']): ?>
            <img class="pv-thumb" src="<?= h($d['img']) ?>" alt="">
          <?php else: ?>
            <span class="pv-thumb" style="display:block;background:var(--off-white);border-radius:4px;"></span>
          <?php endif; ?>
          <span style="display:block;flex:1;min-width:0;">
            <span style="display:block;font-weight:600;font-size:.95rem;">
              <?= h($d['label']) ?>
              <?php /* Visible, non-colour "selected" marker; the radio's own checked state is what a screen reader announces. */ ?>
              <span class="pv-selected" aria-hidden="true">✓ <?= $lang === 'bg' ? 'Избран' : 'Selected' ?></span>
            </span>
            <?php if ($d['attrs']): ?>
              <span style="display:block;font-size:.78rem;color:var(--text-muted);"><?= $d['attrs'] ?></span>
            <?php endif; ?>
          </span>
          <span style="font-size:.8rem;flex-shrink:0;<?= $d['in_stock'] ? 'color:var(--teal);' : ($_preorder_on ? 'color:#92400e;font-weight:600;' : 'color:#b03a2e;font-weight:600;') ?>">
            <?= $d['in_stock']
                ? (int)$pv['stock'] . ($lang === 'bg' ? ' бр.' : ' left')
                : ($_preorder_on ? '⏳ ' . h(product_preorder_label($lang)) : ($lang === 'bg' ? 'Изчерпан' : 'Out of stock')) ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
<?php endif; ?>
