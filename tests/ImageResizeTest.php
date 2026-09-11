<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * image_resize_to_fit() runs after every admin image upload (article, page,
 * partner logo, campaign photo, product image) so a single change here
 * applies everywhere. Oversized JPEG/PNG/WebP get shrunk to fit maxDim;
 * already-small images, GIF, and non-images are left untouched.
 */
#[Group('images')]
final class ImageResizeTest extends TestCase
{
    private string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/images.php';
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/om-image-resize-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function makeJpeg(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 50, 50));
        imagejpeg($im, $path, 95);
        imagedestroy($im);
    }

    private function makePng(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagepng($im, $path);
        imagedestroy($im);
    }

    public function testOversizedJpegIsShrunkToMaxDimension(): void
    {
        $path = $this->tmpDir . '/big.jpg';
        $this->makeJpeg($path, 4000, 3000);

        $result = image_resize_to_fit($path, 2000, 82);

        $this->assertTrue($result);
        [$w, $h] = getimagesize($path);
        $this->assertSame(2000, $w);
        $this->assertSame(1500, $h); // aspect ratio preserved
    }

    public function testOversizedJpegShrinksFileSize(): void
    {
        $path = $this->tmpDir . '/big2.jpg';
        $this->makeJpeg($path, 4000, 3000);
        $before = filesize($path);

        image_resize_to_fit($path, 2000, 82);

        $this->assertLessThan($before, filesize($path));
    }

    public function testImageAlreadyWithinBoundsIsUntouched(): void
    {
        $path = $this->tmpDir . '/small.jpg';
        $this->makeJpeg($path, 800, 600);
        $before = filesize($path);

        $result = image_resize_to_fit($path, 2000, 82);

        $this->assertFalse($result);
        $this->assertSame($before, filesize($path));
        [$w, $h] = getimagesize($path);
        $this->assertSame(800, $w);
        $this->assertSame(600, $h);
    }

    public function testOversizedPortraitCapsOnHeightNotWidth(): void
    {
        $path = $this->tmpDir . '/tall.jpg';
        $this->makeJpeg($path, 1000, 5000);

        image_resize_to_fit($path, 2000, 82);

        [$w, $h] = getimagesize($path);
        $this->assertSame(400, $w);
        $this->assertSame(2000, $h);
    }

    public function testOversizedPngPreservesPngFormat(): void
    {
        $path = $this->tmpDir . '/big.png';
        $this->makePng($path, 3000, 2400);

        $result = image_resize_to_fit($path, 2000, 82);

        $this->assertTrue($result);
        $info = getimagesize($path);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertSame(2000, $info[0]);
    }

    public function testNonImageFileIsLeftAlone(): void
    {
        $path = $this->tmpDir . '/not-an-image.jpg';
        file_put_contents($path, 'not actually a jpeg');

        $result = image_resize_to_fit($path);

        $this->assertFalse($result);
        $this->assertSame('not actually a jpeg', file_get_contents($path));
    }

    public function testMissingFileReturnsFalseWithoutError(): void
    {
        $this->assertFalse(image_resize_to_fit($this->tmpDir . '/does-not-exist.jpg'));
    }
}
