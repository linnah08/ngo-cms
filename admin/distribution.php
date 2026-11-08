<?php
/**
 * Разпространение — stock of a shop product handed out beyond the online shop:
 * produced batches, distributors, hand-overs (with a protocol PDF), distributor
 * sales and payments. Logic lives in includes/distribution.php.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/distribution.php';

$page_title_admin = 'Разпространение';
$active_nav       = 'distribution';

admin_require_shop();
module_admin_guard('distribution');   // also off while the shop is off (needs)

$pdo = get_pdo();

const DIST_TABS = [
    'batches'      => 'Партиди',
    'allocations'  => 'Разпределения',
    'sales'        => 'Продажби',
    'distributors' => 'Дистрибутори',
];

/** Back to this page for the same product, on a tab, keeping list filters. */
function dist_redirect(int $product_id, string $tab, array $keep = []): never
{
    $q = array_filter(['product' => $product_id ?: null] + $keep, fn($v) => $v !== null && $v !== '');
    header('Location: /admin/distribution.php' . ($q ? '?' . http_build_query($q) : '') . '#' . $tab);
    exit;
}

/** Optional trimmed text field from POST, null when empty. */
function dist_post_text(string $key, int $max = 1000): ?string
{
    $v = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    return $v === '' ? null : $v;
}

/** variant_id from POST, null when not given. */
function dist_post_variant(): ?int
{
    $v = (int) ($_POST['variant_id'] ?? 0);
    return $v > 0 ? $v : null;
}

/** Ids from a bulk form. */
function dist_post_ids(): array
{
    return array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($v) => $v > 0));
}

/** A DB date as dd.mm.yyyy. */
function dist_date_bg(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

/** A price typed with a comma or a dot. */
function dist_post_price(string $key): float
{
    return (float) str_replace([',', ' '], ['.', ''], (string) ($_POST[$key] ?? '0'));
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Невалидна заявка. Презаредете страницата и опитайте отново.'); }

    $action = (string) ($_POST['action'] ?? '');
    $pid    = (int) ($_POST['product_id'] ?? 0);
    $keep   = array_intersect_key($_POST, array_flip(['af', 'av', 'sd', 'sp', 'sv', 'archived']));
    $keep   = array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 20) : '', $keep);
    $ok     = function (string $msg) { flash_set('success', $msg); };
    $fail   = function (string $msg) { flash_set('error', $msg); };

    switch ($action) {
        case 'set_online_stock':
            $stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
            $vid   = dist_post_variant();
            if ($stock === false) {
                $fail('Моля въведете цяло число.');
            } elseif ($err = distribution_item_error($pdo, $pid, $vid)) {
                $fail($err);
            } else {
                distribution_set_online_stock($pdo, $pid, $vid, (int) $stock);
                $ok('Наличността в онлайн магазина е поправена на ' . (int) $stock . ' бр.');
            }
            dist_redirect($pid, 'batches', $keep);

        case 'add_batch':
            $r = distribution_create_batch($pdo, $pid, dist_post_variant(), (int) ($_POST['quantity'] ?? 0),
                trim((string) ($_POST['produced_on'] ?? '')), dist_post_text('notes'));
            $r['ok'] ? $ok('Партидата е добавена.') : $fail($r['error']);
            dist_redirect($pid, 'batches', $keep);

        case 'update_batch':
            $r = distribution_update_batch($pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['quantity'] ?? 0),
                trim((string) ($_POST['produced_on'] ?? '')), dist_post_text('notes'));
            $r['ok'] ? $ok('Партидата е запазена.') : $fail($r['error']);
            dist_redirect($pid, 'batches', $keep);

        case 'add_distributor':
        case 'update_distributor':
            $name    = trim((string) ($_POST['name'] ?? ''));
            $company = trim((string) ($_POST['company_number'] ?? ''));
            $email   = dist_post_text('email', 150);
            if ($err = distribution_distributor_error($name, $company, $email)) {
                $fail($err);
            } else {
                $args = [mb_substr($name, 0, 150), $company, dist_post_text('contact_name', 150),
                         dist_post_text('address', 255), $email, dist_post_text('notes')];
                if ($action === 'add_distributor') {
                    distribution_create_distributor($pdo, ...$args);
                    $ok('Дистрибуторът е добавен.');
                } else {
                    distribution_update_distributor($pdo, (int) ($_POST['id'] ?? 0), ...$args);
                    $ok('Дистрибуторът е запазен.');
                }
            }
            dist_redirect($pid, 'distributors', $keep);

        case 'distributors_active':
            $ids    = dist_post_ids();
            $active = ($_POST['active'] ?? '') === '1';
            $n      = distribution_set_distributors_active($pdo, $ids, $active);
            if (!$ids) {
                $fail('Не сте избрали дистрибутори.');
            } else {
                $ok($active ? "Върнати от архива: {$n}." : "Архивирани: {$n}. Записите им остават, само не се предлагат в падащите менюта.");
            }
            dist_redirect($pid, 'distributors', $keep);

        case 'allocate':
            $recipient   = (string) ($_POST['recipient'] ?? '');
            $destination = in_array($recipient, ['online', 'personal', 'sample'], true) ? $recipient : 'distributor';
            $dist_id     = $destination === 'distributor' ? (int) $recipient : null;
            $r = distribution_create_allocation(
                $pdo, $pid, dist_post_variant(), $destination, $dist_id ?: null,
                (int) ($_POST['quantity'] ?? 0), dist_post_price('unit_price_eur'),
                trim((string) ($_POST['allocated_on'] ?? '')), dist_post_text('notes'),
                !empty($_POST['use_shop_stock']), dist_post_text('sample_recipient', 150)
            );
            if (!$r['ok']) {
                $fail($r['error']);
            } elseif ($destination === 'distributor') {
                $p = distribution_issue_protocol($pdo, $r['id'], $_SERVER['DOCUMENT_ROOT']);
                $p['ok']
                    ? $ok('Записано. Приемо-предавателен протокол № ' . $p['number'] . ' е готов — отворете го от таблицата, за да го разпечатате.')
                    : $fail('Даването е записано, но: ' . $p['error']);
            } else {
                $ok('Записано.');
            }
            dist_redirect($pid, 'allocations', $keep);

        case 'give_out_many':
            // Free samples / write-off for several products at once (the
            // "всички продукти" form). Boxes are named qty[<product>_<variant or 0>].
            $kind  = in_array($_POST['kind'] ?? '', DISTRIBUTION_FREE_DESTINATIONS, true) ? (string) $_POST['kind'] : '';
            $lines = [];
            foreach ((array) ($_POST['qty'] ?? []) as $key => $q) {
                if (!preg_match('/^(\d+)_(\d+)$/', (string) $key, $m)) continue;
                $q = trim((string) $q);
                if ($q === '') continue;
                $qi = filter_var($q, FILTER_VALIDATE_INT);
                if ($qi === false) {
                    $fail('Броят трябва да е цяло число.');
                    dist_redirect($pid, 'stock-all', $keep);
                }
                $lines[] = ['product_id' => (int) $m[1], 'variant_id' => (int) $m[2] ?: null, 'quantity' => (int) $qi];
            }
            $recipient = $kind === 'sample' ? dist_post_text('sample_recipient', 150) : null;
            $r = distribution_give_out_many(
                $pdo, $kind, $lines, trim((string) ($_POST['allocated_on'] ?? '')),
                $recipient, dist_post_text('notes'), !empty($_POST['use_shop_stock'])
            );
            $of = fn(array $r): string => $r['count'] . ($r['count'] === 1 ? ' продукт' : ' продукта');
            if (!$r['ok']) {
                $fail($r['error']);
            } elseif ($kind === 'sample') {
                $ok('Записани безплатни мостри: ' . $r['quantity'] . ' бр. (' . $of($r) . ') за „' . $recipient . '“.');
            } else {
                $ok('Отписани: ' . $r['quantity'] . ' бр. (' . $of($r) . ').');
            }
            dist_redirect($pid, 'stock-all', $keep);

        case 'update_allocation':
            $id = (int) ($_POST['id'] ?? 0);
            $r  = distribution_update_allocation($pdo, $id, (int) ($_POST['quantity'] ?? 0), dist_post_price('unit_price_eur'),
                trim((string) ($_POST['allocated_on'] ?? '')), dist_post_text('notes'));
            if (!$r['ok']) {
                $fail($r['error']);
            } else {
                $dest = $pdo->prepare('SELECT destination FROM distribution_allocations WHERE id = ?');
                $dest->execute([$id]);
                if ($dest->fetchColumn() === 'distributor') {
                    // The protocol must always match the record — re-create it under the same number.
                    $p = distribution_issue_protocol($pdo, $id, $_SERVER['DOCUMENT_ROOT']);
                    $p['ok'] ? $ok('Записът и протокол № ' . $p['number'] . ' са обновени.') : $fail('Записът е обновен, но: ' . $p['error']);
                } else {
                    $ok('Записът е обновен.');
                }
            }
            dist_redirect($pid, 'allocations', $keep);

        case 'regenerate_protocol':
            $p = distribution_issue_protocol($pdo, (int) ($_POST['id'] ?? 0), $_SERVER['DOCUMENT_ROOT']);
            $p['ok'] ? $ok('Протокол № ' . $p['number'] . ' е създаден отново.') : $fail($p['error']);
            dist_redirect($pid, 'allocations', $keep);

        case 'record_sale':
            $paid = !empty($_POST['payment_received']);
            $r = distribution_record_sale(
                $pdo, (int) ($_POST['distributor_id'] ?? 0), $pid, dist_post_variant(), (int) ($_POST['quantity'] ?? 0),
                trim((string) ($_POST['sale_date'] ?? '')), $paid,
                $paid ? trim((string) ($_POST['payment_received_on'] ?? '')) : null, dist_post_text('notes')
            );
            $r['ok'] ? $ok('Продажбата е записана.') : $fail($r['error']);
            dist_redirect($pid, 'sales', $keep);

        case 'update_sale':
            $paid = !empty($_POST['payment_received']);
            $r = distribution_update_sale(
                $pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['quantity'] ?? 0), trim((string) ($_POST['sale_date'] ?? '')),
                $paid, $paid ? trim((string) ($_POST['payment_received_on'] ?? '')) : null, dist_post_text('notes')
            );
            $r['ok'] ? $ok('Продажбата е запазена.') : $fail($r['error']);
            dist_redirect($pid, 'sales', $keep);

        case 'sales_paid':
            $ids  = dist_post_ids();
            $paid = ($_POST['paid'] ?? '') === '1';
            if (!$ids) {
                $fail('Не сте избрали продажби.');
            } else {
                $n = distribution_set_sales_paid($pdo, $ids, $paid, date('Y-m-d'));
                $ok($paid ? "Отбелязани като платени: {$n}." : "Отбелязани като неплатени: {$n}.");
            }
            dist_redirect($pid, 'sales', $keep);
    }

    $fail('Непознато действие.');
    dist_redirect($pid, 'batches');
}

// ── Page data ─────────────────────────────────────────────────────────────────
$products   = distribution_products($pdo);
$product_id = (int) ($_GET['product'] ?? 0);
$product    = $product_id ? distribution_product($pdo, $product_id) : null;
if (!$product && $products) {
    $product    = distribution_product($pdo, (int) $products[0]['id']);
    $product_id = (int) $product['id'];
}

$items        = $product ? distribution_product_items($pdo, $product) : [];
$has_variants = $product && $product['type'] === 'variant';
$summary      = $product ? distribution_product_summary($pdo, $product) : ['items' => [], 'total' => []];
$overview     = distribution_overview($pdo);
$today        = date('Y-m-d');

// Filters (GET), all narrowed on the server.
$f_alloc   = mb_substr((string) ($_GET['af'] ?? ''), 0, 20);
$f_alloc_v = (int) ($_GET['av'] ?? 0);
$f_sale_d  = (int) ($_GET['sd'] ?? 0);
$f_sale_p  = in_array($_GET['sp'] ?? '', ['paid', 'unpaid'], true) ? $_GET['sp'] : '';
$f_sale_v  = (int) ($_GET['sv'] ?? 0);
$f_arch    = ($_GET['archived'] ?? '') === '1';
$keep_get  = array_filter(['af' => $f_alloc, 'av' => $f_alloc_v ?: '', 'sd' => $f_sale_d ?: '', 'sp' => $f_sale_p,
                           'sv' => $f_sale_v ?: '', 'archived' => $f_arch ? '1' : ''], fn($v) => $v !== '');

$all_distributors    = $pdo->query('SELECT * FROM distribution_distributors ORDER BY active DESC, name')->fetchAll();
$active_distributors = array_values(array_filter($all_distributors, fn($d) => (int) $d['active'] === 1));

$batches = $allocations = $sales = $distributor_rows = [];
if ($product) {
    $stmt = $pdo->prepare(
        'SELECT b.*, pv.label_bg AS variant_label FROM distribution_batches b
           LEFT JOIN product_variants pv ON pv.id = b.variant_id
          WHERE b.product_id = ? ORDER BY b.produced_on DESC, b.id DESC'
    );
    $stmt->execute([$product_id]);
    $batches = $stmt->fetchAll();

    $where  = ['a.product_id = ?'];
    $params = [$product_id];
    if (in_array($f_alloc, ['online', 'personal', 'sample', 'writeoff', 'distributor'], true)) {
        $where[] = 'a.destination = ?'; $params[] = $f_alloc;
    } elseif (preg_match('/^d(\d+)$/', $f_alloc, $m)) {
        $where[] = 'a.distributor_id = ?'; $params[] = (int) $m[1];
    }
    if ($f_alloc_v) { $where[] = 'a.variant_id = ?'; $params[] = $f_alloc_v; }
    $stmt = $pdo->prepare(
        'SELECT a.*, d.name AS distributor_name, pv.label_bg AS variant_label FROM distribution_allocations a
           LEFT JOIN distribution_distributors d ON d.id = a.distributor_id
           LEFT JOIN product_variants pv ON pv.id = a.variant_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY a.allocated_on DESC, a.id DESC'
    );
    $stmt->execute($params);
    $allocations = $stmt->fetchAll();

    $where  = ['s.product_id = ?'];
    $params = [$product_id];
    if ($f_sale_d) { $where[] = 's.distributor_id = ?'; $params[] = $f_sale_d; }
    if ($f_sale_p) { $where[] = 's.payment_received = ?'; $params[] = $f_sale_p === 'paid' ? 1 : 0; }
    if ($f_sale_v) { $where[] = 's.variant_id = ?'; $params[] = $f_sale_v; }
    $stmt = $pdo->prepare(
        'SELECT s.*, d.name AS distributor_name, pv.label_bg AS variant_label FROM distribution_sales s
           JOIN distribution_distributors d ON d.id = s.distributor_id
           LEFT JOIN product_variants pv ON pv.id = s.variant_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY s.sale_date DESC, s.id DESC'
    );
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    foreach ($all_distributors as $d) {
        if (!$f_arch && !(int) $d['active']) continue;
        $distributor_rows[] = $d
            + distribution_distributor_stock($pdo, (int) $d['id'], $product_id, null, true)
            + distribution_distributor_payment_summary($pdo, (int) $d['id'], $product_id);
    }
}
$archived_count = count($all_distributors) - count($active_distributors);

// Per-item numbers the allocation form's helpers read.
$item_numbers = [];
foreach ($summary['items'] as $it) {
    $item_numbers[(string) ($it['variant_id'] ?? '')] = ['available' => max(0, $it['available']), 'shop' => $it['online_stock']];
}

$dest_labels = [
    'online'   => 'Онлайн магазин',
    'personal' => 'Продадени лично',
    'sample'   => 'Безплатни мостри',
    'writeoff' => 'Отписани (повредени, изгубени)',
];

$page_head_extra = '<style>
.dist-page input[type=number],.dist-page input[type=date],.dist-page input[type=email],.dist-page select{padding:.5rem .75rem;border:1px solid #6b7280;border-radius:6px;font-size:.9rem;font-family:inherit;background:#fff;min-height:44px;box-sizing:border-box;}
.dist-page .admin-form-grid input[type=text]{border-color:#6b7280;min-height:44px;box-sizing:border-box;}
.dist-page input:focus-visible,.dist-page select:focus-visible,.dist-page button:focus-visible,.dist-page a:focus-visible{outline:3px solid #1b998b;outline-offset:2px;}
.dist-page .dist-hint{font-size:.8rem;font-weight:400;color:#4b5563;}
/* Single-row forms: the site-wide label margin pushed the fields above the button, and the variant picker came unstyled. */
.dist-page .dist-inline label{display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;color:var(--text);margin:0;}
.dist-page .dist-inline .btn{min-height:44px;}
</style>';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

/** One labelled variant <select> for a form (nothing for products without variants). */
$variant_select = function (string $id, ?int $selected = null) use ($has_variants, $items): string {
    if (!$has_variants) return '';
    $html = '<label for="' . h($id) . '">Вариант<select name="variant_id" id="' . h($id) . '" required>'
          . '<option value="">— Изберете —</option>';
    foreach ($items as $it) {
        if (!$it['active']) continue;
        $sel   = $selected === $it['variant_id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $it['variant_id'] . '"' . $sel . '>' . h($it['label']) . '</option>';
    }
    return $html . '</select></label>';
};

/** Hidden fields every form on the page carries. */
$common = function () use ($product_id, $keep_get): string {
    $html = csrf_field() . '<input type="hidden" name="product_id" value="' . (int) $product_id . '">';
    foreach ($keep_get as $k => $v) $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    return $html;
};

$box = 'background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.5rem;';
?>
<div class="dist-page">

<div class="admin-page-header" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;">
  <h1 style="margin:0;">Разпространение</h1>
  <a href="/admin/products.php" class="btn btn--outline">← Продукти</a>
</div>

<div role="status" aria-live="polite">
<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" style="margin-bottom:1.5rem;">
    <strong><?= $_flash['type'] === 'error' ? '⚠ Не е записано: ' : '✓ ' ?></strong><?= h($_flash['message']) ?>
  </div>
<?php endforeach; ?>
</div>

<?php if (!$products): ?>
  <div style="<?= $box ?>">
    <p style="margin:0;">Още няма продукти. Първо <a href="/admin/product-edit.php">добавете продукт</a> — после тук ще може да следите колко бройки сте произвели и на кого сте ги дали.</p>
  </div>
<?php else: ?>

<p class="admin-meta" style="max-width:70ch;">
  Тук следите стока, която раздавате и извън онлайн магазина: колко бройки сте произвели (партиди), на кого сте ги дали
  (дистрибутори, магазина, лично продадени, подаръци) и какво са продали и платили дистрибуторите.
</p>

<!-- ── All products at a glance + samples / write-off for several at once ─────── -->
<?php if ($overview): ?>
<?php
  $ov_n        = fn(int $v): string => number_format($v, 0, ',', ' ');
  $ov_writeoff = array_sum(array_map(fn($o) => (int) ($o['total']['to_writeoff'] ?? 0), $overview)) > 0;
  $ov_cell     = 'text-align:right;font-variant-numeric:tabular-nums;';
  $ov_rowhead  = 'text-align:left;font-weight:600;text-transform:none;letter-spacing:normal;font-size:.95rem;color:var(--text);background:transparent;';
  /** One row of numbers; $s is an item or a product total. */
  $ov_numbers  = function (array $s, bool $bold) use ($ov_n, $ov_writeoff, $ov_cell): string {
      $w    = $bold ? 'font-weight:700;' : '';
      $html = '<td style="' . $ov_cell . $w . '">' . $ov_n((int) $s['produced']) . '</td>'
            . '<td style="' . $ov_cell . $w . '">' . $ov_n((int) $s['sold']) . '</td>'
            . '<td style="' . $ov_cell . $w . '">' . $ov_n((int) $s['to_samples']) . '</td>';
      if ($ov_writeoff) $html .= '<td style="' . $ov_cell . $w . '">' . $ov_n((int) ($s['to_writeoff'] ?? 0)) . '</td>';
      $html .= '<td style="' . $ov_cell . '"><strong>' . $ov_n((int) $s['remaining']) . '</strong>';
      if ((int) $s['with_distributors'] > 0) {
          $html .= '<br><span class="dist-hint">+ ' . $ov_n((int) $s['with_distributors']) . ' при дистрибутори</span>';
      }
      return $html . '</td>';
  };
  // Rows of the samples / write-off form: every active item of these products.
  $ov_form_rows = [];
  foreach ($overview as $o) {
      $multi = count($o['items']) > 1 || $o['product']['type'] === 'variant';
      foreach ($o['items'] as $it) {
          // Hidden variants, and items with nothing left anywhere, can't be given.
          if (!$it['active'] || ((int) $it['available'] <= 0 && (int) $it['online_stock'] <= 0)) continue;
          $ov_form_rows[] = [
              'key'       => (int) $o['product']['id'] . '_' . (int) ($it['variant_id'] ?? 0),
              'label'     => $multi ? $o['product']['name_bg'] . ' — ' . $it['label'] : $o['product']['name_bg'],
              'available' => max(0, (int) $it['available']),
              'shop'      => max(0, (int) $it['online_stock']),
          ];
      }
  }
?>
<section id="stock-all" style="<?= $box ?>padding:1.5rem;" aria-labelledby="distAllTitle">
  <h2 id="distAllTitle" style="font-size:1.15rem;margin:0 0 .35rem;">Наличност — всички продукти</h2>
  <p class="dist-hint" style="margin:0 0 1rem;max-width:75ch;line-height:1.55;">
    „Продадени“ е всичко продадено — онлайн, лично и от дистрибуторите. „Налични за продажба“ е това, което още е при вас:
    в склада и в онлайн магазина. Бройките, дадени на дистрибутори и още непродадени, са отделно.
  </p>
  <div class="admin-table-wrap" style="max-height:none;">
    <table class="admin-table" style="font-variant-numeric:tabular-nums;">
      <caption style="position:absolute;left:-9999px;">Произведени, продадени, подарени и налични бройки по продукти</caption>
      <thead><tr>
        <th scope="col" style="min-width:12rem;">Продукт</th>
        <th scope="col" style="text-align:right;">Произведени</th>
        <th scope="col" style="text-align:right;">Продадени</th>
        <th scope="col" style="text-align:right;">Подарени</th>
        <?php if ($ov_writeoff): ?><th scope="col" style="text-align:right;">Отписани</th><?php endif; ?>
        <th scope="col" style="text-align:right;">Налични за продажба</th>
      </tr></thead>
      <tbody>
      <?php foreach ($overview as $o): $op = $o['product']; $is_var = $op['type'] === 'variant'; ?>
        <tr>
          <th scope="row" style="<?= $ov_rowhead ?>">
            <a href="/admin/distribution.php?product=<?= (int) $op['id'] ?>"><?= h($op['name_bg']) ?></a><?= $op['active'] ? '' : ' <span class="dist-hint">(неактивен)</span>' ?>
          </th>
          <?= $ov_numbers($o['total'] + ['produced' => 0, 'sold' => 0, 'to_samples' => 0, 'remaining' => 0, 'with_distributors' => 0], $is_var) ?>
        </tr>
        <?php if ($is_var): foreach ($o['items'] as $it): ?>
        <tr>
          <th scope="row" style="<?= $ov_rowhead ?>font-weight:400;padding-left:2rem;">
            <?= h($it['label']) ?><?= $it['active'] ? '' : ' <span class="dist-hint">(скрит)</span>' ?>
          </th>
          <?= $ov_numbers($it, false) ?>
        </tr>
        <?php endforeach; endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($ov_form_rows): ?>
  <form method="post" id="multiForm" autocomplete="off" style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">
    <?= $common() ?>
    <input type="hidden" name="action" value="give_out_many">
    <h3 style="font-size:1.05rem;margin:0 0 .35rem;">Подари или отпиши — няколко продукта наведнъж</h3>
    <p class="dist-hint" style="margin:0 0 1rem;line-height:1.55;">Напишете брой срещу всеки продукт, който давате. Празните се пропускат. Записва се всичко или нищо.</p>

    <fieldset style="border:0;padding:0;margin:0 0 1rem;">
      <legend style="text-transform:none;letter-spacing:normal;font-weight:600;font-size:.9rem;margin-bottom:.4rem;">Какво записвате</legend>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;">
        <label style="text-transform:none;letter-spacing:normal;display:flex;align-items:center;gap:.5rem;min-height:44px;font-weight:400;margin:0;cursor:pointer;">
          <input type="radio" name="kind" value="sample" checked style="width:20px;height:20px;"> Безплатни мостри (подарък)
        </label>
        <label style="text-transform:none;letter-spacing:normal;display:flex;align-items:center;gap:.5rem;min-height:44px;font-weight:400;margin:0;cursor:pointer;">
          <input type="radio" name="kind" value="writeoff" style="width:20px;height:20px;"> Отписване (повредени, изгубени)
        </label>
      </div>
    </fieldset>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;">
      <label for="multiRecipient" id="multiRecipientWrap" style="text-transform:none;letter-spacing:normal;display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;margin:0;">На кого са подарени
        <input type="text" name="sample_recipient" id="multiRecipient" maxlength="150" required
               style="padding:.5rem .75rem;border:1px solid #6b7280;border-radius:6px;font-size:.9rem;min-height:44px;box-sizing:border-box;">
        <span class="dist-hint">напр. училище, читалище, партньор</span>
      </label>
      <label for="multiDate" style="text-transform:none;letter-spacing:normal;display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;margin:0;">Дата
        <input type="date" name="allocated_on" id="multiDate" value="<?= h($today) ?>" required>
      </label>
      <label for="multiNotes" style="text-transform:none;letter-spacing:normal;display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;margin:0;"><span id="multiNotesLabel">Бележка <span class="dist-hint">(по желание)</span></span>
        <input type="text" name="notes" id="multiNotes" maxlength="1000"
               style="padding:.5rem .75rem;border:1px solid #6b7280;border-radius:6px;font-size:.9rem;min-height:44px;box-sizing:border-box;">
      </label>
    </div>

    <div style="margin-top:1.25rem;display:flex;flex-direction:column;gap:.5rem;">
      <?php foreach ($ov_form_rows as $i => $r): $fid = 'multiQty' . $i; ?>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;padding:.4rem 0;border-bottom:1px solid var(--border);">
          <label for="<?= $fid ?>" style="text-transform:none;letter-spacing:normal;flex:1 1 14rem;font-weight:600;font-size:.95rem;margin:0;"><?= h($r['label']) ?></label>
          <span class="dist-hint" id="<?= $fid ?>-h" style="flex:0 1 16rem;">в склада <?= $ov_n($r['available']) ?> · в магазина <?= $ov_n($r['shop']) ?></span>
          <input type="number" name="qty[<?= h($r['key']) ?>]" id="<?= $fid ?>" min="0" step="1" inputmode="numeric"
                 aria-describedby="<?= $fid ?>-h" placeholder="0" style="width:6rem;">
        </div>
      <?php endforeach; ?>
    </div>

    <label class="admin-checkbox" style="text-transform:none;letter-spacing:normal;text-transform:none;letter-spacing:normal;margin-top:1rem;min-height:44px;">
      <input type="checkbox" name="use_shop_stock" value="1" checked>
      <span>Извади ги и от наличността в онлайн магазина</span>
    </label>
    <p class="dist-hint" style="margin:.25rem 0 0;">Махнете отметката, ако бройките не са взети от стоката за онлайн магазина, а от склада.</p>
    <button type="submit" class="btn btn--primary" id="multiSubmit" style="margin-top:1rem;min-height:44px;">Запиши мострите</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="get" action="/admin/distribution.php" class="dist-inline" style="<?= $box ?>display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">
  <label for="distProduct" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;flex:1 1 240px;">Продукт
    <select name="product" id="distProduct" onchange="this.form.submit()">
      <?php foreach ($products as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $product_id ? 'selected' : '' ?>>
          <?= h($p['name_bg']) ?><?= $p['active'] ? '' : ' (неактивен)' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn btn--outline">Покажи</button>
</form>

<!-- ── Stock overview ─────────────────────────────────────────────────────── -->
<?php
  $t = $summary['total'] + ['produced' => 0, 'sold' => 0, 'with_distributors' => 0, 'remaining' => 0, 'to_samples' => 0, 'to_writeoff' => 0,
                            'to_personal' => 0, 'sold_online' => 0, 'distributor_sold' => 0, 'to_distributors' => 0,
                            'available' => 0, 'online_stock' => 0, 'shop_surplus' => 0];
  // Each part of "produced": key, label, colour (≥3:1 on white, and always shown with its number as text).
  $parts = [
      'sold'              => ['Продадени', '#14796d'],
      'with_distributors' => ['При дистрибутори', '#b45309'],
      'remaining'         => ['Налични за продажба', '#2563eb'],
      'to_samples'        => ['Безплатни мостри', '#6b7280'],
      'to_writeoff'       => ['Отписани', '#7c3aed'],
  ];
  /** A bar splitting one item's produced quantity into its parts — decoration; the numbers are in the text next to it. */
  $bar = function (array $s, string $height) use ($parts): string {
      $whole = max(1, $s['produced'], $s['sold'] + $s['with_distributors'] + $s['remaining'] + $s['to_samples'] + ($s['to_writeoff'] ?? 0));
      $html  = '<div aria-hidden="true" style="display:flex;height:' . $height . ';border-radius:999px;overflow:hidden;background:#e5e7eb;">';
      foreach ($parts as $key => [, $colour]) {
          $v = max(0, (int) ($s[$key] ?? 0));
          if ($v > 0) $html .= '<span style="flex:0 0 ' . round($v / $whole * 100, 2) . '%;background:' . $colour . ';"></span>';
      }
      return $html . '</div>';
  };
  // Row headers look like the cells, not like the uppercase column headers admin.css gives every <th>.
  $row_head = 'text-align:left;font-weight:600;text-transform:none;letter-spacing:normal;font-size:.95rem;color:var(--text);background:transparent;';
  $n = fn(int $v): string => number_format($v, 0, ',', ' ');
  $cards = [
      ['produced', 'Произведени', '#111827', ['всички партиди', 'от тях ' . $n($t['to_samples']) . ' безплатни мостри'
          . ($t['to_writeoff'] ? ' · ' . $n($t['to_writeoff']) . ' отписани' : '')]],
      ['sold', 'Продадени', $parts['sold'][1],
          ['лично ' . $n($t['to_personal']) . ' · онлайн ' . $n($t['sold_online']), 'от дистрибутори ' . $n($t['distributor_sold'])]],
      ['with_distributors', 'При дистрибутори', $parts['with_distributors'][1],
          ['дадени ' . $n($t['to_distributors']), 'от тях продадени ' . $n($t['distributor_sold'])]],
      ['remaining', 'Налични за продажба', $parts['remaining'][1],
          ['в склада ' . $n(max(0, $t['available'])), 'в онлайн магазина ' . $n(max(0, $t['online_stock']))]],
  ];
?>
<section style="<?= $box ?>padding:1.5rem;" aria-labelledby="distStockTitle">
  <h2 id="distStockTitle" style="font-size:1.15rem;margin:0 0 1.25rem;">Наличност — <?= h($product['name_bg']) ?></h2>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;">
    <?php foreach ($cards as [$key, $label, $colour, $detail]): ?>
      <div style="border:1px solid var(--border);border-top:4px solid <?= $colour ?>;border-radius:10px;padding:1rem 1.1rem;display:flex;flex-direction:column;gap:.3rem;">
        <div style="font-size:.9rem;font-weight:600;color:#374151;"><?= h($label) ?></div>
        <div style="font-size:2.1rem;font-weight:700;line-height:1.1;color:<?= $key === 'produced' ? 'var(--text)' : $colour ?>;font-variant-numeric:tabular-nums;"><?= $n((int) $t[$key]) ?></div>
        <div style="font-size:.8rem;color:#4b5563;line-height:1.5;"><?= implode('<br>', array_map('h', $detail)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($t['produced'] > 0): ?>
  <div style="margin-top:1.25rem;">
    <?= $bar($t, '14px') ?>
    <ul style="list-style:none;display:flex;flex-wrap:wrap;gap:.4rem 1.25rem;padding:0;margin:.6rem 0 0;font-size:.8rem;color:#374151;">
      <?php foreach ($parts as $key => [$label, $colour]): if ($key === 'to_writeoff' && !$t['to_writeoff']) continue; ?>
        <li style="display:flex;align-items:center;gap:.4rem;">
          <span aria-hidden="true" style="width:.75rem;height:.75rem;border-radius:3px;background:<?= $colour ?>;"></span>
          <?= h($label) ?> <?= $n((int) $t[$key]) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($has_variants && $summary['items']): ?>
  <div class="admin-table-wrap" style="margin-top:1.5rem;max-height:none;">
    <table class="admin-table" style="font-variant-numeric:tabular-nums;">
      <caption style="text-align:left;padding:.5rem 1rem;font-weight:600;">По варианти</caption>
      <thead><tr>
        <th scope="col" style="min-width:12rem;">Вариант</th>
        <th scope="col" style="text-align:right;">Произведени</th>
        <th scope="col" style="text-align:right;">Продадени</th>
        <th scope="col" style="text-align:right;">При дистрибутори</th>
        <th scope="col" style="text-align:right;">Налични за продажба</th>
      </tr></thead>
      <tbody>
      <?php foreach ($summary['items'] as $it): ?>
        <tr>
          <th scope="row" style="<?= $row_head ?>">
            <?= h($it['label']) ?><?= $it['active'] ? '' : ' <span class="dist-hint">(скрит)</span>' ?>
            <?php if ($it['produced'] > 0): ?><div style="margin-top:.45rem;"><?= $bar($it, '6px') ?></div><?php endif; ?>
          </th>
          <td style="text-align:right;"><?= $n((int) $it['produced']) ?></td>
          <td style="text-align:right;"><?= $n((int) $it['sold']) ?></td>
          <td style="text-align:right;"><?= $n((int) $it['with_distributors']) ?></td>
          <td style="text-align:right;"><strong><?= $n((int) $it['remaining']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid var(--border);">
        <th scope="row" style="<?= $row_head ?>font-weight:700;">Общо</th>
        <td style="text-align:right;font-weight:700;"><?= $n($t['produced']) ?></td>
        <td style="text-align:right;font-weight:700;"><?= $n($t['sold']) ?></td>
        <td style="text-align:right;font-weight:700;"><?= $n($t['with_distributors']) ?></td>
        <td style="text-align:right;font-weight:700;"><?= $n($t['remaining']) ?></td>
      </tr></tfoot>
    </table>
  </div>
  <?php endif; ?>

  <?php foreach ($summary['items'] as $it): if ($it['shop_surplus'] <= 0) continue; ?>
    <p role="note" style="margin:1rem 0 0;padding:.75rem 1rem;border:1px solid #b45309;border-radius:8px;background:#fffbeb;font-size:.875rem;line-height:1.5;">
      <strong>⚠ Сметката не излиза<?= $has_variants ? ' за „' . h($it['label']) . '“' : '' ?>:</strong>
      в онлайн магазина има <?= $n((int) $it['online_stock']) ?> бр., а като изпратени към магазина са записани само <?= $n((int) $it['to_online']) ?> бр.
      Запишете останалите <?= $n((int) $it['shop_surplus']) ?> бр. в „Разпределения“ като „Онлайн магазин“, без отметката за наличността в магазина.
    </p>
  <?php endforeach; ?>

  <p class="dist-hint" style="margin:1.25rem 0 0;line-height:1.55;max-width:75ch;">
    „Продадени“ събира всичко продадено: лично, от дистрибуторите (по техните отчети) и онлайн — поръчки, билети и всичко
    друго, което е излязло от наличността в онлайн магазина. „Налични за продажба“ е неразпределеното плюс това, което е в онлайн магазина сега.
  </p>

  <details style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem;">
    <summary style="cursor:pointer;font-weight:600;font-size:.9rem;padding:.7rem 0;">Поправи наличността в онлайн магазина (след преброяване)</summary>
    <p class="dist-hint" style="margin:.5rem 0 0;line-height:1.55;">
      Въведете колко бройки има в магазина в момента. Клиентите купуват от това число и то намалява само с всяка поръчка.
    </p>
    <form method="post" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:.75rem;">
      <?= $common() ?>
      <input type="hidden" name="action" value="set_online_stock">
      <?= $variant_select('distStockVariant') ?>
      <label for="distStockQty" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Брой в онлайн магазина сега
        <input type="number" name="stock" id="distStockQty" step="1" required
               value="<?= $has_variants ? '' : (int) ($summary['items'][0]['online_stock'] ?? 0) ?>">
      </label>
      <button type="submit" class="btn btn--outline">Поправи наличността</button>
    </form>
  </details>
</section>

<!-- ── Tabs ───────────────────────────────────────────────────────────────── -->
<nav aria-label="Раздели" id="distTabs" style="display:flex;flex-wrap:wrap;gap:.25rem;border-bottom:2px solid var(--border);margin-bottom:1rem;">
  <?php foreach (DIST_TABS as $key => $label): ?>
    <a href="#<?= $key ?>" data-tab="<?= $key ?>"
       style="padding:.7rem 1rem;min-height:44px;box-sizing:border-box;text-decoration:none;color:var(--text);font-weight:600;border-bottom:3px solid transparent;margin-bottom:-2px;"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<!-- ── Batches ────────────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="batches" id="panel-batches" style="<?= $box ?>" aria-labelledby="h-batches">
  <h2 id="h-batches" style="font-size:1.1rem;margin:0 0 .5rem;">Партиди</h2>
  <p class="dist-hint" style="margin:0 0 1rem;">Всяка нова доставка или производство (напр. отпечатан тираж) — колко бройки и кога.</p>
  <form method="post">
    <?= $common() ?>
    <input type="hidden" name="action" value="add_batch">
    <div class="admin-form-grid">
      <?= $variant_select('batchVariant') ?>
      <label for="batchQty">Количество (бр.)<input type="number" name="quantity" id="batchQty" min="1" step="1" required></label>
      <label for="batchDate">Дата<input type="date" name="produced_on" id="batchDate" value="<?= h($today) ?>" required></label>
      <label for="batchNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="batchNotes" maxlength="1000"></label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">+ Добави партида</button>
  </form>

  <?php if ($batches): ?>
  <div class="admin-table-wrap" style="margin-top:1.5rem;">
    <table class="admin-table">
      <thead><tr><th scope="col">Дата</th><?php if ($has_variants): ?><th scope="col">Вариант</th><?php endif; ?><th scope="col">Количество</th><th scope="col">Бележка</th><th scope="col"><span class="visually-hidden" style="position:absolute;left:-9999px;">Действия</span></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): $eid = 'edit-batch-' . (int) $b['id']; ?>
        <tr>
          <td><?= h(dist_date_bg((string) $b['produced_on'])) ?></td>
          <?php if ($has_variants): ?><td><?= h((string) $b['variant_label']) ?></td><?php endif; ?>
          <td><?= (int) $b['quantity'] ?></td>
          <td><?= h((string) $b['notes']) ?></td>
          <td><button type="button" class="btn-link" data-edit-toggle="<?= $eid ?>" aria-expanded="false" aria-controls="<?= $eid ?>">Поправи</button></td>
        </tr>
        <tr id="<?= $eid ?>" hidden>
          <td colspan="<?= $has_variants ? 5 : 4 ?>">
            <form method="post" class="admin-form-grid" style="align-items:end;">
              <?= $common() ?>
              <input type="hidden" name="action" value="update_batch">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <label for="<?= $eid ?>-q">Количество (бр.)<input type="number" id="<?= $eid ?>-q" name="quantity" min="1" step="1" value="<?= (int) $b['quantity'] ?>" required></label>
              <label for="<?= $eid ?>-d">Дата<input type="date" id="<?= $eid ?>-d" name="produced_on" value="<?= h(substr($b['produced_on'], 0, 10)) ?>" required></label>
              <label for="<?= $eid ?>-n">Бележка<input type="text" id="<?= $eid ?>-n" name="notes" maxlength="1000" value="<?= h((string) $b['notes']) ?>"></label>
              <button type="submit" class="btn btn--primary">Запази</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;">Още няма партиди за този продукт.</p>
  <?php endif; ?>
</section>

<!-- ── Allocations ────────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="allocations" id="panel-allocations" style="<?= $box ?>" aria-labelledby="h-allocations">
  <h2 id="h-allocations" style="font-size:1.1rem;margin:0 0 .5rem;">Разпределения — на кого са дадени бройките</h2>
  <form method="post" id="allocForm">
    <?= $common() ?>
    <input type="hidden" name="action" value="allocate">
    <div class="admin-form-grid">
      <?= $variant_select('allocVariant') ?>
      <label for="allocRecipient">На кого
        <select name="recipient" id="allocRecipient" required>
          <option value="online"><?= h($dest_labels['online']) ?></option>
          <option value="personal"><?= h($dest_labels['personal']) ?></option>
          <option value="sample"><?= h($dest_labels['sample']) ?></option>
          <?php if ($active_distributors): ?>
          <optgroup label="Дистрибутори">
            <?php foreach ($active_distributors as $d): ?>
              <option value="<?= (int) $d['id'] ?>"><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </label>
      <label for="allocQty">Количество (бр.)
        <input type="number" name="quantity" id="allocQty" min="1" step="1" required aria-describedby="allocAvail">
        <span class="dist-hint" id="allocAvail"></span>
        <button type="button" class="btn-link" id="allocUseAll" style="align-self:flex-start;min-height:44px;">Попълни всички неразпределени</button>
      </label>
      <label for="allocSample" id="allocSampleWrap" style="display:none;">На кого са подарени
        <input type="text" name="sample_recipient" id="allocSample" maxlength="150" placeholder="напр. училище, читалище, партньор">
      </label>
      <label for="allocPrice" id="allocPriceWrap">Цена за брой (€)
        <input type="text" inputmode="decimal" name="unit_price_eur" id="allocPrice" value="<?= h(number_format((float) $product['price_eur'], 2, '.', '')) ?>" required>
      </label>
      <label for="allocDate">Дата<input type="date" name="allocated_on" id="allocDate" value="<?= h($today) ?>" required></label>
      <label for="allocNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="allocNotes" maxlength="1000"></label>
    </div>
    <label class="admin-checkbox" id="allocShopWrap" style="margin-top:1rem;min-height:44px;">
      <input type="checkbox" name="use_shop_stock" value="1" id="allocShop" checked>
      <span id="allocShopText">Добави ги и към наличността в онлайн магазина</span>
    </label>
    <p class="dist-hint" id="allocDistHint" style="margin:.5rem 0 0;">При дистрибутор автоматично се създава приемо-предавателен протокол за подпис.<?= $active_distributors ? '' : ' Още нямате дистрибутори — добавете ги в раздел „Дистрибутори“.' ?></p>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запиши</button>
  </form>

  <form method="get" action="/admin/distribution.php#allocations" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <input type="hidden" name="product" value="<?= (int) $product_id ?>">
    <label for="fAlloc" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Покажи
      <select name="af" id="fAlloc">
        <option value="">Всички</option>
        <option value="distributor" <?= $f_alloc === 'distributor' ? 'selected' : '' ?>>Всички дистрибутори</option>
        <?php foreach ($all_distributors as $d): ?>
          <option value="d<?= (int) $d['id'] ?>" <?= $f_alloc === 'd' . (int) $d['id'] ? 'selected' : '' ?>>— <?= h($d['name']) ?></option>
        <?php endforeach; ?>
        <?php foreach ($dest_labels as $k => $l): ?>
          <option value="<?= $k ?>" <?= $f_alloc === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($has_variants): ?>
    <label for="fAllocV" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Вариант
      <select name="av" id="fAllocV">
        <option value="">Всички</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= (int) $it['variant_id'] ?>" <?= $f_alloc_v === $it['variant_id'] ? 'selected' : '' ?>><?= h($it['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_alloc || $f_alloc_v): ?><a href="/admin/distribution.php?product=<?= (int) $product_id ?>#allocations" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>

  <?php if ($allocations): ?>
  <div class="admin-table-wrap" style="margin-top:1rem;">
    <table class="admin-table">
      <thead><tr>
        <th scope="col">Дата</th><th scope="col">На кого</th><?php if ($has_variants): ?><th scope="col">Вариант</th><?php endif; ?>
        <th scope="col">Количество</th><th scope="col">Цена</th><th scope="col">Протокол</th><th scope="col"><span style="position:absolute;left:-9999px;">Действия</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($allocations as $a): $eid = 'edit-alloc-' . (int) $a['id']; $cols = $has_variants ? 7 : 6; ?>
        <tr>
          <td><?= h(dist_date_bg((string) $a['allocated_on'])) ?></td>
          <td>
            <?php if ($a['destination'] === 'distributor'): ?>
              <?= h((string) $a['distributor_name']) ?>
            <?php else: ?>
              <?= h($dest_labels[$a['destination']] ?? $a['destination']) ?>
              <?php if ($a['destination'] === 'sample' && $a['sample_recipient']): ?><br><span class="dist-hint"><?= h($a['sample_recipient']) ?></span><?php endif; ?>
            <?php endif; ?>
          </td>
          <?php if ($has_variants): ?><td><?= h((string) $a['variant_label']) ?></td><?php endif; ?>
          <td><?= (int) $a['quantity'] ?></td>
          <td><?= in_array($a['destination'], DISTRIBUTION_FREE_DESTINATIONS, true) ? '<span class="dist-hint">безплатно</span>' : h(format_eur((float) $a['unit_price_eur'])) ?></td>
          <td>
            <?php if ($a['protocol_file_path']): ?>
              <a href="/admin/download-distribution-protocol.php?id=<?= (int) $a['id'] ?>" target="_blank" rel="noopener" class="btn-link">№ <?= h($a['protocol_formatted_number']) ?> <span style="position:absolute;left:-9999px;">(PDF, отваря се в нов раздел)</span></a>
            <?php elseif ($a['destination'] === 'distributor'): ?>
              <form method="post" style="display:inline;">
                <?= $common() ?>
                <input type="hidden" name="action" value="regenerate_protocol">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="btn-link">⚠ Създай протокола отново</button>
              </form>
            <?php else: ?>
              <span aria-label="няма">—</span>
            <?php endif; ?>
          </td>
          <td><button type="button" class="btn-link" data-edit-toggle="<?= $eid ?>" aria-expanded="false" aria-controls="<?= $eid ?>">Поправи</button></td>
        </tr>
        <tr id="<?= $eid ?>" hidden>
          <td colspan="<?= $cols ?>">
            <form method="post" class="admin-form-grid" style="align-items:end;">
              <?= $common() ?>
              <input type="hidden" name="action" value="update_allocation">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <label for="<?= $eid ?>-q">Количество (бр.)<input type="number" id="<?= $eid ?>-q" name="quantity" min="1" step="1" value="<?= (int) $a['quantity'] ?>" required></label>
              <?php if (!in_array($a['destination'], DISTRIBUTION_FREE_DESTINATIONS, true)): ?>
              <label for="<?= $eid ?>-p">Цена за брой (€)<input type="text" inputmode="decimal" id="<?= $eid ?>-p" name="unit_price_eur" value="<?= h(number_format((float) $a['unit_price_eur'], 2, '.', '')) ?>" required></label>
              <?php endif; ?>
              <label for="<?= $eid ?>-d">Дата<input type="date" id="<?= $eid ?>-d" name="allocated_on" value="<?= h(substr($a['allocated_on'], 0, 10)) ?>" required></label>
              <label for="<?= $eid ?>-n">Бележка<input type="text" id="<?= $eid ?>-n" name="notes" maxlength="1000" value="<?= h((string) $a['notes']) ?>"></label>
              <button type="submit" class="btn btn--primary">Запази</button>
            </form>
            <p class="dist-hint" style="margin:.5rem 0 0;">Това поправя само записа. Наличността в магазина не се променя — за нея ползвайте „Поправи наличността“ горе.</p>
            <?php if ($a['protocol_file_path']): ?>
              <p class="dist-hint" style="margin:.5rem 0 0;">Запазването обновява протокол № <?= h($a['protocol_formatted_number']) ?> с новите данни. Ако вече е подписан, разпечатайте и подпишете новия.</p>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;"><?= ($f_alloc || $f_alloc_v) ? 'Няма записи, които отговарят на филтъра.' : 'Още няма разпределения за този продукт.' ?></p>
  <?php endif; ?>
</section>

<!-- ── Sales ──────────────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="sales" id="panel-sales" style="<?= $box ?>" aria-labelledby="h-sales">
  <h2 id="h-sales" style="font-size:1.1rem;margin:0 0 .5rem;">Продажби и плащания от дистрибутори</h2>
  <?php if ($active_distributors): ?>
  <form method="post">
    <?= $common() ?>
    <input type="hidden" name="action" value="record_sale">
    <div class="admin-form-grid">
      <label for="saleDist">Дистрибутор
        <select name="distributor_id" id="saleDist" required>
          <option value="">— Изберете —</option>
          <?php foreach ($active_distributors as $d): ?>
            <option value="<?= (int) $d['id'] ?>"><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?= $variant_select('saleVariant') ?>
      <label for="saleQty">Продадени (бр.)<input type="number" name="quantity" id="saleQty" min="1" step="1" required></label>
      <label for="saleDate">Дата на продажба<input type="date" name="sale_date" id="saleDate" value="<?= h($today) ?>" required></label>
      <label class="admin-checkbox" style="flex-direction:row;align-self:end;min-height:44px;">
        <input type="checkbox" name="payment_received" value="1" id="salePaid"> Плащането е получено
      </label>
      <label for="salePaidOn">Дата на плащане<input type="date" name="payment_received_on" id="salePaidOn" value="<?= h($today) ?>"></label>
      <label for="saleNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="saleNotes" maxlength="1000"></label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запиши продажба</button>
  </form>
  <?php else: ?>
    <p style="margin:0;color:#4b5563;">Първо добавете дистрибутор в раздел „Дистрибутори“ и му дайте стока.</p>
  <?php endif; ?>

  <form method="get" action="/admin/distribution.php#sales" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <input type="hidden" name="product" value="<?= (int) $product_id ?>">
    <label for="fSaleD" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Дистрибутор
      <select name="sd" id="fSaleD">
        <option value="">Всички</option>
        <?php foreach ($all_distributors as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= $f_sale_d === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="fSaleP" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Плащане
      <select name="sp" id="fSaleP">
        <option value="">Всички</option>
        <option value="unpaid" <?= $f_sale_p === 'unpaid' ? 'selected' : '' ?>>Неплатени</option>
        <option value="paid" <?= $f_sale_p === 'paid' ? 'selected' : '' ?>>Платени</option>
      </select>
    </label>
    <?php if ($has_variants): ?>
    <label for="fSaleV" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Вариант
      <select name="sv" id="fSaleV">
        <option value="">Всички</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= (int) $it['variant_id'] ?>" <?= $f_sale_v === $it['variant_id'] ? 'selected' : '' ?>><?= h($it['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_sale_d || $f_sale_p || $f_sale_v): ?><a href="/admin/distribution.php?product=<?= (int) $product_id ?>#sales" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>

  <?php if ($sales): ?>
  <div class="admin-table-wrap" style="margin-top:1rem;">
    <table class="admin-table">
      <thead><tr>
        <th scope="col" style="width:44px;"><input type="checkbox" class="bulk-all" data-bulk="sales" aria-label="Избери всички продажби" style="width:20px;height:20px;"></th>
        <th scope="col">Дата</th><th scope="col">Дистрибутор</th><?php if ($has_variants): ?><th scope="col">Вариант</th><?php endif; ?>
        <th scope="col">Количество</th><th scope="col">Плащане</th><th scope="col"><span style="position:absolute;left:-9999px;">Действия</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($sales as $s): $eid = 'edit-sale-' . (int) $s['id']; $cols = $has_variants ? 7 : 6; ?>
        <tr>
          <td><input type="checkbox" class="bulk-cb" data-bulk="sales" value="<?= (int) $s['id'] ?>" data-on="<?= $s['payment_received'] ? '1' : '0' ?>"
                     aria-label="Избери продажбата от <?= h(substr($s['sale_date'], 0, 10)) ?>, <?= h($s['distributor_name']) ?>" style="width:20px;height:20px;"></td>
          <td><?= h(dist_date_bg((string) $s['sale_date'])) ?></td>
          <td><?= h($s['distributor_name']) ?></td>
          <?php if ($has_variants): ?><td><?= h((string) $s['variant_label']) ?></td><?php endif; ?>
          <td><?= (int) $s['quantity'] ?></td>
          <td>
            <?php if ($s['payment_received']): ?>
              <span class="badge badge--published">✓ Платено<?= $s['payment_received_on'] ? ' ' . h(date('d.m.Y', strtotime($s['payment_received_on']))) : '' ?></span>
            <?php else: ?>
              <span class="badge badge--draft">⏳ Неплатено</span>
            <?php endif; ?>
          </td>
          <td><button type="button" class="btn-link" data-edit-toggle="<?= $eid ?>" aria-expanded="false" aria-controls="<?= $eid ?>">Поправи</button></td>
        </tr>
        <tr id="<?= $eid ?>" hidden>
          <td colspan="<?= $cols ?>">
            <form method="post" class="admin-form-grid" style="align-items:end;">
              <?= $common() ?>
              <input type="hidden" name="action" value="update_sale">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <label for="<?= $eid ?>-q">Продадени (бр.)<input type="number" id="<?= $eid ?>-q" name="quantity" min="1" step="1" value="<?= (int) $s['quantity'] ?>" required></label>
              <label for="<?= $eid ?>-d">Дата на продажба<input type="date" id="<?= $eid ?>-d" name="sale_date" value="<?= h(substr($s['sale_date'], 0, 10)) ?>" required></label>
              <label class="admin-checkbox" style="flex-direction:row;min-height:44px;"><input type="checkbox" name="payment_received" value="1" <?= $s['payment_received'] ? 'checked' : '' ?>> Плащането е получено</label>
              <label for="<?= $eid ?>-pd">Дата на плащане<input type="date" id="<?= $eid ?>-pd" name="payment_received_on" value="<?= h((string) ($s['payment_received_on'] ?? $today)) ?>"></label>
              <label for="<?= $eid ?>-n">Бележка<input type="text" id="<?= $eid ?>-n" name="notes" maxlength="1000" value="<?= h((string) $s['notes']) ?>"></label>
              <button type="submit" class="btn btn--primary">Запази</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" id="bulkForm-sales" hidden>
    <?= $common() ?>
    <input type="hidden" name="action" value="sales_paid">
    <input type="hidden" name="paid" value="1">
  </form>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;"><?= ($f_sale_d || $f_sale_p || $f_sale_v) ? 'Няма продажби, които отговарят на филтъра.' : 'Още няма записани продажби за този продукт.' ?></p>
  <?php endif; ?>
</section>

<!-- ── Distributors ───────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="distributors" id="panel-distributors" style="<?= $box ?>" aria-labelledby="h-distributors">
  <h2 id="h-distributors" style="font-size:1.1rem;margin:0 0 .5rem;">Дистрибутори</h2>
  <p class="dist-hint" style="margin:0 0 1rem;">Магазини, партньори и други, които продават вместо вас. Числата в таблицата са за избрания продукт.</p>
  <form method="post">
    <?= $common() ?>
    <input type="hidden" name="action" value="add_distributor">
    <div class="admin-form-grid">
      <label for="dName">Име<input type="text" name="name" id="dName" maxlength="150" required></label>
      <label for="dEik">ЕИК / Булстат <span class="dist-hint">(отпечатва се в протокола)</span><input type="text" name="company_number" id="dEik" maxlength="20" required></label>
      <label for="dContact">Лице за контакт<input type="text" name="contact_name" id="dContact" maxlength="150"></label>
      <label for="dAddress">Адрес<input type="text" name="address" id="dAddress" maxlength="255"></label>
      <label for="dEmail">Имейл<input type="email" name="email" id="dEmail" maxlength="150"></label>
      <label for="dNotes">Бележка<input type="text" name="notes" id="dNotes" maxlength="1000"></label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">+ Добави дистрибутор</button>
  </form>

  <?php if ($archived_count): ?>
  <p style="margin:1.5rem 0 0;">
    <?php if ($f_arch): ?>
      <a href="/admin/distribution.php?<?= h(http_build_query(['product' => $product_id] + array_diff_key($keep_get, ['archived' => 1]))) ?>#distributors" class="btn-link">Скрий архивираните (<?= (int) $archived_count ?>)</a>
    <?php else: ?>
      <a href="/admin/distribution.php?<?= h(http_build_query(['product' => $product_id, 'archived' => 1] + $keep_get)) ?>#distributors" class="btn-link">Покажи и архивираните (<?= (int) $archived_count ?>)</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <?php if ($distributor_rows): ?>
  <div class="admin-table-wrap" style="margin-top:1rem;">
    <table class="admin-table">
      <thead><tr>
        <th scope="col" style="width:44px;"><input type="checkbox" class="bulk-all" data-bulk="distributors" aria-label="Избери всички дистрибутори" style="width:20px;height:20px;"></th>
        <th scope="col">Име</th><th scope="col">ЕИК</th><th scope="col">Контакт</th><th scope="col">Дадени</th><th scope="col">Продадени</th>
        <th scope="col">Остават при тях</th><th scope="col">Платени (бр.)</th><th scope="col">Неплатени (бр.)</th><th scope="col"><span style="position:absolute;left:-9999px;">Действия</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($distributor_rows as $d): $eid = 'edit-dist-' . (int) $d['id']; ?>
        <tr<?= (int) $d['active'] ? '' : ' style="opacity:.75;"' ?>>
          <td><input type="checkbox" class="bulk-cb" data-bulk="distributors" value="<?= (int) $d['id'] ?>" data-on="<?= (int) $d['active'] ? '1' : '0' ?>"
                     aria-label="Избери <?= h($d['name']) ?>" style="width:20px;height:20px;"></td>
          <td><strong><?= h($d['name']) ?></strong><?= (int) $d['active'] ? '' : ' <span class="badge badge--draft">Архивиран</span>' ?></td>
          <td><?= h((string) $d['company_number']) ?></td>
          <td><?= h((string) $d['contact_name']) ?><?php if ($d['address']): ?><br><small><?= h($d['address']) ?></small><?php endif; ?><?php if ($d['email']): ?><br><small><?= h($d['email']) ?></small><?php endif; ?></td>
          <td><?= (int) $d['allocated'] ?></td>
          <td><?= (int) $d['sold'] ?></td>
          <td><?= (int) $d['unsold'] ?></td>
          <td><?= (int) $d['paid_qty'] ?></td>
          <td><?= $d['unpaid_qty'] > 0 ? '<strong style="color:#b03a2e;">⏳ ' . (int) $d['unpaid_qty'] . '</strong>' : '0' ?></td>
          <td><button type="button" class="btn-link" data-edit-toggle="<?= $eid ?>" aria-expanded="false" aria-controls="<?= $eid ?>">Поправи</button></td>
        </tr>
        <tr id="<?= $eid ?>" hidden>
          <td colspan="10">
            <form method="post" class="admin-form-grid" style="align-items:end;">
              <?= $common() ?>
              <input type="hidden" name="action" value="update_distributor">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <label for="<?= $eid ?>-n">Име<input type="text" id="<?= $eid ?>-n" name="name" maxlength="150" value="<?= h($d['name']) ?>" required></label>
              <label for="<?= $eid ?>-e">ЕИК / Булстат<input type="text" id="<?= $eid ?>-e" name="company_number" maxlength="20" value="<?= h((string) $d['company_number']) ?>" required></label>
              <label for="<?= $eid ?>-c">Лице за контакт<input type="text" id="<?= $eid ?>-c" name="contact_name" maxlength="150" value="<?= h((string) $d['contact_name']) ?>"></label>
              <label for="<?= $eid ?>-a">Адрес<input type="text" id="<?= $eid ?>-a" name="address" maxlength="255" value="<?= h((string) $d['address']) ?>"></label>
              <label for="<?= $eid ?>-m">Имейл<input type="email" id="<?= $eid ?>-m" name="email" maxlength="150" value="<?= h((string) $d['email']) ?>"></label>
              <label for="<?= $eid ?>-b">Бележка<input type="text" id="<?= $eid ?>-b" name="notes" maxlength="1000" value="<?= h((string) $d['notes']) ?>"></label>
              <button type="submit" class="btn btn--primary">Запази</button>
            </form>
            <p class="dist-hint" style="margin:.5rem 0 0;">Промяната влиза в нови протоколи. Вече издадените се обновяват, когато поправите съответния запис.</p>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" id="bulkForm-distributors" hidden>
    <?= $common() ?>
    <input type="hidden" name="action" value="distributors_active">
    <input type="hidden" name="active" value="0">
  </form>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;">Още няма дистрибутори.</p>
  <?php endif; ?>
</section>

<!-- Bulk bar (shared by the sales and distributors lists; inline styles on purpose) -->
<div id="distBulkBar" role="region" aria-label="Действия с избраните"
     style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;padding:.75rem 1rem;display:none;flex-wrap:wrap;align-items:center;gap:.75rem;z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,.3);">
  <span id="distBulkCount" aria-live="polite" style="font-weight:600;"></span>
  <button type="button" id="distBulkAction" class="btn btn--primary" style="min-height:44px;"></button>
  <button type="button" id="distBulkClear" class="btn-link" style="color:#e5e7eb;margin-left:auto;min-height:44px;">✕ Изчисти избора</button>
</div>

<?php endif; ?>
</div>

<script>
(function () {
  var tabKeys = <?= json_encode(array_keys(DIST_TABS)) ?>;
  var tabs    = document.querySelectorAll('#distTabs [data-tab]');
  var panels  = document.querySelectorAll('.dist-panel');
  if (!tabs.length) return;

  function activate(key) {
    if (tabKeys.indexOf(key) === -1) key = tabKeys[0];
    tabs.forEach(function (t) {
      var on = t.dataset.tab === key;
      t.style.borderBottomColor = on ? '#1b998b' : 'transparent';
      t.style.color = on ? '#14796d' : '';
      if (on) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
    });
    panels.forEach(function (p) { p.hidden = p.dataset.panel !== key; });
    clearBulk();
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      history.replaceState(null, '', '#' + t.dataset.tab);
      activate(t.dataset.tab);
    });
  });
  window.addEventListener('hashchange', function () { activate(location.hash.slice(1)); });

  // Inline "Поправи" rows
  document.querySelectorAll('[data-edit-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById(btn.dataset.editToggle);
      if (!row) return;
      row.hidden = !row.hidden;
      btn.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
      if (!row.hidden) { var f = row.querySelector('input:not([type=hidden])'); if (f) f.focus(); }
    });
  });

  // Samples / write-off form: the recipient is only for samples.
  var multi = document.getElementById('multiForm');
  if (multi) {
    var mWrap = document.getElementById('multiRecipientWrap');
    var mIn   = document.getElementById('multiRecipient');
    var mBtn  = document.getElementById('multiSubmit');
    var mNote = document.getElementById('multiNotesLabel');
    var refreshMulti = function () {
      var k = multi.querySelector('input[name=kind]:checked');
      var isSample = !k || k.value === 'sample';
      // style.display + required together: a hidden required field would block submit invisibly.
      mWrap.style.display = isSample ? 'flex' : 'none';
      mIn.required = isSample;
      mBtn.textContent = isSample ? 'Запиши мострите' : 'Отпиши бройките';
      mNote.innerHTML = isSample ? 'Бележка <span class="dist-hint">(по желание)</span>' : 'Причина <span class="dist-hint">(по желание, напр. „повредени при транспорт“)</span>';
    };
    multi.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', refreshMulti); });
    refreshMulti();
  }

  // Allocation form: fields follow the chosen recipient
  var numbers   = <?= json_encode($item_numbers, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var recipient = document.getElementById('allocRecipient');
  var variant   = document.getElementById('allocVariant');
  var qty       = document.getElementById('allocQty');
  var availEl   = document.getElementById('allocAvail');
  var shopWrap  = document.getElementById('allocShopWrap');
  var shopBox   = document.getElementById('allocShop');
  var shopText  = document.getElementById('allocShopText');
  var sampleW   = document.getElementById('allocSampleWrap');
  var sampleIn  = document.getElementById('allocSample');
  var priceW    = document.getElementById('allocPriceWrap');
  var priceIn   = document.getElementById('allocPrice');
  var distHint  = document.getElementById('allocDistHint');
  var lastDest  = null;
  function show(el, on) { if (el) el.style.display = on ? '' : 'none'; }

  function itemNums() {
    var key = variant ? variant.value : '';
    return numbers[key] || null;
  }
  function refreshAlloc() {
    if (!recipient) return;
    var dest = recipient.value;
    if (['online', 'personal', 'sample'].indexOf(dest) === -1) dest = 'distributor';
    var n = itemNums();
    availEl.textContent = n ? 'Неразпределени: ' + n.available + ' бр. · в магазина: ' + n.shop + ' бр.'
                            : 'Изберете вариант, за да видите наличността.';
    var isSample = dest === 'sample';
    // style.display, not [hidden]: the admin CSS gives these labels display:flex,
    // which would beat the hidden attribute. `required` moves with visibility —
    // a hidden required field blocks submit with an error nobody can see.
    show(sampleW, isSample);  sampleIn.required = isSample;
    show(priceW, !isSample);  priceIn.required  = !isSample;
    show(distHint, dest === 'distributor');
    var shopNow = n ? ' (сега там: ' + n.shop + ' бр.)' : '';
    if (dest === 'online') shopText.textContent = 'Добави ги и към наличността в онлайн магазина' + shopNow;
    if (dest !== 'online') shopText.textContent = 'Извади ги и от наличността в онлайн магазина' + shopNow;
    // Ticked by default for every recipient; re-ticked when the recipient changes.
    if (dest !== lastDest) { shopBox.checked = true; lastDest = dest; }
  }
  if (recipient) {
    recipient.addEventListener('change', refreshAlloc);
    if (variant) variant.addEventListener('change', refreshAlloc);
    document.getElementById('allocUseAll').addEventListener('click', function () {
      var n = itemNums();
      if (n) { qty.value = n.available; qty.focus(); }
    });
    refreshAlloc();
  }

  // Bulk selection — sales (paid/unpaid) and distributors (archive/restore)
  var bar = document.getElementById('distBulkBar');
  var countEl = document.getElementById('distBulkCount');
  var actionBtn = document.getElementById('distBulkAction');
  var current = null;
  var labels = {
    sales:        { on: 'Отбележи като неплатени', off: 'Отбележи като платени', field: 'paid', noun: 'продажби' },
    distributors: { on: 'Архивирай', off: 'Върни от архива', field: 'active', noun: 'дистрибутори' }
  };
  function boxes(group) { return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb[data-bulk="' + group + '"]')); }
  function update(group) {
    current = group;
    var all = boxes(group), sel = all.filter(function (c) { return c.checked; });
    var head = document.querySelector('.bulk-all[data-bulk="' + group + '"]');
    if (head) { head.checked = sel.length && sel.length === all.length; head.indeterminate = sel.length > 0 && sel.length < all.length; }
    if (!sel.length) { bar.style.display = 'none'; return; }
    var allOn = sel.every(function (c) { return c.dataset.on === '1'; });
    // For sales "on" = paid; for distributors "on" = active (so the action archives).
    actionBtn.textContent = allOn ? labels[group].on : labels[group].off;
    actionBtn.dataset.value = allOn ? '0' : '1';
    countEl.textContent = 'Избрани ' + labels[group].noun + ': ' + sel.length;
    bar.style.display = 'flex';
  }
  function clearBulk() {
    document.querySelectorAll('.bulk-cb, .bulk-all').forEach(function (c) { c.checked = false; c.indeterminate = false; });
    if (bar) bar.style.display = 'none';
  }
  document.querySelectorAll('.bulk-all').forEach(function (head) {
    head.addEventListener('change', function () {
      boxes(head.dataset.bulk).forEach(function (c) { c.checked = head.checked; });
      update(head.dataset.bulk);
    });
  });
  document.querySelectorAll('.bulk-cb').forEach(function (c) {
    c.addEventListener('change', function () {
      // Selecting in one list clears the other — one bulk bar, one list at a time.
      if (current && current !== c.dataset.bulk) boxes(current).forEach(function (o) { o.checked = false; });
      update(c.dataset.bulk);
    });
  });
  if (actionBtn) actionBtn.addEventListener('click', function () {
    if (!current) return;
    var form = document.getElementById('bulkForm-' + current);
    form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
    boxes(current).filter(function (c) { return c.checked; }).forEach(function (c) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value; form.appendChild(i);
    });
    form.querySelector('input[name="' + labels[current].field + '"]').value = actionBtn.dataset.value;
    form.submit();
  });
  var clearBtn = document.getElementById('distBulkClear');
  if (clearBtn) clearBtn.addEventListener('click', clearBulk);

  activate(location.hash.slice(1));
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
