<?php
/**
 * Shared upload-image helpers. Used by every admin upload endpoint so a
 * single change (target dimension, quality) applies everywhere at once.
 */

/**
 * Resize a JPEG/PNG/WebP file in place if it exceeds $maxDim on either
 * side, re-encoding at $jpegQuality. Animated GIFs and vector formats
 * (SVG) are left untouched — GD can't safely resize either. Best-effort:
 * any failure (corrupt file, memory limit) leaves the original file as-is
 * and returns false, so a resize failure never blocks the upload itself.
 */
function image_resize_to_fit(string $path, int $maxDim = 2000, int $jpegQuality = 82): bool
{
    $info = @getimagesize($path);
    if (!$info) return false;

    [$width, $height, $type] = $info;
    if ($width <= $maxDim && $height <= $maxDim) return false;

    $creators = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($creators[$type])) return false;

    $src = @($creators[$type])($path);
    if (!$src) return false;

    $ratio     = min($maxDim / $width, $maxDim / $height);
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
