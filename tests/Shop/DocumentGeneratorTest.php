<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the DocumentGenerator base utilities and the three untested
 * concrete generators: InvoiceGenerator, ReceiptGenerator, CreditNoteGenerator.
 *
 * buildHtml() is protected, so each generator is subclassed to expose it.
 * No PDF rendering happens — only the HTML string is tested.
 */
#[Group('shop')]
#[Group('documents')]
final class DocumentGeneratorTest extends TestCase
{
    // ── Testable subclasses ───────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/InvoiceGenerator.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/ReceiptGenerator.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/CreditNoteGenerator.php';

        if (!class_exists('TestableInvoiceGenerator')) {
            eval('class TestableInvoiceGenerator extends InvoiceGenerator {
                public function html(array $o, array $i, array $d): string { return $this->buildHtml($o,$i,$d); }
                public static function callEur2Bgn(float $v): float { return self::eur2bgn($v); }
                public static function callFmtEur(float $v): string { return self::fmtEur($v); }
                public static function callFmtBgn(float $v): string { return self::fmtBgn($v); }
                public static function callFmtDate(string $s): string { return self::fmtDate($s); }
                public static function callAmountWordsBg(float $v): string { return self::amountInWordsBg($v); }
                public static function callAmountWordsEur(float $v): string { return self::amountInWordsEur($v); }
            }');
        }
        if (!class_exists('TestableReceiptGenerator')) {
            eval('class TestableReceiptGenerator extends ReceiptGenerator {
                public function html(array $o, array $i, array $d): string { return $this->buildHtml($o,$i,$d); }
            }');
        }
        if (!class_exists('TestableCreditNoteGenerator')) {
            eval('class TestableCreditNoteGenerator extends CreditNoteGenerator {
                public function html(array $o, array $i, array $d): string { return $this->buildHtml($o,$i,$d); }
            }');
        }
    }

    // ── Shared fixtures ───────────────────────────────────────────────────────

    private function order(array $override = []): array
    {
        return array_merge([
            'id'             => 1,
            'order_number'   => 'OM-20260101-TEST',
            'customer_name'  => 'Мария Петрова',
            'customer_email' => 'maria@example.com',
            'total_eur'      => 35.99,
            'subtotal_eur'   => 29.99,
            'shipping_eur'   => 6.00,
            'payment_method' => 'card',
            'created_at'     => '2026-01-15 10:30:00',
            'invoice_data'   => '{}',
        ], $override);
    }

    private function items(array $override = []): array
    {
        return [array_merge([
            'type'        => 'product',
            'name_bg'     => 'Тениска с щампа',
            'quantity'    => 2,
            'price_eur'   => 14.99,
            'subtotal_eur'=> 29.99,
        ], $override)];
    }

    private function doc(array $override = []): array
    {
        return array_merge(['formatted_number' => '00099'], $override);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // DocumentGenerator base utilities
    // ═════════════════════════════════════════════════════════════════════════

    public function test_eur2bgn_rounds_to_two_decimal_places(): void
    {
        // 1 EUR × 1.95583 = 1.95583 → rounded to 2dp = 1.96
        $this->assertSame(1.96, TestableInvoiceGenerator::callEur2Bgn(1.00));
        $this->assertSame(19.56, TestableInvoiceGenerator::callEur2Bgn(10.00));
    }

    public function test_fmt_eur_includes_euro_symbol(): void
    {
        $this->assertStringContainsString('€', TestableInvoiceGenerator::callFmtEur(12.50));
    }

    public function test_fmt_eur_formats_two_decimals(): void
    {
        $this->assertStringContainsString('12.50', TestableInvoiceGenerator::callFmtEur(12.50));
    }

    public function test_fmt_bgn_includes_lv_suffix(): void
    {
        $this->assertStringContainsString('лв.', TestableInvoiceGenerator::callFmtBgn(25.00));
    }

    public function test_fmt_date_formats_dd_mm_yyyy(): void
    {
        $this->assertSame('15.01.2026', TestableInvoiceGenerator::callFmtDate('2026-01-15 10:30:00'));
    }

    public function test_fmt_date_with_date_only_string(): void
    {
        $this->assertSame('01.06.2026', TestableInvoiceGenerator::callFmtDate('2026-06-01'));
    }

    public static function amountWordsBgProvider(): array
    {
        // ucfirst() is not Cyrillic-aware, so output is lowercase
        return [
            [1.00,  'един лв.'],
            [2.00,  'два лв.'],
            [10.00, 'десет лв.'],
            [50.00, 'петдесет лв.'],
            [1.50,  '50 ст.'],
        ];
    }

    #[DataProvider('amountWordsBgProvider')]
    public function test_amount_in_words_bg(float $amount, string $expected): void
    {
        $words = TestableInvoiceGenerator::callAmountWordsBg($amount);
        $this->assertStringContainsString($expected, $words);
    }

    public function test_amount_in_words_eur_contains_evro(): void
    {
        $this->assertStringContainsString('евро', TestableInvoiceGenerator::callAmountWordsEur(5.00));
    }

    public function test_amount_in_words_eur_with_cents(): void
    {
        $words = TestableInvoiceGenerator::callAmountWordsEur(5.50);
        $this->assertStringContainsString('50 цента', $words);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InvoiceGenerator
    // ═════════════════════════════════════════════════════════════════════════

    public function test_invoice_contains_customer_name(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('Мария Петрова', $html);
    }

    public function test_invoice_contains_invoice_number(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('00099', $html);
    }

    public function test_invoice_contains_product_name(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('Тениска с щампа', $html);
    }

    public function test_invoice_contains_formatted_date(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('15.01.2026', $html);
    }

    public function test_invoice_shows_shipping_row_when_nonzero(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(['shipping_eur' => 5.00]), $this->items(), $this->doc());
        $this->assertStringContainsString('Доставка', $html);
    }

    public function test_invoice_hides_shipping_row_when_zero(): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(['shipping_eur' => 0.00]), $this->items(), $this->doc());
        $this->assertStringNotContainsString('Доставка', $html);
    }

    public static function invoicePaymentLabelProvider(): array
    {
        return [
            ['card',          'банкова карта'],
            ['bank_transfer', 'Банков превод'],
            ['cod',           'В брой'],
        ];
    }

    #[DataProvider('invoicePaymentLabelProvider')]
    public function test_invoice_payment_label(string $method, string $expected): void
    {
        $html = (new TestableInvoiceGenerator())->html($this->order(['payment_method' => $method]), $this->items(), $this->doc());
        $this->assertStringContainsStringIgnoringCase($expected, $html);
    }

    public function test_invoice_uses_company_name_for_b2b(): void
    {
        $order = $this->order(['invoice_data' => json_encode([
            'company_name' => 'Тест ЕООД',
            'eik'          => '123456789',
        ])]);
        $html = (new TestableInvoiceGenerator())->html($order, $this->items(), $this->doc());
        $this->assertStringContainsString('Тест ЕООД', $html);
    }

    public function test_invoice_excludes_donation_from_total(): void
    {
        $items = [
            ['type' => 'product', 'name_bg' => 'Книга', 'quantity' => 1, 'price_eur' => 10.00, 'subtotal_eur' => 10.00],
            ['type' => 'donation', 'name_bg' => 'Дарение', 'quantity' => 1, 'price_eur' => 20.00, 'subtotal_eur' => 20.00, 'amount_eur' => 20.00],
        ];
        $order = $this->order(['total_eur' => 30.00, 'subtotal_eur' => 10.00, 'shipping_eur' => 0.00]);
        $html  = (new TestableInvoiceGenerator())->html($order, $items, $this->doc());
        // Invoice total should be 10 EUR, not 30 EUR
        $this->assertStringContainsString('10.00', $html);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ReceiptGenerator
    // ═════════════════════════════════════════════════════════════════════════

    public function test_receipt_contains_customer_name(): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('Мария Петрова', $html);
    }

    public function test_receipt_contains_receipt_number(): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('00099', $html);
    }

    public function test_receipt_contains_product_name(): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('Тениска с щампа', $html);
    }

    public function test_receipt_shows_shipping_when_nonzero(): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(['shipping_eur' => 5.00]), $this->items(), $this->doc());
        $this->assertStringContainsString('Доставка', $html);
    }

    public static function receiptPaymentLabelProvider(): array
    {
        return [
            ['card',          'Банкова карта'],
            ['bank_transfer', 'Банков превод'],
            ['cod',           'Наложен платеж'],
        ];
    }

    #[DataProvider('receiptPaymentLabelProvider')]
    public function test_receipt_payment_label(string $method, string $expected): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(['payment_method' => $method]), $this->items(), $this->doc());
        $this->assertStringContainsString($expected, $html);
    }

    public function test_receipt_contains_order_number(): void
    {
        $html = (new TestableReceiptGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('OM-20260101-TEST', $html);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CreditNoteGenerator
    // ═════════════════════════════════════════════════════════════════════════

    public function test_credit_note_contains_note_number(): void
    {
        $html = (new TestableCreditNoteGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('00099', $html);
    }

    public function test_credit_note_references_source_invoice_number(): void
    {
        $doc  = $this->doc(['source_formatted_number' => '00055', 'source_date' => '10.01.2026']);
        $html = (new TestableCreditNoteGenerator())->html($this->order(), $this->items(), $doc);
        $this->assertStringContainsString('00055', $html);
    }

    public function test_credit_note_uses_company_name_for_b2b(): void
    {
        $order = $this->order(['invoice_data' => json_encode(['company_name' => 'Клиент ООД'])]);
        $html  = (new TestableCreditNoteGenerator())->html($order, $this->items(), $this->doc());
        $this->assertStringContainsString('Клиент ООД', $html);
    }

    public function test_credit_note_excludes_donation_from_total(): void
    {
        $items = [
            ['type' => 'product', 'name_bg' => 'Книга', 'quantity' => 1, 'price_eur' => 10.00, 'subtotal_eur' => 10.00],
            ['type' => 'donation', 'amount_eur' => 15.00, 'name_bg' => 'Дарение', 'quantity' => 1, 'price_eur' => 15.00, 'subtotal_eur' => 15.00],
        ];
        $order = $this->order(['total_eur' => 25.00, 'subtotal_eur' => 10.00, 'shipping_eur' => 0.00]);
        $html  = (new TestableCreditNoteGenerator())->html($order, $items, $this->doc());
        $this->assertStringContainsString('10.00', $html);
    }

    public function test_credit_note_contains_product_name(): void
    {
        $html = (new TestableCreditNoteGenerator())->html($this->order(), $this->items(), $this->doc());
        $this->assertStringContainsString('Тениска с щампа', $html);
    }
}
