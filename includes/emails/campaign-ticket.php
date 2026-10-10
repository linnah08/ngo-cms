<?php
// Variables: $pledge (array from campaign_pledges table), $lang (optional)
if (!function_exists('email_tpl_get')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
}
$_lang     = $lang ?? $pledge['lang'] ?? 'bg';
// $event: the events row (includes/events.php). Callers that still pass only
// the pledge get its event looked up — or the old single-event settings.
if (!isset($event) || !is_array($event)) {
    require_once ($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 2)) . '/includes/events.php';
    require_once ($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 2)) . '/admin/includes/db.php';
    try {
        $event = event_for_pledge(get_pdo(), $pledge);
    } catch (Throwable $e) {
        $event = ['title' => 'Събитие', 'event_date' => null, 'event_time' => '', 'place' => ''];
    }
}
if (!function_exists('event_text')) {
    require_once ($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 2)) . '/includes/events.php';
}
$ev_name  = event_text($event, 'title', $_lang) ?: ($_lang === 'en' ? 'Event' : 'Събитие');
$ev_place = event_text($event, 'place', $_lang);
$ev_when  = event_when($event, $_lang);

$_vars = [
    'name'         => $pledge['name'],
    'pledge_number'=> $pledge['pledge_number'],
    'event_name'   => $ev_name,
];
$_tpl  = email_tpl_get('campaign-ticket', $_lang, $_vars);

$amount_eur  = number_format($pledge['amount_eur'], 2, '.', ' ');
$ticket_code = htmlspecialchars($pledge['ticket_code'] ?? '', ENT_QUOTES, 'UTF-8');

echo $_tpl['intro'];
?>

<div class="box">
  <strong><?= $_lang === 'en' ? 'Event' : 'Събитие' ?>:</strong> <?= htmlspecialchars($ev_name, ENT_QUOTES, 'UTF-8') ?><br>
  <?php if ($ev_when): ?>
  <strong><?= $_lang === 'en' ? 'Date / time' : 'Дата / час' ?>:</strong> <?= htmlspecialchars($ev_when, ENT_QUOTES, 'UTF-8') ?><br>
  <?php endif; ?>
  <?php if ($ev_place): ?>
  <strong><?= $_lang === 'en' ? 'Venue' : 'Място' ?>:</strong> <?= htmlspecialchars($ev_place, ENT_QUOTES, 'UTF-8') ?><br>
  <?php endif; ?>
  <strong><?= $_lang === 'en' ? 'Entry code' : 'Код за вход' ?>:</strong>
  <span style="font-family:monospace;font-weight:700;color:#0387A5;letter-spacing:.1em;"><?= $ticket_code ?></span><br>
  <strong><?= $_lang === 'en' ? 'Ticket #' : 'Билет №' ?>:</strong> <?= htmlspecialchars($pledge['pledge_number'], ENT_QUOTES, 'UTF-8') ?><br>
  <strong><?= $_lang === 'en' ? 'Amount' : 'Сума' ?>:</strong> <?= $amount_eur ?> EUR
</div>

<?php echo $_tpl['outro']; ?>

<p style="color:#6b6560;font-size:14px;">
  <?= $_lang === 'en' ? 'Questions?' : 'При въпроси:' ?>
  <a href="mailto:<?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?>"><?= defined('SITE_EMAIL') ? SITE_EMAIL : '' ?></a>
</p>
