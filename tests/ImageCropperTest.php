<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Static wiring guards for the shared image cropper. The cropper itself is
 * client-side JS; these tests ensure the integration points are not removed
 * by accident (assets loaded, inputs tagged, TinyMCE picker wired).
 */
final class ImageCropperTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents(self::$root . '/' . $rel);
    }

    public function testVendoredCropperAssetsExist(): void
    {
        $this->assertFileExists(self::$root . '/assets/vendor/cropperjs/cropper.min.js');
        $this->assertFileExists(self::$root . '/assets/vendor/cropperjs/cropper.min.css');
        $this->assertFileExists(self::$root . '/assets/js/image-cropper.js');
    }

    public function testHeaderLoadsCropperAssets(): void
    {
        $h = $this->read('admin/includes/admin-header.php');
        $this->assertStringContainsString('/assets/vendor/cropperjs/cropper.min.css', $h);
        $this->assertStringContainsString('/assets/vendor/cropperjs/cropper.min.js', $h);
        $this->assertStringContainsString('/assets/js/image-cropper.js', $h);
    }

    public function testTinymceFilePickerWiredToCropper(): void
    {
        $h = $this->read('admin/includes/admin-header.php');
        $this->assertStringContainsString('file_picker_callback', $h);
        $this->assertStringContainsString('window.OMCrop', $h);
    }

    public function testCropperJsExposesApiAndInterceptor(): void
    {
        $js = $this->read('assets/js/image-cropper.js');
        $this->assertStringContainsString('window.OMCrop', $js);
        $this->assertStringContainsString('data-om-crop', $js);
        $this->assertStringContainsString('DataTransfer', $js);
        // GIF bypass and transparency handling must remain.
        $this->assertStringContainsString("image/gif", $js);
        $this->assertStringContainsString('image/png', $js);
    }

    /**
     * Every single-file image input we intended to crop must keep its
     * data-om-crop tag. The multiple-file campaign gallery is intentionally
     * excluded (the interceptor skips multiple inputs).
     */
    public function testFileInputsAreTagged(): void
    {
        $expected = [
            'admin/article-edit.php'  => 1,
            'admin/partners.php'      => 1,
            'admin/product-edit.php'  => 3,
            'admin/pages.php'         => 5,
        ];
        foreach ($expected as $file => $count) {
            $src = $this->read($file);
            $this->assertSame(
                $count,
                substr_count($src, 'data-om-crop'),
                "Expected {$count} data-om-crop input(s) in {$file}"
            );
        }
    }
}
