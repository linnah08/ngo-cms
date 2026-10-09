<?php
/**
 * Giving a shop order's card payment back — used when an order is cancelled and
 * by the "Върни сумата на клиента" button on a cancelled order still marked paid.
 */

require_once __DIR__ . '/DSKBankPayment.php';
require_once __DIR__ . '/payment_errors.php';
require_once dirname(__DIR__) . '/mailer.php';
require_once dirname(__DIR__) . '/email-templates.php';

/** A cancelled card order the bank still holds money for, as far as the site knows. */
function order_card_payment_owed(array $order): bool
{
    return ($order['status'] ?? '') === 'cancelled'
        && ($order['payment_status'] ?? '') === 'paid'
        && ($order['payment_method'] ?? '') === 'card'
        && !empty($order['dsk_order_id']);
}

/**
 * Get the money back to the customer through DSK, then mark the order "refunded"
 * and send the cancellation email. DSK's status decides whether it is back.
 *
 * @return string|null null when the money is back; otherwise a plain-language
 *                     message for the admin (the details go to the error email).
 */
function order_return_card_payment(PDO $pdo, array $order, ?DSKBankPayment $dsk = null): ?string
{
    $returned = ($dsk ?? new DSKBankPayment())->returnPayment($order['dsk_order_id'], (float)$order['total_eur']);
    if (!$returned['ok']) {
        payment_error_report('Автоматичното връщане на парите не успя — върнете сумата ръчно в DSK', $order['order_number'],
            new RuntimeException('DSK: ' . $returned['detail']));
        return 'Сумата не беше върната автоматично (в DSK плащането е „'
            . DSKBankPayment::stateLabel($returned['state']) . '“). Моля, върнете я ръчно в DSK Bank.';
    }

    $pdo->prepare("UPDATE orders SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
        ->execute([(int)$order['id']]);

    try {
        $lang = $order['lang'] ?? 'bg';
        $tpl  = email_tpl_get('order-cancelled-customer', $lang, [
            'customer_name' => $order['customer_name'],
            'order_number'  => $order['order_number'],
        ]);
        send_order_mail(
            (int)$order['id'],
            $order['customer_email'],
            $tpl['subject'],
            render_email('order-cancelled-customer', ['order' => $order, 'tpl' => $tpl]),
            ['template_key' => 'order-cancelled-customer']
        );
    } catch (Throwable $e) {
        // The money is back and the order says so; only the customer email failed.
        payment_error_report('Сумата е върната, но клиентът не получи имейл за отмяната', $order['order_number'], $e);
    }
    return null;
}
