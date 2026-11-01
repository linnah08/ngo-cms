<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every file upload in the admin and in Edit mode shows a progress bar
 * (assets/js/upload-progress.js, window.OMUpload). fetch() can't report how much
 * has been sent, so an upload made with it looks like nothing is happening for
 * a few seconds. These are static wiring guards: the bar is client-side JS.
 */
final class UploadProgressTest extends TestCase
{
    private static string $root;

    /** The upload endpoints. A fetch() to any of them would bring the silent wait back. */
    private const ENDPOINTS = ['upload-product-image.php', 'inline-upload.php', 'home-upload.php', 'upload-file.php'];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents(self::$root . '/' . $rel);
    }

    public function testHelperReportsUploadProgressAccessibly(): void
    {
        $js = $this->read('assets/js/upload-progress.js');
        $this->assertStringContainsString('window.OMUpload', $js);
        $this->assertStringContainsString('XMLHttpRequest', $js);
        $this->assertStringContainsString('upload.onprogress', $js);
        // Screen readers: a real progressbar with its value, and a live region for the phases.
        $this->assertStringContainsString("'progressbar'", $js);
        $this->assertStringContainsString('aria-valuenow', $js);
        $this->assertStringContainsString("'aria-live'", $js);
        // The server still resizes the photo after the file has arrived.
        $this->assertStringContainsString('Обработва се', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        // Errors stay on screen until closed, never a browser alert().
        $this->assertStringContainsString("role', 'alert'", $js);
        $this->assertStringNotContainsString('alert(', str_replace(["'alert'"], '', $js));
    }

    public function testHelperIsLoadedWhereverTheEditorIs(): void
    {
        // tinymce-base.php is printed in the admin <head> and in the public admin bar (Edit mode).
        $base = $this->read('templates/tinymce-base.php');
        $this->assertStringContainsString("versioned_asset('/assets/js/upload-progress.js')", $base);
        $this->assertStringContainsString('tinymce-base.php', $this->read('admin/includes/admin-header.php'));
        $public = $this->read('templates/header.php');
        $this->assertLessThan(strpos($public, 'inline-cms.js'), strpos($public, 'tinymce-base.php'),
            'upload-progress.js must load before inline-cms.js uses it');
    }

    /** @return array<string, array{string, int}> file => [file, how many uploads it makes] */
    public static function uploadSites(): array
    {
        return [
            'editor image + PDF'   => ['templates/tinymce-base.php', 2],
            'article photos'       => ['admin/article-edit.php', 1],
            'product photos'       => ['admin/product-edit.php', 3],
            'home section photos'  => ['admin/home-sections.php', 1],
            'Edit mode image swap' => ['assets/js/inline-cms.js', 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploadSites')]
    public function testUploadSiteUsesTheProgressBar(string $file, int $uploads): void
    {
        $src = $this->read($file);
        $this->assertSame($uploads, substr_count($src, 'OMUpload.send('), $file);
        foreach (self::ENDPOINTS as $endpoint) {
            $this->assertDoesNotMatchRegularExpression('#fetch\(\s*[\'"]/admin/' . preg_quote($endpoint, '#') . '#', $src,
                "$file uploads to $endpoint with fetch(), which shows no progress");
        }
    }

    public function testNoOtherUploadBypassesTheProgressBar(): void
    {
        $hits = [];
        foreach (['admin', 'assets/js', 'templates', 'includes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!in_array($f->getExtension(), ['php', 'js'], true)) continue;
                $src = (string) file_get_contents($f->getPathname());
                foreach (self::ENDPOINTS as $endpoint) {
                    if (preg_match('#fetch\(\s*[\'"][^\'"]*/admin/' . preg_quote($endpoint, '#') . '#', $src)) {
                        $hits[] = substr($f->getPathname(), strlen(self::$root) + 1) . ' → ' . $endpoint;
                    }
                }
            }
        }
        $this->assertSame([], $hits, "Upload with OMUpload.send() instead:\n" . implode("\n", $hits));
    }
}
