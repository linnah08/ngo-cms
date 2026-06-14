<?php
// Variables: $order (array), $tracking_number, $courier_label
if (!function_exists('email_tpl_get')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
}
$_lang = $order['lang'] ?? 'bg';
$_vars = [
    'customer_name' => $order['customer_name'],
    'order_number'  => $order['order_number'],
    'courier'       => $courier_label,
];
$_tpl = email_tpl_get('order-shipped-customer', $_lang, $_vars);

echo $_tpl['intro'];
?>

<div class="box">
  <strong><?= $_lang === 'en' ? 'Courier' : 'Куриер' ?>:</strong> <?= htmlspecialchars($courier_label, ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Tracking number' : 'Номер за проследяване' ?>:</strong>
  <strong style="font-size:1.1em;"><?= htmlspecialchars($tracking_number, ENT_QUOTES, 'UTF-8') ?></strong>
</div>

<?php
$tracking_urls = [
    'econt'  => 'https://www.econt.com/services/track-shipment.html',
    'speedy' => 'https://www.speedy.bg/bg/track-shipment/',
    'boxnow' => 'https://boxnow.bg/track',
];
$track_url = $tracking_urls[$order['courier']] ?? null;
if ($track_url): ?>
<p>
  <a href="<?= htmlspecialchars($track_url, ENT_QUOTES, 'UTF-8') ?>"
     style="background:#0387A5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block;">
    <?= $_lang === 'en' ? 'Track your shipment →' : 'Проследи пратката →' ?>
  </a>
</p>
<?php endif; ?>

<?php echo $_tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
