<?php
/**
 * Getting a post's photos ready for Buffer. Lifted out of admin/social-ajax.php and
 * admin/linkedin-ajax.php so both share it and it works on a list of photos.
 */
require_once __DIR__ . '/articles.php';
require_once __DIR__ . '/images.php';

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

/** Opened upright: a phone photo's EXIF rotate flag is applied, as the browser does. @return \GdImage|false */
function _social_gd_open(string $abs, string $ext) {
    $im = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($abs),
        'png'         => @imagecreatefrompng($abs),
        'webp'        => @imagecreatefromwebp($abs),
        default       => false,
    };
    return $im ? image_apply_orientation($im, image_exif_orientation($abs)) : false;
}

function _social_gd_save(\GdImage $im, string $abs, string $ext): bool {
    return match ($ext) {
        'jpg', 'jpeg' => imagejpeg($im, $abs, 90),
        'png'         => imagepng($im, $abs),
        'webp'        => imagewebp($im, $abs, 90),
        default       => false,
    };
}

/** Instagram's accepted feed shapes (width ÷ height): 4:5 portrait … 1.91:1 landscape. */
const SOCIAL_IG_MIN = 0.8;
const SOCIAL_IG_MAX = 1.91;
const SOCIAL_STORY_AR = 9 / 16;
/** How far down the spare height an automatic crop starts — faces sit near the top, not the middle. */
const SOCIAL_CROP_TOP_BIAS = 0.2;
/**
 * An automatic crop may cut away at most this share of a photo. More than that and the
 * post is not sent to Instagram until the author picks the part to show themselves —
 * landscape sticker photos once went out cut to portrait, with half of every sticker gone.
 */
const SOCIAL_IG_MAX_CUT = 0.2;

/**
 * The shape (width ÷ height) a $w×$h photo is sent at in $mode, or null when $mode
 * doesn't crop. 'carousel': $shared, the one shape every slide has (see
 * social_carousel_aspect(); 4:5 when not given). 'insta': the photo's own shape, clamped
 * to 4:5 … 1.91:1. 'story': 9:16. 'square': 1:1 (LinkedIn's document pages).
 */
function social_target_aspect(int $w, int $h, string $mode, ?float $shared = null): ?float {
    return match ($mode) {
        'carousel' => $shared ?? SOCIAL_IG_MIN,
        'insta'    => max(SOCIAL_IG_MIN, min(SOCIAL_IG_MAX, $w / $h)),
        'story'    => SOCIAL_STORY_AR,
        'square'   => 1.0,
        default    => null,
    };
}

/** The share (0 … 1) of a $w×$h photo that an automatic crop to shape $ar cuts away. */
function social_crop_loss(int $w, int $h, float $ar): float {
    $p = $w / $h;
    return max(0.0, 1 - min($p / $ar, $ar / $p));
}

/**
 * The one shape every slide of an Instagram carousel shares, from the photos' own
 * shapes ([w, h] each). Instagram takes anything from 4:5 to 1.91:1, so try each photo's
 * own shape and the middle between the narrowest and the widest, and keep the shape
 * whose worst-cut slide loses least (then the least in total): wide photos make a wide
 * carousel, tall ones a 4:5 one.
 */
function social_carousel_aspect(array $sizes): float {
    $shapes = array_map(static fn(array $s): float => max(SOCIAL_IG_MIN, min(SOCIAL_IG_MAX, $s[0] / $s[1])), $sizes);
    if (!$shapes) return SOCIAL_IG_MIN;
    $shapes[] = sqrt(min($shapes) * max($shapes));
    $best = SOCIAL_IG_MIN; $bestScore = null;
    foreach ($shapes as $ar) {
        $ar = round($ar, 6);
        $loss  = array_map(static fn(array $s): float => social_crop_loss($s[0], $s[1], $ar), $sizes);
        $score = [round(max($loss), 6), round(array_sum($loss), 6)];
        if ($bestScore === null || $score < $bestScore) { $best = $ar; $bestScore = $score; }
    }
    return $best;
}

/** Which saved crop (insta_crops.post / .story) a mode uses; null for modes nobody can adjust. */
function social_crop_kind(string $mode): ?string {
    return match ($mode) { 'carousel', 'insta' => 'post', 'story' => 'story', default => null };
}

/**
 * Whether a saved crop rectangle (fractions x, y, w, h of the photo, plus the shape `ar`
 * it was made for) still fits a $w×$h photo sent at shape $ar. A crop made for another
 * shape — the post went from one photo to several, say — is ignored, not stretched.
 */
function social_crop_rect_fits(mixed $rect, int $w, int $h, float $ar): bool {
    if (!is_array($rect)) return false;
    foreach (['x', 'y', 'w', 'h', 'ar'] as $k) {
        if (!isset($rect[$k]) || !is_int($rect[$k]) && !is_float($rect[$k])) return false;
    }
    if ($rect['w'] <= 0 || $rect['h'] <= 0 || $rect['ar'] <= 0) return false;
    if (abs($rect['ar'] / $ar - 1) > 0.01) return false;
    // The rectangle on this photo must itself have that shape (allowing for rounding).
    return abs(($rect['w'] * $w) / ($rect['h'] * $h) / $ar - 1) <= 0.03;
}

/**
 * The part of a $w×$h photo to send in $mode, as pixels [x, y, width, height]. A saved
 * $rect that fits is used; otherwise the automatic crop: cut width from both sides
 * equally, cut height mostly from the bottom (start SOCIAL_CROP_TOP_BIAS down the spare
 * height), so the top of a tall phone photo — where the faces are — stays in.
 */
function social_crop_box(int $w, int $h, string $mode, mixed $rect = null, ?float $shared = null): array {
    $ar = social_target_aspect($w, $h, $mode, $shared);
    if ($ar === null) return [0, 0, $w, $h];
    if ($rect !== null && social_crop_rect_fits($rect, $w, $h, $ar)) {
        $cw = max(1, min($w, (int) round($rect['w'] * $w)));
        $ch = (int) round($cw / $ar);
        if ($ch > $h) { $ch = $h; $cw = max(1, min($w, (int) round($ch * $ar))); }
        $cx = max(0, min($w - $cw, (int) round($rect['x'] * $w)));
        $cy = max(0, min($h - $ch, (int) round($rect['y'] * $h)));
        return [$cx, $cy, $cw, $ch];
    }
    if ($w / $h > $ar) {
        $cw = min($w, (int) round($h * $ar));
        return [intdiv($w - $cw, 2), 0, $cw, $h];
    }
    $ch = min($h, (int) round($w / $ar));
    return [0, (int) round(($h - $ch) * SOCIAL_CROP_TOP_BIAS), $w, $ch];
}

/** The saved crop for one photo of a post, or null. Never trusted — social_crop_box re-checks it. */
function social_saved_crop(array $article, ?string $kind, string $src): ?array {
    if ($kind === null) return null;
    $r = $article['insta_crops'][$kind][$src] ?? null;
    return is_array($r) ? $r : null;
}

/**
 * The file to send for one photo. 'fit': at most 4800px wide (Buffer's limit is 5000).
 * 'insta', 'carousel', 'story', 'square': also cut to that mode's shape — see
 * social_target_aspect() and social_crop_box(). $rect is the author's own crop, when
 * there is one; $shared the carousel's shape. The file name carries a short hash of the crop, so a changed crop is a
 * new URL and Buffer/Instagram never reuse a stale copy. Returns a site path, or null
 * if unreadable.
 */
function social_prepare_image(string $path, string $mode, ?array $rect = null, ?float $shared = null): ?string {
    $abs = $_SERVER['DOCUMENT_ROOT'] . $path;
    // Sizes and crops are of the photo as the author sees it in the browser: upright.
    $size = image_upright_size($abs);
    if (!$size) return null;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    [$w, $h] = $size;
    $orientation = image_exif_orientation($abs);

    $scale = $w > 4800 ? 4800 / $w : 1.0;
    [$cx, $cy, $cw, $ch] = social_crop_box($w, $h, $mode, $rect, $shared);
    // A photo with a rotate flag is always re-saved upright — Instagram doesn't obey the flag.
    if ($scale === 1.0 && $cw === $w && $ch === $h && $orientation === 1) return $path;

    $src = _social_gd_open($abs, $ext);
    if (!$src) return $path;   // can't read it with GD — send the original, as before
    $dw = (int) round($cw * $scale); $dh = (int) round($ch * $scale);
    $dst = imagecreatetruecolor($dw, $dh);
    if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);
    $suffix  = match ($mode) { 'square' => '-sq', 'story' => '-story', 'carousel' => '-car', default => '-ig' };
    // The orientation is in the hash so a copy saved sideways before this fix is never reused.
    $key = "{$cx},{$cy},{$cw},{$ch},{$dw},{$dh}" . ($orientation === 1 ? '' : ",o{$orientation}");
    $suffix .= '-' . substr(md5($key), 0, 8);
    // Case-insensitive, and never the original's own name — an upper-case .JPG once made
    // the crop overwrite the photo on the website too.
    $outPath = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', $suffix . '.' . $ext, $path);
    if ($outPath === null || $outPath === $path) { imagedestroy($src); imagedestroy($dst); return $path; }
    $ok = _social_gd_save($dst, $_SERVER['DOCUMENT_ROOT'] . $outPath, $ext);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? $outPath : $path;
}

/**
 * Check a crop the editor wants to save (admin/social-ajax.php, action 'save_crop').
 * $src must be one of the post's own photos, $kind 'post' or 'story', and the rectangle
 * four numbers that are clamped into the photo. Returns
 * ['ok' => true, 'kind' => ..., 'src' => ..., 'rect' => [x, y, w, h, ar]] or
 * ['ok' => false, 'error' => plain-language message].
 */
function social_crop_clean(array $article, array $body, ?callable $exists = null): array {
    $kind = $body['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, ['post', 'story'], true)) {
        return ['ok' => false, 'error' => 'Невалиден вид публикация.'];
    }
    $src = $body['src'] ?? null;
    if (!is_string($src) || !in_array($src, array_column(article_photos($article, $exists), 'src'), true)) {
        return ['ok' => false, 'error' => 'Тази снимка не е от статията. Презаредете страницата и опитайте отново.'];
    }
    $r = $body['rect'] ?? null;
    $num = static fn($v): ?float => (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) && is_finite((float) $v) ? (float) $v : null;
    if (!is_array($r)) return ['ok' => false, 'error' => 'Липсва изрязването.'];
    $v = [];
    foreach (['x', 'y', 'w', 'h', 'ar'] as $k) {
        $v[$k] = $num($r[$k] ?? null);
        if ($v[$k] === null) return ['ok' => false, 'error' => 'Изрязването не е валидно. Опитайте отново.'];
    }
    $c = static fn(float $f): float => round(max(0.0, min(1.0, $f)), 6);
    $x = $c($v['x']); $y = $c($v['y']);
    $w = min($c($v['w']), round(1 - $x, 6)); $h = min($c($v['h']), round(1 - $y, 6));
    if ($w <= 0 || $h <= 0 || $v['ar'] < 0.5 || $v['ar'] > 2) {
        return ['ok' => false, 'error' => 'Изрязването не е валидно. Опитайте отново.'];
    }
    return ['ok' => true, 'kind' => $kind, 'src' => $src,
            'rect' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'ar' => round($v['ar'], 6)]];
}

/**
 * Every photo Instagram gets for a feed post ($kind 'post') or a story ('story'), main
 * first, and how each is cut: src, w, h (upright), mode, ar (its shape), shared (the
 * carousel's shape, or null), rect (the author's own crop, when it still fits, else
 * null), box ([x, y, w, h] pixels sent), cut (share of the photo cut away; 0 for the
 * author's own crop — they chose it) and too_much (cut over SOCIAL_IG_MAX_CUT).
 * The preview, the check and the photos sent all come from here, so they always agree.
 */
function social_insta_slides(array $article, string $kind): array {
    $paths = social_photo_paths($article);
    if ($kind === 'story') $paths = array_slice($paths, 0, 1);
    $photos = [];
    foreach ($paths as $p) {
        $size = image_upright_size($_SERVER['DOCUMENT_ROOT'] . $p);
        if ($size) $photos[] = [$p, $size[0], $size[1]];
    }
    $mode   = $kind === 'story' ? 'story' : (count($photos) > 1 ? 'carousel' : 'insta');
    $shared = $mode === 'carousel'
        ? social_carousel_aspect(array_map(static fn(array $ph): array => [$ph[1], $ph[2]], $photos))
        : null;
    $out = [];
    foreach ($photos as [$p, $w, $h]) {
        $ar   = social_target_aspect($w, $h, $mode, $shared);
        $rect = social_saved_crop($article, $kind, $p);
        if (!social_crop_rect_fits($rect, $w, $h, $ar)) $rect = null;
        $cut  = $rect === null ? social_crop_loss($w, $h, $ar) : 0.0;
        $out[] = [
            'src' => $p, 'w' => $w, 'h' => $h, 'mode' => $mode, 'ar' => round($ar, 6), 'shared' => $shared,
            'rect' => $rect, 'box' => social_crop_box($w, $h, $mode, $rect, $shared),
            'cut' => $cut, 'too_much' => $cut > SOCIAL_IG_MAX_CUT + 1e-9,
        ];
    }
    return $out;
}

/**
 * When Instagram would lose too much of a photo: a plain-language explanation and what
 * the author can do, for the editor and for the refused schedule request. Null when
 * every photo is fine. ['lead' => ..., 'tips' => [...], 'srcs' => [photos to fix]]
 */
function social_insta_cut_problem(array $slides): ?array {
    $bad = array_keys(array_filter($slides, static fn(array $s): bool => $s['too_much']));
    if (!$bad) return null;
    $story = ($slides[0]['mode'] ?? '') === 'story';
    $worst = (int) round(100 * max(array_map(static fn(int $i): float => $slides[$i]['cut'], $bad)));
    if ($story || count($slides) === 1) {
        $what = 'снимката';
    } else {
        $nums = array_map(static fn(int $i): string => (string) ($i + 1), $bad);
        $last = array_pop($nums);
        $what = $nums ? 'снимки ' . implode(', ', $nums) . ' и ' . $last : 'снимка ' . $last;
    }
    $lead = "Instagram ще отреже твърде голяма част от {$what} (до {$worst}%), затова "
          . ($story ? 'Story-то няма да бъде изпратено' : 'публикацията няма да бъде изпратена')
          . ', докато не изберете какво да се вижда.';
    $tips = ['Натиснете снимката тук и изберете сами коя част да се вижда.'];
    if (!$story && count($slides) > 1) {
        $tips[] = 'Или сложете в публикацията снимки с еднаква форма — всички широки или всички високи.';
        $tips[] = 'Или махнете тази снимка от статията.';
    } else {
        $tips[] = $story
            ? 'Или изберете за главна снимка, която е висока (портретна).'
            : 'Или сложете снимка, която не е толкова широка или толкова висока.';
    }
    return ['lead' => $lead, 'tips' => $tips, 'srcs' => array_map(static fn(int $i): string => $slides[$i]['src'], $bad)];
}

/** The same problem as one sentence-run, for an error line. */
function social_insta_cut_message(array $problem): string {
    return $problem['lead'] . ' ' . implode(' ', $problem['tips']);
}

/**
 * What the Instagram preview in the post editor needs: every photo Instagram will get
 * (main first) with its pixel size, the shape it goes at, the part shown (fractions) and
 * how much is cut away, plus the problem to show when a cut is too big.
 */
function social_insta_preview(array $article): array {
    $out = [];
    foreach (['post', 'story'] as $kind) {
        $slides = social_insta_slides($article, $kind);
        $out[$kind] = array_map(static function (array $s): array {
            [$x, $y, $bw, $bh] = $s['box'];
            return [
                'src' => $s['src'], 'w' => $s['w'], 'h' => $s['h'], 'ar' => $s['ar'],
                'rect' => ['x' => $x / $s['w'], 'y' => $y / $s['h'], 'w' => $bw / $s['w'], 'h' => $bh / $s['h']],
                'cut' => (int) round(100 * $s['cut']), 'too_much' => $s['too_much'],
            ];
        }, $slides);
        $out[$kind . '_problem'] = social_insta_cut_problem($slides);
    }
    return $out;
}

/**
 * What social-ajax.php sends Buffer for Facebook or Instagram: the photo URLs (main
 * first), the metadata and, for Instagram, 'problem' (see social_insta_cut_problem —
 * when set, the caller must not send). Instagram with several photos is a carousel
 * sharing one shape picked from the photos; an Instagram story is the main photo alone,
 * cropped to 9:16. The author's own crops (insta_crops) are used where they still fit.
 */
function social_fb_insta_request(array $article, string $channel, bool $is_story = false): array {
    $is_story = $is_story && $channel === 'insta';
    $urls = [];
    $problem = null;
    if ($channel === 'insta') {
        $slides  = social_insta_slides($article, $is_story ? 'story' : 'post');
        $problem = social_insta_cut_problem($slides);
        foreach ($slides as $s) {
            if ($problem !== null) break;   // nothing is cut or sent while a photo would lose too much
            $ready = social_prepare_image($s['src'], $s['mode'], $s['rect'], $s['shared']);
            if ($ready !== null) $urls[] = social_image_url($ready);
        }
    } else {
        foreach (social_photo_paths($article) as $p) {
            $ready = social_prepare_image($p, 'fit');
            if ($ready !== null) $urls[] = social_image_url($ready);
        }
    }
    if ($channel === 'fb') {
        $metadata = 'facebook: { type: post }';
    } elseif ($is_story) {
        $metadata = 'instagram: { type: story, shouldShareToFeed: false }';
    } else {
        $metadata = 'instagram: { type: post, shouldShareToFeed: true }';   // several assets make the carousel; Buffer refuses type: carousel
    }
    return ['urls' => $urls, 'metadata' => $metadata, 'problem' => $problem];
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
