<?php
/**
 * Returning-visitor notice: "You left 2 items in your cart." and/or "You started supporting …",
 * one line each, each with its own link. Included by templates/header.php right after
 * </header>. Shown only on the shop list, a product page and the cart page, and only
 * when the session cart is empty, the admin switched the notice on and this browser has
 * a saved cart or an unfinished pledge. On any other page nothing is read at all (not
 * even the setting); with the switch off there is no database query for saved carts.
 * Never breaks the page.
 */
try {
    $_scn_lang = (function_exists('get_lang') ? get_lang() : 'bg') === 'en' ? 'en' : 'bg';
    $_scn_path = rtrim((string)url_request_path($_SERVER['REQUEST_URI'] ?? '/'), '/') . '/';
    $_scn_shop = shop_path('shop', $_scn_lang);
    $_scn_show = $_scn_path === $_scn_shop
        || $_scn_path === shop_path('cart', $_scn_lang)
        || (str_starts_with($_scn_path, $_scn_shop) && substr_count(substr($_scn_path, strlen($_scn_shop)), '/') === 1);

    if ($_scn_show && function_exists('cart_count') && cart_count() === 0) {
        require_once __DIR__ . '/../../includes/saved_carts.php';
        if (saved_cart_notice_enabled()) {
            require_once __DIR__ . '/../../admin/includes/db.php';
            $_scn = saved_cart_for_browser(get_pdo());
            $_scn_lines = [];   // what => [message, link text]
            if ($_scn['cart'] !== null) {
                $n = 0;
                foreach ($_scn['cart']['items'] as $it) $n += max(0, (int)($it['quantity'] ?? 0));
                if ($n > 0) {
                    $_scn_lines['cart'] = [
                        $n === 1
                            ? t_or('cart.notice.one', 'Оставихте 1 продукт в количката.', 'You left 1 item in your cart.', $_scn_lang)
                            : t_or('cart.notice.many', 'Оставихте {n} продукта в количката.', 'You left {n} items in your cart.', $_scn_lang, ['n' => $n]),
                        t_or('cart.notice.restore', 'Върнете ги', 'Bring them back', $_scn_lang),
                    ];
                }
            }
            if ($_scn['pledge'] !== null) {
                // The campaign's name is a setting; English falls back to a generic wording, never to the Bulgarian title.
                $name = trim(setting_get($_scn_lang === 'en' ? 'campaign_title_en' : 'campaign_title', ''));
                if ($name === '') $name = t_or('cart.notice.campaign', 'кампанията', 'the campaign', $_scn_lang);
                $_scn_lines['pledge'] = [
                    t_or('cart.notice.pledge', 'Започнахте да подкрепите „{campaign}“.', 'You started supporting {campaign}.', $_scn_lang, ['campaign' => $name]),
                    t_or('cart.notice.finish', 'Довършете', 'Finish', $_scn_lang),
                ];
            }
            if ($_scn_lines):
?>
<div data-saved-cart-notice role="status" style="background:#fff8e6;border-bottom:1px solid #e0c36a;color:#3d2f00;">
  <div style="max-width:1100px;margin:0 auto;padding:.5rem 1rem;display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;">
    <div style="flex:1 1 16rem;">
<?php foreach ($_scn_lines as $_scn_what => [$_scn_msg, $_scn_cta]): ?>
      <p style="margin:0;font-size:.95rem;line-height:1.5;">
        <?= h($_scn_msg) ?>
        <a href="<?= h('/cart/restore.php?what=' . $_scn_what . '&lang=' . $_scn_lang) ?>" style="display:inline-block;min-height:44px;line-height:44px;margin-left:.5rem;font-weight:600;color:#3d2f00;text-decoration:underline;"><?= h($_scn_cta) ?></a>
      </p>
<?php endforeach; ?>
    </div>
    <form method="post" action="/cart/dismiss-notice.php" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="return" value="<?= h($_scn_path) ?>">
<?php foreach (array_keys($_scn_lines) as $_scn_what): ?>
      <input type="hidden" name="what[]" value="<?= h($_scn_what) ?>">
<?php endforeach; ?>
      <button type="submit" aria-label="<?= h(t_or('cart.notice.dismiss', 'Скрий съобщението', 'Dismiss this message', $_scn_lang)) ?>" style="min-width:44px;min-height:44px;background:transparent;border:1px solid #3d2f00;border-radius:6px;color:#3d2f00;font-size:1.25rem;line-height:1;cursor:pointer;"><span aria-hidden="true">&times;</span></button>
    </form>
  </div>
</div>
<?php
            endif;
        }
    }
} catch (Throwable $e) {
    error_log('Saved cart notice failed: ' . $e->getMessage());
}
