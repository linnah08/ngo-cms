<?php
/**
 * The bank's return address for ticket purchases (module "events").
 * The buyer lands here after paying, cancelling or failing on the card page.
 *
 *   ?pledge=CP-…           check the payment with the bank, then show the result
 *   ?pledge=CP-…&retry=1   start a new card payment for an unpaid purchase
 *
 * Ticket purchases made through the campaign before the events module existed
 * come back to /api/campaign-payment-return.php, which forwards them here.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/payment_errors.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/events.php';
start_session();

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('events');

$pdo    = get_pdo();
$number = trim((string) ($_GET['pledge'] ?? ''));
$pledge = event_ticket_pledge($pdo, $number);
if (!$pledge) {
    header('Location: ' . events_path('list', 'bg'));
    exit;
}
$lang = ($pledge['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$done = events_path('confirmation', $lang) . '?pledge=' . urlencode($pledge['pledge_number']);

if ($pledge['payment_status'] === 'paid') {
    header('Location: ' . $done);
    exit;
}

// ── Try again: a new card payment for the same purchase ─────────────────────
if (isset($_GET['retry'])) {
    if ($pledge['payment_status'] === 'pending') {
        try {
            $dsk    = new DSKBankPayment();
            $return = SITE_URL . '/api/event-payment-return.php?pledge=' . urlencode($pledge['pledge_number']);
            $result = $dsk->register($pledge['pledge_number'] . '_' . time(), (float) $pledge['amount_eur'], $return);
            $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id = ? WHERE id = ?')->execute([$result['dsk_order_id'], $pledge['id']]);
            header('Location: ' . $result['formUrl']);
            exit;
        } catch (Throwable $e) {
            payment_error_report('„Опитай отново“ за билет не успя — банката не създаде плащане', $pledge['pledge_number'], $e);
        }
    }
    header('Location: ' . $done . '&err=1');
    exit;
}

// ── Ask the bank what happened ──────────────────────────────────────────────
// The id saved when the payment was started wins; the address only fills a gap.
$bank_id = (string) (($pledge['dsk_order_id'] ?? '') ?: ($_GET['mdOrder'] ?? $_GET['orderId'] ?? ''));
if ($bank_id === '' || !preg_match('/^[A-Za-z0-9-]{1,100}$/', $bank_id)) {
    header('Location: ' . $done . '&err=1');
    exit;
}
try {
    $status = (new DSKBankPayment())->getStatus($bank_id);
    $result = event_process_payment_status($pdo, $pledge, $bank_id, $status);
} catch (Throwable $e) {
    payment_error_report('Статусът на плащането за билет не можа да бъде проверен', $pledge['pledge_number'], $e);
    $result = 'failed';
}
header('Location: ' . $done . ($result === 'paid' ? '' : '&err=1'));
exit;
