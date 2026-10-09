<?php
declare(strict_types=1);

/**
 * Test photos that behave like a phone's: stored as the sensor saw them, with an
 * EXIF Orientation flag saying how to turn them upright. GD can't write EXIF, so the
 * APP1 block is built by hand.
 *
 * Each photo is blue with one red block at stored x 4–20, y 2–10, so the block's
 * centre (12, 6) is off the diagonal and every one of the eight orientations puts it
 * somewhere different.
 */
final class ExifJpeg
{
    public const MARK_X = 12;
    public const MARK_Y = 6;

    /** Write a $w×$h JPEG to $path; $orientation null means no EXIF at all. */
    public static function write(string $path, int $w, int $h, ?int $orientation): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 60, 220));
        imagefilledrectangle($im, 4, 2, 20, 10, imagecolorallocate($im, 230, 20, 20));
        ob_start();
        imagejpeg($im, null, 95);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        if ($orientation !== null) {
            // TIFF header (big-endian) + one IFD0 entry: tag 0x0112 Orientation, SHORT, count 1.
            $tiff = "MM\x00\x2A" . pack('N', 8)
                  . pack('n', 1)
                  . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00"
                  . pack('N', 0);
            $payload = "Exif\x00\x00" . $tiff;
            $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
            $jpeg = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
        }
        file_put_contents($path, $jpeg);
    }

    /** Where the stored point ($x, $y) of a $w×$h photo lands once turned upright. */
    public static function upright(int $x, int $y, int $w, int $h, int $orientation): array
    {
        return match ($orientation) {
            2 => [$w - 1 - $x, $y],
            3 => [$w - 1 - $x, $h - 1 - $y],
            4 => [$x, $h - 1 - $y],
            5 => [$y, $x],
            6 => [$h - 1 - $y, $x],
            7 => [$h - 1 - $y, $w - 1 - $x],
            8 => [$y, $w - 1 - $x],
            default => [$x, $y],
        };
    }

    /** Is the pixel at ($x, $y) of $im the red block (allowing for JPEG blur)? */
    public static function isRed(\GdImage $im, int $x, int $y): bool
    {
        $c = imagecolorat($im, $x, $y);
        return (($c >> 16) & 0xFF) > 150 && ($c & 0xFF) < 100;
    }
}
