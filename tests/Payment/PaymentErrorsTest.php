<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Payment error alert text — includes/payment/payment_errors.php.
 * Only the message is tested; nothing is logged or emailed.
 */
#[Group('payment')]
final class PaymentErrorsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/payment_errors.php';
    }

    public function testBankHtmlErrorPageBecomesReadableText(): void
    {
        // What IRIS actually answered on 2026-09-19 (unencoded merchant key).
        $e = new RuntimeException('IRIS invalid JSON: <!doctype html><html lang="en"><head><title>HTTP Status 400 – Bad Request</title>'
            . '<style type="text/css">body {font-family:Tahoma}</style></head><body><h1>HTTP Status 400 – Bad Request</h1></body></html>');

        $msg = payment_error_message('IRIS не създаде плащане', 'OM-20260919-2862', $e);

        $this->assertSame(
            'Грешка при плащане: IRIS не създаде плащане — OM-20260919-2862: IRIS invalid JSON: HTTP Status 400 – Bad Request HTTP Status 400 – Bad Request',
            $msg
        );
        $this->assertStringNotContainsString('<', $msg);
        $this->assertStringNotContainsString('Tahoma', $msg, 'style blocks are dropped');
    }

    public function testStringReasonAndNoReference(): void
    {
        $this->assertSame(
            'Грешка при плащане: Подкрепата за кампания не можа да бъде записана: DB down',
            payment_error_message('Подкрепата за кампания не можа да бъде записана', '', 'DB down')
        );
    }

    public function testLongReasonIsCapped(): void
    {
        $msg = payment_error_message('X', 'OM-1', str_repeat('ж', 2000));
        $this->assertSame(500, mb_substr_count($msg, 'ж'));
    }
}
