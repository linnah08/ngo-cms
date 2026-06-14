<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * One-time migration: consolidate individual soap and кърпи products
 * into two variant-type products.
 *
 * Run once on the production server while logged in as admin.
 * Safe to run multiple times — skips if new products already exist.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/db.php';
admin_require_login();

$pdo = get_pdo();

$log = [];
$errors = [];

function log_msg(string $msg, array &$log): void {
    $log[] = $msg;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function get_product_by_slug(PDO $pdo, string $slug): ?array {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function create_variant_product(
    PDO $pdo,
    string $slug,
    string $name_bg,
    string $name_en,
    string $description_bg,
    string $description_en,
    float $price_eur,
    string $image,
    array $variant_attributes,
    array &$log
): int {
    $stmt = $pdo->prepare('
        INSERT INTO products
            (slug, name_bg, name_en, description_bg, description_en,
             price_eur, stock, active, image, type, variant_attributes)
        VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, \'variant\', ?)
    ');
    $stmt->execute([
        $slug, $name_bg, $name_en, $description_bg, $description_en,
        $price_eur, $image,
        json_encode($variant_attributes, JSON_UNESCAPED_UNICODE),
    ]);
    $id = (int) $pdo->lastInsertId();
    log_msg("Created variant product '{$name_bg}' (id={$id}, slug={$slug})", $log);
    return $id;
}

function insert_variant(
    PDO $pdo,
    int $product_id,
    string $label_bg,
    string $label_en,
    string $image,
    int $stock,
    int $sort_order,
    array &$log
): int {
    $stmt = $pdo->prepare('
        INSERT INTO product_variants
            (product_id, label_bg, label_en, attributes, image, stock, active, sort_order)
        VALUES (?, ?, ?, \'[]\', ?, ?, 1, ?)
    ');
    $stmt->execute([$product_id, $label_bg, $label_en, $image, $stock, $sort_order]);
    $id = (int) $pdo->lastInsertId();
    log_msg("  → Variant '{$label_bg}' (id={$id}, stock={$stock}, image={$image})", $log);
    return $id;
}

function deactivate_product(PDO $pdo, int $id, string $slug, array &$log): void {
    $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);
    log_msg("Deactivated old product '{$slug}' (id={$id})", $log);
}

// ─────────────────────────────────────────────────────────────────────────────
// SOAP MIGRATION
// ─────────────────────────────────────────────────────────────────────────────

log_msg('=== Soap migration ===', $log);

$soap_slugs = [
    'natural-soap-lavender' => [
        'label_bg' => 'Лавандула',
        'label_en' => 'Lavender',
        'image'    => 'upload-c756fe708a4ab779.jpg',
        'sort'     => 0,
    ],
    'natural-soap-with-pomegranate-and-orange-scent' => [
        'label_bg' => 'Нар и портокал №1',
        'label_en' => 'Pomegranate & Orange #1',
        'image'    => 'upload-d064da109783084f.jpg',
        'sort'     => 1,
    ],
    'naturalen-sapun-s-nar-i-portokal1' => [
        'label_bg' => 'Нар и портокал №2',
        'label_en' => 'Pomegranate & Orange #2',
        'image'    => 'upload-8e62ea33d8ef006b.jpg',
        'sort'     => 2,
    ],
];

$soap_desc_bg = '<p>Натуралните сапуни са ръчно изработени с любов и грижа. Подходящи за всеки тип кожа.</p>';
$soap_desc_en = '<p>Handmade natural soaps crafted with love and care. Suitable for all skin types.</p>';

// Check if already migrated
$existing_soap = get_product_by_slug($pdo, 'naturalen-sapun');
if ($existing_soap) {
    log_msg("Soap product already exists (id={$existing_soap['id']}) — skipping soap migration.", $log);
} else {
    // Collect old products
    $old_soaps = [];
    foreach ($soap_slugs as $slug => $meta) {
        $p = get_product_by_slug($pdo, $slug);
        if ($p) {
            $old_soaps[$slug] = $p;
        } else {
            $errors[] = "Old soap product not found: {$slug}";
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $new_id = create_variant_product(
                $pdo,
                'naturalen-sapun',
                'Натурален сапун',
                'Natural Soap',
                $soap_desc_bg,
                $soap_desc_en,
                6.50,
                'upload-c756fe708a4ab779.jpg',
                [],
                $log
            );

            foreach ($soap_slugs as $slug => $meta) {
                $stock = isset($old_soaps[$slug]) ? (int)$old_soaps[$slug]['stock'] : 0;
                insert_variant($pdo, $new_id, $meta['label_bg'], $meta['label_en'], $meta['image'], $stock, $meta['sort'], $log);
            }

            foreach ($old_soaps as $slug => $p) {
                deactivate_product($pdo, (int)$p['id'], $slug, $log);
            }

            $pdo->commit();
            log_msg('Soap migration committed.', $log);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Soap migration rolled back: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// КЪРПИ MIGRATION
// ─────────────────────────────────────────────────────────────────────────────

log_msg('=== Кърпи migration ===', $log);

$karpi_slugs = [
    'vosachni-karpi' => [
        'label_bg' => 'Дизайн А',
        'label_en' => 'Design A',
        'image'    => 'upload-6c653bab13bb0b7c.jpg',
        'sort'     => 0,
    ],
    'vosachni-karpi7' => [
        'label_bg' => 'Дизайн Б',
        'label_en' => 'Design B',
        'image'    => 'upload-6a0f7510ff4fdd45.jpg',
        'sort'     => 1,
    ],
    'vosachni-karpi1' => [
        'label_bg' => 'Дизайн В',
        'label_en' => 'Design C',
        'image'    => 'upload-df5e1cc3bc52e432.jpg',
        'sort'     => 2,
    ],
    'vosachni-karpi6' => [
        'label_bg' => 'Дизайн Г',
        'label_en' => 'Design D',
        'image'    => 'upload-60623c3ab3937125.jpg',
        'sort'     => 3,
    ],
];

$karpi_desc_bg = '<p>Восъчните кърпи са изработени от пчелен восък и смола дамар. Издържат 6–12 месеца и не са подходящи за горещи ястия, сурово месо или риба.</p>';
$karpi_desc_en = '<p>Beeswax wraps made with beeswax and dammar resin. Lifespan 6–12 months. Not suitable for hot dishes, raw meat or fish.</p>';

$existing_karpi = get_product_by_slug($pdo, 'vosachni-karpi-set');
if ($existing_karpi) {
    log_msg("Кърпи product already exists (id={$existing_karpi['id']}) — skipping кърпи migration.", $log);
} else {
    $old_karpi = [];
    foreach ($karpi_slugs as $slug => $meta) {
        $p = get_product_by_slug($pdo, $slug);
        if ($p) {
            $old_karpi[$slug] = $p;
        } else {
            $errors[] = "Old кърпи product not found: {$slug}";
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $new_id = create_variant_product(
                $pdo,
                'vosachni-karpi-set',
                'Восъчни кърпи',
                'Beeswax Wraps',
                $karpi_desc_bg,
                $karpi_desc_en,
                15.30,
                'upload-6c653bab13bb0b7c.jpg',
                [],
                $log
            );

            foreach ($karpi_slugs as $slug => $meta) {
                $stock = isset($old_karpi[$slug]) ? (int)$old_karpi[$slug]['stock'] : 0;
                insert_variant($pdo, $new_id, $meta['label_bg'], $meta['label_en'], $meta['image'], $stock, $meta['sort'], $log);
            }

            foreach ($old_karpi as $slug => $p) {
                deactivate_product($pdo, (int)$p['id'], $slug, $log);
            }

            $pdo->commit();
            log_msg('Кърпи migration committed.', $log);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Кърпи migration rolled back: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Soap & Кърпи Migration</title>
<style>
  body { font-family: monospace; padding: 2rem; background: #f5f5f5; }
  pre  { background: #fff; border: 1px solid #ddd; padding: 1rem; white-space: pre-wrap; }
  .ok  { color: #1a7a1a; }
  .err { color: #c00; font-weight: bold; }
</style>
</head>
<body>
<h2>Soap &amp; Кърпи Migration</h2>

<?php if ($errors): ?>
<h3 class="err">Errors</h3>
<pre class="err"><?= implode("\n", array_map('htmlspecialchars', $errors)) ?></pre>
<?php endif; ?>

<h3 class="ok">Log</h3>
<pre class="ok"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></pre>

<p><a href="/admin/products.php">← Back to products</a></p>
</body>
</html>
