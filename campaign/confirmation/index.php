<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$pdo           = get_pdo();
$pledge_number = trim($_GET['pledge'] ?? '');
$pledge        = null;

if (preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $pledge_number)) {
    $stmt = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
    $stmt->execute([$pledge_number]);
    $pledge = $stmt->fetch() ?: null;
}

$show_bgn = date('Y-m') < '2026-06';
$page_title = 'Благодарим ти! — Фондация Различни умове';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section" style="min-height:60vh;display:flex;align-items:center;">
  <div class="container" style="max-width:640px;margin:0 auto;text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">💚</div>
    <h1 style="font-size:2rem;margin-bottom:1rem;color:var(--teal,#1b998b);">Благодарим ти!</h1>

    <?php if ($pledge && $pledge['payment_status'] === 'paid'): ?>
    <p style="font-size:1.05rem;line-height:1.75;color:#444;margin-bottom:1.5rem;">
      Твоята подкрепа от
      <strong><?= number_format($pledge['amount_eur'], 2) ?> EUR<?php if ($show_bgn): ?> (<?= number_format($pledge['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ') ?> лв)<?php endif; ?></strong>
      беше успешно получена.<br>
      <?php if (($pledge['pledge_type'] ?? 'donation') === 'ticket'): ?>
      Изпратихме ти потвърждение и билет на <strong><?= h($pledge['email']) ?></strong>.
      <?php else: ?>
      Изпратихме ти потвърждение и сертификат за дарение на <strong><?= h($pledge['email']) ?></strong>.
      <?php endif; ?>
    </p>

    <?php if ($pledge['reward_id']): ?>
    <div style="background:#f0faf9;border:1px solid #b2dbd7;border-radius:8px;padding:1.1rem 1.5rem;margin-bottom:1.5rem;text-align:left;">
      <div style="font-weight:700;margin-bottom:.35rem;">Твоята награда ще бъде изпратена на:</div>
      <?php $addr = json_decode($pledge['delivery_address'] ?? '{}', true) ?? []; ?>
      <div style="font-size:.9rem;color:#444;line-height:1.65;">
        <?= h(implode(', ', array_filter([$addr['address']??'', $addr['city']??'', $addr['postcode']??'']))) ?>
        <?php if (!empty($addr['phone'])): ?><br><?= h($addr['phone']) ?><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div style="font-size:.78rem;color:#9b9590;margin-bottom:1.5rem;">Номер: <?= h($pledge['pledge_number']) ?></div>

    <?php else: ?>
    <p style="font-size:1.05rem;line-height:1.75;color:#444;margin-bottom:1.5rem;">
      Твоята подкрепа беше получена успешно. Ще получиш потвърждение по имейл.
    </p>
    <?php endif; ?>

    <a href="/campaign/" class="btn btn--primary">← Обратно към кампанията</a>

    <div style="margin-top:2.5rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <p style="color:var(--text-muted);font-size:.95rem;margin-bottom:1rem;">Последвайте ни и споделете мисията ни:</p>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;">
        <?php $social_variant = 'outline'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/social-links.php'; ?>
      </div>
    </div>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
