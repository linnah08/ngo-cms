<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/Support/ExifJpeg.php';

/**
 * Phone photos carry an EXIF flag saying how to turn them upright. GD ignores it, so
 * every upload is turned upright before GD re-saves it (which drops the flag) —
 * otherwise the photo goes sideways on the site and on Instagram.
 */
#[Group('images')]
final class ImageOrientationTest extends TestCase
{
    private string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/images.php';
    }

    protected function setUp(): void
    {
        if (!function_exists('exif_read_data')) $this->markTestSkipped('No exif extension.');
        $this->tmpDir = sys_get_temp_dir() . '/om-image-orient-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public static function orientations(): array
    {
        $out = [];
        foreach (range(2, 8) as $o) $out["orientation $o"] = [$o];
        return $out;
    }

    /** The red block is where a browser would show it, and nowhere another orientation would put it. */
    private function assertUpright(string $path, int $w, int $h, int $o): void
    {
        $im = imagecreatefromjpeg($path);
        $this->assertSame($o >= 5 ? [$h, $w] : [$w, $h], [imagesx($im), imagesy($im)], 'size');
        [$x, $y] = ExifJpeg::upright(ExifJpeg::MARK_X, ExifJpeg::MARK_Y, $w, $h, $o);
        $this->assertTrue(ExifJpeg::isRed($im, $x, $y), "red block at ($x, $y)");
        foreach (range(1, 8) as $other) {
            if ($other === $o || ($other >= 5) !== ($o >= 5)) continue;
            [$ox, $oy] = ExifJpeg::upright(ExifJpeg::MARK_X, ExifJpeg::MARK_Y, $w, $h, $other);
            if ([$ox, $oy] === [$x, $y]) continue;
            $this->assertFalse(ExifJpeg::isRed($im, $ox, $oy), "turned like orientation $other");
        }
        imagedestroy($im);
        $this->assertSame(1, image_exif_orientation($path), 'no flag left to turn it twice');
    }

    #[DataProvider('orientations')]
    public function testOrientationIsRead(int $o): void
    {
        $path = $this->tmpDir . '/p.jpg';
        ExifJpeg::write($path, 60, 30, $o);
        $this->assertSame($o, image_exif_orientation($path));
    }

    public function testNoFlagNotJpegOrMissingFileReadAsUpright(): void
    {
        ExifJpeg::write($this->tmpDir . '/plain.jpg', 60, 30, null);
        $this->assertSame(1, image_exif_orientation($this->tmpDir . '/plain.jpg'));

        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $this->tmpDir . '/p.png');
        imagedestroy($im);
        $this->assertSame(1, image_exif_orientation($this->tmpDir . '/p.png'));

        $this->assertSame(1, image_exif_orientation($this->tmpDir . '/missing.jpg'));
    }

    public function testOutOfRangeFlagIsIgnored(): void
    {
        $path = $this->tmpDir . '/p.jpg';
        ExifJpeg::write($path, 60, 30, 9);
        $this->assertSame(1, image_exif_orientation($path));
        $this->assertFalse(image_fix_orientation($path));
    }

    #[DataProvider('orientations')]
    public function testFixOrientationResavesUpright(int $o): void
    {
        $path = $this->tmpDir . '/p.jpg';
        ExifJpeg::write($path, 60, 30, $o);
        $this->assertTrue(image_fix_orientation($path));
        $this->assertUpright($path, 60, 30, $o);
    }

    public function testFixOrientationLeavesUnflaggedPhotoAlone(): void
    {
        foreach (['plain.jpg' => null, 'one.jpg' => 1] as $name => $o) {
            $path = $this->tmpDir . '/' . $name;
            ExifJpeg::write($path, 60, 30, $o);
            $before = md5_file($path);
            $this->assertFalse(image_fix_orientation($path));
            $this->assertSame($before, md5_file($path), $name);
        }
    }

    public function testUprightSizeSwapsForQuarterTurns(): void
    {
        ExifJpeg::write($this->tmpDir . '/six.jpg', 60, 30, 6);
        ExifJpeg::write($this->tmpDir . '/three.jpg', 60, 30, 3);
        $this->assertSame([30, 60], image_upright_size($this->tmpDir . '/six.jpg'));
        $this->assertSame([60, 30], image_upright_size($this->tmpDir . '/three.jpg'));
        $this->assertNull(image_upright_size($this->tmpDir . '/missing.jpg'));
    }

    /** A small flagged photo used to skip the resize and keep its flag; it is now turned upright too. */
    #[DataProvider('orientations')]
    public function testResizeTurnsSmallFlaggedPhotoUpright(int $o): void
    {
        $path = $this->tmpDir . '/p.jpg';
        ExifJpeg::write($path, 60, 30, $o);
        $this->assertTrue(image_resize_to_fit($path, 2000, 90));
        $this->assertUpright($path, 60, 30, $o);
    }

    /** A big phone photo: shrunk to fit by its upright sides, not sideways. */
    public function testResizeShrinksBigFlaggedPhotoByItsUprightSides(): void
    {
        $path = $this->tmpDir . '/p.jpg';
        ExifJpeg::write($path, 3000, 1500, 6);
        $this->assertTrue(image_resize_to_fit($path, 2000, 90));
        [$w, $h] = getimagesize($path);
        $this->assertSame([1000, 2000], [$w, $h]);
        $this->assertSame(1, image_exif_orientation($path));
    }
}
