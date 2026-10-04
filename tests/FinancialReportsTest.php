<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Yearly reports: several documents per year, shared by the BG and EN pages. */
final class FinancialReportsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/financial_reports.php';
    }

    private static function fields(string $t = 'Годишен финансов отчет'): array
    {
        return ['title_bg' => $t, 'title_en' => 'Annual statement', 'description_bg' => 'Описание', 'description_en' => ''];
    }

    private static function two(): array
    {
        $d = reports_add_year(['years' => []], 2024);
        $d = reports_add_year($d, 2025);
        $d = reports_add_document($d, 2025, self::fields('А'), '2025-aaaaaaaa.pdf');
        $d = reports_add_document($d, 2025, self::fields('Б'), '2025-bbbbbbbb.pdf');
        return reports_add_document($d, 2024, self::fields('В'), '2024-cccccccc.pdf');
    }

    public function testYearsAreKeptNewestFirst(): void
    {
        $this->assertSame([2025, 2024], array_column(self::two()['years'], 'year'));
    }

    public function testBadOrDuplicateYearsAreRefusedInPlainWords(): void
    {
        $d = self::two();
        $this->assertIsString(reports_add_year($d, 2025));
        $this->assertIsString(reports_add_year($d, 1989));
        $this->assertIsString(reports_add_year($d, (int) date('Y') + 2));
        $this->assertIsArray(reports_add_year($d, (int) date('Y') + 1));
    }

    public function testDocumentIdComesFromItsFile(): void
    {
        $doc = self::two()['years'][0]['documents'][0];
        $this->assertSame('aaaaaaaa', $doc['id']);
        $this->assertSame('2025-aaaaaaaa.pdf', $doc['file']);
    }

    public function testMoveAndUpdate(): void
    {
        $d = reports_move_document(self::two(), 'bbbbbbbb', -1);
        $this->assertSame(['Б', 'А'], array_column($d['years'][0]['documents'], 'title_bg'));
        $d = reports_update_document($d, 'aaaaaaaa', self::fields('Ново'), '2025-dddddddd.pdf');
        $doc = $d['years'][0]['documents'][1];
        $this->assertSame('Ново', $doc['title_bg']);
        $this->assertSame('2025-dddddddd.pdf', $doc['file']);
        $this->assertSame('aaaaaaaa', $doc['id'], 'a replaced file keeps the document id');
    }

    public function testDeletesReturnTheFilesToRemove(): void
    {
        $r = reports_delete_document(self::two(), 'aaaaaaaa');
        $this->assertSame('2025-aaaaaaaa.pdf', $r['file']);
        $this->assertCount(1, $r['data']['years'][0]['documents']);

        $r = reports_delete_year(self::two(), 2025);
        $this->assertSame(['2025-aaaaaaaa.pdf', '2025-bbbbbbbb.pdf'], $r['files']);
        $this->assertSame([2024], array_column($r['data']['years'], 'year'));
    }

    public function testPublicListUsesTheLanguageAndFallsBackToBulgarian(): void
    {
        $size = static fn(string $f): ?int => 1024;
        $bg = reports_public_list(self::two(), 'bg', $size);
        $en = reports_public_list(self::two(), 'en', $size);
        $this->assertSame('А', $bg[0]['documents'][0]['title']);
        $this->assertSame('Annual statement', $en[0]['documents'][0]['title']);
        $this->assertSame('Описание', $en[0]['documents'][0]['description'], 'empty EN description falls back to BG');
        $this->assertSame('/assets/files/reports/2025-aaaaaaaa.pdf', $bg[0]['documents'][0]['url']);
    }

    /** Review Focus 1 */
    public function testMissingFileIsFlaggedNotFatal(): void
    {
        $list = reports_public_list(self::two(), 'bg', static fn(string $f): ?int => $f === '2025-aaaaaaaa.pdf' ? null : 10);
        $this->assertFalse($list[0]['documents'][0]['exists']);
        $this->assertTrue($list[0]['documents'][1]['exists']);
    }

    public function testBadFileNamesInHandEditedJsonCountAsMissing(): void
    {
        $d = ['years' => [['year' => 2025, 'documents' => [
            ['id' => 'x', 'title_bg' => 'x', 'file' => '../../config.php'],
        ]]]];
        $list = reports_public_list($d, 'bg', static fn(string $f): ?int => 10);
        $this->assertFalse($list[0]['documents'][0]['exists']);
        $this->assertSame('', $list[0]['documents'][0]['url']);
        $this->assertFalse(reports_file_name_ok('2025-AAAAAAAA.pdf'));
        $this->assertTrue(reports_file_name_ok('2025-0a1b2c3d.pdf'));
    }

    public function testEmptyYearsAreNotShownAndCountAsNothingPublished(): void
    {
        $d = reports_add_year(['years' => []], 2025);
        $this->assertSame([], reports_public_list($d, 'bg'));
        $this->assertFalse(reports_any_published($d));
        $this->assertTrue(reports_any_published(self::two()));
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $file = sys_get_temp_dir() . '/fr-' . bin2hex(random_bytes(4)) . '.json';
        $this->assertTrue(reports_save(self::two(), $file));
        $this->assertSame(self::two(), reports_load($file));
        unlink($file);
        $this->assertSame(['years' => []], reports_load($file));
    }

    private function upload(string $bytes, int $err = UPLOAD_ERR_OK): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        return ['name' => 'отчет.pdf', 'tmp_name' => $tmp, 'error' => $err, 'size' => strlen($bytes)];
    }

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public function testARealPdfPasses(): void
    {
        $this->assertNull(reports_check_upload($this->upload(self::PDF)));
    }

    /** Review Focus 2 */
    public function testAnythingElseNamedPdfIsRefused(): void
    {
        foreach (["\xFF\xD8\xFF\xE0JFIF", '<html><script>x</script></html>', ''] as $bytes) {
            $this->assertSame('Позволени са само PDF файлове.', reports_check_upload($this->upload($bytes)));
        }
    }

    public function testPhpUploadErrorsBecomePlainWords(): void
    {
        $this->assertSame('Файлът е твърде голям за сървъра.', reports_check_upload($this->upload('', UPLOAD_ERR_INI_SIZE)));
        $this->assertSame('Изберете PDF файл.', reports_check_upload($this->upload('', UPLOAD_ERR_NO_FILE)));
        $this->assertNotNull(reports_check_upload($this->upload('', UPLOAD_ERR_PARTIAL)));
        $this->assertNotNull(reports_check_upload([]));
    }

    /** Review Focus 4: a failed upload stores nothing. */
    public function testStoringNamesTheFileAndRefusesWithoutLeavingAnything(): void
    {
        $dir = sys_get_temp_dir() . '/fr-' . bin2hex(random_bytes(4));
        $move = static fn(string $from, string $to): bool => rename($from, $to);

        $r = reports_store_upload($this->upload(self::PDF), 2025, $dir, $move);
        $this->assertNull($r['error']);
        $this->assertTrue(reports_file_name_ok($r['file']));
        $this->assertStringStartsWith('2025-', $r['file']);
        $this->assertFileExists($dir . '/' . $r['file']);

        $bad = reports_store_upload($this->upload('<html>'), 2025, $dir, $move);
        $this->assertNull($bad['file']);
        $this->assertSame('Позволени са само PDF файлове.', $bad['error']);
        $this->assertCount(1, glob($dir . '/*.pdf'));

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testTheReportsFolderServesOnlyPdfs(): void
    {
        $ht = (string) @file_get_contents(dirname(__DIR__) . '/assets/files/reports/.htaccess');
        $this->assertMatchesRegularExpression('#<IfModule mod_php\.c>\s*php_flag engine off#', $ht, 'a bare php_flag is a 500 on PHP-FPM hosts');
        $this->assertMatchesRegularExpression('#<IfModule mod_headers\.c>\s*Header set#', $ht);
        $this->assertMatchesRegularExpression('/FilesMatch/', $ht);
        $gi = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
        $this->assertStringContainsString('/content/financial-reports.json', $gi);
        $this->assertStringContainsString('/assets/files/reports/*.pdf', $gi);
    }
}
