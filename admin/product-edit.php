<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';

admin_require_shop();

$pdo    = get_pdo();
$id     = (int)($_GET['id'] ?? 0);
$is_new = $id === 0;
$errors = [];
$ok     = false;

// Load existing product
$product = [
    'id' => 0, 'slug' => '', 'name_bg' => '', 'name_en' => '',
    'description_bg' => '', 'description_en' => '',
    'price_eur' => '', 'stock' => 0, 'active' => 1, 'image' => '',
    'type' => 'standard', 'variants' => null,
];
if (!$is_new) {
    $row = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $row->execute([$id]);
    $found = $row->fetch();
    if (!$found) { header('Location: /admin/products.php'); exit; }
    $product = $found;
}

$page_title_admin = $is_new ? 'Нов продукт' : 'Редакция: ' . $product['name_bg'];
$active_nav       = 'products';
$deepl_ready      = deepl_is_configured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $name_bg    = trim($_POST['name_bg']        ?? '');
    $name_en    = trim($_POST['name_en']        ?? '');
    $desc_bg    = trim($_POST['description_bg'] ?? '');
    $desc_en    = trim($_POST['description_en'] ?? '');
    $price      = $_POST['price_eur']           ?? '';
    $stock      = (int)($_POST['stock']         ?? 0);
    $active     = isset($_POST['active']) ? 1 : 0;
    $slug_input = trim($_POST['slug']           ?? '');
    // Image was uploaded separately via AJAX; filename arrives in a plain field
    $image_name = trim($_POST['image_filename'] ?? '') ?: $product['image'];

    $prod_type = in_array($_POST['type'] ?? '', ['standard', 'print', 'variant'], true)
        ? $_POST['type']
        : 'standard';

    // Build the print-type variant structure (colours, sizes, print-area clamp,
    // per-size dims, size-guide). See includes/products.php for the rules + tests.
    $variants_json = null;
    $sizes = [];
    $size_guide_image = '';
    if ($prod_type === 'print') {
        $existing_vdata   = json_decode($product['variants'] ?? '{}', true) ?? [];
        $print            = product_build_print_variants($_POST, $existing_vdata);
        $sizes            = $print['sizes'];        // used by validation below
        $size_guide_image = $print['size_guide'];   // used by validation below
        $variants_json    = json_encode($print, JSON_UNESCAPED_UNICODE);
    }

    $variant_attributes_json = null;
    $submitted_variants = []; // processed after DB save (needs the product id)
    if ($prod_type === 'variant') {
        $variant_attributes_json = json_encode(
            product_clean_variant_attributes($_POST['variant_attributes'] ?? []),
            JSON_UNESCAPED_UNICODE
        );
        // Inject the gallery-image existence check so the parser stays pure.
        $products_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/products/';
        $submitted_variants = product_parse_variant_rows(
            $_POST,
            static fn(string $f): bool => is_file($products_dir . $f)
        );
    }

    // Validate
    if (!$name_bg) $errors[] = 'Наименованието на BG е задължително.';
    if ($price === '' || (float)$price <= 0) $errors[] = 'Цената трябва да е по-голяма от 0.';
    if ($stock < 0) $errors[] = 'Наличността не може да е отрицателна.';
    if ($prod_type === 'print' && empty($sizes)) $errors[] = 'Изберете поне един размер за продукт от тип Печат.';
    if ($prod_type === 'print' && empty($size_guide_image)) $errors[] = 'Добавете таблица с размери за продукт от тип Печат.';
    if ($prod_type === 'variant' && empty($submitted_variants)) {
        $errors[] = 'Добавете поне един вариант.';
    }

    $slug_val = product_compute_slug($slug_input, $name_en, $name_bg);
    if (!$slug_val) $errors[] = 'Невалиден slug.';

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id != ?');
        $check->execute([$slug_val, $id]);
        if ($check->fetch()) $errors[] = 'Slug вече съществува. Изберете друг.';
    }

    if (!$errors) {
        if ($is_new) {
            $pdo->prepare('INSERT INTO products (slug,name_bg,name_en,description_bg,description_en,price_eur,stock,active,image,`type`,variants,variant_attributes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json, $variant_attributes_json]);
            $saved_id = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE products SET slug=?,name_bg=?,name_en=?,description_bg=?,description_en=?,price_eur=?,stock=?,active=?,image=?,`type`=?,variants=?,variant_attributes=? WHERE id=?')
                ->execute([$slug_val, $name_bg, $name_en, $desc_bg, $desc_en, (float)$price, $stock, $active, $image_name, $prod_type, $variants_json, $variant_attributes_json, $id]);
            $saved_id = $id;
        }

        // Upsert product_variants for variant-type products
        if ($prod_type === 'variant') {
            $existing_stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
            $existing_stmt->execute([$saved_id]);
            $existing_ids = array_map('intval', array_column($existing_stmt->fetchAll(), 'id'));
            $submitted_ids = array_map('intval', array_filter(array_column($submitted_variants, 'id')));

            // Soft-delete or hard-delete removed variants
            foreach ($existing_ids as $eid) {
                if (!in_array($eid, $submitted_ids, true)) {
                    // NOTE: '$[*].variant_id' must match the key name written by cart/add.php + checkout/index.php (Tasks 6 & 7).
                    $ord = $pdo->prepare(
                        "SELECT COUNT(*) FROM orders WHERE JSON_SEARCH(items, 'one', ?, NULL, '\$[*].variant_id') IS NOT NULL"
                    );
                    $ord->execute([(string)$eid]);
                    if ((int)$ord->fetchColumn() > 0) {
                        $pdo->prepare('UPDATE product_variants SET active = 0 WHERE id = ?')->execute([$eid]);
                    } else {
                        $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$eid]);
                    }
                }
            }

            // Insert or update each submitted variant
            foreach ($submitted_variants as $sort => $sv) {
                $attrs_json  = json_encode($sv['attrs'], JSON_UNESCAPED_UNICODE);
                $images_json = json_encode($sv['images'] ?? [], JSON_UNESCAPED_UNICODE);
                if ($sv['id'] > 0 && in_array($sv['id'], $existing_ids, true)) {
                    $pdo->prepare(
                        'UPDATE product_variants SET label_bg=?,label_en=?,attributes=?,image=?,images=?,stock=?,sort_order=?,active=1 WHERE id=? AND product_id=?'
                    )->execute([$sv['label_bg'], $sv['label_en'], $attrs_json, $sv['image'], $images_json, $sv['stock'], $sort, $sv['id'], $saved_id]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,images,stock,sort_order) VALUES (?,?,?,?,?,?,?,?)'
                    )->execute([$saved_id, $sv['label_bg'], $sv['label_en'], $attrs_json, $sv['image'], $images_json, $sv['stock'], $sort]);
                }
            }
        }

        flash_set('success', 'Продуктът е запазен.');
        header('Location: /admin/products.php');
        exit;
    }

    // Re-populate form on error
    $product = array_merge($product, [
        'name_bg' => $name_bg, 'name_en' => $name_en,
        'description_bg' => $desc_bg, 'description_en' => $desc_en,
        'price_eur' => $price, 'stock' => $stock,
        'active' => $active, 'slug' => $slug_val,
        'image' => $image_name,
        'type' => $prod_type, 'variants' => $variants_json,
        'variant_attributes' => $variant_attributes_json,
    ]);
}

$lbl_bg_badge = '<span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>';
$lbl_en_badge = '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';
$_tinymce_key = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1><?= h($page_title_admin) ?></h1>
  <div style="display:flex;gap:.75rem;align-items:center;">
    <a href="/admin/products.php" class="btn btn--outline">← Назад</a>
    <button type="submit" id="submitBtn" form="productForm" class="btn btn--primary">Запази</button>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1rem;"><?= h($e) ?></div>
<?php endforeach; ?>

<form id="productForm" method="POST" class="admin-form" style="max-width:720px;">
  <?= csrf_field() ?>

  <div class="admin-form-grid">
    <label><span>Наименование <?= $lbl_bg_badge ?> *</span>
      <input type="text" id="nameBg" name="name_bg" value="<?= h($product['name_bg']) ?>" required
             oninput="if(!document.getElementById('slug').dataset.edited) document.getElementById('slug').value = slugify(this.value)">
    </label>
    <label>
      <?php if ($deepl_ready): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span>Name <?= $lbl_en_badge ?></span>
          <button type="button" class="btn btn--outline" onclick="txField('nameBg','nameEn',this)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
        </div>
      <?php else: ?>
        <span>Name <?= $lbl_en_badge ?></span>
      <?php endif; ?>
      <input type="text" id="nameEn" name="name_en" value="<?= h($product['name_en']) ?>"
             oninput="if(!document.getElementById('slug').dataset.edited) document.getElementById('slug').value = slugify(this.value)">
    </label>
  </div>

  <div class="form-group" style="margin-top:1rem;">
    <label style="font-size:.875rem;font-weight:600;">Slug (URL)
      <input type="text" name="slug" id="slug"
             value="<?= h($product['slug']) ?>"
             style="font-family:monospace;"
             oninput="this.dataset.edited='1'">
    </label>
    <small style="color:var(--text-muted);">Auto-generated from name. Edit if needed. Only a-z, 0-9, hyphens.</small>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-top:1rem;">
    <label style="font-size:.875rem;font-weight:600;">Цена (EUR) *
      <input type="number" name="price_eur" value="<?= h((string)$product['price_eur']) ?>"
             min="0.01" step="0.01" required style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;">
    </label>
    <label id="stockField" style="font-size:.875rem;font-weight:600;<?= ($product['type'] ?? '') === 'variant' ? 'display:none;' : '' ?>">Наличност
      <input type="number" name="stock" value="<?= (int)$product['stock'] ?>"
             min="0" step="1" style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;">
    </label>
    <label class="admin-checkbox" style="align-self:flex-end;padding-bottom:.5rem;">
      <input type="checkbox" name="active" value="1" <?= $product['active'] ? 'checked' : '' ?>>
      Активен (видим в магазина)
    </label>
  </div>

  <div class="admin-form-grid" style="margin-top:1.5rem;">
    <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.875rem;font-weight:600;"><span>Описание <?= $lbl_bg_badge ?></span>
      <textarea id="descBg" name="description_bg" rows="6"
                style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;resize:vertical;"><?= h($product['description_bg']) ?></textarea>
    </label>
    <div style="display:flex;flex-direction:column;gap:.35rem;font-size:.875rem;font-weight:600;">
      <?php if ($deepl_ready): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span>Description <?= $lbl_en_badge ?></span>
          <button type="button" class="btn btn--outline" onclick="txField('descBg','descEn',this,true)" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
        </div>
      <?php else: ?>
        <span>Description <?= $lbl_en_badge ?></span>
      <?php endif; ?>
      <textarea id="descEn" name="description_en" rows="6"
                style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;resize:vertical;"><?= h($product['description_en']) ?></textarea>
    </div>
  </div>

  <div id="imageField" class="form-group" style="margin-top:1.5rem;<?= ($product['type'] ?? '') === 'variant' ? 'display:none;' : '' ?>">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Снимка</label>
    <div id="imagePreview" style="margin-bottom:.75rem;<?= $product['image'] ? '' : 'display:none;' ?>">
      <img id="previewImg"
           src="<?= $product['image'] ? '/assets/images/products/' . h($product['image']) : '' ?>"
           alt="" style="height:100px;border-radius:6px;border:1px solid var(--border);">
      <div id="previewName" style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem;"><?= h($product['image']) ?></div>
    </div>
    <!-- hidden field carries the filename to the main form POST -->
    <input type="hidden" name="image_filename" id="imageFilename" value="<?= h($product['image']) ?>">
    <input type="file" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif" data-om-crop>
    <button type="button" class="btn btn--outline"
            style="margin-top:.5rem;font-size:.82rem;"
            onclick="_pickProductImage()">Избери от библиотека</button>
    <small id="imageStatus" style="display:block;color:var(--text-muted);margin-top:.25rem;">JPG, PNG, WebP, GIF — снимката се качва веднага след избор</small>
  </div>

<div class="form-group" style="margin-top:1.5rem;">
  <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Тип продукт</label>
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="standard"
             <?= ($product['type'] ?? 'standard') === 'standard' ? 'checked' : '' ?>
             onchange="switchType('standard')"
             style="accent-color:var(--teal);">
      Стандартен
    </label>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="print"
             <?= ($product['type'] ?? '') === 'print' ? 'checked' : '' ?>
             onchange="switchType('print')"
             style="accent-color:var(--teal);">
      Печат (тениска)
    </label>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
      <input type="radio" name="type" value="variant"
             <?= ($product['type'] ?? '') === 'variant' ? 'checked' : '' ?>
             onchange="switchType('variant')"
             style="accent-color:var(--teal);">
      С варианти
    </label>
  </div>
</div>

<?php
$vdata     = json_decode($product['variants'] ?? '{}', true) ?? [];
$vcolours  = $vdata['colours'] ?? [['name'=>'#ffffff','label_bg'=>'Бяло','label_en'=>'White','mockup'=>'']];
$vsizes    = $vdata['sizes']      ?? [];
$vpa        = $vdata['print_area'] ?? ['x'=>0.28,'y'=>0.18,'w'=>0.44,'h'=>0.50];
$vcustom     = !empty($vdata['custom']);
$vsize_guide = $vdata['size_guide'] ?? '';
$vsize_dims  = $vdata['size_dims']  ?? [];

$prod_variant_attrs = json_decode($product['variant_attributes'] ?? '[]', true) ?? [];
$prod_variants_rows = [];
if (($product['type'] ?? '') === 'variant' && !$is_new) {
    $pv_stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order');
    $pv_stmt->execute([$id]);
    $prod_variants_rows = $pv_stmt->fetchAll();
}
?>
<div id="variantsPanel" style="<?= ($product['type'] ?? 'standard') === 'print' ? '' : 'display:none;' ?>margin-top:1.5rem;padding:1.25rem;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--off-white);">
  <h3 style="font-size:.95rem;font-weight:700;margin:0 0 1rem;">Варианти за печат</h3>

  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Цветове</label>
    <div style="display:grid;grid-template-columns:auto 1fr 1fr auto;gap:.5rem;margin-bottom:.25rem;">
      <span style="font-size:.75rem;color:var(--text-muted);">Hex код</span>
      <span style="font-size:.75rem;color:var(--text-muted);">Наименование (БГ)</span>
      <span style="font-size:.75rem;color:var(--text-muted);">Name (EN)</span>
      <span></span>
    </div>
    <div id="colourRows">
      <?php foreach ($vcolours as $vc): ?>
      <div class="colour-row" style="display:grid;grid-template-columns:auto 1fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;">
        <input type="text" name="colour_name[]" value="<?= h($vc['name']) ?>" placeholder="#ffffff" maxlength="32" style="width:80px;padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;font-family:monospace;">
        <input type="text" name="colour_label_bg[]" placeholder="напр. Праскова" value="<?= h($vc['label_bg']) ?>" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
        <input type="text" name="colour_label_en[]" placeholder="e.g. Peach"     value="<?= h($vc['label_en']) ?>" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
        <input type="hidden" name="colour_mockup[]" value="<?= h($vc['mockup']) ?>">
        <button type="button" onclick="this.closest('.colour-row').remove()"
                style="padding:.3rem .6rem;border:1px solid #f0c4c0;background:#fdf0ef;color:#c0392b;border-radius:4px;cursor:pointer;font-size:.85rem;">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" onclick="addColourRow()" class="btn btn--outline" style="font-size:.82rem;margin-top:.25rem;">+ Добави цвят</button>
  </div>

  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.25rem;">Таблица с размери</label>
    <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .5rem;">Снимка на таблицата с размери от сайта на производителя — показва се на клиентите. Извличането на размерите ще попълни автоматично размерите и cm по-долу.</p>
    <input type="hidden" name="size_guide_filename" id="sizeGuideFilename" value="<?= h($vsize_guide) ?>">
    <div id="sizeGuidePreview" style="<?= $vsize_guide ? '' : 'display:none;' ?>margin-bottom:.5rem;">
      <img id="sizeGuideImg"
           src="<?= $vsize_guide ? '/assets/images/products/' . h($vsize_guide) : '' ?>"
           alt="" style="max-height:120px;border-radius:6px;border:1px solid var(--border);">
      <div id="sizeGuideName" style="font-size:.8rem;color:var(--text-muted);margin-top:.2rem;"><?= h($vsize_guide) ?></div>
    </div>
    <input type="file" id="sizeGuideInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" data-om-crop>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
      <button type="button" class="btn btn--outline" style="font-size:.82rem;" onclick="document.getElementById('sizeGuideInput').click()">
        <?= $vsize_guide ? 'Смени снимката' : 'Качи таблица с размери' ?>
      </button>
      <button type="button" id="extractDimsBtn" class="btn btn--outline" style="font-size:.82rem;<?= $vsize_guide ? '' : 'display:none;' ?>" onclick="extractSizeDims()">
        Извлечи размери от снимката
      </button>
    </div>
    <small id="sizeGuideStatus" style="display:block;color:var(--text-muted);margin-top:.25rem;">JPG, PNG, WebP — качва се веднага</small>
  </div>

  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Размери</label>
    <div id="sizesContainer" style="display:flex;gap:1rem;flex-wrap:wrap;">
      <?php
        // Render whatever sizes are saved; default to adult set if none
        $render_sizes = !empty($vsizes) ? $vsizes : ['S','M','L','XL','XXL'];
        // Detect if saved sizes are kids (contain slash or hyphen with digits)
        $is_kids_saved = !empty($vsizes) && preg_match('/\d[\/\-]\d/', implode(' ', $vsizes));
        $all_render = $is_kids_saved
            ? ['3/4','5/6','7/8','9/10','10/11','11/12','12/13','14/15']
            : ['S','M','L','XL','XXL'];
        foreach ($all_render as $sz):
      ?>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
          <input type="checkbox" name="sizes[]" value="<?= h($sz) ?>"
                 <?= in_array($sz, $vsizes, true) ? 'checked' : '' ?>
                 style="accent-color:var(--teal);">
          <?= h($sz) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.25rem;">Размери на тениската по размер (cm)</label>
    <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .5rem;">Ширина на гърдите (половин) × Дължина на тялото за всеки размер. Използват се за изчисляване на точните cm на печата.</p>
    <?php $grid_sizes = !empty($vsize_dims) ? array_keys($vsize_dims) : ['S','M','L','XL','XXL']; ?>
    <div id="sizeDimsGrid" style="display:grid;grid-template-columns:auto repeat(<?= count($grid_sizes) ?>,1fr);gap:.4rem .75rem;align-items:center;">
      <span></span>
      <?php foreach ($grid_sizes as $sz): ?>
        <span style="font-size:.8rem;font-weight:700;text-align:center;"><?= h($sz) ?></span>
      <?php endforeach; ?>
      <span style="font-size:.75rem;color:var(--text-muted);">Ширина (cm)</span>
      <?php foreach ($grid_sizes as $sz): ?>
        <input type="number" name="size_dim_w[<?= h($sz) ?>]"
               value="<?= h($vsize_dims[$sz]['w'] ?? '') ?>"
               min="0" max="200" step="0.5" placeholder="–"
               style="padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.82rem;width:100%;text-align:center;">
      <?php endforeach; ?>
      <span style="font-size:.75rem;color:var(--text-muted);">Дължина (cm)</span>
      <?php foreach ($grid_sizes as $sz): ?>
        <input type="number" name="size_dim_h[<?= h($sz) ?>]"
               value="<?= h($vsize_dims[$sz]['h'] ?? '') ?>"
               min="0" max="200" step="0.5" placeholder="–"
               style="padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.82rem;width:100%;text-align:center;">
      <?php endforeach; ?>
    </div>
  </div>

  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.25rem;">Зона за печат</label>
    <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .5rem;">Колко процента от ширината/височината на снимката заема зоната за дизайн.</p>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;">
      <label style="font-size:.8rem;font-weight:600;">Отляво (%)<input type="number" name="pa_x" value="<?= round($vpa['x'] * 100) ?>" min="0" max="100" step="1" style="display:block;width:100%;margin-top:.2rem;padding:.4rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"></label>
      <label style="font-size:.8rem;font-weight:600;">Отгоре (%)<input type="number" name="pa_y" value="<?= round($vpa['y'] * 100) ?>" min="0" max="100" step="1" style="display:block;width:100%;margin-top:.2rem;padding:.4rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"></label>
      <label style="font-size:.8rem;font-weight:600;">Ширина (%)<input type="number" name="pa_w" value="<?= round($vpa['w'] * 100) ?>" min="1" max="100" step="1" style="display:block;width:100%;margin-top:.2rem;padding:.4rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"></label>
      <label style="font-size:.8rem;font-weight:600;">Височина (%)<input type="number" name="pa_h" value="<?= round($vpa['h'] * 100) ?>" min="1" max="100" step="1" style="display:block;width:100%;margin-top:.2rem;padding:.4rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"></label>
    </div>
  </div>

  <label class="admin-checkbox">
    <input type="checkbox" name="custom_orders" value="1" <?= $vcustom ? 'checked' : '' ?>>
    Позволи персонализирани дизайни (качване от клиента)
  </label>
</div>

<?php $is_variant_type = ($product['type'] ?? 'standard') === 'variant'; ?>
<div id="productVariantsPanel" style="<?= $is_variant_type ? '' : 'display:none;' ?>margin-top:1.5rem;padding:1.25rem;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--off-white);">
  <h3 style="font-size:.95rem;font-weight:700;margin:0 0 1rem;">Варианти на продукта</h3>

  <!-- Attribute dimensions -->
  <div style="margin-bottom:1.25rem;">
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.4rem;">Атрибути (какво се различава)</label>
    <small style="color:var(--text-muted);display:block;margin-bottom:.5rem;">напр. "Вид", "Аромат" — станат заглавия на колоните долу</small>
    <div id="attrTags" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem;">
      <?php foreach ($prod_variant_attrs as $attr): ?>
        <span class="attr-tag" style="display:inline-flex;align-items:center;gap:.3rem;background:#e8f0fe;border:1px solid #4a90e2;border-radius:20px;padding:.2rem .75rem;font-size:.85rem;">
          <?= h($attr) ?>
          <input type="hidden" name="variant_attributes[]" value="<?= h($attr) ?>">
          <button type="button" onclick="removeAttrTag(this)" style="background:none;border:none;cursor:pointer;color:#888;padding:0;font-size:.9rem;line-height:1;">×</button>
        </span>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:.5rem;">
      <input type="text" id="newAttrInput" placeholder="Добави атрибут..." maxlength="64"
             style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;width:180px;"
             onkeydown="if(event.key==='Enter'){event.preventDefault();addAttrTag();}">
      <button type="button" onclick="addAttrTag()" class="btn btn--outline" style="font-size:.82rem;">Добави</button>
    </div>
  </div>

  <!-- Variants table -->
  <div>
    <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Варианти</label>
    <div id="pvTableWrap" style="overflow-x:auto;">
      <table id="pvTable" style="width:100%;border-collapse:collapse;font-size:.85rem;">
        <thead id="pvHead">
          <tr style="border-bottom:2px solid var(--border);">
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Снимка</th>
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Название (BG) *</th>
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Название (EN)</th>
            <!-- attr columns injected by JS -->
            <th style="text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;">Наличност</th>
            <th style="width:30px;"></th>
          </tr>
        </thead>
        <tbody id="pvBody">
          <?php foreach ($prod_variants_rows as $pvr): ?>
          <?php $pvr_attrs = json_decode($pvr['attributes'] ?? '{}', true) ?? []; ?>
          <?php $pvr_gallery = json_encode(['images' => variant_gallery($pvr), 'primary' => (string)($pvr['image'] ?? '')], JSON_UNESCAPED_UNICODE); ?>
          <tr class="pv-row" data-id="<?= (int)$pvr['id'] ?>" data-attrs="<?= h(json_encode($pvr_attrs, JSON_UNESCAPED_UNICODE)) ?>">
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <div class="pv-photos" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;"></div>
              <input type="hidden" name="pv_id[]" value="<?= (int)$pvr['id'] ?>">
              <input type="hidden" class="pv-images-val" name="pv_images[]" value="<?= h($pvr_gallery) ?>">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="text" name="pv_label_bg[]" value="<?= h($pvr['label_bg']) ?>" required
                     style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="text" name="pv_label_en[]" value="<?= h($pvr['label_en'] ?? '') ?>"
                     style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <!-- attr value cells injected by JS via rebuildAttrCols() -->
            <td style="padding:.4rem .5rem;vertical-align:middle;">
              <input type="number" name="pv_stock[]" value="<?= (int)$pvr['stock'] ?>" min="0"
                     style="width:70px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">
            </td>
            <td style="padding:.4rem .5rem;vertical-align:middle;text-align:center;">
              <button type="button" onclick="removePvRow(this)"
                      style="background:none;border:none;color:#e53935;cursor:pointer;font-size:1.1rem;padding:.2rem;">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="pvAttrInputs"></div>
    <button type="button" onclick="addPvRow()" class="btn btn--outline" style="margin-top:.75rem;font-size:.82rem;">+ Добави вариант</button>
  </div>
</div>

<script>
function switchType(type) {
  document.getElementById('variantsPanel').style.display        = type === 'print'   ? '' : 'none';
  document.getElementById('productVariantsPanel').style.display = type === 'variant' ? '' : 'none';
  document.getElementById('stockField').style.display           = type === 'variant' ? 'none' : '';
  document.getElementById('imageField').style.display           = type === 'variant' ? 'none' : '';
}
function addColourRow() {
  var row = document.createElement('div');
  row.className = 'colour-row';
  row.style.cssText = 'display:grid;grid-template-columns:auto 1fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;';
  row.innerHTML = '<input type="text" name="colour_name[]" value="#ffffff" placeholder="#ffffff" maxlength="32" style="width:80px;padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;font-family:monospace;">'
    + '<input type="text" name="colour_label_bg[]" placeholder="напр. Праскова" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '<input type="text" name="colour_label_en[]" placeholder="e.g. Peach" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '<input type="hidden" name="colour_mockup[]" value="">'
    + '<button type="button" onclick="this.closest(\'.colour-row\').remove()" style="padding:.3rem .6rem;border:1px solid #f0c4c0;background:#fdf0ef;color:#c0392b;border-radius:4px;cursor:pointer;font-size:.85rem;">✕</button>';
  document.getElementById('colourRows').appendChild(row);
}

// ── Product variants panel ───────────────────────────────────────────────────

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function getAttrLabels() {
  return Array.from(document.querySelectorAll('#attrTags .attr-tag input[name="variant_attributes[]"]'))
              .map(function(i){ return i.value; });
}

function addAttrTag() {
  var inp = document.getElementById('newAttrInput');
  var val = inp.value.trim();
  if (!val || val.length > 64) return;
  var existing = getAttrLabels();
  if (existing.includes(val)) { inp.value=''; return; }

  var span = document.createElement('span');
  span.className = 'attr-tag';
  span.style.cssText = 'display:inline-flex;align-items:center;gap:.3rem;background:#e8f0fe;border:1px solid #4a90e2;border-radius:20px;padding:.2rem .75rem;font-size:.85rem;';
  var labelNode = document.createTextNode(val);
  var hiddenInp = document.createElement('input');
  hiddenInp.type = 'hidden';
  hiddenInp.name = 'variant_attributes[]';
  hiddenInp.value = val;
  var rmBtn = document.createElement('button');
  rmBtn.type = 'button';
  rmBtn.setAttribute('onclick', 'removeAttrTag(this)');
  rmBtn.style.cssText = 'background:none;border:none;cursor:pointer;color:#888;padding:0;font-size:.9rem;line-height:1;';
  rmBtn.textContent = '×';
  span.appendChild(labelNode);
  span.appendChild(hiddenInp);
  span.appendChild(rmBtn);
  document.getElementById('attrTags').insertBefore(span, document.getElementById('attrTags').lastElementChild);
  inp.value = '';
  rebuildAttrCols();
}

function removeAttrTag(btn) {
  btn.closest('.attr-tag').remove();
  rebuildAttrCols();
}

function rebuildAttrCols() {
  var labels = getAttrLabels();
  var head   = document.getElementById('pvHead').querySelector('tr');
  var rows   = document.querySelectorAll('#pvBody .pv-row');

  head.querySelectorAll('th.attr-col').forEach(function(th){ th.remove(); });
  var stockTh = head.querySelectorAll('th')[3];
  labels.forEach(function(lbl) {
    var th = document.createElement('th');
    th.className = 'attr-col';
    th.style.cssText = 'text-align:left;padding:.4rem .5rem;color:var(--text-muted);font-size:.75rem;';
    th.textContent = lbl;
    head.insertBefore(th, stockTh);
  });

  rows.forEach(function(row) {
    row.querySelectorAll('td.attr-col').forEach(function(td){ td.remove(); });
    var attrHidden = row.querySelector('input.pv-attrs-json');
    var existing = {};
    if (attrHidden) {
      try { existing = JSON.parse(attrHidden.value); } catch(e){}
    } else if (row.dataset.attrs) {
      try { existing = JSON.parse(row.dataset.attrs); } catch(e){}
    }

    var tds = row.querySelectorAll('td');
    var stockTd = tds[tds.length - 2];
    labels.forEach(function(lbl) {
      var td = document.createElement('td');
      td.className = 'attr-col';
      td.style.cssText = 'padding:.4rem .5rem;vertical-align:middle;';
      td.innerHTML = '<input type="text" placeholder="' + esc(lbl) + '" value="' + esc(existing[lbl] || '') + '"'
        + ' style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"'
        + ' data-attr="' + esc(lbl) + '" onchange="syncAttrJson(this.closest(\'tr\'))">';
      row.insertBefore(td, stockTd);
    });
    syncAttrJson(row);
  });
}

function syncAttrJson(row) {
  var labels = getAttrLabels();
  var obj = {};
  labels.forEach(function(lbl) {
    var inp = row.querySelector('input[data-attr="' + lbl.replace(/"/g,'\\"') + '"]');
    if (inp) obj[lbl] = inp.value;
  });
  var hidden = row.querySelector('input.pv-attrs-json');
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.className = 'pv-attrs-json';
    hidden.name = 'pv_attrs[]';
    row.appendChild(hidden);
  }
  hidden.value = JSON.stringify(obj);
}

function addPvRow() {
  var labels = getAttrLabels();
  var tbody  = document.getElementById('pvBody');
  var tr     = document.createElement('tr');
  tr.className = 'pv-row';
  tr.dataset.id = '0';

  var attrCols = labels.map(function(lbl) {
    return '<td class="attr-col" style="padding:.4rem .5rem;vertical-align:middle;">'
      + '<input type="text" placeholder="' + esc(lbl) + '" data-attr="' + esc(lbl) + '"'
      + ' style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;"'
      + ' onchange="syncAttrJson(this.closest(\'tr\'))">'
      + '</td>';
  }).join('');

  tr.innerHTML = '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<div class="pv-photos" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;"></div>'
    + '<input type="hidden" name="pv_id[]" value="0">'
    + '<input type="hidden" class="pv-images-val" name="pv_images[]" value=\'{"images":[],"primary":""}\'>'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="text" name="pv_label_bg[]" required style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="text" name="pv_label_en[]" style="width:100%;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + attrCols
    + '<td style="padding:.4rem .5rem;vertical-align:middle;">'
    + '<input type="number" name="pv_stock[]" value="0" min="0" style="width:70px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.85rem;">'
    + '</td>'
    + '<td style="padding:.4rem .5rem;vertical-align:middle;text-align:center;">'
    + '<button type="button" onclick="removePvRow(this)" style="background:none;border:none;color:#e53935;cursor:pointer;font-size:1.1rem;padding:.2rem;">✕</button>'
    + '</td>';
  tbody.appendChild(tr);
  syncAttrJson(tr);
  renderPvPhotos(tr);
}

function removePvRow(btn) {
  btn.closest('tr').remove();
}

// ── Variant photo strip (multiple photos per variant) ─────────────────────────
var PV_MAX_PHOTOS = 8;

function pvGet(row) {
  try { return JSON.parse(row.querySelector('.pv-images-val').value) || {}; }
  catch (e) { return {}; }
}
function pvSet(row, data) {
  data.images = (data.images || []).filter(function (f) { return !!f; });
  if (!data.images.length) data.primary = '';
  else if (!data.primary || data.images.indexOf(data.primary) < 0) data.primary = data.images[0];
  row.querySelector('.pv-images-val').value = JSON.stringify(data);
  renderPvPhotos(row);
}
function renderPvPhotos(row) {
  var data = pvGet(row);
  var imgs = data.images || [];
  var wrap = row.querySelector('.pv-photos');
  if (!wrap) return;
  var html = '';
  imgs.forEach(function (fn) {
    var isPrimary = fn === data.primary;
    html += '<div class="pv-photo" draggable="true" data-fn="' + esc(fn) + '" '
      + 'style="position:relative;width:44px;height:44px;flex:0 0 auto;cursor:grab;">'
      + '<img src="/assets/images/products/' + esc(fn) + '" '
      + 'style="width:44px;height:44px;object-fit:cover;border-radius:4px;border:2px solid ' + (isPrimary ? 'var(--teal)' : 'var(--border)') + ';">'
      + '<button type="button" class="pv-star" title="Основна снимка" '
      + 'style="position:absolute;top:-7px;left:-7px;width:17px;height:17px;border:1px solid var(--border);border-radius:50%;background:#fff;cursor:pointer;font-size:11px;line-height:1;padding:0;color:' + (isPrimary ? '#f5a623' : '#ccc') + ';">★</button>'
      + '<button type="button" class="pv-del" title="Премахни" '
      + 'style="position:absolute;top:-7px;right:-7px;width:17px;height:17px;border:1px solid var(--border);border-radius:50%;background:#fff;cursor:pointer;font-size:10px;line-height:1;padding:0;color:#e53935;">✕</button>'
      + '</div>';
  });
  if (imgs.length < PV_MAX_PHOTOS) {
    html += '<div class="pv-img-placeholder" onclick="openVariantImagePicker(this)" title="Добави снимка" '
      + 'style="width:44px;height:44px;border:2px dashed var(--border);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-muted);font-size:1.2rem;flex:0 0 auto;">+</div>';
  }
  wrap.innerHTML = html;
}

// Delegated click handling for star (set primary) and delete
document.getElementById('pvBody').addEventListener('click', function (e) {
  var star = e.target.closest('.pv-star');
  var del  = e.target.closest('.pv-del');
  if (!star && !del) return;
  var photo = e.target.closest('.pv-photo');
  var row   = e.target.closest('tr');
  if (!photo || !row) return;
  var data = pvGet(row);
  var fn   = photo.dataset.fn;
  if (star) { data.primary = fn; }
  else if (del) { data.images = (data.images || []).filter(function (f) { return f !== fn; }); }
  pvSet(row, data);
});

// Drag-to-reorder photos within a row
var _pvDrag = null;
document.getElementById('pvBody').addEventListener('dragstart', function (e) {
  var p = e.target.closest('.pv-photo');
  if (!p) return;
  _pvDrag = p;
  e.dataTransfer.effectAllowed = 'move';
});
document.getElementById('pvBody').addEventListener('dragover', function (e) {
  if (_pvDrag) e.preventDefault();
});
document.getElementById('pvBody').addEventListener('drop', function (e) {
  var target = e.target.closest('.pv-photo');
  if (!_pvDrag || !target || target === _pvDrag) { _pvDrag = null; return; }
  var row = target.closest('tr');
  if (!row || _pvDrag.closest('tr') !== row) { _pvDrag = null; return; }
  e.preventDefault();
  var data = pvGet(row);
  var from = data.images.indexOf(_pvDrag.dataset.fn);
  var to   = data.images.indexOf(target.dataset.fn);
  if (from > -1 && to > -1) {
    data.images.splice(to, 0, data.images.splice(from, 1)[0]);
    pvSet(row, data);
  }
  _pvDrag = null;
});

// ── Variant image picker modal ───────────────────────────────────────────────
(function () {
  var _row = null;

  // Build modal once
  var modal = document.createElement('div');
  modal.id = 'pvImgModal';
  modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10100;align-items:flex-start;justify-content:center;padding:2rem;overflow-y:auto;';
  modal.innerHTML = [
    '<div style="background:#fff;border-radius:10px;width:100%;max-width:820px;margin:auto;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;">',
      '<div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">',
        '<span style="font-weight:600;font-size:.95rem;">Снимка на вариант</span>',
        '<button type="button" id="pvImgClose" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;line-height:1;padding:.25rem .5rem;">✕</button>',
      '</div>',
      '<div style="display:grid;grid-template-columns:1fr 1fr;min-height:320px;">',
        // Upload panel
        '<div style="padding:1.5rem;border-right:1px solid var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;">',
          '<div id="pvUploadZone" style="width:100%;border:2px dashed var(--border);border-radius:8px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:border-color .15s;" ',
               'onmouseover="this.style.borderColor=\'var(--teal)\'" onmouseout="this.style.borderColor=\'var(--border)\'">',
            '<div style="font-size:2rem;margin-bottom:.5rem;">⬆</div>',
            '<div style="font-size:.9rem;font-weight:600;margin-bottom:.25rem;">Качи снимка</div>',
            '<div style="font-size:.78rem;color:var(--text-muted);">JPG, PNG, WebP — макс. 8 MB</div>',
            '<input type="file" id="pvFileInput" accept="image/*" style="display:none;" data-om-crop>',
          '</div>',
          '<div id="pvUploadStatus" style="font-size:.82rem;color:var(--text-muted);min-height:1.2em;text-align:center;"></div>',
        '</div>',
        // Library panel
        '<div style="padding:1rem;display:flex;flex-direction:column;">',
          '<div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.6rem;">Библиотека</div>',
          '<div id="pvLibGrid" style="flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));grid-auto-rows:150px;gap:.6rem;overflow-y:auto;max-height:420px;border:1px solid var(--border);border-radius:6px;padding:.6rem;min-height:80px;">',
            '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;font-size:.85rem;">Зарежда…</p>',
          '</div>',
        '</div>',
      '</div>',
    '</div>',
  ].join('');
  document.body.appendChild(modal);

  var fileInput = document.getElementById('pvFileInput');
  var uploadZone = document.getElementById('pvUploadZone');
  var status = document.getElementById('pvUploadStatus');
  var libGrid = document.getElementById('pvLibGrid');
  var _libCache = null;

  function openModal(row) {
    _row = row;
    status.textContent = '';
    status.style.color = 'var(--text-muted)';
    modal.style.display = 'flex';
    loadLibrary();
  }
  function closeModal() { modal.style.display = 'none'; _row = null; }

  // Add a photo to the current variant. Keeps the picker open so several can be
  // added in a row; returns false if the 8-photo cap is reached.
  function addToRow(filename) {
    if (!_row) return false;
    var data = pvGet(_row);
    data.images = data.images || [];
    if (data.images.indexOf(filename) >= 0) return true; // already there
    if (data.images.length >= PV_MAX_PHOTOS) return false;
    data.images.push(filename);
    if (!data.primary) data.primary = filename;
    pvSet(_row, data);
    return true;
  }

  // Library click toggles a photo on/off for the current variant.
  function toggleInRow(filename) {
    if (!_row) return;
    var data = pvGet(_row);
    data.images = data.images || [];
    var idx = data.images.indexOf(filename);
    if (idx >= 0) {
      data.images.splice(idx, 1);
      pvSet(_row, data);
      renderLib();
    } else if (addToRow(filename)) {
      renderLib();
    } else {
      status.textContent = 'Максимум ' + PV_MAX_PHOTOS + ' снимки.';
      status.style.color = '#c0392b';
    }
  }

  function loadLibrary() {
    if (_libCache) { renderLib(); return; }
    fetch('/admin/media-library-ajax.php')
      .then(function(r){ return r.json(); })
      .then(function(d){
        // Only product photos belong in a product-variant picker, newest first
        // so a just-uploaded image surfaces at the top.
        _libCache = (d.images || [])
          .filter(function(i){ return i.cat === 'products'; })
          .sort(function(a, b){ return (b.mtime || 0) - (a.mtime || 0); });
        renderLib();
      })
      .catch(function(){ libGrid.innerHTML = '<p style="grid-column:1/-1;color:#c0392b;padding:1rem;text-align:center;font-size:.85rem;">Грешка при зареждане.</p>'; });
  }

  function renderLib() {
    if (!_libCache.length) { libGrid.innerHTML = '<p style="grid-column:1/-1;color:#888;padding:1rem;text-align:center;font-size:.85rem;">Няма снимки.</p>'; return; }
    var current = _row ? (pvGet(_row).images || []) : [];
    var html = '';
    _libCache.forEach(function(img) {
      var p     = img.path.replace(/"/g, '&quot;');
      var fn    = img.path.split('/').pop();
      var added = current.indexOf(fn) >= 0;
      var rest  = added ? 'var(--teal)' : 'transparent';
      html += '<div style="position:relative;cursor:pointer;border-radius:4px;overflow:hidden;border:2px solid ' + rest + ';transition:border-color .12s;" '
            + 'onmouseover="this.style.borderColor=\'var(--teal)\'" onmouseout="this.style.borderColor=\'' + rest + '\'" '
            + 'data-path="' + p + '">'
            + '<img src="' + p + '" loading="lazy" style="width:100%;height:150px;object-fit:cover;display:block;' + (added ? 'opacity:.75;' : '') + '">'
            + (added ? '<div style="position:absolute;top:6px;right:6px;background:var(--teal);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.3);">✓</div>' : '')
            + '</div>';
    });
    libGrid.innerHTML = html;
    libGrid.querySelectorAll('[data-path]').forEach(function(el) {
      el.addEventListener('click', function() {
        toggleInRow(this.dataset.path.split('/').pop());
      });
    });
  }

  uploadZone.addEventListener('click', function() { fileInput.click(); });

  fileInput.addEventListener('change', function() {
    if (!fileInput.files || !fileInput.files[0]) return;
    var fd = new FormData();
    fd.append('image', fileInput.files[0]);
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    status.textContent = 'Качва се…';
    status.style.color = 'var(--text-muted)';
    uploadZone.style.pointerEvents = 'none';
    fetch('/admin/upload-product-image.php', { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        uploadZone.style.pointerEvents = '';
        fileInput.value = '';
        if (d.error) { status.textContent = 'Грешка: ' + d.error; status.style.color = '#c0392b'; return; }
        // Surface the upload at the top of the library and add it to the variant.
        var newPath = '/assets/images/products/' + d.filename;
        _libCache = (_libCache || []).filter(function(i){ return i.path !== newPath; });
        _libCache.unshift({ path: newPath, cat: 'products', mtime: Date.now() / 1000 });
        if (addToRow(d.filename)) {
          status.textContent = 'Качена и добавена ✓';
          status.style.color = '#2d6a35';
        } else {
          status.textContent = 'Качена. Достигнат е максимумът от ' + PV_MAX_PHOTOS + ' снимки за варианта.';
          status.style.color = '#c0392b';
        }
        renderLib(); // picker stays open; show it added
      })
      .catch(function() {
        uploadZone.style.pointerEvents = '';
        fileInput.value = '';
        status.textContent = 'Грешка при качването.';
        status.style.color = '#c0392b';
      });
  });

  document.getElementById('pvImgClose').addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e) { if (modal.style.display !== 'none' && e.key === 'Escape') closeModal(); });

  window.openVariantImagePicker = function(el) { openModal(el.closest('tr')); };
})();

// Init on page load
document.addEventListener('DOMContentLoaded', function() {
  rebuildAttrCols();
  document.querySelectorAll('#pvBody .pv-row').forEach(renderPvPhotos);
});
</script>

  <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
    <button type="submit" class="btn btn--primary">Запази</button>
  </div>

</form>

<script>
function _pickProductImage() {
  openMediaPicker(function (p) {
    var fn = p.indexOf('/assets/images/products/') === 0
           ? p.replace('/assets/images/products/', '') : p;
    document.getElementById('imageFilename').value = fn;
    document.getElementById('previewImg').src = p;
    document.getElementById('previewName').textContent = fn;
    document.getElementById('imagePreview').style.display = '';
    var status = document.getElementById('imageStatus');
    status.textContent = 'Снимката е избрана от библиотеката.';
    status.style.color = '#2d6a35';
  }, { cat: 'products' });
}

document.getElementById('imageInput').addEventListener('change', function () {
  var file   = this.files[0];
  var status = document.getElementById('imageStatus');
  var btn    = document.getElementById('submitBtn');
  if (!file) return;

  // Client-side size guard (2MB matches the server's actual post_max_size for this request)
  if (file.size > 8 * 1024 * 1024) {
    status.textContent = 'Снимката е прекалено голяма (' + (file.size/1024/1024).toFixed(1) + ' MB). Макс. 8 MB.';
    status.style.color = '#c0392b';
    return;
  }

  status.textContent = 'Качване…';
  status.style.color = 'var(--text-muted)';
  btn.disabled = true;

  var fd = new FormData();
  fd.append('image', file);
  fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);

  fetch('/admin/upload-product-image.php', { method: 'POST', body: fd })
    .then(function(r) {
      return r.text().then(function(text) {
        try { return JSON.parse(text); }
        catch(e) { throw new Error(text.substring(0, 200)); }
      });
    })
    .then(function(data) {
      if (data.filename) {
        document.getElementById('imageFilename').value = data.filename;
        document.getElementById('previewImg').src = '/assets/images/products/' + data.filename;
        document.getElementById('previewName').textContent = data.filename;
        document.getElementById('imagePreview').style.display = '';
        status.textContent = 'Снимката е качена успешно.';
        status.style.color = '#2d6a35';
      } else {
        status.textContent = data.error || 'Грешка при качване.';
        status.style.color = '#c0392b';
      }
      btn.disabled = false;
    })
    .catch(function(e) {
      status.textContent = 'Грешка: ' + e.message;
      status.style.color = '#c0392b';
      btn.disabled = false;
    });
});

document.getElementById('sizeGuideInput').addEventListener('change', function () {
  var file   = this.files[0];
  var status = document.getElementById('sizeGuideStatus');
  if (!file) return;
  if (file.size > 8 * 1024 * 1024) {
    status.textContent = 'Файлът е прекалено голям (макс. 8 MB).';
    status.style.color = '#c0392b';
    return;
  }
  status.textContent = 'Качване…';
  status.style.color = 'var(--text-muted)';
  var fd = new FormData();
  fd.append('image', file);
  fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
  fetch('/admin/upload-product-image.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.filename) {
        document.getElementById('sizeGuideFilename').value = data.filename;
        document.getElementById('sizeGuideImg').src = '/assets/images/products/' + data.filename;
        document.getElementById('sizeGuideName').textContent = data.filename;
        document.getElementById('sizeGuidePreview').style.display = '';
        document.getElementById('extractDimsBtn').style.display = '';
        status.textContent = 'Качено успешно.';
        status.style.color = '#2d6a35';
      } else {
        status.textContent = data.error || 'Грешка при качване.';
        status.style.color = '#c0392b';
      }
    })
    .catch(function(e) {
      status.textContent = 'Грешка: ' + e.message;
      status.style.color = '#c0392b';
    });
});

function extractSizeDims() {
  var filename = document.getElementById('sizeGuideFilename').value;
  if (!filename) return;
  var btn    = document.getElementById('extractDimsBtn');
  var status = document.getElementById('sizeGuideStatus');
  btn.disabled = true;
  btn.textContent = 'Извличане…';
  status.textContent = 'Анализиране на таблицата с размери…';
  status.style.color = 'var(--text-muted)';

  fetch('/admin/extract-size-dims.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ filename: filename, csrf_token: document.querySelector('[name=csrf_token]').value }),
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    btn.disabled = false;
    btn.textContent = 'Извлечи размери от снимката';
    if (data.ok && data.dims) {
      var sizes = Object.keys(data.dims);

      // Detect kids sizes (contain / or - between digits)
      var isKids = sizes.some(function(s) { return /\d[\/\-]\d/.test(s); });
      var allSizes = isKids
        ? ['3/4','5/6','7/8','9/10','10/11','11/12','12/13','14/15']
        : ['S','M','L','XL','XXL'];

      // Rebuild size checkboxes
      var container = document.getElementById('sizesContainer');
      container.innerHTML = allSizes.map(function(s) {
        var checked = sizes.indexOf(s) !== -1 ? ' checked' : '';
        return '<label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">' +
          '<input type="checkbox" name="sizes[]" value="' + s + '"' + checked + ' style="accent-color:var(--teal);">' +
          s + '</label>';
      }).join('');

      // Rebuild dims grid
      var grid = document.getElementById('sizeDimsGrid');
      grid.style.gridTemplateColumns = 'auto repeat(' + sizes.length + ', 1fr)';
      grid.innerHTML =
        '<span></span>' +
        sizes.map(function(s) { return '<span style="font-size:.8rem;font-weight:700;text-align:center;">' + s + '</span>'; }).join('') +
        '<span style="font-size:.75rem;color:var(--text-muted);">Ширина (cm)</span>' +
        sizes.map(function(s) {
          return '<input type="number" name="size_dim_w[' + s + ']" value="' + data.dims[s].w + '" min="0" max="200" step="0.5" placeholder="–" style="padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.82rem;width:100%;text-align:center;background:#e8f7f9;">';
        }).join('') +
        '<span style="font-size:.75rem;color:var(--text-muted);">Дължина (cm)</span>' +
        sizes.map(function(s) {
          return '<input type="number" name="size_dim_h[' + s + ']" value="' + data.dims[s].h + '" min="0" max="200" step="0.5" placeholder="–" style="padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.82rem;width:100%;text-align:center;background:#e8f7f9;">';
        }).join('');
      status.textContent = 'Извлечени ' + sizes.length + ' размера. Проверете и запазете.';
      status.style.color = '#2d6a35';
    } else {
      status.textContent = data.error || 'Грешка при извличане.';
      status.style.color = '#c0392b';
    }
  })
  .catch(function(e) {
    btn.disabled = false;
    btn.textContent = 'Извлечи размери от снимката';
    status.textContent = 'Грешка: ' + e.message;
    status.style.color = '#c0392b';
  });
}

function slugify(str) {
  // Basic transliteration for common BG chars + ASCII slugify
  const map = {
    'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ж':'zh','з':'z',
    'и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p',
    'р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'ts','ч':'ch',
    'ш':'sh','щ':'sht','ъ':'a','ь':'','ю':'yu','я':'ya'
  };
  return str.toLowerCase()
    .split('').map(c => map[c] || c).join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}
</script>

<?php if ($deepl_ready): ?>
<script>
function _tmGet(el) {
  var ed = el.id && typeof tinymce !== 'undefined' ? tinymce.get(el.id) : null;
  return ed ? ed.getContent() : el.value;
}
function _tmSet(el, html) {
  var ed = el.id && typeof tinymce !== 'undefined' ? tinymce.get(el.id) : null;
  if (ed) ed.setContent(html); else el.value = html;
}
async function txEl(bgEl, enEl, btn, isHtml) {
  if (!bgEl || !enEl) return;
  const text = _tmGet(bgEl);
  if (!text.trim()) return;
  const orig = btn.textContent;
  btn.disabled = true; btn.textContent = '…';
  try {
    const r = await fetch('/admin/translate-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({text: text, is_html: !!isHtml, csrf_token: document.querySelector('[name=csrf_token]').value})
    });
    const d = await r.json();
    if (d.ok) { _tmSet(enEl, d.translated); btn.textContent = '✓'; }
    else alert(d.error || 'Translation failed');
  } catch(e) { alert(e.message); }
  btn.disabled = false;
}
function txField(bgId, enId, btn, isHtml) {
  txEl(document.getElementById(bgId), document.getElementById(enId), btn, isHtml);
}
</script>
<?php endif; ?>

<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#descBg, #descEn', min_height: 200 }));
initAutosave({
  key:     <?= json_encode('product:' . ($id ?: 'new')) ?>,
  formId:  'productForm',
  tinyIds: ['descBg', 'descEn']
});
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
