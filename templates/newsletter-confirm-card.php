<?php
/**
 * "Would you like to hear from us?" card on the order and donation
 * confirmation pages: the buyer ticks topics and is signed up with the email
 * already on the order (newsletter/subscribe-order.php).
 *
 * Expects $order (the orders row) and $nl_flow ('order' | 'donation').
 *
 * Shown only to the browser session that placed the order — the endpoint
 * refuses anyone else, and a form that can only fail would be worse than
 * none. Hidden once this browser is subscribed, except to show the result of
 * the sign-up that just happened. The heading and button are edited in place
 * on the page (section `newsletter` of pages.json; defaults in strings.json).
 * Layout-critical styles are inline (public-section rule: main.css may be
 * stale-cached on the server).
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

if (newsletter_session_owns_order((string) ($order['order_number'] ?? ''))):
    $_nlc_msgs = flash_get();
    if (empty($_COOKIE['om_nl_sub']) || $_nlc_msgs):
        $_nlc_lang  = get_lang();
        $_nlc_saved = newsletter_pages_section();
        $_nlc_text  = static function (string $f, string $fallback) use ($_nlc_saved, $_nlc_lang): string {
            $v = $_nlc_saved[$_nlc_lang === 'en' ? $f . '_en' : $f] ?? '';
            return is_string($v) && trim($v) !== '' ? $v : $fallback;
        };
        $_nlc_cms = static fn(string $f): string =>
            'data-cms-field="' . h($f) . '" data-cms-section="newsletter" data-cms-type="text"'
            . ' data-cms-bg="' . h((string) ($_nlc_saved[$f] ?? '')) . '"'
            . ' data-cms-en="' . h((string) ($_nlc_saved[$f . '_en'] ?? '')) . '"';
        $_nlc_done = !empty($_COOKIE['om_nl_sub'])
            || array_filter($_nlc_msgs, fn($m) => ($m['type'] ?? '') === 'success');
?>
<section class="section" id="newsletter" style="padding-top:0;">
  <div class="container" style="max-width:560px;margin:0 auto;padding-left:16px;padding-right:16px;box-sizing:border-box;">
    <div style="background:var(--off-white,#f8f6f2);border:1px solid var(--border,#e8ddd5);border-radius:var(--radius-lg,12px);padding:1.5rem 1.25rem;">
      <?php foreach ($_nlc_msgs as $_m): $_err = ($_m['type'] ?? '') === 'error'; ?>
      <p role="<?= $_err ? 'alert' : 'status' ?>" style="margin:0 0 1rem;padding:.75rem 1rem;border-radius:6px;font-size:.95rem;text-align:center;<?= $_err
            ? 'background:#fdf0ef;border:1px solid #f0c4c0;color:#a4243d;'
            : 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' ?>"><?= h((string) ($_m['message'] ?? '')) ?></p>
      <?php endforeach; ?>
      <?php if (!$_nlc_done): ?>
      <h2 style="margin:0 0 .25rem;font-size:1.25rem;color:var(--teal,#0387A5);text-align:center;" <?= $_nlc_cms('confirm_heading') ?>><?= h($_nlc_text('confirm_heading', t_or('newsletter.confirm.heading', 'Искате ли да получавате новини от нас?', 'Would you like to hear from us?'))) ?></h2>
      <form method="POST" action="/newsletter/subscribe-order.php" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="order" value="<?= h((string) $order['order_number']) ?>">
        <input type="hidden" name="lang" value="<?= h($_nlc_lang) ?>">
        <input type="hidden" name="flow" value="<?= $nl_flow === 'donation' ? 'donation' : 'order' ?>">
        <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-topics.php'; ?>
        <div style="text-align:center;">
          <button type="submit" class="btn btn--primary" <?= $_nlc_cms('confirm_button') ?>><?= h($_nlc_text('confirm_button', t_or('newsletter.confirm.button', 'Запишете ме', 'Sign me up'))) ?></button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
    endif;
endif;
unset($nl_flow);
