<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

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

$amount = (float)$order['total_eur'];
$paid   = $order['payment_status'] === 'paid';

$page_title = $lang === 'bg' ? 'Благодарим за дарението!' : 'Thank you for your donation!';

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
    <div style="font-size:3rem;margin-bottom:1rem;">💛</div>
    <h1 style="color:#fff;"><?= $lang === 'bg' ? 'Благодарим от сърце!' : 'Thank you from the heart!' ?></h1>
    <p style="color:rgba(255,255,255,.85);font-size:1.1rem;">
      <?= $lang === 'bg'
        ? 'Вашето дарение ще помогне на деца, на които е нужна подкрепа.'
        : 'Your donation will help children who need support.' ?>
    </p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:560px;">

    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem;margin-bottom:2rem;text-align:center;">
      <?php if ($paid): ?>
        <div style="font-size:2.5rem;margin-bottom:.75rem;">✅</div>
        <h2 style="color:var(--teal);margin-top:0;">
          <?= $lang === 'bg' ? 'Плащането е успешно!' : 'Payment confirmed!' ?>
        </h2>
        <p style="color:var(--text-muted);">
          <?= $lang === 'bg'
            ? 'Дарението ви от <strong>' . number_format($amount, 2) . ' €</strong> е прието. Изпратихме потвърждение на имейла ви.'
            : 'Your donation of <strong>' . number_format($amount, 2) . ' €</strong> has been received. A confirmation has been sent to your email.' ?>
        </p>
      <?php else: ?>
        <div style="font-size:2.5rem;margin-bottom:.75rem;">⏳</div>
        <h2 style="color:var(--teal);margin-top:0;">
          <?= $lang === 'bg' ? 'Дарението е регистрирано' : 'Donation registered' ?>
        </h2>
        <p style="color:var(--text-muted);">
          <?= $lang === 'bg'
            ? 'Дарението ви от <strong>' . number_format($amount, 2) . ' €</strong> е регистрирано и се обработва.'
            : 'Your donation of <strong>' . number_format($amount, 2) . ' €</strong> has been registered and is being processed.' ?>
        </p>
      <?php endif; ?>

      <p style="font-size:.85rem;color:var(--text-muted);margin-top:1.5rem;">
        <?= $lang === 'bg' ? 'Номер:' : 'Reference:' ?> <strong><?= h($order_number) ?></strong>
      </p>
    </div>

    <?php if ($order['donation_message']): ?>
    <div style="padding:1rem 1.25rem;background:var(--warm-grey);border-radius:var(--radius-lg);margin-bottom:2rem;">
      <strong style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">
        <?= $lang === 'bg' ? 'Вашето послание' : 'Your message' ?>
      </strong>
      <p style="margin:.5rem 0 0;"><?= h($order['donation_message']) ?></p>
    </div>
    <?php endif; ?>

    <div style="text-align:center;">
      <a href="/" class="btn btn--primary"><?= $lang === 'bg' ? 'Към началото' : 'Back to home' ?></a>
      <a href="<?= $lang === 'bg' ? '/kak-da-pomogna/' : '/en/how-to-help/' ?>" class="btn btn--outline" style="margin-left:1rem;">
        <?= $lang === 'bg' ? 'Как още да помогна' : 'Other ways to help' ?>
      </a>
    </div>
  </div>
</section>

<!-- Follow / share -->
<section class="section" style="padding-top:0;">
  <div class="container" style="max-width:560px;text-align:center;">
    <p style="color:var(--text-muted);font-size:.95rem;margin-bottom:1rem;">
      <?= $lang === 'bg' ? 'Последвайте ни и споделете мисията ни:' : 'Follow us and share our mission:' ?>
    </p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
      <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
