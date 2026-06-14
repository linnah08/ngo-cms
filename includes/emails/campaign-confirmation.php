<?php
// Variables: $pledge (array from campaign_pledges table), $lang (optional)
if (!function_exists('email_tpl_get')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
}
$_lang     = $lang ?? $pledge['lang'] ?? 'bg';
$_show_bgn = date('Y-m') < '2026-06';
$_vars     = ['name' => $pledge['name'], 'pledge_number' => $pledge['pledge_number']];
$_tpl      = email_tpl_get('campaign-confirmation', $_lang, $_vars);

$amount_bgn = number_format($pledge['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ');
$amount_eur = number_format($pledge['amount_eur'], 2, '.', ' ');
$addr       = $pledge['delivery_address'] ? json_decode($pledge['delivery_address'], true) : null;

echo $_tpl['intro'];
?>

<div class="box">
  <strong><?= $_lang === 'en' ? 'Number' : 'Номер' ?>:</strong> <?= htmlspecialchars($pledge['pledge_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Amount' : 'Сума' ?>:</strong>
  <?= $amount_eur ?> EUR<?php if ($_show_bgn): ?> (<?= $amount_bgn ?> лв)<?php endif; ?><br>
  <strong><?= $_lang === 'en' ? 'Date' : 'Дата' ?>:</strong> <?= htmlspecialchars(substr($pledge['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>
</div>

<?php if ($addr): ?>
<h3 style="color:#0387A5;"><?= $_lang === 'en' ? 'Reward delivery' : 'Доставка на наградата' ?></h3>
<p>
  <?= $_lang === 'en' ? 'Your reward will be shipped to:' : 'Твоята награда ще бъде изпратена на:' ?><br>
  <strong><?= htmlspecialchars(implode(', ', array_filter([$addr['address']??'', $addr['city']??'', $addr['postcode']??''])), ENT_QUOTES, 'UTF-8') ?></strong>
  <?php if (!empty($addr['phone'])): ?>
  <br><?= $_lang === 'en' ? 'Phone' : 'Телефон' ?>: <?= htmlspecialchars($addr['phone'], ENT_QUOTES, 'UTF-8') ?>
  <?php endif; ?>
</p>
<?php endif; ?>

<?php echo $_tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
