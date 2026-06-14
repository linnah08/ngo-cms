<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
start_session();

$pledge_number = trim($_GET['pledge'] ?? '');
$retry_url     = '';
if (preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $pledge_number)) {
    $retry_url = '/api/campaign-payment-return.php?pledge=' . urlencode($pledge_number) . '&retry=1';
}

$page_title = 'Плащането не беше успешно — Фондация Различни умове';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section" style="min-height:60vh;display:flex;align-items:center;">
  <div class="container" style="max-width:580px;margin:0 auto;text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">❌</div>
    <h1 style="font-size:1.8rem;margin-bottom:1rem;color:#c0392b;">Плащането не беше успешно</h1>
    <p style="font-size:1rem;line-height:1.75;color:#555;margin-bottom:2rem;">
      Нещо се обърка при обработката на плащането. Не беше направено никакво удържане от твоята карта.
      Можеш да опиташ отново или да се свържеш с нас.
    </p>
    <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
      <?php if ($retry_url): ?>
      <a href="<?= h($retry_url) ?>" class="btn btn--primary">Опитай отново</a>
      <?php endif; ?>
      <a href="/campaign/" class="btn btn--secondary">← Обратно към кампанията</a>
    </div>
    <?php if ($pledge_number): ?>
    <div style="font-size:.78rem;color:#9b9590;margin-top:1.5rem;">Референция: <?= h($pledge_number) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
