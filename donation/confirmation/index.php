<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
start_session();

if (!feature_enabled('donations')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/errors/404.php';
    exit;
}

$lang         = get_lang();
$order_number = trim($_GET['order'] ?? '');

if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? AND type = ?');
$stmt->execute([$order_number, 'donation']);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /');
    exit;
}

// Show the confirmation in the language the donation was made in. Payment
// returns and older links may land on the BG path, so move an English
// donation to /en/ — header, footer and <html lang> follow the URL.
$order_lang = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
if ($order_lang !== $lang) {
    header('Location: ' . donation_path('confirmation', $order_lang) . '?order=' . urlencode($order['order_number']));
    exit;
}

$amount = (float)$order['total_eur'];
$paid   = $order['payment_status'] === 'paid';
$_amt   = '<strong>' . h(number_format($amount, 2)) . ' €</strong>';

$page_title = t_or('donation.confirm.title', 'Благодарим за дарението!', 'Thank you for your donation!');

$page_head_extra = '<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    event: "purchase",
    ecommerce: {
        transaction_id: ' . json_encode($order_number) . ',
        value: ' . $amount . ',
        currency: "EUR"
    }
});
</script>';

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--teal">
  <div class="container" style="text-align:center;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1rem;" aria-hidden="true">💛</div>
    <h1 style="color:#fff;"><?= h(t_or('donation.confirm.thanks', 'Благодарим от сърце!', 'Thank you from the heart!')) ?></h1>
    <p style="color:rgba(255,255,255,.85);font-size:1.1rem;">
      <?= h(t_or('donation.confirm.lead', 'Вашето дарение ще помогне на деца, на които е нужна подкрепа.', 'Your donation will help children who need support.')) ?>
    </p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:560px;">

    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem;margin-bottom:2rem;text-align:center;">
      <?php if ($paid): ?>
        <div style="font-size:2.5rem;margin-bottom:.75rem;" aria-hidden="true">✅</div>
        <h2 style="color:var(--teal);margin-top:0;">
          <?= h(t_or('donation.confirm.paid', 'Плащането е успешно!', 'Payment confirmed!')) ?>
        </h2>
        <p style="color:var(--text-muted);">
          <?= strtr(h(t_or('donation.confirm.paid_text', 'Дарението ви от {amount} е прието. Изпратихме потвърждение на имейла ви.', 'Your donation of {amount} has been received. We have sent a confirmation to your email.')), ['{amount}' => $_amt]) ?>
        </p>
      <?php else: ?>
        <div style="font-size:2.5rem;margin-bottom:.75rem;" aria-hidden="true">⏳</div>
        <h2 style="color:var(--teal);margin-top:0;">
          <?= h(t_or('donation.confirm.pending', 'Дарението е регистрирано', 'Donation registered')) ?>
        </h2>
        <p style="color:var(--text-muted);">
          <?= strtr(h(t_or('donation.confirm.pending_text', 'Дарението ви от {amount} е регистрирано и се обработва.', 'Your donation of {amount} has been registered and is being processed.')), ['{amount}' => $_amt]) ?>
        </p>
      <?php endif; ?>

      <p style="font-size:.85rem;color:var(--text-muted);margin-top:1.5rem;">
        <?= h(t_or('donation.confirm.reference', 'Номер', 'Reference')) ?>: <strong><?= h($order_number) ?></strong>
      </p>
    </div>

    <?php if ($order['donation_message']): ?>
    <div style="padding:1rem 1.25rem;background:var(--warm-grey);border-radius:var(--radius-lg);margin-bottom:2rem;">
      <strong style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">
        <?= h(t_or('donation.confirm.message', 'Вашето послание', 'Your message')) ?>
      </strong>
      <p style="margin:.5rem 0 0;"><?= h($order['donation_message']) ?></p>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <a href="<?= $lang === 'en' ? '/en/' : '/' ?>" class="btn btn--primary"><?= h(t_or('donation.confirm.home', 'Към началото', 'Back to home')) ?></a>
      <a href="<?= $lang === 'en' ? '/en/how-to-help/' : '/kak-da-pomogna/' ?>" class="btn btn--outline">
        <?= h(t_or('donation.confirm.more', 'Как още да помогна', 'Other ways to help')) ?>
      </a>
    </div>
  </div>
</section>

<!-- Follow / share -->
<section class="section" style="padding-top:0;">
  <div class="container" style="max-width:560px;text-align:center;">
    <p style="color:var(--text-muted);font-size:.95rem;margin-bottom:1rem;">
      <?= h(t_or('confirm.follow', 'Последвайте ни и споделете мисията ни:', 'Follow us and share our mission:')) ?>
    </p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
    </div>
  </div>
</section>

<?php $nl_flow = 'donation'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-confirm-card.php'; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
