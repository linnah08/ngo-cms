<?php
/**
 * Ticket purchase — POST from an event page (/sabitiya/<slug>/ or the EN twin).
 * Records an unpaid ticket purchase and sends the buyer to the bank's card
 * page; the bank sends them back to /api/event-payment-return.php.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/payment_errors.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('events');

$lang = ($_POST['lang'] ?? '') === 'en' ? 'en' : 'bg';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . events_path('list', $lang));
    exit;
}

$pdo      = get_pdo();
$event_id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$event    = $event_id ? event_get($pdo, $event_id) : null;
if (!$event || empty($event['published'])) {
    header('Location: ' . events_path('list', $lang));
    exit;
}
$back = event_url($event, $lang);
$old  = [
    'name'       => mb_substr(trim((string) (is_scalar($_POST['name'] ?? null) ? $_POST['name'] : '')), 0, 200),
    'email'      => mb_substr(trim((string) (is_scalar($_POST['email'] ?? null) ? $_POST['email'] : '')), 0, 200),
    'ticket_qty' => (int) (is_scalar($_POST['ticket_qty'] ?? null) ? $_POST['ticket_qty'] : 1),
];
$fail = static function (string $message) use ($event, $old, $back): never {
    $_SESSION['event_error'] = ['event' => (int) $event['id'], 'message' => $message, 'old' => $old];
    header('Location: ' . $back . '#buy-title');
    exit;
};

if (!csrf_verify()) {
    $fail(t_or('events.err.session', 'Страницата беше отворена твърде дълго. Моля, опитайте отново.', 'The page was open for too long. Please try again.', $lang));
}

// Card payments are set up site-wide in Admin → Плащания. Say so plainly
// instead of taking the buyer's details for a sale that cannot be paid.
if (!DSKBankPayment::isEnabled()) {
    payment_error_report('Плащането с карта не е настроено — билет не може да бъде платен', '', 'DSK Bank not configured');
    $fail(t_or('events.err.payments', 'В момента не можем да приемем плащане с карта. Моля, опитайте по-късно или ни пишете.', 'We cannot take card payments right now. Please try again later or write to us.', $lang));
}

try {
    $r = event_create_ticket_pledge($pdo, $event, $_POST, $lang);
} catch (Throwable $e) {
    payment_error_report('Покупката на билет не можа да бъде записана', '', $e);
    $fail(t_or('events.err.technical', 'Възникна техническа грешка. Моля, опитайте отново.', 'Something went wrong on our side. Please try again.', $lang));
}
if (!$r['ok']) $fail($r['error']);

// Only this browser may see the purchase's details on the confirmation page.
order_session_remember($r['pledge_number']);

try {
    $dsk    = new DSKBankPayment();
    $return = SITE_URL . '/api/event-payment-return.php?pledge=' . urlencode($r['pledge_number']);
    $result = $dsk->register($r['pledge_number'] . '_' . time(), $r['amount_eur'], $return);
    $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id = ? WHERE id = ?')->execute([$result['dsk_order_id'], $r['pledge_id']]);
    header('Location: ' . $result['formUrl']);
    exit;
} catch (Throwable $e) {
    payment_error_report('Банката не създаде плащане (билет)', $r['pledge_number'], $e);
    header('Location: ' . events_path('confirmation', $lang) . '?pledge=' . urlencode($r['pledge_number']) . '&err=1');
    exit;
}
