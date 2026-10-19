<?php
/**
 * Shared upload-image helpers. Used by every admin upload endpoint so a
 * single change (target dimension, quality) applies everywhere at once.
 */

/**
 * The EXIF Orientation of a JPEG (1–8), or 1 when there is none, the file isn't
 * a JPEG, or the server has no exif extension. Phones store photos as the sensor
 * saw them and set this flag to say how to turn them upright. Browsers obey it;
 * GD ignores it — so anything GD touches must apply it first, or the photo ends up
 * sideways (on the site once re-saved, on Instagram once cropped).
 */
function image_exif_orientation(string $path): int
{
    if (!function_exists('exif_read_data')) return 1;
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) return 1;
    $exif = @exif_read_data($path, 'IFD0');
    $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    return $o >= 1 && $o <= 8 ? $o : 1;
}

/**
 * Turn $im upright for EXIF $orientation. Returns the upright image — a new one
 * when it had to be rotated (the old one is destroyed), else $im itself.
 */
function image_apply_orientation(\GdImage $im, int $orientation): \GdImage
{
    // imagerotate() turns counter-clockwise: 270 = a quarter turn clockwise.
    $turn = match ($orientation) { 3, 4 => 180, 5, 6 => 270, 7, 8 => 90, default => 0 };
    if ($turn) {
        $rotated = imagerotate($im, $turn, 0);
        if ($rotated) { imagedestroy($im); $im = $rotated; }
    }
    // 2, 4, 5, 7 are the mirror images of 1, 3, 6, 8: same turn, then a left-right flip.
    if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
    return $im;
}

/** Width and height as the photo is meant to be seen (EXIF applied), or null if unreadable. */
function image_upright_size(string $path): ?array
{
    $info = @getimagesize($path);
    if (!$info) return null;
    [$w, $h] = $info;
    return image_exif_orientation($path) >= 5 ? [$h, $w] : [$w, $h];
}

/**
 * Re-save a JPEG upright when it carries an EXIF Orientation other than 1. The new
 * file has no EXIF, so nothing turns it a second time. Returns true if it was
 * re-saved. Best-effort: on any failure the file is left as it was.
 */
function image_fix_orientation(string $path, int $jpegQuality = 90): bool
{
    $o = image_exif_orientation($path);
    if ($o === 1) return false;
    $im = @imagecreatefromjpeg($path);
    if (!$im) return false;
    $im = image_apply_orientation($im, $o);
    $ok = imagejpeg($im, $path, $jpegQuality);
    imagedestroy($im);
    return $ok;
}

/**
 * Resize a JPEG/PNG/WebP file in place if it exceeds $maxDim on either
 * side, re-encoding at $jpegQuality. A JPEG with an EXIF rotate flag is also
 * turned upright (and re-saved even when it is small enough), because re-encoding
 * drops the flag. Animated GIFs and vector formats (SVG) are left untouched — GD
 * can't safely resize either. Best-effort: any failure (corrupt file, memory
 * limit) leaves the original file as-is and returns false, so a resize failure
 * never blocks the upload itself.
 */
function image_resize_to_fit(string $path, int $maxDim = 2000, int $jpegQuality = 82): bool
{
    $info = @getimagesize($path);
    if (!$info) return false;

    [$width, $height, $type] = $info;
    $orientation = image_exif_orientation($path);
    if ($width <= $maxDim && $height <= $maxDim && $orientation === 1) return false;

    $creators = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($creators[$type])) return false;

    $src = @($creators[$type])($path);
    if (!$src) return false;
    if ($orientation !== 1) {
        $src = image_apply_orientation($src, $orientation);
        $width  = imagesx($src);
        $height = imagesy($src);
    }

    $ratio     = min(1, $maxDim / $width, $maxDim / $height);
    $newWidth  = max(1, (int) round($width * $ratio));
    $newHeight = max(1, (int) round($height * $ratio));

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($type === IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $path, $jpegQuality),
        IMAGETYPE_PNG  => imagepng($dst, $path, 6),
        IMAGETYPE_WEBP => imagewebp($dst, $path, $jpegQuality),
        default        => false,
    };

    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

/** "8M" / "512K" / "1G" (php.ini shorthand) → bytes. 0 or unparsable → 0 (no limit). */
function ini_size_bytes(string $v): int
{
    $v = trim($v);
    if (!preg_match('/^(\d+)\s*([KMG]?)$/i', $v, $m)) return 0;
    $n = (int)$m[1];
    return match (strtoupper($m[2])) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
}

/** Largest single upload the server accepts: the smaller of upload_max_filesize and post_max_size. */
function upload_max_bytes(): int
{
    $limits = array_filter([
        ini_size_bytes((string)ini_get('upload_max_filesize')),
        ini_size_bytes((string)ini_get('post_max_size')),
    ]);
    return $limits ? min($limits) : 0;
}

/** "8 MB" — for messages; rounds down so the stated limit is never more than the real one. */
function upload_max_label(): string
{
    $b = upload_max_bytes();
    return $b >= 1 << 20 ? floor($b / (1 << 20)) . ' MB' : max(1, (int)floor($b / 1024)) . ' KB';
}
