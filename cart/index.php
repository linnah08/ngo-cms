<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

$lang = get_lang();
$pdo  = get_pdo();

$shop_url = $lang === 'en' ? '/en/shop/' : '/magazin/';

// Redirect to shop if cart is empty
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: ' . $shop_url);
    exit;
}

$page_title = $lang === 'bg' ? 'Количка' : 'Cart';
$page_head_extra = '<style>@media(max-width:640px){table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch;}}</style>';
if (defined('GOOGLE_ADS_ID') && GOOGLE_ADS_ID !== '' && defined('GOOGLE_ADS_PURCHASE_LABEL') && GOOGLE_ADS_PURCHASE_LABEL !== '') {
    $page_head_extra .= '<script>gtag(\'event\',\'conversion\',{\'send_to\':\'' . GOOGLE_ADS_ID . '/' . GOOGLE_ADS_PURCHASE_LABEL . '\'});</script>';
}
$flash      = flash_get();
$cart_data = [];
$subtotal  = 0.0;

if (!empty($cart)) {
    $ids  = array_column($cart, 'product_id');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($in) AND active=1");
    $stmt->execute($ids);
    $products_by_id = [];
    foreach ($stmt->fetchAll() as $p) {
        $products_by_id[$p['id']] = $p;
    }

    foreach ($cart as $item) {
        $pid = $item['product_id'];
        if (!isset($products_by_id[$pid])) continue;
        $p        = $products_by_id[$pid];
        $qty      = $p['type'] === 'variant'
            ? (int)$item['quantity']
            : min((int)$item['quantity'], (int)$p['stock']);
        $line     = $qty * (float)$p['price_eur'];
        $subtotal += $line;
        $cart_data[] = [
            'product'         => $p,
            'quantity'        => $qty,
            'line_eur'        => $line,
            'colour'          => $item['colour']          ?? null,
            'size'            => $item['size']            ?? null,
            'design_file'     => $item['design_file']     ?? null,
            'design_position' => $item['design_position'] ?? null,
            'variant_id'      => $item['variant_id']      ?? null,
            'variant_label'   => $item['variant_label']   ?? null,
            'variant_image'   => null,
            'variant_stock'   => null,
        ];
    }
}

$variant_image_ids = array_filter(array_column($cart_data, 'variant_id'));
if ($variant_image_ids) {
    $in3 = implode(',', array_fill(0, count($variant_image_ids), '?'));
    $vi2 = $pdo->prepare("SELECT id, image, stock FROM product_variants WHERE id IN ($in3)");
    $vi2->execute(array_values($variant_image_ids));
    $vi2_map = [];
    foreach ($vi2->fetchAll() as $vir) $vi2_map[$vir['id']] = $vir;
    foreach ($cart_data as &$cd) {
        if ($cd['variant_id'] && isset($vi2_map[$cd['variant_id']])) {
            $cd['variant_image'] = $vi2_map[$cd['variant_id']]['image'];
            $cd['variant_stock'] = (int)$vi2_map[$cd['variant_id']]['stock'];
        }
    }
    unset($cd);
}

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
?>

<section class="section section--grey" style="padding-bottom:1.5rem;">
  <div class="container">
    <h1><?= $lang === 'bg' ? 'Количка' : 'Cart' ?></h1>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:800px;">

    <?php foreach ($flash as $f): ?>
    <div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;
      <?= $f['type'] === 'success' ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;' : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
      <?= h($f['message']) ?>
    </div>
    <?php endforeach; ?>


    <!-- Hidden form used by JS remove buttons (must be outside the update form) -->
    <form id="removeForm" method="POST" action="/cart/remove.php">
      <?= csrf_field() ?>
      <input type="hidden" id="removeIndex" name="cart_index" value="">
    </form>

    <form method="POST" action="/cart/update.php">
      <?= csrf_field() ?>
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--off-white);">
              <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);"><?= $lang === 'bg' ? 'Продукт' : 'Product' ?></th>
              <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);"><?= $lang === 'bg' ? 'Бр.' : 'Qty' ?></th>
              <th style="padding:.65rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);"><?= $lang === 'bg' ? 'Сума' : 'Total' ?></th>
              <th style="padding:.65rem 1rem;width:40px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cart_data as $ri => $row): $p = $row['product']; ?>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.75rem 1rem;vertical-align:middle;">
                <div style="display:flex;align-items:center;gap:1rem;">
                  <?php
                    $variants_arr = json_decode($p['variants'] ?? '{}', true) ?? [];
                    $is_print     = $p['type'] === 'print';
                    $has_design   = $is_print && !empty($row['design_file']);
                    $print_area   = $variants_arr['print_area'] ?? null;
                    $preview_data = null;
                    if ($is_print && $p['image']) {
                        $preview_data = json_encode([
                            'shirt'    => '/assets/images/products/' . $p['image'],
                            'colour'   => $row['colour'] ?? '#ffffff',
                            'design'   => $has_design ? '/cart/design-thumb.php?file=' . urlencode(basename($row['design_file'])) : null,
                            'pos'      => $row['design_position'],
                            'pa'       => $print_area,
                        ]);
                    }
                  ?>
                  <?php
                    $prod_url = ($lang === 'bg' ? '/magazin/' : '/en/shop/') . h($p['slug']) . '/';
                  ?>
                  <?php if ($is_print && $has_design): ?>
                    <img src="/cart/design-thumb.php?file=<?= urlencode(basename($row['design_file'])) ?>" alt="design"
                         style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;cursor:pointer;"
                         onclick='openPreview(<?= htmlspecialchars($preview_data, ENT_QUOTES) ?>)' title="<?= $lang === 'bg' ? 'Преглед' : 'Preview' ?>">
                  <?php elseif ($p['type'] === 'variant' && !empty($row['variant_image'])): ?>
                    <a href="<?= $prod_url ?>">
                      <img src="/assets/images/products/<?= h($row['variant_image']) ?>" alt=""
                           style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                    </a>
                  <?php elseif ($p['image']): ?>
                    <?php if ($is_print): ?>
                      <img src="/assets/images/products/<?= h($p['image']) ?>" alt=""
                           style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;cursor:pointer;"
                           onclick='openPreview(<?= htmlspecialchars($preview_data, ENT_QUOTES) ?>)' title="<?= $lang === 'bg' ? 'Преглед' : 'Preview' ?>">
                    <?php else: ?>
                      <a href="<?= $prod_url ?>">
                        <img src="/assets/images/products/<?= h($p['image']) ?>" alt=""
                             style="width:48px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                      </a>
                    <?php endif; ?>
                  <?php endif; ?>
                  <div>
                    <a href="<?= $prod_url ?>" style="color:inherit;text-decoration:none;">
                      <strong><?= h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg'])) ?></strong>
                    </a>
                    <div style="font-size:.8rem;color:var(--text-muted);"><?= price_html((float)$p['price_eur']) ?></div>
                    <?php if (!empty($row['colour']) || !empty($row['size'])): ?>
                      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem;">
                        <?php
                          $parts = [];
                          if (!empty($row['colour'])) {
                              $variants = json_decode($p['variants'] ?? '{}', true) ?? [];
                              $colours  = array_column($variants['colours'] ?? [], null, 'name');
                              $clabel   = $colours[$row['colour']][$lang === 'bg' ? 'label_bg' : 'label_en'] ?? ucfirst($row['colour']);
                              $parts[]  = ($lang === 'bg' ? 'Цвят' : 'Colour') . ': ' . $clabel;
                          }
                          if (!empty($row['size'])) {
                              $parts[] = ($lang === 'bg' ? 'Размер' : 'Size') . ': ' . $row['size'];
                          }
                          echo h(implode(' · ', $parts));
                        ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($row['variant_label'])): ?>
                      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem;">
                        <?= h($row['variant_label']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <?php $stock_max = $p['type'] === 'variant' ? (int)($row['variant_stock'] ?? 999) : (int)$p['stock']; ?>
              <td style="padding:.75rem 1rem;text-align:center;vertical-align:middle;">
                <input type="number" name="quantity[<?= $ri ?>]"
                       value="<?= $row['quantity'] ?>"
                       min="1"
                       data-max="<?= $stock_max ?>"
                       style="width:60px;text-align:center;padding:.35rem .5rem;border:1px solid var(--border);border-radius:4px;font-size:.9rem;"
                       oninput="cartQtyInput(this)">
                <div class="cart-stock-msg" style="display:none;font-size:.75rem;color:#c0392b;font-weight:600;margin-top:.3rem;white-space:nowrap;">
                  <?= $lang === 'bg' ? "Налични: {$stock_max} бр." : "Only {$stock_max} available" ?>
                </div>
              </td>
              <td style="padding:.75rem 1rem;text-align:right;vertical-align:middle;font-weight:600;">
                <?= price_html($row['line_eur']) ?>
              </td>
              <td style="padding:.75rem 1rem;text-align:center;vertical-align:middle;">
                <button type="button" onclick="removeItem(<?= $ri ?>)"
                        style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:1.1rem;padding:0;"
                        title="<?= $lang === 'bg' ? 'Премахни' : 'Remove' ?>">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;">
        <div>
          <a href="<?= $shop_url ?>" style="font-size:.9rem;color:var(--text-muted);text-decoration:none;align-self:center;">
            ← <?= $lang === 'bg' ? 'Продължи пазаруването' : 'Continue shopping' ?>
          </a>
        </div>
        <div style="text-align:right;">
          <div style="font-size:.9rem;color:var(--text-muted);margin-bottom:.25rem;">
            <?= $lang === 'bg' ? 'Междинна сума:' : 'Subtotal:' ?>
          </div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--teal);">
            <?= price_html($subtotal) ?>
          </div>
        </div>
      </div>
    </form>

    <script>
    function removeItem(cartIndex) {
        document.getElementById('removeIndex').value = cartIndex;
        document.getElementById('removeForm').submit();
    }
    function cartQtyInput(el) {
        var max = parseInt(el.dataset.max, 10);
        var val = parseInt(el.value, 10);
        var msg = el.parentNode.querySelector('.cart-stock-msg');
        if (val > max) {
            el.style.borderColor = '#c0392b';
            if (msg) msg.style.display = 'block';
        } else {
            el.style.borderColor = '';
            if (msg) msg.style.display = 'none';
            el.form.submit();
        }
    }
    </script>

    <div style="text-align:right;">
      <a href="/checkout/" class="btn btn--primary" style="padding:.9rem 2rem;font-size:1rem;">
        <?= $lang === 'bg' ? 'Продължи към поръчка →' : 'Proceed to checkout →' ?>
      </a>
    </div>

  </div>
</section>

<!-- Print preview modal -->
<div id="previewOverlay" onclick="closePreview()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;align-items:center;justify-content:center;">
  <div onclick="event.stopPropagation()" style="background:#fff;border-radius:12px;padding:1.25rem;max-width:480px;width:calc(100vw - 2rem);position:relative;">
    <button onclick="closePreview()" style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.4rem;cursor:pointer;line-height:1;color:var(--text-muted);">✕</button>
    <h3 style="margin:0 0 1rem;font-size:1rem;"><?= $lang === 'bg' ? 'Преглед на продукта' : 'Product preview' ?></h3>
    <canvas id="previewCanvas" style="width:100%;border-radius:8px;background:var(--off-white);display:block;"></canvas>
    <p id="previewHint" style="margin:.75rem 0 0;font-size:.78rem;color:var(--text-muted);text-align:center;display:none;"><?= $lang === 'bg' ? 'Наслагването показва позицията на печата — точните цветове може да варират.' : 'Overlay shows print position — exact colours may vary.' ?></p>
  </div>
</div>

<script>
function openPreview(data) {
  var overlay = document.getElementById('previewOverlay');
  overlay.style.display = 'flex';

  var canvas = document.getElementById('previewCanvas');
  var ctx    = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  // Load and tint shirt
  var shirt = new Image();
  shirt.onload = function() {
    canvas.width  = shirt.naturalWidth;
    canvas.height = shirt.naturalHeight;
    ctx.drawImage(shirt, 0, 0);

    // Tint
    var hex = (data.colour || '#ffffff').replace('#','');
    var nr  = parseInt(hex.slice(0,2),16)/255;
    var ng  = parseInt(hex.slice(2,4),16)/255;
    var nb  = parseInt(hex.slice(4,6),16)/255;
    var imgd = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var d = imgd.data;
    for (var i = 0; i < d.length; i += 4) {
      d[i]   = Math.round(d[i]   * nr);
      d[i+1] = Math.round(d[i+1] * ng);
      d[i+2] = Math.round(d[i+2] * nb);
    }
    ctx.putImageData(imgd, 0, 0);

    // Draw print area guide
    if (data.pa) {
      ctx.strokeStyle = 'rgba(3,135,165,.5)';
      ctx.lineWidth   = Math.max(2, canvas.width / 200);
      ctx.setLineDash([8, 5]);
      ctx.strokeRect(data.pa.x * canvas.width, data.pa.y * canvas.height, data.pa.w * canvas.width, data.pa.h * canvas.height);
      ctx.setLineDash([]);
    }

    // Overlay design if present
    if (data.design && data.pos) {
      var hint = document.getElementById('previewHint');
      hint.style.display = 'block';
      var design = new Image();
      design.onload = function() {
        var pos = data.pos;
        var pw  = pos.scale * canvas.width;
        var ph  = pw * (design.naturalHeight / design.naturalWidth);
        var cx  = pos.x * canvas.width;
        var cy  = pos.y * canvas.height;
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(pos.rotation || 0);
        ctx.drawImage(design, -pw/2, -ph/2, pw, ph);
        ctx.restore();
      };
      design.src = data.design;
    } else {
      document.getElementById('previewHint').style.display = 'none';
    }
  };
  shirt.src = data.shirt;
}

function closePreview() {
  document.getElementById('previewOverlay').style.display = 'none';
  document.getElementById('previewHint').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closePreview();
});
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
