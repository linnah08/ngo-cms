<?php
// Variables: $donor_name, $amount_eur, $donation_message, $reference, $lang (optional)
if (!function_exists('email_tpl_get')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
}
$_lang = $lang ?? 'bg';
$_vars = ['donor_name' => $donor_name, 'amount_eur' => number_format((float)$amount_eur, 2)];
$_tpl  = email_tpl_get('donation-confirmation-customer', $_lang, $_vars);

echo $_tpl['intro'];
?>

<?php if (!empty($donation_message)): ?>
<p><strong><?= $_lang === 'en' ? 'Your message' : 'Вашето послание' ?>:</strong> <?= htmlspecialchars($donation_message, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php echo $_tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
