<?php
/**
 * Getting a post's photos ready for Buffer. Lifted out of admin/social-ajax.php and
 * admin/linkedin-ajax.php so both share it and it works on a list of photos.
 */
require_once __DIR__ . '/articles.php';

function social_image_url(string $path): string {
    return SITE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/** Main photo first, then the others in the author's order; only files that exist. */
function social_photo_paths(array $article): array {
    $photos = article_photos($article);
    if (!$photos) return [];
    $main = article_main_photo_index($article, $photos);
    $paths = array_column($photos, 'src');
    $first = $paths[$main];
    unset($paths[$main]);
    return array_merge([$first], array_values($paths));
}

function social_buffer_assets_gql(array $urls): string {
    if (!$urls) return '';
    $items = array_map(static fn(string $u): string => '{ image: { url: ' . json_encode($u, JSON_UNESCAPED_SLASHES) . ' } }', $urls);
    return 'assets: [' . implode(', ', $items) . '],';
}

/** @return \GdImage|false */
function _social_gd_open(string $abs, string $ext) {
    return match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($abs),
        'png'         => @imagecreatefrompng($abs),
        'webp'        => @imagecreatefromwebp($abs),
        default       => false,
    };
}

function _social_gd_save(\GdImage $im, string $abs, string $ext): bool {
    return match ($ext) {
        'jpg', 'jpeg' => imagejpeg($im, $abs, 90),
        'png'         => imagepng($im, $abs),
        'webp'        => imagewebp($im, $abs, 90),
        default       => false,
    };
}

/**
 * The file to send for one photo. 'fit': at most 4800px wide (Buffer's limit is 5000).
 * 'insta': also between 4:5 and 1.91:1. 'square': also a centred 1:1 crop (every slide of
 * an Instagram carousel shares one shape). Returns a site path, or null if unreadable.
 */
function social_prepare_image(string $path, string $mode): ?string {
    $abs = $_SERVER['DOCUMENT_ROOT'] . $path;
    $size = @getimagesize($abs);
    if (!$size) return null;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    [$w, $h] = $size;

    $scale = $w > 4800 ? 4800 / $w : 1.0;
    $cw = $w; $ch = $h; $cx = 0; $cy = 0;
    if ($mode === 'square' && $w !== $h) {
        $cw = $ch = min($w, $h);
        $cx = intdiv($w - $cw, 2); $cy = intdiv($h - $ch, 2);
    } elseif ($mode === 'insta') {
        $r = $w / $h;
        if ($r < 0.8)      { $ch = (int) round($w * 5 / 4); $cy = intdiv($h - $ch, 2); }
        elseif ($r > 1.91) { $cw = (int) round($h * 1.91);  $cx = intdiv($w - $cw, 2); }
    }
    if ($scale === 1.0 && $cw === $w && $ch === $h) return $path;

    $src = _social_gd_open($abs, $ext);
    if (!$src) return $path;   // can't read it with GD — send the original, as before
    $dw = (int) round($cw * $scale); $dh = (int) round($ch * $scale);
    $dst = imagecreatetruecolor($dw, $dh);
    if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);
    $suffix  = $mode === 'square' ? '-sq' : '-ig';
    $outPath = preg_replace('/\.' . preg_quote($ext, '/') . '$/', $suffix . '.' . $ext, $path);
    $ok = _social_gd_save($dst, $_SERVER['DOCUMENT_ROOT'] . $outPath, $ext);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? $outPath : $path;
}

/**
 * What social-ajax.php sends Buffer for Facebook or Instagram: the photo URLs (main
 * first) and the metadata. Instagram with several photos is a carousel of square slides.
 */
function social_fb_insta_request(array $article, string $channel): array {
    $paths    = social_photo_paths($article);
    $carousel = $channel === 'insta' && count($paths) > 1;
    $mode     = $channel === 'insta' ? ($carousel ? 'square' : 'insta') : 'fit';
    $urls = [];
    foreach ($paths as $p) {
        $ready = social_prepare_image($p, $mode);
        if ($ready !== null) $urls[] = social_image_url($ready);
    }
    $metadata = $channel === 'fb'
        ? 'facebook: { type: post }'
        : 'instagram: { type: post, shouldShareToFeed: true }';   // several assets make the carousel; Buffer refuses type: carousel
    return ['urls' => $urls, 'metadata' => $metadata];
}

/**
 * LinkedIn: several photos go as one PDF "document" — LinkedIn shows it as a swipeable
 * carousel; one photo stays an ordinary image post.
 */
function social_linkedin_assets_gql(array $bg, array $en): string {
    $paths = social_photo_paths($bg);
    if (count($paths) > 1) {
        require_once __DIR__ . '/documents/LinkedInCarouselGenerator.php';
        $pdf = LinkedInCarouselGenerator::save($bg, $en);
        if ($pdf !== null) {
            $thumb = social_prepare_image($paths[0], 'square') ?? $paths[0];
            return 'assets: [{ document: { url: ' . json_encode(social_image_url($pdf), JSON_UNESCAPED_SLASHES)
                 . ', title: ' . json_encode((string) ($en['title'] ?? $bg['title'] ?? ''), JSON_UNESCAPED_UNICODE)
                 . ', thumbnailUrl: ' . json_encode(social_image_url($thumb), JSON_UNESCAPED_SLASHES) . ' } }],';
        }
    }
    if (!$paths) return '';
    $one = social_prepare_image($paths[0], 'fit');
    return $one === null ? '' : social_buffer_assets_gql([social_image_url($one)]);
}
