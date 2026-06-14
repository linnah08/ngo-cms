<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('shop')]
#[Group('documents')]
final class DonationCertTest extends TestCase
{
    private static array $baseOrder = [];
    private static array $baseItems = [];
    private static array $baseDoc   = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

        // Expose protected buildHtml() for testing without modifying production code
        if (!class_exists('TestableDonationCertGenerator')) {
            eval('class TestableDonationCertGenerator extends DonationCertGenerator {
                public function buildHtmlPublic(array $order, array $items, array $document): string {
                    return $this->buildHtml($order, $items, $document, null);
                }
            }');
        }

        self::$baseOrder = [
            'id'             => 0,
            'order_number'   => 'CP-20260508-ABCD',
            'customer_name'  => 'Иван Иванов',
            'customer_email' => 'ivan@example.com',
            'total_eur'      => 50.00,
            'payment_method' => 'card',
            'created_at'     => '2026-05-08 12:00:00',
            'invoice_data'   => json_encode(['donor_type' => 'individual']),
        ];
        self::$baseItems = [[
            'type'       => 'donation',
            'amount_eur' => 50.00,
            'recipient'  => 'foundation',
        ]];
        self::$baseDoc = [
            'formatted_number' => '00042',
        ];
    }

    // ── payment method label ──────────────────────────────────────────────────

    public function testCardPaymentShowsBankovaKarta(): void
    {
        // Regression: was defaulting to 'bank_transfer' → 'Банков превод' for card payments.
        $order = array_merge(self::$baseOrder, ['payment_method' => 'card']);
        $gen   = new TestableDonationCertGenerator();
        $html  = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Банкова карта', $html);
        $this->assertStringNotContainsString('Банков превод', $html);
    }

    public function testMissingPaymentMethodDefaultsToCard(): void
    {
        // When payment_method is absent (campaign pledges stored as 'card' implicitly),
        // the cert must not say 'Банков превод'.
        $order = self::$baseOrder;
        unset($order['payment_method']);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringNotContainsString('Банков превод', $html);
        $this->assertStringContainsString('Банкова карта', $html);
    }

    public static function paymentLabelProvider(): array
    {
        return [
            ['card',          'Банкова карта'],
            ['bank_transfer', 'Банков превод'],
            ['cod',           'В брой'],
        ];
    }

    #[DataProvider('paymentLabelProvider')]
    public function testPaymentLabelRenderedCorrectly(string $method, string $expected): void
    {
        $order = array_merge(self::$baseOrder, ['payment_method' => $method]);
        $gen   = new TestableDonationCertGenerator();
        $html  = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString($expected, $html);
    }

    // ── donor info ────────────────────────────────────────────────────────────

    public function testDonorNameAppearsInCert(): void
    {
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Иван Иванов', $html);
    }

    public function testCertNumberAppearsInCert(): void
    {
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('00042', $html);
    }

    public function testDonationAmountAppearsInCert(): void
    {
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('50', $html);
    }

    public function testCompanyDonorUsesCompanyName(): void
    {
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode([
                'donor_type'   => 'company',
                'company_name' => 'Тест ЕООД',
            ]),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Тест ЕООД', $html);
    }

    // ── English cert ──────────────────────────────────────────────────────────

    public function testEnglishCertTitleIsDonationCertificate(): void
    {
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode(['donor_type' => 'individual', 'lang' => 'en']),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('DONATION CERTIFICATE', $html);
        $this->assertStringNotContainsString('СЕРТИФИКАТ ЗА ДАРЕНИЕ', $html);
    }

    public function testEnglishCertDoesNotContainBulgarianLabels(): void
    {
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode(['donor_type' => 'individual', 'lang' => 'en']),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringNotContainsString('Данни за дарителя', $html);
        $this->assertStringNotContainsString('Банков превод', $html);
        $this->assertStringContainsString('DONOR DETAILS', $html);
    }

    public function testEnglishCertPaymentMethodCard(): void
    {
        $order = array_merge(self::$baseOrder, [
            'payment_method' => 'card',
            'invoice_data'   => json_encode(['donor_type' => 'individual', 'lang' => 'en']),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Bank card', $html);
    }

    public function testEnglishCertDoesNotShowBgnAmount(): void
    {
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode(['donor_type' => 'individual', 'lang' => 'en']),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        // English cert shows only EUR, not BGN
        $this->assertStringNotContainsString('лв', $html);
    }

    public function testDefaultLangIsBulgarian(): void
    {
        // When lang is not set in invoice_data, should default to BG
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode(['donor_type' => 'individual']),
        ]);
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('СЕРТИФИКАТ ЗА ДАРЕНИЕ', $html);
    }
}
