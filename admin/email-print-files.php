<?php
/**
 * AJAX: email composite print preview + raw artwork for all print items in an order.
 *
 * POST (JSON):
 *   order_id   int     — order ID
 *   items      int[]   — 0-based item indices to include
 *   email      string  — recipient email
 *   csrf_token string
 *
 * Response: { "ok": true } | { "ok": false, "error": "..." }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

admin_require_shop();
csrf_verify();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$order_id    = (int)($body['order_id'] ?? 0);
$item_indices = array_map('intval', (array)($body['items'] ?? []));
$email        = trim($body['email'] ?? '');

if (!$order_id || empty($item_indices) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Невалидни данни.']);
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) { echo json_encode(['ok' => false, 'error' => 'Поръчката не е намерена.']); exit; }

$all_items = json_decode($order['items'], true) ?? [];

// ── GD helper ─────────────────────────────────────────────────────────────────

function _epf_load_gd(string $path): GdImage|false
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg','jpeg' => imagecreatefromjpeg($path),
        'png'        => imagecreatefrompng($path),
        'webp'       => imagecreatefromwebp($path),
        default      => false,
    };
}

// Font for spec footer
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

// ── Build attachments for each requested item ─────────────────────────────────

$attachments = [];
$tmp_files   = [];

// Pre-load product data
$pids = array_unique(array_filter(array_map(fn($i) => (int)($all_items[$i]['product_id'] ?? 0), $item_indices)));
$product_map = [];
if ($pids) {
    $in = implode(',', array_fill(0, count($pids), '?'));
    $ps = $pdo->prepare("SELECT id, name_bg, image, variants FROM products WHERE id IN ($in)");
    $ps->execute(array_values($pids));
    foreach ($ps->fetchAll() as $pr) {
        $product_map[(int)$pr['id']] = $pr;
    }
}

foreach ($item_indices as $item_index) {
    if (!isset($all_items[$item_index])) continue;
    $item = $all_items[$item_index];
    if (empty($item['design_file'])) continue;

    $pid        = (int)($item['product_id'] ?? 0);
    $product    = $product_map[$pid] ?? null;
    if (!$product || !$product['image']) continue;

    $shirt_path  = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/products/' . $product['image'];
    $design_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($item['design_file'], '/');

    if (!file_exists($shirt_path) || !file_exists($design_path)) continue;

    $label = ($item_index + 1); // "Позиция N"

    // ── Composite PNG ──────────────────────────────────────────────────────────

    $shirt = _epf_load_gd($shirt_path);
    if (!$shirt) continue;

    $sw = imagesx($shirt);
    $sh = imagesy($shirt);
    imagealphablending($shirt, false);
    imagesavealpha($shirt, true);

    // Tint
    $colour = $item['colour'] ?? '#ffffff';
    $hex = ltrim($colour, '#');
    if (strlen($hex) !== 6) $hex = 'ffffff';
    $tr = hexdec(substr($hex, 0, 2));
    $tg = hexdec(substr($hex, 2, 2));
    $tb = hexdec(substr($hex, 4, 2));
    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            $rgba = imagecolorat($shirt, $x, $y);
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8)  & 0xFF;
            $b = $rgba         & 0xFF;
            $a = ($rgba >> 24) & 0x7F;
            imagesetpixel($shirt, $x, $y, imagecolorallocatealpha($shirt,
                (int)round($r * $tr / 255),
                (int)round($g * $tg / 255),
                (int)round($b * $tb / 255),
                $a));
        }
    }

    // Overlay design
    $pos    = is_array($item['design_position'] ?? null) ? $item['design_position'] : null;
    $design = _epf_load_gd($design_path);
    if ($design && $pos) {
        $dw = imagesx($design);
        $dh = imagesy($design);
        $rendered_w = (int)round(($pos['scale'] ?? 0.3) * $sw);
        $cx = ($pos['x'] ?? 0.5) * $sw;
        $cy = ($pos['y'] ?? 0.4) * $sh;
        $rotation = (float)($pos['rotation'] ?? 0);
        $rendered_h = (int)round($rendered_w * $dh / max(1, $dw));

        $scaled = imagecreatetruecolor($rendered_w, $rendered_h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $design, 0, 0, 0, 0, $rendered_w, $rendered_h, $dw, $dh);
        imagedestroy($design);

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

        $fw = imagesx($final); $fh = imagesy($final);
        imagealphablending($shirt, true);
        imagecopy($shirt, $final, (int)round($cx - $fw/2), (int)round($cy - $fh/2), 0, 0, $fw, $fh);
        imagealphablending($shirt, false);
        imagesavealpha($shirt, true);
        imagedestroy($final);
    }

    // Spec footer
    $spec_line = $spec_line2 = null;
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
            $spec_line2 = "Поръчка {$order_id}  ·  Позиция {$label}";
        } else {
            $size_lbl  = $ordered_size ? " (Размер: {$ordered_size})" : '';
            $spec_line  = "Поръчка {$order_id}  ·  Позиция {$label}{$size_lbl}";
            $spec_line2 = "Добавете размери по размер в продукта за изчисляване на cm.";
        }
    }

    if ($spec_line && $font_path) {
        $font_size = max(9, (int)($sw / 60));
        $max_w     = (int)($sw * 0.94);
        while ($font_size > 9) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $spec_line);
            if (($bbox[2] - $bbox[0]) <= $max_w) break;
            $font_size--;
        }
        $lh       = (int)($font_size * 1.6);
        $pad_x    = (int)($sw * 0.03);
        $pad_top  = (int)($font_size * 1.2);
        $footer_h = $pad_top + $lh * 2 + (int)($font_size * 0.8);
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
    }

    $tmp = tempnam(sys_get_temp_dir(), 'print_') . '.png';
    imagepng($shirt, $tmp);
    imagedestroy($shirt);
    $tmp_files[] = $tmp;

    $ext_raw = strtolower(pathinfo($design_path, PATHINFO_EXTENSION));
    $attachments[] = ['path' => $tmp,         'name' => "print-{$order_id}-pozicia-{$label}-kompozit.png"];
    $attachments[] = ['path' => $design_path, 'name' => "print-{$order_id}-pozicia-{$label}-artwork.{$ext_raw}"];
}

if (empty($attachments)) {
    echo json_encode(['ok' => false, 'error' => 'Няма файлове за изпращане.']);
    exit;
}

// ── Send email ────────────────────────────────────────────────────────────────

$count   = count($item_indices);
$subject = "Файлове за печат — Поръчка #{$order_id}";
$body_html = "<p>Здравейте,</p>
<p>Прилагаме файловете за печат за поръчка <strong>#{$order_id}</strong> ({$count} " . ($count === 1 ? 'артикул' : 'артикула') . ").</p>
<ul>
  <li><strong>Композит</strong> — снимка на тениската с позиционирания дизайн и спецификации</li>
  <li><strong>Artwork</strong> — оригиналният файл за печат/лепенка</li>
</ul>
<p>Odd Minds</p>";

$ok = send_mail($email, $subject, $body_html, '', $attachments);

foreach ($tmp_files as $tmp) { @unlink($tmp); }

echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Грешка при изпращане на имейла.']);
