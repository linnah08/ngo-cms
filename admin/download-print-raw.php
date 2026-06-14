<?php
/**
 * Downloads the raw customer design file (the print/sticker artwork,
 * no shirt background) for a given order item.
 *
 * GET params:
 *   order_id  int  — order ID
 *   item      int  — 0-based index of the item in the order's items JSON
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

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

$design_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($item['design_file'], '/');

if (!file_exists($design_path)) { http_response_code(404); exit('Design file not found.'); }

$ext  = strtolower(pathinfo($design_path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png'       => 'image/png',
    'jpg','jpeg'=> 'image/jpeg',
    'webp'      => 'image/webp',
    'gif'       => 'image/gif',
    default     => 'application/octet-stream',
};

$fname = 'print-' . $order_id . '-item' . ($item_index + 1) . '-artwork.' . $ext;
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($design_path));
readfile($design_path);
exit;
