<?php
/**
 * Flash messages on the single-product page.
 *
 * After a buyer adds a product to the cart (cart/add.php with redirect=product)
 * they land back on the product page with a success flash. A bare "Added to
 * cart!" leaves them stranded, so the success message also offers the next
 * step: view the cart, or go straight to checkout.
 *
 * Accessibility:
 *   - success sits in role="status" (polite), errors in role="alert";
 *   - the success notice takes focus on load (tabindex="-1"), because content
 *     already in the HTML is not announced by a live region on its own — this
 *     way keyboard and screen-reader users land on it and hear it;
 *   - a real close button (44x44, aria-label) dismisses it and hands focus back
 *     to the "Add to cart" button, or to the page's <h1> when there is none;
 *   - no animation, so nothing to switch off for prefers-reduced-motion.
 *
 * Copy: t_or() — content/{lang}/strings.json, with built-in BG/EN defaults.
 * Links: shop_path() — /en/cart/ and /en/checkout/ for English buyers.
 *
 * Inline styles only (see CLAUDE.md — main.css may be stale-cached).
 *
 * Only a real add gets the cart buttons. Other success messages can land here
 * too — the newsletter sign-up in the footer redirects back to the page it was
 * sent from — and "Завърши поръчката" under "Записахте се за бюлетина" makes no
 * sense. cart/add.php marks a successful add with ?gads=atc on the redirect.
 *
 * Expects: $flash (array of ['type' => ..., 'message' => ...]);
 *          optional $just_added (bool, defaults to reading ?gads=atc).
 */
$flash = $flash ?? [];
if (!$flash) return;
$_pf_added       = $just_added ?? (($_GET['gads'] ?? '') === 'atc');
$_pf_has_success = false;

?>
<?php foreach ($flash as $f):
    $_pf_ok      = ($f['type'] ?? '') === 'success';
    $_pf_success = $_pf_ok && $_pf_added;   // the "added to cart" notice, with its next steps
    if ($_pf_success) $_pf_has_success = true;
?>
  <div <?= $_pf_success ? 'role="status" aria-live="polite" tabindex="-1" data-cart-notice' : ($_pf_ok ? 'role="status"' : 'role="alert"') ?>
       style="position:relative;padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;outline-offset:3px;
       <?= $_pf_success ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;padding-right:3.25rem;' : ($_pf_ok ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;') ?>">
    <?php if ($_pf_success): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <span style="font-weight:600;"><span aria-hidden="true">✓ </span><?= h($f['message']) ?></span>
        <span style="display:flex;gap:.6rem;flex-wrap:wrap;">
          <a href="<?= h(shop_path('cart')) ?>" class="btn btn--outline"
             style="display:inline-flex;align-items:center;min-height:44px;padding:.45rem 1.1rem;font-size:.9rem;">
            <?= h(t_or('shop.added.view_cart', 'Виж количката', 'View cart')) ?>
          </a>
          <a href="<?= h(shop_path('checkout')) ?>" class="btn btn--primary"
             style="display:inline-flex;align-items:center;min-height:44px;padding:.45rem 1.1rem;font-size:.9rem;">
            <?= h(t_or('shop.added.checkout', 'Завърши поръчката', 'Checkout now')) ?> <span aria-hidden="true">&nbsp;→</span>
          </a>
        </span>
      </div>
      <button type="button" data-cart-notice-close aria-label="<?= h(t_or('shop.added.dismiss', 'Затвори съобщението', 'Dismiss message')) ?>"
              style="position:absolute;top:.25rem;right:.25rem;width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:transparent;border:0;border-radius:6px;color:inherit;font-size:1.4rem;line-height:1;cursor:pointer;">
        <span aria-hidden="true">×</span>
      </button>
    <?php elseif ($_pf_ok): ?>
      <span aria-hidden="true">✓ </span><?= h($f['message']) ?>
    <?php else: ?>
      <?= h($f['message']) ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($_pf_has_success): ?>
<script>
(function () {
  var notice = document.querySelector('[data-cart-notice]');
  if (!notice) return;
  try { notice.focus(); } catch (e) {}
  var close = notice.querySelector('[data-cart-notice-close]');
  if (!close) return;
  close.addEventListener('click', function () {
    var back = document.getElementById('addToCartBtn') || document.querySelector('h1');
    notice.parentNode.removeChild(notice);
    if (back) {
      if (!back.hasAttribute('tabindex') && back.tagName === 'H1') back.setAttribute('tabindex', '-1');
      back.focus();
    }
  });
  notice.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close.click();
  });
})();
</script>
<?php endif; ?>
