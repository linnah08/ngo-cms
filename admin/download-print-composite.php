<?php
/**
 * Generates and downloads a composite print preview:
 * shirt image tinted to the chosen colour, with the customer's design
 * overlaid at the exact position/scale/rotation they set.
 *
 * GET params:
 *   order_id  int  — order ID
 *   item      int  — 0-based index of the item in the order's items JSON
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/print_helpers.php';

admin_require_shop();

$order_id   = (int)($_GET['order_id'] ?? 0);
$item_index = (int)($_GET['item']     ?? 0);

if (!$order_id) { http_response_code(400); exit('Invalid request.'); }

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) { http_response_code(404); exit('Order not found.'); }

$items = json_decode($order['items'], true) ?? [];
if (!isset($items[$item_index])) { http_response_code(404); exit('Item not found.'); }

$item = $items[$item_index];

if (empty($item['design_file'])) { http_response_code(400); exit('No design for this item.'); }

// Load shirt image path and physical dimensions from product
$pid   = (int)($item['product_id'] ?? 0);
$pstmt = $pdo->prepare('SELECT image, variants FROM products WHERE id = ?');
$pstmt->execute([$pid]);
$product = $pstmt->fetch();

if (!$product || !$product['image']) { http_response_code(404); exit('Product image not found.'); }

$shirt_path  = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/products/' . $product['image'];
$design_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($item['design_file'], '/');
$colour      = $item['colour'] ?? '#ffffff';
$pos         = is_array($item['design_position'] ?? null) ? $item['design_position'] : null;
// (shirt physical dimensions vary per size — spec is expressed as % of shirt image)

foreach ([$shirt_path, $design_path] as $f) {
    if (!file_exists($f)) { http_response_code(404); exit('Image file not found.'); }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function load_gd_image(string $path): GdImage|false
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => imagecreatefromjpeg($path),
        'png'         => imagecreatefrompng($path),
        'webp'        => imagecreatefromwebp($path),
        default       => false,
    };
}

// ── Load and tint shirt ───────────────────────────────────────────────────────

$shirt = load_gd_image($shirt_path);
if (!$shirt) { http_response_code(500); exit('Could not load shirt image.'); }

$sw = imagesx($shirt);
$sh = imagesy($shirt);

imagealphablending($shirt, false);
imagesavealpha($shirt, true);

$hex = ltrim($colour, '#');
if (strlen($hex) !== 6) $hex = 'ffffff';
$tr = hexdec(substr($hex, 0, 2));
$tg = hexdec(substr($hex, 2, 2));
$tb = hexdec(substr($hex, 4, 2));

for ($y = 0; $y < $sh; $y++) {
    for ($x = 0; $x < $sw; $x++) {
        $rgba = imagecolorat($shirt, $x, $y);
        $r    = ($rgba >> 16) & 0xFF;
        $g    = ($rgba >> 8)  & 0xFF;
        $b    = $rgba         & 0xFF;
        $a    = ($rgba >> 24) & 0x7F;
        $col  = imagecolorallocatealpha(
            $shirt,
            (int)round($r * $tr / 255),
            (int)round($g * $tg / 255),
            (int)round($b * $tb / 255),
            $a
        );
        imagesetpixel($shirt, $x, $y, $col);
    }
}

// ── Overlay design ────────────────────────────────────────────────────────────

$design = load_gd_image($design_path);
if ($design) {
    $dw = imagesx($design);
    $dh = imagesy($design);

    if ($pos) {
        $rendered_w = (int)round(($pos['scale'] ?? 0.3) * $sw);
        $cx         = ($pos['x'] ?? 0.5) * $sw;
        $cy         = ($pos['y'] ?? 0.4) * $sh;
        $rotation   = (float)($pos['rotation'] ?? 0);
    } else {
        $rendered_w = (int)round($sw * 0.4);
        $cx         = $sw * 0.5;
        $cy         = $sh * 0.4;
        $rotation   = 0.0;
    }

    $rendered_h = (int)round($rendered_w * $dh / max(1, $dw));

    // Resize design to render dimensions
    $scaled = imagecreatetruecolor($rendered_w, $rendered_h);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
    imagecopyresampled($scaled, $design, 0, 0, 0, 0, $rendered_w, $rendered_h, $dw, $dh);
    imagedestroy($design);

    // Rotate if needed (GD rotates CCW; our rotation is CW radians)
    if (abs($rotation) > 0.001) {
        $degrees = -$rotation * 180 / M_PI;
        $rotated = imagerotate($scaled, $degrees, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($scaled);
        $final = $rotated;
    } else {
        $final = $scaled;
    }

    $fw     = imagesx($final);
    $fh     = imagesy($final);
    $dest_x = (int)round($cx - $fw / 2);
    $dest_y = (int)round($cy - $fh / 2);

    imagealphablending($shirt, true);
    imagecopy($shirt, $final, $dest_x, $dest_y, 0, 0, $fw, $fh);
    imagealphablending($shirt, false);
    imagesavealpha($shirt, true);
    imagedestroy($final);
}

// ── Spec footer ───────────────────────────────────────────────────────────────

$spec_line  = null;
$spec_line2 = null;
$fname_spec = '';
if ($pos) {
    $dim          = @getimagesize($design_path);
    $ar           = ($dim && $dim[0] > 0) ? ($dim[1] / $dim[0]) : 1.0;
    $ordered_size = $item['size'] ?? '';
    $pvars_c      = json_decode($product['variants'] ?? '{}', true) ?? [];
    $sdims        = $pvars_c['size_dims'][$ordered_size] ?? null;
    if ($sdims && ($sdims['w'] ?? 0) > 0) {
        $dw_cm   = round($pos['scale'] * $sdims['w'], 1);
        $dh_cm   = round($pos['scale'] * $ar * $sdims['h'], 1);
        $left_cm = round(max(0, $pos['x'] - $pos['scale'] / 2) * $sdims['w'], 1);
        $top_cm  = round(max(0, $pos['y'] - ($pos['scale'] * $ar) / 2) * $sdims['h'], 1);
        $size_lbl   = $ordered_size ? "  |  Размер: {$ordered_size}" : '';
        $spec_line  = "Печат: {$dw_cm} x {$dh_cm} cm  |  Ляво: {$left_cm} cm  |  Горе: {$top_cm} cm{$size_lbl}";
        $spec_line2 = "Поръчка {$order_id}  ·  Позиция " . ($item_index + 1);
        $fname_spec = "_{$dw_cm}x{$dh_cm}cm";
    } else {
        $size_lbl  = $ordered_size ? " (Размер: {$ordered_size})" : '';
        $spec_line  = "Поръчка {$order_id}  ·  Позиция " . ($item_index + 1) . $size_lbl;
        $spec_line2 = "Добавете размери по размер в продукта за изчисляване на cm.";
        $fname_spec = '_no-dims';
    }
}

// Try to find a system TrueType font for clean annotation
$font_candidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/TTF/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    '/Library/Fonts/Arial.ttf',
    '/System/Library/Fonts/Supplemental/Arial.ttf',
];
$font_path = null;
foreach ($font_candidates as $f) {
    if (file_exists($f)) { $font_path = $f; break; }
}

if ($spec_line && $font_path) {
    // Start at a reasonable size and shrink until spec_line fits within 94% of image width
    $font_size = max(9, (int)($sw / 60));
    $max_w     = (int)($sw * 0.94);
    while ($font_size > 9) {
        $bbox = imagettfbbox($font_size, 0, $font_path, $spec_line);
        if (($bbox[2] - $bbox[0]) <= $max_w) break;
        $font_size--;
    }
    $lh        = (int)($font_size * 1.6);
    $pad_x     = (int)($sw * 0.03);
    $pad_top   = (int)($font_size * 1.2);
    $footer_h  = $pad_top + $lh * 2 + (int)($font_size * 0.8);
    $out   = imagecreatetruecolor($sw, $sh + $footer_h);
    $white = imagecolorallocate($out, 255, 255, 255);
    $dark  = imagecolorallocate($out,  30,  30,  30);
    $muted = imagecolorallocate($out, 110, 110, 110);
    imagefilledrectangle($out, 0, 0, $sw, $sh + $footer_h, $white);
    imagealphablending($out, true);
    imagecopy($out, $shirt, 0, 0, 0, 0, $sw, $sh);
    $y1 = $sh + $pad_top;
    imagettftext($out, $font_size,            0, $pad_x, $y1,        $dark,  $font_path, $spec_line);
    if ($spec_line2) {
        imagettftext($out, $font_size * 0.82, 0, $pad_x, $y1 + $lh, $muted, $font_path, $spec_line2);
    }
    imagedestroy($shirt);
    $shirt = $out;
} elseif ($spec_line) {
    // Fallback: GD bitmap font
    $line_h  = 18;
    $lines   = $spec_line2 ? 3 : 2;
    $out     = imagecreatetruecolor($sw, $sh + $line_h * $lines + 10);
    imagefilledrectangle($out, 0, 0, $sw, $sh + $line_h * $lines + 10, imagecolorallocate($out, 255, 255, 255));
    imagecopy($out, $shirt, 0, 0, 0, 0, $sw, $sh);
    imagestring($out, 3, 6, $sh + 4,              'Order ' . $order_id . ' / Item ' . ($item_index + 1), imagecolorallocate($out, 110, 110, 110));
    imagestring($out, 4, 6, $sh + 4 + $line_h,    $spec_line,  imagecolorallocate($out, 30, 30, 30));
    if ($spec_line2) {
        imagestring($out, 3, 6, $sh + 4 + $line_h * 2, $spec_line2, imagecolorallocate($out, 110, 110, 110));
    }
    imagedestroy($shirt);
    $shirt = $out;
}

// ── Output ────────────────────────────────────────────────────────────────────

$fname = 'print-' . $order_id . '-item' . ($item_index + 1) . $fname_spec . '.png';
header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $fname . '"');
imagepng($shirt);
imagedestroy($shirt);
exit;
