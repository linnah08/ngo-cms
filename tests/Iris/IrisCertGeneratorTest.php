<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('iris')]
#[Group('documents')]
final class IrisCertGeneratorTest extends TestCase
{
    private static array $baseOrder = [];
    private static array $baseItems = [];
    private static array $baseDoc   = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/IrisCertGenerator.php';

        if (!class_exists('TestableIrisCertGenerator')) {
            eval('class TestableIrisCertGenerator extends IrisCertGenerator {
                public function buildHtmlPublic(array $order, array $items, array $document): string {
                    return $this->buildHtml($order, $items, $document, null);
                }
            }');
        }

        self::$baseOrder = [
            'id'             => 0,
            'order_number'   => 'IRIS-TEST-001',
            'customer_name'  => 'Мария Петрова',
            'customer_email' => 'maria@example.com',
            'total_eur'      => 100.00,
            'payment_method' => 'bank_transfer',
            'created_at'     => '2026-06-06 10:00:00',
            'invoice_data'   => json_encode(['donor_type' => 'individual']),
        ];
        self::$baseItems = [[
            'type'       => 'donation',
            'amount_eur' => 100.00,
            'recipient'  => 'iris',
        ]];
        self::$baseDoc = ['formatted_number' => '00001'];
    }

    public function testIrisOrgNameAppearsInCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('ЦСРИ Ирис', $html);
    }

    public function testIrisEikAppearsInCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('104686779', $html);
    }

    public function testIrisIbanAppearsInCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('BG23STSA93000032143827', $html);
    }

    public function testIrisDirectorNameAppearsInCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Анелия Тимофеева', $html);
    }

    public function testFoundationNameDoesNotAppearInIrisCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringNotContainsString('ФОНДАЦИЯ РАЗЛИЧНИ УМОВЕ', $html);
    }

    public function testFoundationEikDoesNotAppearInIrisCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringNotContainsString('208453360', $html);
    }

    public function testDonorNameAppearsInIrisCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('Мария Петрова', $html);
    }

    public function testEnglishIrisCertUsesCorrectIntro(): void
    {
        $order = array_merge(self::$baseOrder, [
            'invoice_data' => json_encode(['donor_type' => 'individual', 'lang' => 'en']),
        ]);
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic($order, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('CSRI Iris', $html);
        $this->assertStringNotContainsString('Razlichni Umove Foundation', $html);
    }

    public function testRegistrationNoteAbsentInIrisCert(): void
    {
        $gen  = new TestableIrisCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringNotContainsString('Централния регистър на ЮЛНЦ', $html);
    }

    public function testBaseDonationCertStillUsesFoundationData(): void
    {
        if (!class_exists('TestableDonationCertGenerator')) {
            eval('class TestableDonationCertGenerator extends DonationCertGenerator {
                public function buildHtmlPublic(array $order, array $items, array $document): string {
                    return $this->buildHtml($order, $items, $document, null);
                }
            }');
        }
        $gen  = new TestableDonationCertGenerator();
        $html = $gen->buildHtmlPublic(self::$baseOrder, self::$baseItems, self::$baseDoc);
        $this->assertStringContainsString('ФОНДАЦИЯ РАЗЛИЧНИ УМОВЕ', $html);
        // Foundation IBAN (not IRIS IBAN) appears
        $this->assertStringContainsString('BG40STSA93000032062526', $html);
        $this->assertStringNotContainsString('BG23STSA93000032143827', $html);
    }
}
