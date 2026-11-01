<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/images.php';

/**
 * An uploaded file is saved under an extension read from what it is, never from
 * the name it was uploaded with: a PHP script with an image header passes a
 * content check, and saved as .php in a public folder the server runs it.
 */
final class UploadExtensionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);
        return $path;
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    public function test_an_image_gets_the_extension_of_what_it_is(): void
    {
        $this->assertSame('png', image_upload_ext($this->tmp($this->png())));
    }

    public function test_a_script_with_an_image_header_is_refused_unless_gif_is_allowed(): void
    {
        $polyglot = $this->tmp("GIF89a<?php system(\$_GET['c']); ?>");
        $this->assertNull(image_upload_ext($polyglot), 'GIF is not in the default list');
        // Allowed, it can only ever be saved as .gif — which no server runs.
        $this->assertSame('gif', image_upload_ext($polyglot, ['image/gif' => 'gif']));
    }

    public function test_scripts_svg_and_missing_files_are_refused(): void
    {
        $this->assertNull(image_upload_ext($this->tmp("<?php echo 1;")));
        $this->assertNull(image_upload_ext($this->tmp('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
        $this->assertNull(image_upload_ext('/no/such/file'));
    }

    /** Every PHP file the site serves or includes. */
    private static function phpFiles(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            if ($f->getExtension() !== 'php' || preg_match('#^(vendor|node_modules|tests|\.git|\.claude)/#', $rel)) continue;
            $files[] = $rel;
        }
        return $files;
    }

    public function test_no_code_takes_an_extension_from_an_uploaded_file_name(): void
    {
        $offenders = [];
        foreach (self::phpFiles() as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            if (preg_match('/pathinfo\(\s*\$(_FILES\b|\w+\[\'name\'\])/', $src)) $offenders[] = $rel;
        }
        $this->assertSame([], $offenders, 'Use image_upload_ext() on the uploaded file instead.');
    }

    public static function imageFolderNames(): array
    {
        return [
            'script'               => ['shell.php', true],
            'script, image first'  => ['shell.jpg.php', true],
            'other script'         => ['x.phtml', true],
            'html'                 => ['page.html', true],
            'jpg'                  => ['photo.jpg', false],
            'jpeg'                 => ['photo.jpeg', false],
            'png'                  => ['logo.png', false],
            'webp'                 => ['hero.webp', false],
            'gif'                  => ['anim.gif', false],
            'svg'                  => ['logo.svg', false],
        ];
    }

    #[DataProvider('imageFolderNames')]
    public function test_the_image_folder_serves_only_images(string $name, bool $refused): void
    {
        $ht = (string) file_get_contents(self::root() . '/assets/images/.htaccess');
        $this->assertStringContainsString('php_flag engine off', $ht);
        $this->assertMatchesRegularExpression('/<FilesMatch "([^"]+)">\s*<IfModule mod_authz_core\.c>\s*Require all denied/', $ht);
        preg_match('/<FilesMatch "([^"]+)">\s*<IfModule mod_authz_core\.c>\s*Require all denied/', $ht, $m);
        $this->assertSame($refused, (bool) preg_match('/' . $m[1] . '/', $name));
    }

    public function test_the_print_design_folder_is_closed_on_every_apache(): void
    {
        $ht = (string) file_get_contents(self::root() . '/uploads/print-designs/.htaccess');
        $this->assertStringContainsString('Require all denied', $ht);
        $this->assertStringContainsString('Deny from all', $ht);
        $this->assertStringContainsString('php_flag engine off', $ht);
    }
}
