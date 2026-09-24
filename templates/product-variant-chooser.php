<?php
/**
 * Variant block on the public product page (magazin/index.php, BG and EN).
 * Expected variables:
 *   $prod_variants  array   the product's ACTIVE variant rows, in sort order (non-empty)
 *   $lang           string  'bg' | 'en'
 *
 * Several variants → the "Choose variant" list; the first one starts selected
 * and the hidden variant_id in the add-to-cart form follows the buyer's pick.
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

$_single_pv = product_single_variant($prod_variants);
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
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div style="margin-bottom:1rem;">
    <div style="font-size:.85rem;font-weight:600;margin-bottom:.6rem;">
      <?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:.5rem;">
      <?php foreach ($prod_variants as $pvi => $pv): ?>
        <?php $d = $_pv_describe($pv); ?>
        <div class="pv-option<?= $pvi === 0 ? ' active' : '' ?><?= !$d['in_stock'] ? ' disabled' : '' ?>"
             id="pvo-<?= (int)$pv['id'] ?>"
             <?= $d['in_stock'] ? 'onclick="selectVariant(' . (int)$pv['id'] . ')"' : '' ?>>
          <?php if ($d['img']): ?>
            <img class="pv-thumb" src="<?= h($d['img']) ?>" alt="">
          <?php else: ?>
            <div class="pv-thumb" style="background:var(--off-white);border-radius:4px;"></div>
          <?php endif; ?>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:.95rem;"><?= h($d['label']) ?></div>
            <?php if ($d['attrs']): ?>
              <div style="font-size:.78rem;color:var(--text-muted);"><?= $d['attrs'] ?></div>
            <?php endif; ?>
          </div>
          <div style="font-size:.8rem;flex-shrink:0;<?= $d['in_stock'] ? 'color:var(--teal);' : 'color:#e53935;' ?>">
            <?= $d['in_stock']
                ? (int)$pv['stock'] . ($lang === 'bg' ? ' бр.' : ' left')
                : ($lang === 'bg' ? 'Изчерпан' : 'Out of stock') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
