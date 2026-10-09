<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/print_helpers.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
start_session();

// Module switched off in Admin → Модули — the shop does not exist (site's 404).
module_public_guard('shop');

$lang     = post_lang();
$shop_url = shop_path('shop', $lang);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $shop_url);
    exit;
}

if (!csrf_verify()) {
    flash_set('error', t_or('cart.err.invalid_request', 'Невалидна заявка.', 'Invalid request. Please try again.', $lang));
    header('Location: ' . $shop_url);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$qty        = max(1, (int)($_POST['quantity'] ?? 1));
$redirect_param = $_POST['redirect'] ?? '';
$home_url   = $lang === 'en' ? '/en/' : '/';
$redirect   = $redirect_param === 'cart' ? shop_path('cart', $lang) : ($redirect_param === 'home' ? $home_url : $shop_url);

if (!$product_id) {
    flash_set('error', t_or('cart.err.invalid_product', 'Невалиден продукт.', 'Invalid product.', $lang));
    header('Location: ' . $redirect);
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT id, slug, name_bg, name_en, stock, active, `type`, variants, preorder_enabled FROM products WHERE id = ? AND active = 1');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    flash_set('error', t_or('cart.err.product_not_found', 'Продуктът не е намерен.', 'Product not found.', $lang));
    header('Location: ' . $redirect);
    exit;
}

// ── Variant product validation ────────────────────────────────────────────────
$variant_id    = 0;
$variant_label = '';

if ($product['type'] === 'variant') {
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    if (!$variant_id) {
        flash_set('error', t_or('cart.err.choose_variant', 'Моля изберете вариант.', 'Please choose an option.', $lang));
        header('Location: ' . $redirect);
        exit;
    }
    $pv_stmt = $pdo->prepare(
        'SELECT id, label_bg, label_en, stock, active FROM product_variants WHERE id = ? AND product_id = ?'
    );
    $pv_stmt->execute([$variant_id, $product_id]);
    $pv = $pv_stmt->fetch();
    if (!$pv || !$pv['active']) {
        flash_set('error', t_or('cart.err.variant_not_found', 'Вариантът не е намерен.', 'That option was not found.', $lang));
        header('Location: ' . $redirect);
        exit;
    }
    if ((int)$pv['stock'] <= 0 && !product_is_preorder($product, (int)$pv['stock'])) {
        $_label = $lang === 'en' ? (($pv['label_en'] ?? '') ?: $pv['label_bg']) : $pv['label_bg'];
        flash_set('error', t_or('cart.err.sold_out', '{name} е изчерпан.', '{name} is sold out.', $lang, ['name' => $_label]));
        header('Location: ' . $redirect . '?out=1');
        exit;
    }
    $variant_label = $pv['label_bg'];
}

// For standard/print products, check product-level stock
if ($product['type'] !== 'variant' && (int)$product['stock'] <= 0 && !product_is_preorder($product, (int)$product['stock'])) {
    $_name = $lang === 'en' ? (($product['name_en'] ?? '') ?: $product['name_bg']) : $product['name_bg'];
    flash_set('error', t_or('cart.err.sold_out', '{name} е изчерпан.', '{name} is sold out.', $lang, ['name' => $_name]));
    header('Location: ' . $redirect . '?out=1');
    exit;
}

// ── Print product validation ──────────────────────────────────────────────────
$colour          = '';
$size            = '';
$design_file     = null;
$design_position = null;

if ($product['type'] === 'print') {
    $variants = json_decode($product['variants'] ?? '{}', true) ?? [];
    $colour   = trim($_POST['colour'] ?? '');
    $size     = trim($_POST['size']   ?? '');

    $v_errors = print_validate_item($variants, $colour, $size, $lang);
    if ($v_errors) {
        flash_set('error', implode(' ', $v_errors));
        header('Location: ' . $redirect);
        exit;
    }

    // Handle design file upload (custom products only)
    if (!empty($_FILES['design']['name'])) {
        if (($_FILES['design']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            flash_set('error', t_or('cart.err.upload_failed', 'Файлът не можа да се качи. Моля, опитай отново.', 'The file could not be uploaded. Please try again.', $lang));
            header('Location: ' . $redirect);
            exit;
        }
        // Use server-side MIME detection — do NOT trust $_FILES[x]['type'] (user-controlled)
        $detected_mime = mime_content_type($_FILES['design']['tmp_name']);
        $file_errors   = print_validate_design_file([
            'type' => $detected_mime,
            'size' => $_FILES['design']['size'],
        ], $lang);
        if ($file_errors) {
            flash_set('error', implode(' ', $file_errors));
            header('Location: ' . $redirect);
            exit;
        }

        $ext        = strtolower(pathinfo($_FILES['design']['name'], PATHINFO_EXTENSION));
        $safe_name  = print_generate_filename($ext);
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/print-designs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        if (!move_uploaded_file($_FILES['design']['tmp_name'], $upload_dir . $safe_name)) {
            flash_set('error', t_or('cart.err.upload_failed', 'Файлът не можа да се качи. Моля, опитай отново.', 'The file could not be uploaded. Please try again.', $lang));
            header('Location: ' . $redirect);
            exit;
        }
        $design_file = 'uploads/print-designs/' . $safe_name;

        $raw_pos         = $_POST['design_position'] ?? '{}';
        $pos             = json_decode($raw_pos, true);
        if (is_array($pos)) {
            $design_position = [
                'x'        => (float)($pos['x']        ?? 0.5),
                'y'        => (float)($pos['y']         ?? 0.4),
                'scale'    => (float)($pos['scale']     ?? 0.3),
                'rotation' => (float)($pos['rotation']  ?? 0),
            ];
        }
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Add/increment in cart
$cart        = $_SESSION['cart'] ?? [];
$found       = false;
$actually_added = true; // flipped to false if stock limit prevents any addition
// A pre-order line has no stock cap (product_line_limit returns PHP_INT_MAX).
$stock_limit = product_line_limit($product, $product['type'] === 'variant' ? (int)$pv['stock'] : (int)$product['stock']);
foreach ($cart as &$item) {
    $match = $item['product_id'] === $product_id;
    if ($product['type'] === 'variant') $match = $match && (int)($item['variant_id'] ?? 0) === $variant_id;
    if ($match) {
        $new_qty = min($item['quantity'] + $qty, $stock_limit);
        if ($new_qty === $item['quantity']) {
            // Already at stock limit — nothing added
            $actually_added = false;
            $msg = t_or('cart.err.no_more_stock', 'Няма повече налични бройки за този артикул.', 'Sorry, no more units available for this item.', $lang);
            flash_set('error', $msg);
        } elseif ($new_qty < $item['quantity'] + $qty) {
            // Partially capped
            $msg = t_or('cart.err.capped', 'Налични са само {n} бр. — количеството е коригирано.', 'Only {n} available — quantity adjusted.', $lang, ['n' => $stock_limit]);
            flash_set('error', $msg);
        }
        $item['quantity'] = $new_qty;
        // For print products: update variant selection if re-added
        if ($product['type'] === 'print') {
            $item['colour'] = $colour;
            $item['size']   = $size;
            // Only overwrite the stored design if a new file was actually uploaded
            if ($design_file !== null) {
                $item['design_file']     = $design_file;
                $item['design_position'] = $design_position;
            }
        }
        if ($product['type'] === 'variant') {
            $item['variant_label'] = $variant_label;
        }
        $found = true;
        break;
    }
}
unset($item);
if (!$found) {
    $capped_qty = min($qty, $stock_limit);
    if ($capped_qty < $qty) {
        $msg = t_or('cart.err.capped', 'Налични са само {n} бр. — количеството е коригирано.', 'Only {n} available — quantity adjusted.', $lang, ['n' => $stock_limit]);
        flash_set('error', $msg);
    }
    $entry = ['product_id' => $product_id, 'quantity' => $capped_qty];
    if ($product['type'] === 'print') {
        $entry['colour']          = $colour;
        $entry['size']            = $size;
        $entry['design_file']     = $design_file;
        $entry['design_position'] = $design_position;
    }
    if ($product['type'] === 'variant') {
        $entry['variant_id']    = $variant_id;
        $entry['variant_label'] = $variant_label;
    }
    $cart[] = $entry;
}
$_SESSION['cart'] = $cart;

if ($redirect_param === 'product') {
    $back = $shop_url . rawurlencode($product['slug']) . '/';
    if ($actually_added) {
        flash_set('success', t_or('cart.added', 'Добавено в количката!', 'Added to cart!', $lang));
        $back .= '?gads=atc';
    }
    header('Location: ' . $back);
} else {
    header('Location: ' . shop_path('cart', $lang) . '?gads=atc');
}
exit;
