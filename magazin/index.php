<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$lang = get_lang();
$pdo  = get_pdo();

// ── Route: /magazin/SLUG/ or /en/shop/SLUG/ → single product ─────────────────
$_uri_parts = explode('/', trim(strtok($_SERVER['REQUEST_URI'], '?'), '/'));
$_slug_idx  = $lang === 'bg' ? 1 : 2;
$raw  = basename(str_replace(['..', "\0"], '', rawurldecode($_uri_parts[$_slug_idx] ?? '')));
$slug = $raw !== '' ? $raw : null;

if ($slug) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE slug = ? AND active = 1');
    $stmt->execute([$slug]);
    $p = $stmt->fetch();

    $variants      = [];
    $is_print      = false;
    $is_custom     = false;
    $print_colours = [];
    $print_sizes   = [];
    $print_area    = ['x'=>0.28,'y'=>0.18,'w'=>0.44,'h'=>0.50];

    if ($p && $p['type'] === 'print') {
        $is_print      = true;
        $variants      = json_decode($p['variants'] ?? '{}', true) ?? [];
        $print_colours = $variants['colours']    ?? [];
        $print_sizes   = $variants['sizes']      ?? [];
        $print_area    = $variants['print_area'] ?? $print_area;
        $is_custom     = !empty($variants['custom']);
        $size_guide    = $variants['size_guide'] ?? '';
    }

    $is_variant    = false;
    $prod_variants = [];
    $variant_attrs = [];

    if ($p && $p['type'] === 'variant') {
        $is_variant    = true;
        $variant_attrs = json_decode($p['variant_attributes'] ?? '[]', true) ?? [];
        $pv_stmt = $pdo->prepare(
            'SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order'
        );
        $pv_stmt->execute([$p['id']]);
        $prod_variants = $pv_stmt->fetchAll();
    }

    if (!$p) {
        http_response_code(404);
        $page_title = $lang === 'bg' ? 'Продуктът не е намерен' : 'Product not found';
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
        echo '<section class="section"><div class="container"><h1>404</h1><p><a href="' . ($lang === 'bg' ? '/magazin/' : '/en/shop/') . '">' . ($lang === 'bg' ? '← Към магазина' : '← Back to shop') . '</a></p></div></section>';
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
        exit;
    }

    $name      = $lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg']);
    $desc      = $lang === 'bg' ? $p['description_bg'] : ($p['description_en'] ?: $p['description_bg']);
    $shop_url  = $lang === 'bg' ? '/magazin/' : '/en/shop/';
    $flash     = flash_get();
    $page_title = $name;

    // ── SEO: Open Graph image + Product structured data ──
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/seo.php';
    $seo_type = 'product';
    $_prod_img = $p['image'] ? '/assets/images/products/' . $p['image'] : '/assets/images/logo.png';
    $og_image  = $_prod_img;
    $_prod_schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $name,
        'description' => trim(mb_substr(strip_tags($desc ?? ''), 0, 300)),
        'image'       => seo_abs_url($_prod_img),
        'sku'         => $p['slug'],
        'brand'       => ['@type' => 'Brand', 'name' => SITE_NAME_EN],
    ];
    if ((float)$p['price_eur'] > 0) {
        $_prod_schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => number_format((float)$p['price_eur'], 2, '.', ''),
            'priceCurrency' => 'EUR',
            'availability'  => ((int)$p['stock'] > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => rtrim(SITE_URL, '/') . ($lang === 'bg' ? '/magazin/' : '/en/shop/') . rawurlencode($p['slug']) . '/',
        ];
    }
    $seo_jsonld = [$_prod_schema];

    $page_head_extra = '<style>
@media(max-width:640px){.product-detail-grid{grid-template-columns:1fr!important;gap:1.5rem!important;}.company-fields-grid{grid-template-columns:1fr!important;}}
.print-swatch{width:32px;height:32px;border-radius:50%;border:3px solid transparent;cursor:pointer;transition:border-color .15s;box-shadow:inset 0 0 0 1px rgba(0,0,0,0.18);}
.print-swatch.active{border-color:var(--teal);}
.print-size-btn{padding:.4rem .9rem;border:2px solid var(--border);border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;font-weight:600;transition:border-color .15s,background .15s;}
.print-size-btn.active{border-color:var(--teal);background:#e8f7f9;}
.print-size-btn:disabled{opacity:.4;cursor:default;}
@keyframes size-pulse{0%,100%{outline:2px solid transparent}50%{outline:2px solid var(--teal);outline-offset:4px;}}
#sizeBtns{animation:size-pulse 1.8s ease-in-out 3;}
.pv-option{display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;border:2px solid var(--border);border-radius:8px;cursor:pointer;transition:border-color .15s;}
.pv-option:hover{border-color:var(--teal);}
.pv-option.active{border-color:var(--teal);background:#e8f7f9;}
.pv-option.disabled{opacity:.45;cursor:default;}
.pv-thumb{width:40px;height:40px;object-fit:cover;border-radius:4px;flex-shrink:0;background:var(--off-white);}
.pv-thumbstrip{display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap;}
.pv-thumbstrip img{width:52px;height:52px;object-fit:cover;border-radius:4px;cursor:pointer;border:2px solid transparent;transition:border-color .15s;}
.pv-thumbstrip img.active{border-color:var(--teal);}
@media(max-width:640px){#pvGallery{display:none!important;}}
</style>';
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section">
  <div class="container" style="max-width:960px;">

    <div style="margin-bottom:2rem;">
      <a href="<?= $shop_url ?>" style="font-size:.85rem;color:var(--text-muted);">
        ← <?= $lang === 'bg' ? 'Обратно към магазина' : 'Back to shop' ?>
      </a>
    </div>

    <?php foreach ($flash as $f): ?>
      <div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
        <?= $f['type'] === 'success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
        <?= h($f['message']) ?>
      </div>
    <?php endforeach; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:start;" class="product-detail-grid">

      <!-- Image / Mockup -->
      <?php $initial_src = $p['image'] ? '/assets/images/products/' . $p['image'] : ''; ?>
      <?php
        if ($is_variant && !empty($prod_variants)) {
            $first_pv = $prod_variants[0];
            $initial_src = $first_pv['image'] ? '/assets/images/products/' . $first_pv['image'] : '';
        }
      ?>
      <span class="om-img-wrap"
            data-cms-field="image"
            data-cms-section="product"
            data-cms-id="<?= $p['id'] ?>"
            style="display:block;">
      <div id="mockupContainer" style="border-radius:var(--radius-lg);background:var(--off-white);aspect-ratio:1;position:relative;overflow:hidden;">
        <?php if ($is_variant): ?>
          <?php if ($initial_src): ?>
            <img id="mockupImg" src="<?= h($initial_src) ?>" alt="<?= h($name) ?>"
                 style="width:100%;height:100%;object-fit:cover;display:block;border-radius:var(--radius-lg);">
          <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:5rem;">🖼</div>
          <?php endif; ?>
        <?php elseif ($initial_src): ?>
          <?php if ($is_print): ?>
            <canvas id="mockupCanvas" style="width:100%;height:100%;display:block;"></canvas>
            <?php if ($is_custom): ?>
              <div id="printAreaGuide" style="position:absolute;box-sizing:border-box;border:2px dashed rgba(0,150,200,0.6);pointer-events:none;
                left:<?= ($print_area['x'] * 100) ?>%;top:<?= ($print_area['y'] * 100) ?>%;
                width:<?= ($print_area['w'] * 100) ?>%;height:<?= ($print_area['h'] * 100) ?>%;"></div>
              <div id="designWrap" style="display:none;position:absolute;cursor:move;touch-action:none;user-select:none;outline:2px dashed rgba(0,150,200,0.8);outline-offset:2px;">
                <img id="designImg" src="" alt="" draggable="false" style="display:block;width:100%;height:100%;pointer-events:none;">
                <div id="designHandle" style="position:absolute;bottom:-5px;right:-5px;width:12px;height:12px;background:var(--teal);cursor:se-resize;touch-action:none;"></div>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <img id="mockupImg" src="<?= h($initial_src) ?>" alt="<?= h($name) ?>"
                 style="width:100%;height:100%;object-fit:cover;display:block;">
          <?php endif; ?>
        <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:5rem;">🖼</div>
        <?php endif; ?>
      </div>
      <?php if ($is_variant && !empty($prod_variants)): ?>
      <!-- Photo gallery for the selected variant (populated by JS; hidden on phones) -->
      <div class="pv-thumbstrip" id="pvGallery" style="margin-top:.75rem;"></div>
      <?php endif; ?>
      <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
      </span>

      <!-- Details -->
      <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <h1 style="font-size:1.75rem;margin:0;line-height:1.25;"
            data-cms-field="name"
            data-cms-section="product"
            data-cms-type="text"
            data-cms-bg="<?= h($p['name_bg'] ?? '') ?>"
            data-cms-en="<?= h($p['name_en'] ?? '') ?>"
            data-cms-id="<?= $p['id'] ?>"><?= h($name) ?></h1>

        <div><?= price_html((float)$p['price_eur']) ?></div>

        <?php if ($desc): ?>
          <div style="line-height:1.8;color:var(--text-muted);white-space:pre-wrap;"
               data-cms-field="description"
               data-cms-section="product"
               data-cms-type="richtext"
               data-cms-bg="<?= h($p['description_bg'] ?? '') ?>"
               data-cms-en="<?= h($p['description_en'] ?? '') ?>"
               data-cms-id="<?= $p['id'] ?>"><?= $desc ?></div>
        <?php endif; ?>

        <?php if ($is_variant && !empty($prod_variants)): ?>
          <div style="margin-bottom:1rem;">
            <div style="font-size:.85rem;font-weight:600;margin-bottom:.6rem;">
              <?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
              <?php foreach ($prod_variants as $pvi => $pv): ?>
                <?php
                  $pv_label = $lang === 'bg' ? $pv['label_bg'] : ($pv['label_en'] ?: $pv['label_bg']);
                  $pv_attrs_arr = json_decode($pv['attributes'] ?? '{}', true) ?? [];
                  $pv_attrs_arr = array_filter($pv_attrs_arr, fn($v) => trim((string)$v) !== '');
                  $pv_attr_str  = implode(' · ', array_map(
                      fn($k,$v) => h($k) . ': ' . h($v),
                      array_keys($pv_attrs_arr), $pv_attrs_arr
                  ));
                  $pv_in_stock = (int)$pv['stock'] > 0;
                  $pv_img_src  = $pv['image'] ? '/assets/images/products/' . $pv['image'] : '';
                ?>
                <div class="pv-option<?= $pvi === 0 ? ' active' : '' ?><?= !$pv_in_stock ? ' disabled' : '' ?>"
                     id="pvo-<?= (int)$pv['id'] ?>"
                     <?= $pv_in_stock ? 'onclick="selectVariant(' . (int)$pv['id'] . ')"' : '' ?>>
                  <?php if ($pv_img_src): ?>
                    <img class="pv-thumb" src="<?= h($pv_img_src) ?>" alt="">
                  <?php else: ?>
                    <div class="pv-thumb" style="background:var(--off-white);border-radius:4px;"></div>
                  <?php endif; ?>
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:.95rem;"><?= h($pv_label) ?></div>
                    <?php if ($pv_attr_str): ?>
                      <div style="font-size:.78rem;color:var(--text-muted);"><?= $pv_attr_str ?></div>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:.8rem;flex-shrink:0;<?= $pv_in_stock ? 'color:var(--teal);' : 'color:#e53935;' ?>">
                    <?= $pv_in_stock
                        ? (int)$pv['stock'] . ($lang === 'bg' ? ' бр.' : ' left')
                        : ($lang === 'bg' ? 'Изчерпан' : 'Out of stock') ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php
          $show_cart_form = $is_variant
              ? !empty(array_filter($prod_variants, fn($pv) => (int)$pv['stock'] > 0))
              : (int)$p['stock'] > 0;
        ?>
        <?php if ($show_cart_form): ?>
          <form method="POST" action="/cart/add.php"
                <?= $is_custom ? 'enctype="multipart/form-data"' : '' ?>
                id="addToCartForm">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="redirect"   value="product">
            <input type="hidden" name="_lang"      value="<?= h($lang) ?>">

            <?php if ($is_variant): ?>
              <input type="hidden" name="variant_id" id="variantIdInput"
                     value="<?= !empty($prod_variants) ? (int)$prod_variants[0]['id'] : '' ?>">
            <?php endif; ?>

            <?php if ($is_print && !empty($print_colours)): ?>
            <!-- Colour selector -->
            <div style="margin-bottom:1rem;">
              <div style="font-size:.85rem;font-weight:600;margin-bottom:.5rem;">
                <?= $lang === 'bg' ? 'Цвят' : 'Colour' ?>: <span id="colourLabel" style="font-weight:400;"></span>
              </div>
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php foreach ($print_colours as $ci => $c): ?>
                  <button type="button"
                          class="print-swatch<?= $ci === 0 ? ' active' : '' ?>"
                          style="background:<?= h($c['name']) ?>;"
                          title="<?= h($lang === 'bg' ? $c['label_bg'] : $c['label_en']) ?>"
                          data-colour="<?= h($c['name']) ?>"
                          data-label="<?= h($lang === 'bg' ? $c['label_bg'] : $c['label_en']) ?>"
                          data-mockup="<?= h($c['mockup']) ?>"
                          onclick="selectColour(this)">
                  </button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="colour" id="colourInput"
                     value="<?= h($print_colours[0]['name'] ?? '') ?>">
            </div>
            <?php endif; ?>

            <?php if ($is_print && !empty($print_sizes)): ?>
            <!-- Size selector -->
            <div style="margin-bottom:1.25rem;">
              <div style="font-size:.85rem;font-weight:600;margin-bottom:.5rem;">
                <?= $lang === 'bg' ? 'Размер' : 'Size' ?> *
              </div>
              <div id="sizeBtns" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php foreach ($print_sizes as $s): ?>
                  <button type="button" class="print-size-btn"
                          data-size="<?= h($s) ?>"
                          onclick="selectSize(this)">
                    <?= h($s) ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="size" id="sizeInput" value="">
              <?php if (!empty($size_guide)): ?>
                <button type="button" onclick="openSizeGuide()"
                        style="margin-top:.5rem;background:none;border:none;padding:0;font-size:.8rem;color:var(--teal);cursor:pointer;text-decoration:underline;">
                  <?= $lang === 'bg' ? 'Таблица с размери' : 'Size guide' ?>
                </button>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_custom): ?>
            <!-- Design file upload (canvas is in the image block above) -->
            <div style="margin-bottom:1.25rem;">
              <div style="font-size:.85rem;font-weight:600;margin-bottom:.75rem;">
                <?= $lang === 'bg' ? 'Твоят дизайн' : 'Your design' ?>
              </div>
              <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn--outline"
                        onclick="document.getElementById('designFileInput').click()">
                  <?= $lang === 'bg' ? 'Качи твоя дизайн' : 'Upload your design' ?>
                </button>
                <button type="button" class="btn btn--outline" id="centerBtn"
                        onclick="centerDesign()" style="display:none;">
                  <?= $lang === 'bg' ? 'Центрирай' : 'Centre' ?>
                </button>
                <span id="designFileName" style="font-size:.82rem;color:var(--text-muted);"></span>
              </div>
              <input type="file" id="designFileInput" name="design"
                     accept="image/jpeg,image/png,image/webp"
                     style="display:none;"
                     onchange="loadDesignFile(this)">
              <input type="hidden" name="design_position" id="designPositionInput" value="">
            </div>
            <?php endif; ?>

            <button type="submit" id="addToCartBtn" class="btn btn--primary"
                    style="font-size:1rem;padding:.8rem 2rem;"
                    <?= $is_print && !empty($print_sizes) ? 'disabled' : '' ?>>
              <?= $lang === 'bg' ? 'Добави в количката' : 'Add to cart' ?>
            </button>
            <?php if ($is_print && !empty($print_sizes)): ?>
              <p id="sizeHint" role="alert" style="font-size:.875rem;font-weight:700;color:#b45309;background:#fef3c7;border:2px solid #f59e0b;border-radius:6px;padding:.55rem .85rem;margin-top:.75rem;display:flex;align-items:center;gap:.5rem;">
                <span aria-hidden="true" style="font-size:1.1rem;">⚠</span>
                <?= $lang === 'bg' ? 'Изберете размер, за да продължите.' : 'Select a size to continue.' ?>
              </p>
            <?php endif; ?>
          </form>

          <?php if ($is_print): ?>
          <script>
          // Canvas pixel tinting
          var _canvas = document.getElementById('mockupCanvas');
          var _ctx    = _canvas ? _canvas.getContext('2d') : null;
          var _origData = null;

          (function(){
            if (_canvas) {
              var img = new Image();
              img.onload = function() {
                _canvas.width  = img.naturalWidth;
                _canvas.height = img.naturalHeight;
                _ctx.drawImage(img, 0, 0);
                _origData = _ctx.getImageData(0, 0, _canvas.width, _canvas.height);
                var first = document.querySelector('.print-swatch');
                if (first) _tintCanvas(first.dataset.colour);
              };
              img.src = <?= json_encode($initial_src) ?>;
            }
            var firstSwatch = document.querySelector('.print-swatch');
            if (firstSwatch) {
              var lbl = document.getElementById('colourLabel');
              if (lbl) lbl.textContent = firstSwatch.dataset.label;
            }
          })();

          function _tintCanvas(hex) {
            if (!_origData || !_ctx) return;
            var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
            var nr = r/255, ng = g/255, nb = b/255;
            var out = new ImageData(new Uint8ClampedArray(_origData.data), _origData.width, _origData.height);
            var d = out.data;
            for (var i = 0; i < d.length; i += 4) {
              d[i]   = d[i]   * nr;
              d[i+1] = d[i+1] * ng;
              d[i+2] = d[i+2] * nb;
            }
            _ctx.putImageData(out, 0, 0);
          }

          function selectColour(btn) {
            document.querySelectorAll('.print-swatch').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('colourInput').value = btn.dataset.colour;
            var lbl = document.getElementById('colourLabel');
            if (lbl) lbl.textContent = btn.dataset.label;
            _tintCanvas(btn.dataset.colour);
          }

          function selectSize(btn) {
            document.querySelectorAll('.print-size-btn').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('sizeInput').value = btn.dataset.size;
            var cartBtn = document.getElementById('addToCartBtn');
            if (cartBtn) cartBtn.disabled = false;
            var hint = document.getElementById('sizeHint');
            if (hint) hint.style.display = 'none';
            var sizeBtns = document.getElementById('sizeBtns');
            if (sizeBtns) sizeBtns.style.animation = 'none';
          }
          </script>
          <?php if ($is_custom): ?>
          <script>
          (function() {
            var wrap = document.getElementById('designWrap');
            var handle = document.getElementById('designHandle');
            var container = document.getElementById('mockupContainer');
            var ox = 0, oy = 0, ow = 0, oh = 0;

            function savePos() {
              var cw = container.offsetWidth, ch = container.offsetHeight || cw;
              document.getElementById('designPositionInput').value = JSON.stringify({
                x: (ox + wrap.offsetWidth / 2) / cw,
                y: (oy + wrap.offsetHeight / 2) / ch,
                scale: wrap.offsetWidth / cw,
                rotation: 0,
              });
            }

            // Drag to move
            wrap.addEventListener('mousedown', function(e) {
              if (e.target === handle) return;
              e.preventDefault();
              var sx = e.clientX - ox, sy = e.clientY - oy;
              function move(e) { ox = e.clientX - sx; oy = e.clientY - sy; wrap.style.left = ox + 'px'; wrap.style.top = oy + 'px'; savePos(); }
              function up() { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); }
              document.addEventListener('mousemove', move);
              document.addEventListener('mouseup', up);
            });

            // Drag handle to resize
            handle.addEventListener('mousedown', function(e) {
              e.preventDefault(); e.stopPropagation();
              var sx = e.clientX, sw = wrap.offsetWidth, sh = wrap.offsetHeight, aspect = sw / sh;
              function move(e) {
                var nw = Math.max(40, sw + (e.clientX - sx));
                wrap.style.width  = nw + 'px';
                wrap.style.height = (nw / aspect) + 'px';
                savePos();
              }
              function up() { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); }
              document.addEventListener('mousemove', move);
              document.addEventListener('mouseup', up);
            });

            window.centerDesign = function() {
              if (!wrap.offsetWidth) return;
              var cw = container.offsetWidth;
              var ch = container.offsetHeight || cw; // aspect-ratio:1 but abs children collapse height
              var pa = <?= json_encode($print_area) ?>;
              ox = pa.x * cw + (pa.w * cw - wrap.offsetWidth) / 2;
              wrap.style.left = ox + 'px';
              savePos();
            };

            window.loadDesignFile = function(input) {
              var file = input.files[0];
              if (!file) return;
              var span = document.getElementById('designFileName');
              if (span) span.textContent = file.name;
              var img = document.getElementById('designImg');
              var url = URL.createObjectURL(file);
              img.onload = function() {
                var cw = container.offsetWidth, ch = container.offsetHeight || cw;
                var pa = <?= json_encode($print_area) ?>;
                ow = pa.w * cw * 0.6;
                oh = ow * (img.naturalHeight / img.naturalWidth);
                ox = pa.x * cw + (pa.w * cw - ow) / 2;
                oy = pa.y * ch + (pa.h * ch - oh) / 2;
                wrap.style.width  = ow + 'px';
                wrap.style.height = oh + 'px';
                wrap.style.left   = ox + 'px';
                wrap.style.top    = oy + 'px';
                wrap.style.display = '';
                savePos();
                var cb = document.getElementById('centerBtn');
                if (cb) cb.style.display = '';
              };
              img.src = url;
            };
          })();
          </script>
          <?php endif; ?>
          <?php endif; ?>

        <?php else: ?>
          <?php if (!$is_variant): ?>
          <div style="padding:.75rem 1.25rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;border-radius:var(--radius);font-weight:600;">
            <?= $lang === 'bg' ? 'Изчерпан' : 'Out of stock' ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<?php if (!empty($size_guide)): ?>
<!-- Size guide modal -->
<div id="sizeGuideOverlay" onclick="closeSizeGuide()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;align-items:center;justify-content:center;">
  <div onclick="event.stopPropagation()" style="background:#fff;border-radius:12px;padding:1.25rem;max-width:720px;width:calc(100vw - 2rem);max-height:90vh;overflow-y:auto;position:relative;">
    <button onclick="closeSizeGuide()" style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.4rem;cursor:pointer;line-height:1;color:var(--text-muted);">✕</button>
    <h3 style="margin:0 0 1rem;font-size:1rem;"><?= $lang === 'bg' ? 'Таблица с размери' : 'Size guide' ?></h3>
    <img src="/assets/images/products/<?= h($size_guide) ?>" alt="Size guide" style="width:100%;height:auto;border-radius:6px;display:block;">
  </div>
</div>
<script>
function openSizeGuide()  { document.getElementById('sizeGuideOverlay').style.display = 'flex'; }
function closeSizeGuide() { document.getElementById('sizeGuideOverlay').style.display = 'none'; }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeSizeGuide(); });
</script>
<?php endif; ?>

<?php if ($is_variant && !empty($prod_variants)): ?>
<script>
var _pvData = <?= json_encode(
  array_map(fn($pv) => [
    'id'     => (int)$pv['id'],
    'image'  => $pv['image'] ? '/assets/images/products/' . $pv['image'] : '',
    'images' => array_map(fn($f) => '/assets/images/products/' . $f, variant_gallery($pv)),
    'stock'  => (int)$pv['stock'],
  ], $prod_variants)
, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

var _galleryImgs = [];
var _galleryIdx  = 0;

// Swap only the main image to gallery photo i (wraps around)
function galleryShow(i) {
  if (!_galleryImgs.length) return;
  _galleryIdx = (i + _galleryImgs.length) % _galleryImgs.length;
  var img = document.getElementById('mockupImg');
  if (img) img.src = _galleryImgs[_galleryIdx];
  document.querySelectorAll('#pvGallery img').forEach(function(t, idx){
    t.classList.toggle('active', idx === _galleryIdx);
  });
}

// Build the thumbnail strip for a variant's photos (hidden if it has <= 1).
// The active thumb tracks the primary photo, which is what the main image shows.
function renderGallery(vid) {
  var pv = _pvData.find(function(v){ return v.id === vid; });
  _galleryImgs = (pv && pv.images && pv.images.length) ? pv.images.slice()
               : (pv && pv.image ? [pv.image] : []);
  var primaryIdx = pv ? _galleryImgs.indexOf(pv.image) : -1;
  if (primaryIdx < 0) primaryIdx = 0;
  _galleryIdx = primaryIdx;
  var wrap = document.getElementById('pvGallery');
  if (!wrap) return;
  if (_galleryImgs.length <= 1) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
  wrap.style.display = '';
  wrap.innerHTML = _galleryImgs.map(function(src, idx){
    return '<img src="' + src + '" class="' + (idx === primaryIdx ? 'active' : '') + '" '
         + 'onclick="galleryShow(' + idx + ')" alt="">';
  }).join('');
}

function selectVariant(vid) {
  var pv = _pvData.find(function(v){ return v.id === vid; });
  if (!pv || pv.stock === 0) return;

  var inp = document.getElementById('variantIdInput');
  if (inp) inp.value = vid;

  var img = document.getElementById('mockupImg');
  if (img && pv.image) img.src = pv.image;

  document.querySelectorAll('.pv-option').forEach(function(el){
    el.classList.remove('active');
  });
  var opt = document.getElementById('pvo-' + vid);
  if (opt) opt.classList.add('active');

  renderGallery(vid);
}

// Mobile: swipe the main image left/right through the selected variant's photos
(function () {
  var c = document.getElementById('mockupContainer');
  if (!c) return;
  var x0 = null;
  c.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, { passive: true });
  c.addEventListener('touchend', function(e){
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) < 40) return;
    galleryShow(_galleryIdx + (dx < 0 ? 1 : -1));
  }, { passive: true });
})();

<?php if (!empty($prod_variants)): ?>
renderGallery(<?= (int)$prod_variants[0]['id'] ?>);
<?php endif; ?>

</script>
<?php endif; ?>

<?php
    require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
    exit;
}

// ── LISTING ───────────────────────────────────────────────────────────────────
$page_title      = $lang === 'bg' ? 'Магазин' : 'Shop';
$page_head_extra = '<style>@media(max-width:640px){.product-detail-grid{grid-template-columns:1fr!important;gap:1.5rem!important;}.company-fields-grid{grid-template-columns:1fr!important;}}</style>';
$page_description = $lang === 'bg'
    ? 'Подкрепете Фондация Различни умове с покупка от нашия магазин.'
    : 'Support Odd Minds Foundation with a purchase from our shop.';

$pages = load_json(CONTENT_PATH . '/pages.json');
$donation_text = $lang === 'bg'
    ? ($pages['shop']['donation_text_bg'] ?? '')
    : ($pages['shop']['donation_text_en'] ?? '');

$products = $pdo->query('SELECT * FROM products WHERE active=1 ORDER BY id')->fetchAll();

// For variant products, image column is empty — use first active variant's image
$variant_images = [];
$variant_product_ids = array_column(
    array_filter($products, fn($p) => $p['type'] === 'variant'),
    'id'
);
$variant_stock = []; // product_id => total stock units across all active variants
if ($variant_product_ids) {
    $in2 = implode(',', array_fill(0, count($variant_product_ids), '?'));
    $vi_stmt = $pdo->prepare(
        "SELECT pv.product_id, pv.image
         FROM product_variants pv
         INNER JOIN (
             SELECT product_id, MIN(sort_order) AS min_sort
             FROM product_variants
             WHERE product_id IN ($in2) AND active = 1 AND image != ''
             GROUP BY product_id
         ) m ON pv.product_id = m.product_id AND pv.sort_order = m.min_sort
         WHERE pv.active = 1 AND pv.image != ''"
    );
    $vi_stmt->execute($variant_product_ids);
    foreach ($vi_stmt->fetchAll() as $vi) {
        $variant_images[$vi['product_id']] = $vi['image'];
    }

    $vs_stmt = $pdo->prepare(
        "SELECT product_id, SUM(stock) AS in_stock
         FROM product_variants
         WHERE product_id IN ($in2) AND active = 1
         GROUP BY product_id"
    );
    $vs_stmt->execute($variant_product_ids);
    foreach ($vs_stmt->fetchAll() as $vs) {
        $variant_stock[$vs['product_id']] = (int)$vs['in_stock'];
    }
}

$flash    = flash_get();
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<!-- Hero -->
<section class="section section--grey" style="padding-bottom:2rem;">
  <div class="container">
    <span class="section-label"><?= $lang === 'bg' ? 'Магазин' : 'Shop' ?></span>
    <h1><?= $lang === 'bg' ? 'Дари и подкрепи' : 'Shop & Support' ?></h1>
    <p class="lead" style="margin-top:1rem;max-width:640px;">
      <?= $lang === 'bg'
        ? 'Всяка покупка директно финансира работата ни с децата.'
        : 'Every purchase directly funds our work with children.' ?>
    </p>
  </div>
</section>

<?php foreach ($flash as $f): ?>
<div class="container" style="margin-top:1.5rem;">
  <div style="padding:.9rem 1.25rem;border-radius:6px;
    <?= $f['type'] === 'success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
    <?= h($f['message']) ?>
  </div>
</div>
<?php endforeach; ?>

<!-- ── SECTION A: Products ─────────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="section-header" style="margin-bottom:2rem;">
      <span class="section-label"><?= $lang === 'bg' ? 'Продукти' : 'Products' ?></span>
      <h2><?= $lang === 'bg' ? 'Нашите продукти' : 'Our products' ?></h2>
    </div>

    <?php if (empty($products)): ?>
      <p style="color:var(--text-muted);text-align:center;padding:3rem 0;">
        <?= $lang === 'bg' ? 'Скоро ще добавим продукти.' : 'Products coming soon.' ?>
      </p>
    <?php else: ?>
    <div class="grid grid--3" style="gap:2rem;align-items:stretch;">
      <?php foreach ($products as $p): ?>
      <?php
        $name      = h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg']));
        $desc      = $lang === 'bg' ? $p['description_bg'] : ($p['description_en'] ?: $p['description_bg']);
        $prod_url  = ($lang === 'bg' ? '/magazin/' : '/en/shop/') . h($p['slug']) . '/';
        $card_img  = $p['type'] === 'variant'
            ? ($variant_images[$p['id']] ?? '')
            : $p['image'];
      ?>
      <div class="card om-removable" id="<?= h($p['slug']) ?>"
           data-cms-remove-type="product"
           data-cms-remove-id="<?= $p['id'] ?>"
           data-cms-id="<?= $p['id'] ?>"
           onclick="if(!event.target.closest('a,button,form'))window.location='<?= $prod_url ?>'"
           style="display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);cursor:pointer;">
        <!-- image -->
        <a href="<?= $prod_url ?>" tabindex="-1" aria-hidden="true" style="display:block;aspect-ratio:1;overflow:hidden;background:var(--off-white);">
          <span class="om-img-wrap"
                data-cms-field="image"
                data-cms-section="product"
                data-cms-id="<?= $p['id'] ?>"
                style="display:block;width:100%;height:100%;position:relative;">
          <?php if ($card_img): ?>
            <img src="/assets/images/products/<?= h($card_img) ?>"
                 alt="<?= $name ?>"
                 loading="lazy"
                 style="width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s;"
                 onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform=''">
          <?php else: ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:3rem;">🖼</div>
          <?php endif; ?>
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
          </span>
        </a>

        <!-- body -->
        <div style="padding:1.25rem 1.25rem 1.5rem;display:flex;flex-direction:column;flex:1;">
          <h3 style="font-size:1rem;font-weight:700;margin:0 0 .6rem;line-height:1.35;">
            <a href="<?= $prod_url ?>" style="color:inherit;text-decoration:none;"
               data-cms-field="name"
               data-cms-section="product"
               data-cms-type="text"
               data-cms-bg="<?= h($p['name_bg'] ?? '') ?>"
               data-cms-en="<?= h($p['name_en'] ?? '') ?>"
               data-cms-id="<?= $p['id'] ?>"><?= $name ?></a>
          </h3>

          <?php if ($desc): ?>
          <p style="font-size:.875rem;color:var(--text-muted);line-height:1.65;margin:0 0 1rem;
                    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"
             data-cms-field="description"
             data-cms-section="product"
             data-cms-type="richtext"
             data-cms-bg="<?= h($p['description_bg'] ?? '') ?>"
             data-cms-en="<?= h($p['description_en'] ?? '') ?>"
             data-cms-id="<?= $p['id'] ?>">
            <?= h(strip_tags($desc)) ?>
          </p>
          <?php endif; ?>

          <!-- stock signal -->
          <?php
            if ($p['type'] === 'variant') {
                $in_stock_count = $variant_stock[$p['id']] ?? 0;
            } else {
                $in_stock_count = (int)$p['stock'];
            }
          ?>
          <?php if ($in_stock_count > 0 && $in_stock_count <= 5): ?>
            <p style="font-size:.78rem;color:#b45309;font-weight:600;margin:0 0 .5rem;">
              ⚠ <?= $lang === 'bg' ? "Само {$in_stock_count} бр. налични" : "Only {$in_stock_count} left" ?>
            </p>
          <?php elseif ($in_stock_count === 0 && $p['type'] !== 'variant'): ?>
            <?php /* out-of-stock handled by button below */ ?>
          <?php endif; ?>

          <!-- price -->
          <div style="margin-top:auto;margin-bottom:1rem;">
            <?= price_html((float)$p['price_eur']) ?>
          </div>

          <!-- actions -->
          <div style="display:flex;flex-direction:column;gap:.5rem;">
            <?php if ($p['type'] === 'variant'): ?>
              <a href="<?= $prod_url ?>" class="btn btn--primary"
                 style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;text-align:center;box-sizing:border-box;">
                <?= $lang === 'bg' ? 'Избери вариант' : 'Choose variant' ?>
              </a>
            <?php elseif ((int)$p['stock'] > 0): ?>
              <form method="POST" action="/cart/add.php">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="redirect" value="shop">
                <input type="hidden" name="_lang" value="<?= h($lang) ?>">
                <button type="submit" class="btn btn--primary"
                        style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;">
                  <?= $lang === 'bg' ? 'Добави в количката' : 'Add to cart' ?>
                </button>
              </form>
            <?php else: ?>
              <div style="width:100%;text-align:center;padding:.65rem 1rem;border-radius:var(--radius);
                          background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;font-size:.875rem;font-weight:600;">
                <?= $lang === 'bg' ? 'Изчерпан' : 'Out of stock' ?>
              </div>
            <?php endif; ?>
            <a href="<?= $prod_url ?>" class="btn btn--outline"
               style="width:100%;justify-content:center;font-size:.9rem;padding:.65rem 1rem;text-align:center;box-sizing:border-box;">
              <?= $lang === 'bg' ? 'Виж детайли' : 'View details' ?>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (admin_logged_in()): ?>
    <a href="/admin/product-edit.php?new=1" class="om-add-btn" style="display:none">+ Add product</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- ── SECTION B: Donations ───────────────────────────────────────────────── -->
<section id="donation" class="section section--teal" style="margin-top:3rem;">
  <div class="container">
    <div style="max-width:640px;margin:0 auto;text-align:center;">
      <span class="section-label" style="color:rgba(255,255,255,.7);">
        <?= $lang === 'bg' ? 'Дарение' : 'Donation' ?>
      </span>
      <h2 style="color:#fff;margin-bottom:1rem;">
        <?= $lang === 'bg' ? 'Направи дарение' : 'Make a donation' ?>
      </h2>
      <div style="color:rgba(255,255,255,.85);margin-bottom:2rem;"
           data-cms-field="donation_text"
           data-cms-section="shop"
           data-cms-type="richtext"
           data-cms-bg="<?= h($pages['shop']['donation_text_bg'] ?? '') ?>"
           data-cms-en="<?= h($pages['shop']['donation_text_en'] ?? '') ?>">
        <?= $donation_text ?>
      </div>
    </div>

    <form method="POST" action="/donation/checkout.php"
          style="max-width:520px;margin:0 auto;background:#fff;padding:2rem;border-radius:var(--radius-lg);">
      <?= csrf_field() ?>
      <input type="hidden" name="_lang" value="<?= h($lang) ?>">

      <div class="form-group">
        <label style="font-weight:600;font-size:.875rem;">
          <?= $lang === 'bg' ? 'Сума' : 'Amount' ?> *
        </label>
        <div style="position:relative;">
          <input type="number" name="amount" min="1" step="1" required
                 placeholder="<?= $lang === 'bg' ? 'напр. 20' : 'e.g. 20' ?>"
                 style="width:100%;box-sizing:border-box;padding-right:2.5rem;">
          <span style="position:absolute;right:.85rem;top:50%;transform:translateY(-50%);font-weight:600;color:var(--text-muted);pointer-events:none;">€</span>
        </div>
      </div>
      <div class="form-group">
        <label style="font-weight:600;font-size:.875rem;">
          <?= $lang === 'bg' ? 'Вашите имена' : 'Your name' ?> *
        </label>
        <input type="text" name="donor_name" required style="width:100%;box-sizing:border-box;">
      </div>
      <div class="form-group">
        <label style="font-weight:600;font-size:.875rem;">
          <?= $lang === 'bg' ? 'Имейл' : 'Email' ?> *
        </label>
        <input type="email" name="donor_email" required style="width:100%;box-sizing:border-box;">
      </div>
      <div class="form-group">
        <label style="font-weight:600;font-size:.875rem;">
          <?= $lang === 'bg' ? 'Съобщение или посвещение (по желание)' : 'Message or dedication (optional)' ?>
        </label>
        <textarea name="donation_message" rows="3"
                  style="width:100%;box-sizing:border-box;padding:.6rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-body);font-size:.95rem;resize:vertical;"></textarea>
      </div>


      <!-- Donor type -->
      <div class="form-group">
        <label style="font-weight:600;font-size:.875rem;display:block;margin-bottom:.5rem;">
          <?= $lang === 'bg' ? 'Вид дарител' : 'Donor type' ?>
        </label>
        <div style="display:flex;gap:1.25rem;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;">
            <input type="radio" name="donor_type" value="individual" checked
                   onchange="toggleDonorCompany(false)" style="accent-color:var(--teal);">
            <?= $lang === 'bg' ? 'Физическо лице' : 'Individual' ?>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;">
            <input type="radio" name="donor_type" value="company"
                   onchange="toggleDonorCompany(true)" style="accent-color:var(--teal);">
            <?= $lang === 'bg' ? 'Юридическо лице (фирма)' : 'Legal entity (company)' ?>
          </label>
        </div>
      </div>

      <!-- Company fields (shown only for legal entities) -->
      <div id="donorCompanyFields" style="display:none;">
        <div class="form-group">
          <label style="font-weight:600;font-size:.875rem;">
            <?= $lang === 'bg' ? 'Наименование на фирмата' : 'Company name' ?> *
          </label>
          <input type="text" name="invoice_company"
                 style="width:100%;box-sizing:border-box;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;" class="company-fields-grid">
          <div class="form-group" style="margin-bottom:0;">
            <label style="font-weight:600;font-size:.875rem;">
              <?= $lang === 'bg' ? 'ЕИК / Булстат' : 'Company ID (EIK)' ?> *
            </label>
            <input type="text" name="invoice_eik"
                   style="width:100%;box-sizing:border-box;">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label style="font-weight:600;font-size:.875rem;">
              <?= $lang === 'bg' ? 'ДДС номер' : 'VAT number' ?>
              <span style="font-weight:400;opacity:.7;">(<?= $lang === 'bg' ? 'ако е приложимо' : 'if applicable' ?>)</span>
            </label>
            <input type="text" name="invoice_vat"
                   style="width:100%;box-sizing:border-box;">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;margin-top:1rem;">
        <?= $lang === 'bg' ? 'Дари сега' : 'Donate now' ?>
      </button>

      <script>
      function toggleDonorCompany(show) {
          document.getElementById('donorCompanyFields').style.display = show ? '' : 'none';
      }
      </script>
    </form>
  </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
