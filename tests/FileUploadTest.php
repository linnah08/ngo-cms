<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/files.php';

/**
 * PDFs admins upload to link to (includes/files.php): only a real PDF is kept, under
 * a readable Latin name with a random part, in a folder that serves nothing but PDFs.
 */
final class FileUploadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ngo_files_' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $dir = $this->root . '/' . FILES_DIR;
        array_map('unlink', glob($dir . '/*') ?: []);
        for (; $dir !== $this->root && str_starts_with($dir, $this->root); $dir = dirname($dir)) @rmdir($dir);
        @rmdir($this->root);
    }

    private function tmp(string $body): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ngo_pdf_');
        file_put_contents($tmp, $body);
        return $tmp;
    }

    /** The smallest file finfo reports as application/pdf. */
    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public function test_a_real_pdf_is_stored_under_a_latin_name(): void
    {
        $r = file_upload_store($this->tmp($this->pdf()), 'Фондация Различни умове - интро.pdf', UPLOAD_ERR_OK, $this->root, false);

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertMatchesRegularExpression('#^/assets/files/uploads/fondatsiya-razlichni-umove-intro-[0-9a-f]{6}\.pdf$#', $r['path']);
        $this->assertFileExists($this->root . $r['path']);
        $this->assertSame('Фондация Различни умове - интро (PDF, 1 KB)', $r['text']);
    }

    /** The folder ships with its .htaccess (a deploy brings it, the PDFs stay out of git). */
    public function test_the_folder_serves_only_pdfs(): void
    {
        $ht = (string) file_get_contents(dirname(__DIR__) . '/' . FILES_DIR . '/.htaccess');
        $this->assertStringContainsString('Require all denied', $ht);
        $this->assertStringContainsString('\\.(?!pdf$)', $ht);
        $this->assertStringNotContainsString("\nphp_flag", $ht, 'a bare php_flag is a 500 on PHP-FPM hosts');
    }

    public function test_a_renamed_file_that_is_not_a_pdf_is_refused(): void
    {
        $r = file_upload_store($this->tmp('<?php echo "hi";'), 'leaflet.pdf', UPLOAD_ERR_OK, $this->root, false);

        $this->assertFalse($r['ok']);
        $this->assertSame('type', $r['reason']);
        $this->assertStringContainsString('не е PDF', $r['message']);
        $this->assertDirectoryDoesNotExist($this->root . '/' . FILES_DIR);
    }

    public function test_a_pdf_with_another_extension_is_refused(): void
    {
        $r = file_upload_store($this->tmp($this->pdf()), 'leaflet.php', UPLOAD_ERR_OK, $this->root, false);

        $this->assertFalse($r['ok']);
        $this->assertSame('type', $r['reason']);
    }

    public function test_too_big_and_empty_say_so_in_plain_words(): void
    {
        $big   = file_upload_store('', 'x.pdf', UPLOAD_ERR_INI_SIZE, $this->root, false);
        $none  = file_upload_store('', '', UPLOAD_ERR_NO_FILE, $this->root, false);
        $empty = file_upload_store($this->tmp(''), 'x.pdf', UPLOAD_ERR_OK, $this->root, false);

        $this->assertSame('size', $big['reason']);
        $this->assertStringContainsString('твърде голям', $big['message']);
        $this->assertSame('empty', $none['reason']);
        $this->assertSame('empty', $empty['reason']);
    }

    public function test_a_file_that_did_not_come_through_an_upload_is_refused(): void
    {
        $r = file_upload_store($this->tmp($this->pdf()), 'a.pdf', UPLOAD_ERR_OK, $this->root);

        $this->assertFalse($r['ok']);
        $this->assertSame('server', $r['reason']);
    }

    public function test_names_never_collide_and_never_run_out(): void
    {
        $this->assertNotSame(file_upload_name('a.pdf'), file_upload_name('a.pdf'));
        $this->assertMatchesRegularExpression('/^document-[0-9a-f]{6}\.pdf$/', file_upload_name('!!!.pdf'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,60}-[0-9a-f]{6}\.pdf$/', file_upload_name(str_repeat('дълго име ', 20) . '.pdf'));
        $this->assertStringNotContainsString('..', file_upload_name('../../config.php.pdf'));
    }

    public function test_sizes_read_the_way_people_say_them(): void
    {
        $this->assertSame('1 KB', file_size_label(200));
        $this->assertSame('320 KB', file_size_label(320 * 1024));
        $this->assertSame('1,4 MB', file_size_label((int) (1.4 * 1024 * 1024)));
    }
}
