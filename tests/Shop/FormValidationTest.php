<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the checkout and donation forms accept as a name, phone, ЕИК and ДДС
 * номер (includes/form_validation.php).
 */
final class FormValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/form_validation.php';
    }

    public function test_a_name_needs_a_first_and_last_name(): void
    {
        foreach (['Иван Петров', 'Ivan Petrov', '  Мария-Елена   Д\'Арк ', 'Иван П. Петров', 'Ivan P Petrov', 'Георги Ов'] as $ok) {
            $this->assertTrue(form_person_name_valid($ok), $ok);
        }
        foreach (['', 'Иван', 'И', 'И П', 'Иван П', 'Ivan 123', 'Ivan Petrov!', '<b>Ivan</b> Petrov'] as $bad) {
            $this->assertFalse(form_person_name_valid($bad), $bad);
        }
    }

    public function test_a_bulgarian_mobile_needs_all_nine_digits(): void
    {
        foreach (['+359888123456', '0888123456', '888123456', '+359 88 812 3456', '00359898123456'] as $ok) {
            $this->assertTrue(form_phone_valid($ok), $ok);
        }
        foreach (['+35988812345', '088812345', '+3598881234567', '8881', ''] as $bad) {
            $this->assertFalse(form_phone_valid($bad), $bad);
        }
    }

    public function test_bulgarian_landlines_and_foreign_numbers_are_accepted(): void
    {
        foreach (['+35921234567', '029876543', '+35982123456', '+447911123456', '+12025550123'] as $ok) {
            $this->assertTrue(form_phone_valid($ok), $ok);
        }
        $this->assertFalse(form_phone_valid('+3592123'), 'too short for a Bulgarian landline');
        $this->assertFalse(form_phone_valid('+1234567890123456'), 'longer than any number');
    }

    public function test_vat_numbers_carry_a_country_code(): void
    {
        $this->assertSame('BG175074752', form_vat_normalize('BG 175 074 752'));
        $this->assertSame('BG1234567890', form_vat_normalize('bg1234567890'));
        $this->assertSame('DE123456789', form_vat_normalize('DE123456789'));
        foreach (['175074752', 'BG12345678', 'BG12345678901', 'BG17507475A', 'B1', 'ДДС', 'XXABC'] as $bad) {
            $this->assertNull(form_vat_normalize($bad), $bad);
        }
    }

    public function test_the_checkout_and_donation_forms_use_these_checks(): void
    {
        $checkout = file_get_contents(dirname(__DIR__, 2) . '/checkout/index.php');
        foreach (['form_person_name_valid(', 'form_phone_valid(', 'org_eik_normalize(', 'form_vat_normalize(', 'isValidNumberPrecise('] as $call) {
            $this->assertStringContainsString($call, $checkout);
        }
        $this->assertStringNotContainsString('iti.isValidNumber()', $checkout,
            'isValidNumber() only checks the length, so a number one digit short got through');
        $this->assertStringContainsString('$d = array_merge($d, $contact_redisplay)', $checkout,
            'a form with an error comes back with what the customer typed, not emptied');
        $donation = file_get_contents(dirname(__DIR__, 2) . '/includes/donation.php');
        $this->assertStringContainsString('org_eik_normalize(', $donation);
        $this->assertStringContainsString('form_vat_normalize(', $donation);
    }
}
