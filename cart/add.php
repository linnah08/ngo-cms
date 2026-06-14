<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/print_helpers.php';
start_session();

$lang     = ($_POST['_lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$shop_url = $lang === 'en' ? '/en/shop/' : '/magazin/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $shop_url);
    exit;
}

if (!csrf_verify()) {
    flash_set('error', 'Невалидна заявка.');
    header('Location: ' . $shop_url);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$qty        = max(1, (int)($_POST['quantity'] ?? 1));
$redirect_param = $_POST['redirect'] ?? '';
$redirect   = $redirect_param === 'cart' ? '/cart/' : $shop_url;

if (!$product_id) {
    flash_set('error', 'Невалиден продукт.');
    header('Location: ' . $redirect);
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT id, slug, name_bg, stock, active, `type`, variants FROM products WHERE id = ? AND active = 1');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    flash_set('error', 'Продуктът не е намерен.');
    header('Location: ' . $redirect);
    exit;
}

// ── Variant product validation ────────────────────────────────────────────────
$variant_id    = 0;
$variant_label = '';

if ($product['type'] === 'variant') {
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    if (!$variant_id) {
        flash_set('error', 'Моля изберете вариант.');
        header('Location: ' . $redirect);
        exit;
    }
    $pv_stmt = $pdo->prepare(
        'SELECT id, label_bg, stock, active FROM product_variants WHERE id = ? AND product_id = ?'
    );
    $pv_stmt->execute([$variant_id, $product_id]);
    $pv = $pv_stmt->fetch();
    if (!$pv || !$pv['active']) {
        flash_set('error', 'Вариантът не е намерен.');
        header('Location: ' . $redirect);
        exit;
    }
    if ((int)$pv['stock'] <= 0) {
        flash_set('error', h($pv['label_bg']) . ' е изчерпан.');
        header('Location: ' . $redirect . '?out=1');
        exit;
    }
    $variant_label = $pv['label_bg'];
}

// For standard/print products, check product-level stock
if ($product['type'] !== 'variant' && (int)$product['stock'] <= 0) {
    flash_set('error', h($product['name_bg']) . ' е изчерпан.');
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

    $v_errors = print_validate_item($variants, $colour, $size);
    if ($v_errors) {
        flash_set('error', implode(' ', $v_errors));
        header('Location: ' . $redirect);
        exit;
    }

    // Handle design file upload (custom products only)
    if (!empty($_FILES['design']['name'])) {
        if (($_FILES['design']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            flash_set('error', 'Грешка при качване на файла (код ' . (int)$_FILES['design']['error'] . ').');
            header('Location: ' . $redirect);
            exit;
        }
        // Use server-side MIME detection — do NOT trust $_FILES[x]['type'] (user-controlled)
        $detected_mime = mime_content_type($_FILES['design']['tmp_name']);
        $file_errors   = print_validate_design_file([
            'type' => $detected_mime,
            'size' => $_FILES['design']['size'],
        ]);
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
            flash_set('error', 'Грешка при качване на дизайна.');
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
$stock_limit = $product['type'] === 'variant' ? (int)$pv['stock'] : (int)$product['stock'];
foreach ($cart as &$item) {
    $match = $item['product_id'] === $product_id;
    if ($product['type'] === 'variant') $match = $match && (int)($item['variant_id'] ?? 0) === $variant_id;
    if ($match) {
        $new_qty = min($item['quantity'] + $qty, $stock_limit);
        if ($new_qty === $item['quantity']) {
            // Already at stock limit — nothing added
            $actually_added = false;
            $msg = $lang === 'en'
                ? 'Sorry, no more units available for this item.'
                : 'Няма повече налични бройки за този артикул.';
            flash_set('error', $msg);
        } elseif ($new_qty < $item['quantity'] + $qty) {
            // Partially capped
            $msg = $lang === 'en'
                ? "Only {$stock_limit} unit(s) available — quantity adjusted."
                : "Налични са само {$stock_limit} бр. — количеството е коригирано.";
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
        $msg = $lang === 'en'
            ? "Only {$stock_limit} unit(s) available — quantity adjusted."
            : "Налични са само {$stock_limit} бр. — количеството е коригирано.";
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
        flash_set('success', ($lang === 'en' ? 'Added to cart!' : 'Добавено в количката!'));
        $back .= '?gads=atc';
    }
    header('Location: ' . $back);
} else {
    header('Location: /cart/?gads=atc');
}
exit;
