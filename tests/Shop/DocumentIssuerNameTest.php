<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Documents are issued in the legal entity's name (SITE_LEGAL_NAME_BG/EN,
 * Admin → Организация → "Юридическо име"), not the site's brand name — a site
 * run as a project of a foundation prints the foundation on its certificates,
 * invoices and receipts. With no legal name set, it is the site name, as before.
 */
#[Group('shop')]
#[Group('documents')]
final class DocumentIssuerNameTest extends TestCase
{
    private const LEGAL_BG = 'Фондация „Пример & Ко“';
    private const LEGAL_EN = 'Example & Co Foundation';

    private static function root(): string
    {
        return $_SERVER['DOCUMENT_ROOT'];
    }

    /** Give this (separate) process a legal name that differs from the site name. */
    private function defineLegalName(): void
    {
        if (defined('SITE_LEGAL_NAME_BG') || defined('SITE_LEGAL_NAME_EN')) {
            $this->markTestSkipped('This site already has a legal name configured.');
        }
        define('SITE_LEGAL_NAME_BG', self::LEGAL_BG);
        define('SITE_LEGAL_NAME_EN', self::LEGAL_EN);
    }

    private static function loadGenerators(): void
    {
        foreach (['InvoiceGenerator', 'ReceiptGenerator', 'CreditNoteGenerator', 'DonationCertGenerator'] as $cls) {
            require_once self::root() . "/includes/documents/$cls.php";
            $t = 'IssuerTest' . $cls;
            if (!class_exists($t)) {
                eval("class $t extends $cls { public function html(array \$o, array \$i, array \$d): string { return \$this->buildHtml(\$o, \$i, \$d); } }");
            }
        }
    }

    private static function order(string $type, string $lang = 'bg'): array
    {
        return [
            'id' => 1, 'order_number' => 'OM-20260101-TEST', 'customer_name' => 'Мария Петрова',
            'customer_email' => 'maria@example.com', 'total_eur' => 20.0, 'subtotal_eur' => 20.0,
            'shipping_eur' => 0.0, 'payment_method' => 'card', 'created_at' => '2026-01-15 10:30:00',
            'type' => $type,
            'invoice_data' => json_encode(['donor_type' => 'individual', 'lang' => $lang]),
        ];
    }

    private static function items(string $type): array
    {
        return $type === 'donation'
            ? [['type' => 'donation', 'amount_eur' => 20.0]]
            : [['type' => 'product', 'name_bg' => 'Тениска', 'quantity' => 1, 'price_eur' => 20.0, 'subtotal_eur' => 20.0]];
    }

    // ── org_legal_name() ─────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testLegalNameWinsOverTheSiteName(): void
    {
        $this->defineLegalName();
        $this->assertSame(self::LEGAL_BG, org_legal_name('bg'));
        $this->assertSame(self::LEGAL_EN, org_legal_name('en'));
    }

    public function testWithoutALegalNameItIsTheSiteName(): void
    {
        $bg = defined('SITE_LEGAL_NAME_BG') ? trim((string) SITE_LEGAL_NAME_BG) : '';
        $en = defined('SITE_LEGAL_NAME_EN') ? trim((string) SITE_LEGAL_NAME_EN) : '';
        if ($bg !== '' || $en !== '') $this->markTestSkipped('This site has a legal name configured.');
        $this->assertSame(SITE_NAME_BG, org_legal_name('bg'));
        $this->assertSame(SITE_NAME_EN, org_legal_name('en'));
    }

    // ── documents ────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testInvoiceReceiptAndCreditNoteNameTheLegalEntity(): void
    {
        $this->defineLegalName();
        self::loadGenerators();
        $escaped = htmlspecialchars(self::LEGAL_BG, ENT_QUOTES, 'UTF-8');
        foreach (['IssuerTestInvoiceGenerator', 'IssuerTestReceiptGenerator', 'IssuerTestCreditNoteGenerator'] as $cls) {
            $html = (new $cls())->html(self::order('physical'), self::items('physical'), ['formatted_number' => '00001']);
            $this->assertStringContainsString($escaped, $html, "$cls must name the legal entity, escaped");
            $this->assertStringNotContainsString(SITE_NAME_BG . '</', $html, "$cls must not name the brand as the issuer");
        }
    }

    #[RunInSeparateProcess]
    public function testDonationCertificateIsIssuedByTheLegalEntityInTheDonorsLanguage(): void
    {
        $this->defineLegalName();
        self::loadGenerators();
        $gen = new IssuerTestDonationCertGenerator();

        $bg = $gen->html(self::order('donation', 'bg'), self::items('donation'), ['formatted_number' => '00002']);
        $this->assertStringContainsString('С настоящия сертификат ' . htmlspecialchars(self::LEGAL_BG, ENT_QUOTES, 'UTF-8') . ', вписана', $bg);
        $this->assertStringContainsString('letter-spacing:0.04em;">' . htmlspecialchars(self::LEGAL_BG, ENT_QUOTES, 'UTF-8') . '</div>', $bg, 'the header names the legal entity');

        $en = $gen->html(self::order('donation', 'en'), self::items('donation'), ['formatted_number' => '00003']);
        $this->assertStringContainsString('This certificate is issued by ' . htmlspecialchars(self::LEGAL_EN, ENT_QUOTES, 'UTF-8') . ', registered', $en);
        $this->assertStringContainsString('letter-spacing:0.04em;">' . htmlspecialchars(self::LEGAL_EN, ENT_QUOTES, 'UTF-8') . '</div>', $en, 'an English certificate uses the English legal name');
    }

    public function testNoDocumentPrintsTheSiteNameAsTheIssuer(): void
    {
        foreach (glob(self::root() . '/includes/documents/*Generator.php') as $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString('FOUNDATION', $src, basename($path) . ': use self::foundation(), which carries the legal name');
            $code = preg_replace('#//[^\n]*#', '', $src);
            $code = preg_replace("#'DONATION_PURPOSE_(BG|EN)'\)[^;]*;#s", '', (string) $code); // the purpose fallback names the site on purpose
            $this->assertDoesNotMatchRegularExpression('/\bSITE_NAME_(BG|EN)\b/', (string) $code, basename($path) . ' prints the site name where the legal name belongs');
        }
    }

    public function testAdminsCanSetTheLegalNameWithoutEditingFiles(): void
    {
        $this->assertArrayHasKey('site_legal_name_bg', org_fields());
        $this->assertArrayHasKey('site_legal_name_en', org_fields());
        // The wizard leaves it out (only what a site can't start without) but
        // writes the constants and sends the admin straight to the field.
        require_once self::root() . '/install/wizard-lib.php';
        $config = wizard_site_config([
            'account' => ['admin_email' => 'a@example.org'],
            'org'     => ['site_name_bg' => 'Пример', 'site_name_en' => '', 'site_url' => 'https://example.org', 'site_email' => 'a@example.org'],
            'look'    => ['brand_theme' => 'classic', 'brand_primary' => '#000000', 'brand_accent' => '#111111'],
            'modules' => ['modules' => []],
        ]);
        $this->assertSame('', $config['SITE_LEGAL_NAME_BG']);
        $this->assertSame('', $config['SITE_LEGAL_NAME_EN']);
        $wizard = (string) file_get_contents(self::root() . '/install/index.php');
        $this->assertStringContainsString('/admin/organisation.php#f-site_legal_name_bg', $wizard);
    }
}
