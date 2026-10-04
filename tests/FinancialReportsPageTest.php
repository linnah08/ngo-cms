<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The public reports page (BG and EN) and its footer link. */
final class FinancialReportsPageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/financial_reports.php';
    }

    private function render(array $reports, string $lang = 'bg'): string
    {
        ob_start();
        (static function (array $reports, string $lang): void {
            require dirname(__DIR__) . '/templates/financial-reports.php';
        })($reports, $lang);
        return (string) ob_get_clean();
    }

    private function one(array $doc = []): array
    {
        return [['year' => 2025, 'documents' => [$doc + [
            'id' => 'aaaaaaaa', 'title' => 'Годишен финансов отчет', 'description' => 'Приходи и разходи',
            'url' => '/assets/files/reports/2025-aaaaaaaa.pdf', 'bytes' => 12_582_912, 'exists' => true,
        ]]]];
    }

    public function testDocumentHasOpenAndDownloadWithSize(): void
    {
        $html = $this->render($this->one());
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('2025', $html);
        $this->assertStringContainsString('data-report-open="/assets/files/reports/2025-aaaaaaaa.pdf"', $html);
        $this->assertMatchesRegularExpression('#href="/assets/files/reports/2025-aaaaaaaa\.pdf"[^>]*>\s*Изтегли \(PDF, 12 MB\)#u', $html);
    }

    public function testEnglishSaysTheDocumentIsInBulgarian(): void
    {
        $html = $this->render($this->one(), 'en');
        $this->assertStringContainsString('Download (PDF, 12 MB, in Bulgarian)', $html);
        $this->assertStringContainsString('>Open<', $html);
    }

    public function testEmptyStateIsPlainWords(): void
    {
        $this->assertStringContainsString('Още няма публикувани отчети.', $this->render([]));
        $this->assertStringContainsString('No reports have been published yet.', $this->render([], 'en'));
    }

    /** Review Focus 1 */
    public function testMissingFileShowsANoticeAndNoButtons(): void
    {
        $html = $this->render($this->one(['exists' => false, 'bytes' => null]));
        $this->assertStringContainsString('Файлът не е наличен', $html);
        $this->assertStringNotContainsString('data-report-open', $html);
        $this->assertStringNotContainsString('Изтегли', $html);
    }

    /** Review Focus 3 */
    public function testTextIsEscaped(): void
    {
        $html = $this->render($this->one(['title' => '"><script>x</script>', 'description' => 'A & B']));
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('A &amp; B', $html);
    }

    public function testBothPagesUseTheTemplateAndTheHeaderAndFooter(): void
    {
        foreach (['/finansovi-otcheti/index.php', '/en/financial-reports/index.php'] as $p) {
            $src = (string) file_get_contents(dirname(__DIR__) . $p);
            $this->assertStringContainsString('/templates/financial-reports.php', $src, $p);
            $this->assertStringContainsString('/templates/header.php', $src, $p);
            $this->assertStringContainsString('/templates/footer.php', $src, $p);
        }
    }

    public function testFooterLinkOnlyWhenSomethingIsPublished(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/templates/footer.php');
        $this->assertMatchesRegularExpression("#reports_any_published\(\)[^?]*\?>\s*<a href=\"<\?= \\\$lang === 'bg' \? '/finansovi-otcheti/' : '/en/financial-reports/' \?>\"#", $src);
        foreach (['bg' => 'Финансови отчети', 'en' => 'Financial reports'] as $lang => $label) {
            $s = json_decode((string) file_get_contents(dirname(__DIR__) . "/content/$lang/strings.json"), true);
            $this->assertSame($label, $s['footer.reports'] ?? null);
        }
    }

    public function testSitemapListsThePagesOnlyWhenPublished(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/sitemap.php');
        $this->assertMatchesRegularExpression("#if \(reports_any_published\(\)\)\s*\{\s*array_push\(\\\$static, '/finansovi-otcheti/', '/en/financial-reports/'\);#", $src);
    }
}
