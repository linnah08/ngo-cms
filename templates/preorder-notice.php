<?php
/**
 * One box above "checkout" / "place order" when the cart has pre-order lines,
 * so nobody misses that part of the order ships later.
 * Expected: $lang 'bg' | 'en'.
 */
?>
<div class="preorder-notice" role="note"
     style="padding:.85rem 1.1rem;background:#fef3c7;border:1px solid #d97706;color:#78350f;border-radius:6px;margin-bottom:1.25rem;line-height:1.5;font-size:.9rem;">
  <strong><span aria-hidden="true">⏳ </span><?= h(t_or('shop.preorder.notice_title', 'Някои продукти са с предварителна поръчка', 'Some items are pre-orders', $lang)) ?></strong>
  <span style="display:block;"><?= h(t_or('shop.preorder.notice_body',
      'В момента са изчерпани. Ще ги изпратим по-късно — срокът е написан до всеки от тях.',
      'They are sold out right now. We will send them later — the expected time is shown next to each one.', $lang)) ?></span>
</div>
