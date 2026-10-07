<?php
/**
 * "Pre-order — ships later: <admin's note>" under a cart / checkout line.
 * Expected variables:
 *   $p     array   the product row (preorder_note_bg / _en)
 *   $lang  string  'bg' | 'en'
 * Text plus an icon, never colour alone.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
?>
<div class="preorder-line" style="font-size:.8rem;color:#92400e;margin-top:.3rem;line-height:1.45;">
  <strong><span aria-hidden="true">⏳ </span><?= h(product_preorder_label($lang)) ?></strong>
  <span style="display:block;"><?= h(product_preorder_text($p, $lang)) ?></span>
</div>
