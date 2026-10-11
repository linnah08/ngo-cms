<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The EN privacy, legal and terms pages used to ignore the English an admin
 * saved for them — visitors read built-in text naming the site, not the legal
 * entity, while the admin's translation sat unused. And that built-in text
 * carried one organisation's own address, phone, company number, director and
 * IBAN into every site on the CMS.
 */
final class LegalPagesTest extends TestCase
{
    private const PAGES = [
        'en/privacy-policy/index.php' => 'privacy',
        'en/legal/index.php'          => 'legal_info',
        'en/terms/index.php'          => 'terms',
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/legal_pages.php';
    }

    public function test_returns_the_english_the_admin_saved(): void
    {
        $pages = ['legal' => ['privacy' => '<p>БГ</p>', 'privacy_en' => '<h2>1. Introduction</h2><p>EN</p>']];
        $this->assertSame('<h2>1. Introduction</h2><p>EN</p>', legal_en_content('privacy', $pages));
    }

    public function test_nothing_saved_means_the_page_falls_back(): void
    {
        $this->assertSame('', legal_en_content('terms', []));
        $this->assertSame('', legal_en_content('terms', ['legal' => ['terms' => '<p>само БГ</p>']]));
        $this->assertSame('', legal_en_content('terms', ['legal' => ['terms_en' => ['not', 'html']]]));
    }

    public function test_an_emptied_editor_counts_as_nothing_saved(): void
    {
        foreach (['', '   ', '<p>&nbsp;</p>', "<p>\u{00A0}</p>\n<p></p>", '<h2> </h2>'] as $empty) {
            $this->assertSame('', legal_en_content('legal_info', ['legal' => ['legal_info_en' => $empty]]), json_encode($empty));
        }
    }

    public function test_each_english_page_shows_its_own_admin_text(): void
    {
        foreach (self::PAGES as $file => $key) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            $this->assertStringContainsString("legal_en_content('$key')", $src, "$file must read {$key}_en");
            $this->assertStringContainsString('<?= $admin_en ?>', $src, "$file must print the admin's English");
        }
    }

    public function test_the_fallback_text_carries_no_organisation_data(): void
    {
        // Each site's own details come from its settings; a literal here reaches every NGO on the CMS.
        foreach (array_keys(self::PAGES) as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            $this->assertDoesNotMatchRegularExpression('/\b[A-Z]{2}\d{2}[A-Z]{4}\d{6,}/', $src, "$file has an IBAN");
            $this->assertDoesNotMatchRegularExpression('/\+?\d[\d ]{8,}\d/', $src, "$file has a phone or company number");
            $this->assertDoesNotMatchRegularExpression('/\bul\.\s|Street\b|village\b/i', $src, "$file has a street address");
        }
    }

    public function test_the_fallback_names_the_registered_details_when_set(): void
    {
        $lines = legal_en_identity_lines(true);
        foreach (['SITE_ADDRESS' => 'Address: ', 'SITE_EIK' => 'UIC: ', 'SITE_MOL' => 'Representative: '] as $c => $label) {
            $set = defined($c) && trim((string) constant($c)) !== '';
            $this->assertSame($set, str_contains($lines, $label), "$label only when $c is filled in");
        }
        $this->assertStringNotContainsString('Representative: ', legal_en_identity_lines(false));
        foreach (['en/privacy-policy/index.php', 'en/legal/index.php'] as $f) {
            $this->assertStringContainsString('legal_en_identity_lines(', (string) file_get_contents(dirname(__DIR__) . '/' . $f));
        }
    }
}
