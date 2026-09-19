<?php
/**
 * Payment problems the customer can't see and nobody would otherwise notice:
 * a bank refusing to create a payment, a callback we can't process, an IRIS
 * confirmation that doesn't match the order, a failed refund.
 *
 * These are caught (so the customer gets a normal page) and would only reach
 * error_log(), which on the live server doesn't land anywhere readable. This
 * logs them AND emails the admin right away through the error-alert system
 * (Настройки → Известия за грешки), regardless of the digest setting.
 */

/**
 * The alert text: plain language first, then the order and the bank's reason
 * (HTML error pages reduced to their text, capped at 500 chars).
 */
function payment_error_message(string $what, string $ref, Throwable|string $error): string
{
    $detail = $error instanceof Throwable ? $error->getMessage() : $error;
    $detail = preg_replace('#<(style|script)\b.*?</\1>#is', '', $detail);
    $detail = preg_replace('/<[^>]*>/', ' ', $detail);   // tags → spaces, so words don't run together
    $detail = trim(preg_replace('/\s+/u', ' ', html_entity_decode($detail, ENT_QUOTES, 'UTF-8')));
    $detail = mb_substr($detail, 0, 500);

    return 'Грешка при плащане: ' . $what . ($ref !== '' ? ' — ' . $ref : '') . ($detail !== '' ? ': ' . $detail : '');
}

/**
 * Log a payment problem and email the admin right away.
 *
 * @param string           $what  plain-language description, e.g. 'IRIS не създаде плащане'
 * @param string           $ref   order / pledge number ('' if none)
 * @param Throwable|string $error the exception, or a description
 */
function payment_error_report(string $what, string $ref, Throwable|string $error): void
{
    $message = payment_error_message($what, $ref, $error);

    if ($error instanceof Throwable) {
        $file = $error->getFile();
        $line = $error->getLine();
    } else {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file   = $caller['file'] ?? '';
        $line   = $caller['line'] ?? 0;
    }

    error_log($message);
    try {
        if (!function_exists('error_alert_capture')) {
            require_once dirname(__DIR__) . '/error-alerts.php';
        }
        error_alert_capture('PaymentError', $message, (string)$file, (int)$line, true);
    } catch (Throwable $e) {
        error_log('payment_error_report: ' . $e->getMessage());
    }
}
