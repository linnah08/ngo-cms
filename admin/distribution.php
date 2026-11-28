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

/** Back to this page on a tab, keeping the list filters. */
function dist_redirect(int $product_id, string $tab, array $keep = []): never
{
    $q = array_filter($keep, fn($v) => $v !== null && $v !== '');
    header('Location: /admin/distribution.php' . ($q ? '?' . http_build_query($q) : '') . '#' . $tab);
    exit;
}

/** Optional trimmed text field from POST, null when empty. */
function dist_post_text(string $key, int $max = 1000): ?string
{
    $v = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    return $v === '' ? null : $v;
}

/**
 * The item a form's „Продукт“ dropdown chose: [product_id, variant_id or null].
 * Its value is "<product>_<variant or 0>".
 *
 * @return array{0:int, 1:?int}
 */
function dist_item_key(string $value): array
{
    if (!preg_match('/^(\d+)_(\d+)$/', $value, $m)) return [0, null];
    return [(int) $m[1], (int) $m[2] ?: null];
}

/**
 * Lines of a form with a product dropdown per line (line_item[], line_qty[],
 * and line_price[] when it has prices). A string is the error to show.
 *
 * @return list<array{product_id:int, variant_id:?int, quantity:int, unit_price_eur:float}>|string
 */
function dist_post_lines(): array|string
{
    $items  = (array) ($_POST['line_item'] ?? []);
    $qtys   = (array) ($_POST['line_qty'] ?? []);
    $prices = (array) ($_POST['line_price'] ?? []);
    $lines  = [];
    foreach ($items as $i => $key) {
        [$pid, $vid] = dist_item_key((string) $key);
        $q = trim((string) ($qtys[$i] ?? ''));
        if ($q === '') continue;
        if (!$pid) return 'На един от редовете има брой, но не е избран продукт.';
        $qi = filter_var($q, FILTER_VALIDATE_INT);
        if ($qi === false) return 'Броят трябва да е цяло число.';
        $lines[] = [
            'product_id' => $pid, 'variant_id' => $vid, 'quantity' => $qi,
            'unit_price_eur' => (float) str_replace([',', ' '], ['.', ''], (string) ($prices[$i] ?? '0')),
        ];
    }
    return $lines;
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
    $pid    = 0;
    $keep   = array_intersect_key($_POST, array_flip(['fp', 'af', 'av', 'sd', 'sp', 'sv', 'archived', 'df', 'dq']));
    $keep   = array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 20) : '', $keep);
    $ok     = function (string $msg) { flash_set('success', $msg); };
    $fail   = function (string $msg) { flash_set('error', $msg); };

    switch ($action) {
        case 'set_online_stock':
            $stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
            [$item_pid, $vid] = dist_item_key((string) ($_POST['item'] ?? ''));
            if (!$item_pid) {
                $fail('Моля изберете продукт.');
            } elseif ($stock === false) {
                $fail('Моля въведете цяло число.');
            } elseif ($err = distribution_item_error($pdo, $item_pid, $vid)) {
                $fail($err);
            } else {
                distribution_set_online_stock($pdo, $item_pid, $vid, (int) $stock);
                $ok('Наличността в онлайн магазина е поправена на ' . (int) $stock . ' бр.');
            }
            dist_redirect($pid, 'batches', $keep);

        case 'add_batch':
            [$item_pid, $vid] = dist_item_key((string) ($_POST['item'] ?? ''));
            $r = $item_pid ? distribution_create_batch($pdo, $item_pid, $vid, (int) ($_POST['quantity'] ?? 0),
                trim((string) ($_POST['produced_on'] ?? '')), dist_post_text('notes'))
                : ['ok' => false, 'error' => 'Моля изберете продукт.'];
            $r['ok'] ? $ok('Партидата е добавена.') : $fail($r['error']);
            dist_redirect($pid, 'batches', $keep);

        case 'update_batch':
            $r = distribution_update_batch($pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['quantity'] ?? 0),
                trim((string) ($_POST['produced_on'] ?? '')), dist_post_text('notes'));
            $r['ok'] ? $ok('Партидата е запазена.') : $fail($r['error']);
            dist_redirect($pid, 'batches', $keep);

        case 'delete_batch':
            $r = distribution_delete_batch($pdo, (int) ($_POST['id'] ?? 0));
            $r['ok'] ? $ok('Партидата е изтрита.') : $fail($r['error']);
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
            $lines       = dist_post_lines();
            if (is_string($lines)) {
                $fail($lines);
                dist_redirect($pid, 'allocations', $keep);
            }
            // One line per product (or variant) — several to the same recipient at once.
            $r   = distribution_allocate_items(
                $pdo, $lines, $destination, $dist_id ?: null, trim((string) ($_POST['allocated_on'] ?? '')),
                dist_post_text('notes'), !empty($_POST['use_shop_stock']), dist_post_text('sample_recipient', 150)
            );
            $ids = $r['ids'] ?? [];
            if (!$r['ok']) {
                $fail($r['error']);
            } elseif ($destination === 'distributor') {
                $p = distribution_issue_protocol($pdo, $ids[0], $_SERVER['DOCUMENT_ROOT'], $ids);
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

        case 'delete_allocation':
            $r = distribution_delete_allocation($pdo, (int) ($_POST['id'] ?? 0), $_SERVER['DOCUMENT_ROOT']);
            if (!$r['ok']) {
                $fail($r['error']);
            } elseif (!isset($r['protocol'])) {
                $ok('Записът е изтрит.');
            } elseif ($r['protocol']['removed']) {
                $ok('Записът и протокол № ' . $r['protocol']['number'] . ' са изтрити.');
            } elseif ($r['protocol']['ok']) {
                $ok('Записът е изтрит. Протокол № ' . $r['protocol']['number'] . ' е обновен без него.');
            } else {
                $fail('Записът е изтрит, но: ' . $r['protocol']['error']);
            }
            dist_redirect($pid, 'allocations', $keep);

        case 'regenerate_protocol':
            $p = distribution_issue_protocol($pdo, (int) ($_POST['id'] ?? 0), $_SERVER['DOCUMENT_ROOT']);
            $p['ok'] ? $ok('Протокол № ' . $p['number'] . ' е създаден отново.') : $fail($p['error']);
            dist_redirect($pid, 'allocations', $keep);

        case 'record_sale':
            $paid    = !empty($_POST['payment_received']);
            $dist_id = (int) ($_POST['distributor_id'] ?? 0);
            $date    = trim((string) ($_POST['sale_date'] ?? ''));
            $paid_on = $paid ? trim((string) ($_POST['payment_received_on'] ?? '')) : null;
            $lines   = dist_post_lines();
            if (is_string($lines)) {
                $fail($lines);
                dist_redirect($pid, 'sales', $keep);
            }
            // One line per product (or variant) the distributor sold.
            $r = distribution_record_sales_items($pdo, $dist_id, $lines, $date, $paid, $paid_on, dist_post_text('notes'));
            $r['ok'] ? $ok(count($r['ids']) > 1 ? 'Продажбите са записани (' . $r['quantity'] . ' бр.).' : 'Продажбата е записана.') : $fail($r['error']);
            dist_redirect($pid, 'sales', $keep);

        case 'update_sale':
            $paid = !empty($_POST['payment_received']);
            $r = distribution_update_sale(
                $pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['quantity'] ?? 0), trim((string) ($_POST['sale_date'] ?? '')),
                $paid, $paid ? trim((string) ($_POST['payment_received_on'] ?? '')) : null, dist_post_text('notes')
            );
            $r['ok'] ? $ok('Продажбата е запазена.') : $fail($r['error']);
            dist_redirect($pid, 'sales', $keep);

        case 'delete_sale':
            $r = distribution_delete_sale($pdo, (int) ($_POST['id'] ?? 0));
            $r['ok'] ? $ok('Продажбата е изтрита.') : $fail($r['error']);
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
$products = distribution_products($pdo);
$overview = distribution_overview($pdo);
$today    = date('Y-m-d');

// Every item the forms' „Продукт“ dropdowns offer — one per product, or per variant.
// Choosing one changes only that form: nothing reloads.
$all_items = [];   // "<product>_<variant or 0>" => label, price, unallocated, in the shop
foreach ($products as $p) {
    foreach (distribution_product_items($pdo, $p) as $it) {
        if (!$it['active']) continue;
        $vid   = $it['variant_id'];
        $multi = $p['type'] === 'variant';
        $all_items[(int) $p['id'] . '_' . (int) $vid] = [
            'product'   => (int) $p['id'],
            'group'     => (string) $p['name_bg'] . ((int) $p['active'] ? '' : ' (неактивен)'),
            'label'     => $multi ? $p['name_bg'] . ' — ' . $it['label'] : (string) $p['name_bg'],
            'short'     => $multi ? (string) $it['label'] : (string) $p['name_bg'],
            'multi'     => $multi,
            'price'     => number_format((float) $p['price_eur'], 2, '.', ''),
            'available' => max(0, distribution_available($pdo, (int) $p['id'], $vid)),
            'shop'      => max(0, distribution_online_stock($pdo, (int) $p['id'], $vid)),
        ];
    }
}
$item_label = fn(array $row): string => $row['variant_label'] !== null && $row['variant_label'] !== ''
    ? $row['product_name'] . ' — ' . $row['variant_label'] : (string) $row['product_name'];

// List filters (GET), all narrowed on the server. „fp“ narrows a tab's list to one product.
$f_prod    = (int) ($_GET['fp'] ?? 0);
$f_product = $f_prod ? distribution_product($pdo, $f_prod) : null;
if (!$f_product) $f_prod = 0;
$f_items   = $f_product && $f_product['type'] === 'variant' ? distribution_product_items($pdo, $f_product) : [];
$f_alloc   = mb_substr((string) ($_GET['af'] ?? ''), 0, 20);
$f_alloc_v = $f_items ? (int) ($_GET['av'] ?? 0) : 0;
$f_sale_d  = (int) ($_GET['sd'] ?? 0);
$f_sale_p  = in_array($_GET['sp'] ?? '', ['paid', 'unpaid'], true) ? $_GET['sp'] : '';
$f_sale_v  = $f_items ? (int) ($_GET['sv'] ?? 0) : 0;
$f_arch    = ($_GET['archived'] ?? '') === '1';
$f_dist    = in_array($_GET['df'] ?? '', ['holding', 'unpaid'], true) ? $_GET['df'] : '';
$f_dist_q  = mb_substr(trim((string) ($_GET['dq'] ?? '')), 0, 100);
$keep_get  = array_filter(['fp' => $f_prod ?: '', 'af' => $f_alloc, 'av' => $f_alloc_v ?: '', 'sd' => $f_sale_d ?: '', 'sp' => $f_sale_p,
                           'sv' => $f_sale_v ?: '', 'archived' => $f_arch ? '1' : '', 'df' => $f_dist, 'dq' => $f_dist_q],
                          fn($v) => $v !== '');

$all_distributors    = $pdo->query('SELECT * FROM distribution_distributors ORDER BY active DESC, name')->fetchAll();
$active_distributors = array_values(array_filter($all_distributors, fn($d) => (int) $d['active'] === 1));

$name_join = 'JOIN products p ON p.id = %1$s.product_id LEFT JOIN product_variants pv ON pv.id = %1$s.variant_id';
$name_cols = 'p.name_bg AS product_name, pv.label_bg AS variant_label';

$where  = $f_prod ? ['b.product_id = ?'] : ['1 = 1'];
$stmt = $pdo->prepare(
    "SELECT b.*, $name_cols FROM distribution_batches b " . sprintf($name_join, 'b')
    . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY b.produced_on DESC, b.id DESC'
);
$stmt->execute($f_prod ? [$f_prod] : []);
$batches = $stmt->fetchAll();

$where  = $f_prod ? ['a.product_id = ?'] : ['1 = 1'];
$params = $f_prod ? [$f_prod] : [];
if (in_array($f_alloc, ['online', 'personal', 'sample', 'writeoff', 'distributor'], true)) {
    $where[] = 'a.destination = ?'; $params[] = $f_alloc;
} elseif (preg_match('/^d(\d+)$/', $f_alloc, $m)) {
    $where[] = 'a.distributor_id = ?'; $params[] = (int) $m[1];
}
if ($f_alloc_v) { $where[] = 'a.variant_id = ?'; $params[] = $f_alloc_v; }
$stmt = $pdo->prepare(
    "SELECT a.*, d.name AS distributor_name, $name_cols FROM distribution_allocations a
       LEFT JOIN distribution_distributors d ON d.id = a.distributor_id " . sprintf($name_join, 'a')
    . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.allocated_on DESC, a.id DESC'
);
$stmt->execute($params);
$allocations = $stmt->fetchAll();

$where  = $f_prod ? ['s.product_id = ?'] : ['1 = 1'];
$params = $f_prod ? [$f_prod] : [];
if ($f_sale_d) { $where[] = 's.distributor_id = ?'; $params[] = $f_sale_d; }
if ($f_sale_p) { $where[] = 's.payment_received = ?'; $params[] = $f_sale_p === 'paid' ? 1 : 0; }
if ($f_sale_v) { $where[] = 's.variant_id = ?'; $params[] = $f_sale_v; }
$stmt = $pdo->prepare(
    "SELECT s.*, d.name AS distributor_name, $name_cols FROM distribution_sales s
       JOIN distribution_distributors d ON d.id = s.distributor_id " . sprintf($name_join, 's')
    . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.sale_date DESC, s.id DESC'
);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Distributors with their numbers — all products together, or the filtered one.
$totals = distribution_distributor_totals($pdo, $f_prod ?: null);
$distributor_rows = [];
foreach ($all_distributors as $d) {
    if (!$f_arch && !(int) $d['active']) continue;
    $distributor_rows[] = $d + ($totals[(int) $d['id']] ?? ['allocated' => 0, 'sold' => 0, 'unsold' => 0, 'paid_qty' => 0, 'unpaid_qty' => 0]);
}
$distributor_count = count($distributor_rows);
$distributor_rows  = array_values(array_filter($distributor_rows, function (array $d) use ($f_dist, $f_dist_q): bool {
    if ($f_dist === 'holding' && (int) $d['unsold'] <= 0) return false;
    if ($f_dist === 'unpaid' && (int) $d['unpaid_qty'] <= 0) return false;
    if ($f_dist_q !== '') {
        $hay = mb_strtolower(implode(' ', [$d['name'], $d['company_number'], $d['contact_name'], $d['address'], $d['email']]));
        if (!str_contains($hay, mb_strtolower($f_dist_q))) return false;
    }
    return true;
}));
$archived_count = count($all_distributors) - count($active_distributors);

// What each distributor still holds, per item — the sale form shows only those.
$holdings = distribution_distributor_holdings($pdo);

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

/** <option>s of every item, grouped by product when it has variants. */
$item_options = function (string $selected = '') use ($all_items): string {
    $html = '<option value="">— Изберете продукт —</option>';
    $open = null;
    foreach ($all_items as $key => $it) {
        $group = $it['multi'] ? $it['group'] : null;
        if ($group !== $open) {
            if ($open !== null) $html .= '</optgroup>';
            if ($group !== null) $html .= '<optgroup label="' . h($group) . '">';
            $open = $group;
        }
        $html .= '<option value="' . h($key) . '"' . ($key === $selected ? ' selected' : '') . '>'
               . h($it['multi'] ? $it['label'] : $it['group']) . '</option>';
    }
    return $html . ($open !== null ? '</optgroup>' : '');
};

/** The „Продукт“ filter of a tab's list (fp) — narrows the list below, nothing else. */
$product_filter = function (string $id) use ($products, $f_prod): string {
    $html = '<label for="' . h($id) . '" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Продукт'
          . '<select name="fp" id="' . h($id) . '"><option value="">Всички продукти</option>';
    foreach ($products as $p) {
        $html .= '<option value="' . (int) $p['id'] . '"' . ((int) $p['id'] === $f_prod ? ' selected' : '') . '>'
               . h($p['name_bg']) . ((int) $p['active'] ? '' : ' (неактивен)') . '</option>';
    }
    return $html . '</select></label>';
};

/**
 * Lines of a form: a „Продукт“ dropdown and a quantity per line (plus a price),
 * with „+ Добави още продукт“ for the next one. JS clones the <template>.
 */
$lines_block = function (string $prefix, bool $with_price) use ($item_options): string {
    $row = function (string $n) use ($prefix, $with_price, $item_options): string {
        $id = $prefix . 'Line' . $n;
        $html = '<div class="dist-line dist-inline" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem 1rem;padding:.6rem 0;border-bottom:1px solid var(--border);">'
              . '<label for="' . $id . 'Item" style="flex:2 1 16rem;">Продукт<select name="line_item[]" id="' . $id . 'Item" data-line-item aria-describedby="' . $id . 'Hint">' . $item_options() . '</select></label>'
              . '<label for="' . $id . 'Qty" style="flex:0 1 8rem;">Брой<input type="number" name="line_qty[]" id="' . $id . 'Qty" min="1" step="1" inputmode="numeric" data-line-qty aria-describedby="' . $id . 'Hint"></label>';
        if ($with_price) {
            $html .= '<label for="' . $id . 'Price" style="flex:0 1 9rem;" data-line-price-wrap>Цена за брой (€)'
                   . '<input type="text" inputmode="decimal" name="line_price[]" id="' . $id . 'Price" data-line-price></label>';
        }
        return $html . '<button type="button" class="btn-link" data-line-remove style="min-height:44px;">Махни реда</button>'
             . '<span class="dist-hint" id="' . $id . 'Hint" data-line-hint aria-live="polite" style="flex:1 1 100%;"></span></div>';
    };
    return '<div data-lines="' . h($prefix) . '" style="margin-top:1rem;">' . $row('1') . '</div>'
         . '<template data-line-template="' . h($prefix) . '">' . $row('__N__') . '</template>'
         . '<button type="button" class="btn btn--outline" data-line-add="' . h($prefix) . '" style="display:block;margin-top:.75rem;min-height:44px;">+ Добави още продукт</button>';
};

/** Hidden fields every form on the page carries. */
$common = function () use ($keep_get): string {
    $html = csrf_field();
    foreach ($keep_get as $k => $v) $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    return $html;
};

/** Confirm text for deleting an allocation row — says what happens to stock and the protocol. */
$alloc_delete_msg = function (array $a): string {
    $msg = 'Изтриване на записа от ' . dist_date_bg((string) $a['allocated_on']) . ' (' . (int) $a['quantity'] . ' бр.)? '
         . 'Бройките се връщат като неразпределени.';
    if (in_array($a['destination'], ['online', 'personal', 'distributor'], true)) {
        $msg .= ' Наличността в онлайн магазина не се променя — ако трябва, поправете я горе.';
    }
    if ($a['protocol_file_path']) {
        $msg .= ' Протокол № ' . $a['protocol_formatted_number'] . ' ще бъде изтрит или обновен без този ред; номерът му няма да се използва отново. Ако вече е подписан, отбележете това на хартия.';
    }
    return $msg . ' Това не може да се върне.';
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
        <?php if ($is_var && count($o['items']) > 1): // one variant would just repeat the product's row
          foreach ($o['items'] as $it): ?>
        <tr>
          <th scope="row" style="<?= $ov_rowhead ?>font-weight:400;padding-left:2rem;">
            <?= h($it['label']) ?><?= $it['active'] ? '' : ' <span class="dist-hint">(скрит)</span>' ?>
          </th>
          <?= $ov_numbers($it, false) ?>
        </tr>
        <?php endforeach; endif; ?>
      <?php endforeach; ?>
      </tbody>
      <?php if (count($overview) > 1):
        // Sum of the product rows only — variant rows are already inside their product's row.
        $ov_sum = ['produced' => 0, 'sold' => 0, 'to_samples' => 0, 'to_writeoff' => 0, 'remaining' => 0, 'with_distributors' => 0];
        foreach ($overview as $o) foreach ($ov_sum as $k => $_) $ov_sum[$k] += (int) ($o['total'][$k] ?? 0);
      ?>
      <tfoot>
        <tr style="border-top:2px solid var(--text);">
          <th scope="row" style="<?= $ov_rowhead ?>font-weight:700;">Общо</th>
          <?= $ov_numbers($ov_sum, true) ?>
        </tr>
      </tfoot>
      <?php endif; ?>
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

<?php
  $n = fn(int $v): string => number_format($v, 0, ',', ' ');
  $surplus = [];
  foreach ($overview as $o) foreach ($o['items'] as $it) {
      if ($it['shop_surplus'] > 0) $surplus[] = ['label' => count($o['items']) > 1 || $o['product']['type'] === 'variant' ? $o['product']['name_bg'] . ' — ' . $it['label'] : $o['product']['name_bg']] + $it;
  }
?>
<?php foreach ($surplus as $it): ?>
  <p role="note" style="margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #b45309;border-radius:8px;background:#fffbeb;font-size:.875rem;line-height:1.5;">
    <strong>⚠ Сметката не излиза за „<?= h($it['label']) ?>“:</strong>
    в онлайн магазина има <?= $n((int) $it['online_stock']) ?> бр., а като изпратени към магазина са записани само <?= $n((int) $it['to_online']) ?> бр.
    Запишете останалите <?= $n((int) $it['shop_surplus']) ?> бр. в „Разпределения“ като „Онлайн магазин“, без отметката за наличността в магазина.
  </p>
<?php endforeach; ?>

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
      <label for="batchItem">Продукт<select name="item" id="batchItem" required><?= $item_options() ?></select></label>
      <label for="batchQty">Количество (бр.)<input type="number" name="quantity" id="batchQty" min="1" step="1" required></label>
      <label for="batchDate">Дата<input type="date" name="produced_on" id="batchDate" value="<?= h($today) ?>" required></label>
      <label for="batchNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="batchNotes" maxlength="1000"></label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">+ Добави партида</button>
  </form>

  <details style="margin-top:1.25rem;border-top:1px solid var(--border);padding-top:.5rem;">
    <summary style="cursor:pointer;font-weight:600;font-size:.9rem;padding:.7rem 0;">Поправи наличността в онлайн магазина (след преброяване)</summary>
    <p class="dist-hint" style="margin:.5rem 0 0;line-height:1.55;">
      Въведете колко бройки има в магазина в момента. Клиентите купуват от това число и то намалява само с всяка поръчка.
    </p>
    <form method="post" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:.75rem;">
      <?= $common() ?>
      <input type="hidden" name="action" value="set_online_stock">
      <label for="stockItem" style="flex:1 1 16rem;">Продукт<select name="item" id="stockItem" required><?= $item_options() ?></select></label>
      <label for="distStockQty">Брой в онлайн магазина сега
        <input type="number" name="stock" id="distStockQty" step="1" required aria-describedby="distStockHint">
        <span class="dist-hint" id="distStockHint" aria-live="polite"></span>
      </label>
      <button type="submit" class="btn btn--outline">Поправи наличността</button>
    </form>
  </details>

  <form method="get" action="/admin/distribution.php#batches" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <?= $product_filter('fBatchP') ?>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_prod): ?><a href="/admin/distribution.php#batches" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>

  <?php if ($batches): ?>
  <div class="admin-table-wrap" style="margin-top:1.5rem;">
    <table class="admin-table">
      <thead><tr><th scope="col">Дата</th><th scope="col">Продукт</th><th scope="col">Количество</th><th scope="col">Бележка</th><th scope="col"><span class="visually-hidden" style="position:absolute;left:-9999px;">Действия</span></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): $eid = 'edit-batch-' . (int) $b['id']; ?>
        <tr>
          <td><?= h(dist_date_bg((string) $b['produced_on'])) ?></td>
          <td><?= h($item_label($b)) ?></td>
          <td><?= (int) $b['quantity'] ?></td>
          <td><?= h((string) $b['notes']) ?></td>
          <td><button type="button" class="btn-link" data-edit-toggle="<?= $eid ?>" aria-expanded="false" aria-controls="<?= $eid ?>">Поправи</button></td>
        </tr>
        <tr id="<?= $eid ?>" hidden>
          <td colspan="5">
            <form method="post" class="admin-form-grid" style="align-items:end;">
              <?= $common() ?>
              <input type="hidden" name="action" value="update_batch">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <label for="<?= $eid ?>-q">Количество (бр.)<input type="number" id="<?= $eid ?>-q" name="quantity" min="1" step="1" value="<?= (int) $b['quantity'] ?>" required></label>
              <label for="<?= $eid ?>-d">Дата<input type="date" id="<?= $eid ?>-d" name="produced_on" value="<?= h(substr($b['produced_on'], 0, 10)) ?>" required></label>
              <label for="<?= $eid ?>-n">Бележка<input type="text" id="<?= $eid ?>-n" name="notes" maxlength="1000" value="<?= h((string) $b['notes']) ?>"></label>
              <button type="submit" class="btn btn--primary">Запази</button>
            </form>
            <form method="post" style="margin:.75rem 0 0;" data-confirm="Изтриване на партидата от <?= h(dist_date_bg((string) $b['produced_on'])) ?> (<?= (int) $b['quantity'] ?> бр.)? Това не може да се върне." data-confirm-ok="Изтрий">
              <?= $common() ?>
              <input type="hidden" name="action" value="delete_batch">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="btn-link btn-link--danger" style="min-height:44px;" aria-label="Изтрий партидата от <?= h(dist_date_bg((string) $b['produced_on'])) ?>">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;"><?= $f_prod ? 'Още няма партиди за този продукт.' : 'Още няма партиди.' ?></p>
  <?php endif; ?>
</section>

<!-- ── Allocations ────────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="allocations" id="panel-allocations" style="<?= $box ?>" aria-labelledby="h-allocations">
  <h2 id="h-allocations" style="font-size:1.1rem;margin:0 0 .5rem;">Разпределения — на кого са дадени бройките</h2>
  <form method="post" id="allocForm">
    <?= $common() ?>
    <input type="hidden" name="action" value="allocate">
    <div class="admin-form-grid">
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
      <label for="allocSample" id="allocSampleWrap" style="display:none;">На кого са подарени
        <input type="text" name="sample_recipient" id="allocSample" maxlength="150" placeholder="напр. училище, читалище, партньор">
      </label>
      <label for="allocDate">Дата<input type="date" name="allocated_on" id="allocDate" value="<?= h($today) ?>" required></label>
      <label for="allocNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="allocNotes" maxlength="1000"></label>
    </div>
    <?= $lines_block('alloc', true) ?>
    <label class="admin-checkbox" id="allocShopWrap" style="margin-top:1rem;min-height:44px;">
      <input type="checkbox" name="use_shop_stock" value="1" id="allocShop" checked>
      <span id="allocShopText">Добави ги и към наличността в онлайн магазина</span>
    </label>
    <p class="dist-hint" id="allocDistHint" style="margin:.5rem 0 0;">При дистрибутор автоматично се създава приемо-предавателен протокол за подпис.<?= $active_distributors ? '' : ' Още нямате дистрибутори — добавете ги в раздел „Дистрибутори“.' ?></p>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запиши</button>
  </form>

  <form method="get" action="/admin/distribution.php#allocations" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <?= $product_filter('fAllocP') ?>
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
    <?php if ($f_items): ?>
    <label for="fAllocV" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Вариант
      <select name="av" id="fAllocV">
        <option value="">Всички</option>
        <?php foreach ($f_items as $it): ?>
          <option value="<?= (int) $it['variant_id'] ?>" <?= $f_alloc_v === $it['variant_id'] ? 'selected' : '' ?>><?= h($it['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_prod || $f_alloc || $f_alloc_v): ?><a href="/admin/distribution.php#allocations" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>

  <?php if ($allocations): ?>
  <div class="admin-table-wrap" style="margin-top:1rem;">
    <table class="admin-table">
      <thead><tr>
        <th scope="col">Дата</th><th scope="col">На кого</th><th scope="col">Продукт</th>
        <th scope="col">Количество</th><th scope="col">Цена</th><th scope="col">Протокол</th><th scope="col"><span style="position:absolute;left:-9999px;">Действия</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($allocations as $a): $eid = 'edit-alloc-' . (int) $a['id']; $cols = 7; ?>
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
          <td><?= h($item_label($a)) ?></td>
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
            <p class="dist-hint" style="margin:.5rem 0 0;">Това поправя само записа. Наличността в магазина не се променя — за нея ползвайте „Поправи наличността“ в раздел „Партиди“.</p>
            <?php if ($a['protocol_file_path']): ?>
              <p class="dist-hint" style="margin:.5rem 0 0;">Запазването обновява протокол № <?= h($a['protocol_formatted_number']) ?> с новите данни. Ако вече е подписан, разпечатайте и подпишете новия.</p>
            <?php endif; ?>
            <form method="post" style="margin:.75rem 0 0;" data-confirm="<?= h($alloc_delete_msg($a)) ?>" data-confirm-ok="Изтрий">
              <?= $common() ?>
              <input type="hidden" name="action" value="delete_allocation">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <button type="submit" class="btn-link btn-link--danger" style="min-height:44px;" aria-label="Изтрий записа от <?= h(dist_date_bg((string) $a['allocated_on'])) ?>">Изтрий</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;"><?= ($f_prod || $f_alloc || $f_alloc_v) ? 'Няма записи, които отговарят на филтъра.' : 'Още няма разпределения.' ?></p>
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
      <label for="saleDate">Дата на продажба<input type="date" name="sale_date" id="saleDate" value="<?= h($today) ?>" required></label>
      <label class="admin-checkbox" style="flex-direction:row;align-self:end;min-height:44px;">
        <input type="checkbox" name="payment_received" value="1" id="salePaid"> Плащането е получено
      </label>
      <label for="salePaidOn">Дата на плащане<input type="date" name="payment_received_on" id="salePaidOn" value="<?= h($today) ?>"></label>
      <label for="saleNotes">Бележка <span class="dist-hint">(по желание)</span><input type="text" name="notes" id="saleNotes" maxlength="1000"></label>
    </div>
    <p class="dist-hint" id="saleHeld" aria-live="polite" style="margin:1rem 0 0;">Изберете дистрибутор — в списъка с продукти остават само тези, които той има.</p>
    <button type="button" class="btn-link" id="saleUseAll" style="display:block;min-height:44px;">Попълни всичко, което дистрибуторът има</button>
    <?= $lines_block('sale', false) ?>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запиши продажба</button>
  </form>
  <?php else: ?>
    <p style="margin:0;color:#4b5563;">Първо добавете дистрибутор в раздел „Дистрибутори“ и му дайте стока.</p>
  <?php endif; ?>

  <form method="get" action="/admin/distribution.php#sales" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <?= $product_filter('fSaleProd') ?>
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
    <?php if ($f_items): ?>
    <label for="fSaleV" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Вариант
      <select name="sv" id="fSaleV">
        <option value="">Всички</option>
        <?php foreach ($f_items as $it): ?>
          <option value="<?= (int) $it['variant_id'] ?>" <?= $f_sale_v === $it['variant_id'] ? 'selected' : '' ?>><?= h($it['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_prod || $f_sale_d || $f_sale_p || $f_sale_v): ?><a href="/admin/distribution.php#sales" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>

  <?php if ($sales): ?>
  <div class="admin-table-wrap" style="margin-top:1rem;">
    <table class="admin-table">
      <thead><tr>
        <th scope="col" style="width:44px;"><input type="checkbox" class="bulk-all" data-bulk="sales" aria-label="Избери всички продажби" style="width:20px;height:20px;"></th>
        <th scope="col">Дата</th><th scope="col">Дистрибутор</th><th scope="col">Продукт</th>
        <th scope="col">Количество</th><th scope="col">Плащане</th><th scope="col"><span style="position:absolute;left:-9999px;">Действия</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($sales as $s): $eid = 'edit-sale-' . (int) $s['id']; $cols = 7; ?>
        <tr>
          <td><input type="checkbox" class="bulk-cb" data-bulk="sales" value="<?= (int) $s['id'] ?>" data-on="<?= $s['payment_received'] ? '1' : '0' ?>"
                     aria-label="Избери продажбата от <?= h(substr($s['sale_date'], 0, 10)) ?>, <?= h($s['distributor_name']) ?>" style="width:20px;height:20px;"></td>
          <td><?= h(dist_date_bg((string) $s['sale_date'])) ?></td>
          <td><?= h($s['distributor_name']) ?></td>
          <td><?= h($item_label($s)) ?></td>
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
            <form method="post" style="margin:.75rem 0 0;" data-confirm="Изтриване на продажбата от <?= h(dist_date_bg((string) $s['sale_date'])) ?> (<?= (int) $s['quantity'] ?> бр., <?= h($s['distributor_name']) ?>)?<?= $s['payment_received'] ? ' Записът за полученото плащане също ще бъде изтрит.' : '' ?> Бройките отново ще се водят като непродадени при дистрибутора. Това не може да се върне." data-confirm-ok="Изтрий">
              <?= $common() ?>
              <input type="hidden" name="action" value="delete_sale">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn-link btn-link--danger" style="min-height:44px;" aria-label="Изтрий продажбата от <?= h(dist_date_bg((string) $s['sale_date'])) ?>, <?= h($s['distributor_name']) ?>">Изтрий</button>
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
    <p style="margin:1.25rem 0 0;color:#4b5563;"><?= ($f_prod || $f_sale_d || $f_sale_p || $f_sale_v) ? 'Няма продажби, които отговарят на филтъра.' : 'Още няма записани продажби.' ?></p>
  <?php endif; ?>
</section>

<!-- ── Distributors ───────────────────────────────────────────────────────── -->
<section class="dist-panel" data-panel="distributors" id="panel-distributors" style="<?= $box ?>" aria-labelledby="h-distributors">
  <h2 id="h-distributors" style="font-size:1.1rem;margin:0 0 .5rem;">Дистрибутори</h2>
  <p class="dist-hint" style="margin:0 0 1rem;">Магазини, партньори и други, които продават вместо вас. Числата в таблицата са за всички продукти заедно — изберете продукт във филтъра, за да видите само неговите.</p>
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
      <a href="/admin/distribution.php?<?= h(http_build_query(array_diff_key($keep_get, ['archived' => 1]))) ?>#distributors" class="btn-link">Скрий архивираните (<?= (int) $archived_count ?>)</a>
    <?php else: ?>
      <a href="/admin/distribution.php?<?= h(http_build_query(['archived' => 1] + $keep_get)) ?>#distributors" class="btn-link">Покажи и архивираните (<?= (int) $archived_count ?>)</a>
    <?php endif; ?>
  </p>
  <?php endif; ?>

  <?php if ($distributor_count): ?>
  <form method="get" action="/admin/distribution.php#distributors" class="dist-inline" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1.75rem;padding-top:1rem;border-top:1px solid var(--border);">
    <?= $product_filter('fDistP') ?>
    <?php if ($f_arch): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <label for="fDist" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Покажи
      <select name="df" id="fDist">
        <option value="">Всички</option>
        <option value="holding" <?= $f_dist === 'holding' ? 'selected' : '' ?>>Имат непродадени бройки</option>
        <option value="unpaid" <?= $f_dist === 'unpaid' ? 'selected' : '' ?>>Имат неплатени продажби</option>
      </select>
    </label>
    <label for="fDistQ" style="display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.875rem;">Търси
      <input type="search" name="dq" id="fDistQ" maxlength="100" value="<?= h($f_dist_q) ?>" aria-describedby="fDistQHint"
             style="padding:.5rem .75rem;border:1px solid #6b7280;border-radius:6px;font-size:.9rem;min-height:44px;box-sizing:border-box;">
      <span class="dist-hint" id="fDistQHint">име, ЕИК, контакт, адрес или имейл</span>
    </label>
    <button type="submit" class="btn btn--outline">Филтрирай</button>
    <?php if ($f_prod || $f_dist || $f_dist_q !== ''): ?><a href="/admin/distribution.php<?= $f_arch ? '?archived=1' : '' ?>#distributors" class="btn-link" style="min-height:44px;display:inline-flex;align-items:center;">Изчисти филтъра</a><?php endif; ?>
  </form>
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
  <?php elseif ($distributor_count): ?>
    <p style="margin:1.25rem 0 0;color:#4b5563;">Няма дистрибутори, които отговарят на филтъра.</p>
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

  // ── Product lines (allocations, sales): a „Продукт“ dropdown + quantity per line ──
  var itemData = <?= json_encode(array_map(fn($it) => ['label' => $it['label'], 'price' => $it['price'], 'available' => $it['available'], 'shop' => $it['shop']], $all_items), JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT) ?>;
  var holdings = <?= json_encode($holdings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT) ?>;
  var lineCount = 1;
  var lineHint = {};   // prefix => function(row) that fills the row's hint

  function lineRows(prefix) { return Array.prototype.slice.call(document.querySelectorAll('[data-lines="' + prefix + '"] .dist-line')); }
  function addLine(prefix, itemKey, qty) {
    var tpl  = document.querySelector('template[data-line-template="' + prefix + '"]');
    var wrap = document.querySelector('[data-lines="' + prefix + '"]');
    lineCount++;
    var holder = document.createElement('div');
    holder.innerHTML = tpl.innerHTML.replace(/__N__/g, String(lineCount));
    var row = holder.firstElementChild;
    wrap.appendChild(row);
    wireLine(prefix, row);
    if (itemKey) { row.querySelector('[data-line-item]').value = itemKey; refreshLine(prefix, row); }
    if (qty) row.querySelector('[data-line-qty]').value = qty;
    return row;
  }
  function refreshLine(prefix, row) {
    var key   = row.querySelector('[data-line-item]').value;
    var price = row.querySelector('[data-line-price]');
    if (price && key && itemData[key] && price.dataset.touched !== '1') price.value = itemData[key].price;
    if (lineHint[prefix]) lineHint[prefix](row);
  }
  function wireLine(prefix, row) {
    row.querySelector('[data-line-item]').addEventListener('change', function () { refreshLine(prefix, row); });
    var price = row.querySelector('[data-line-price]');
    if (price) price.addEventListener('input', function () { price.dataset.touched = '1'; });
    row.querySelector('[data-line-remove]').addEventListener('click', function () {
      var rows = lineRows(prefix);
      if (rows.length > 1) {
        var i = rows.indexOf(row);
        row.remove();
        var next = lineRows(prefix)[Math.max(0, i - 1)];
        next.querySelector('[data-line-item]').focus();
      } else {
        // The last line is cleared, not removed — the form always keeps one.
        row.querySelector('[data-line-item]').value = '';
        row.querySelector('[data-line-qty]').value = '';
        if (price) { price.value = ''; price.dataset.touched = ''; }
        refreshLine(prefix, row);
      }
    });
    refreshLine(prefix, row);
  }
  document.querySelectorAll('[data-lines]').forEach(function (wrap) {
    var prefix = wrap.dataset.lines;
    lineRows(prefix).forEach(function (row) { wireLine(prefix, row); });
  });
  document.querySelectorAll('[data-line-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      addLine(btn.dataset.lineAdd).querySelector('[data-line-item]').focus();
    });
  });

  // Allocation form: fields follow the chosen recipient
  var recipient = document.getElementById('allocRecipient');
  var shopBox   = document.getElementById('allocShop');
  var shopText  = document.getElementById('allocShopText');
  var sampleW   = document.getElementById('allocSampleWrap');
  var sampleIn  = document.getElementById('allocSample');
  var distHint  = document.getElementById('allocDistHint');
  var lastDest  = null;
  function show(el, on) { if (el) el.style.display = on ? '' : 'none'; }
  function allocDest() {
    var dest = recipient ? recipient.value : 'online';
    return ['online', 'personal', 'sample'].indexOf(dest) === -1 ? 'distributor' : dest;
  }
  lineHint.alloc = function (row) {
    var key = row.querySelector('[data-line-item]').value, n = itemData[key];
    row.querySelector('[data-line-hint]').textContent = n ? 'неразпределени ' + n.available + ' бр. · в магазина ' + n.shop + ' бр.' : '';
    // Free samples have no price — the price box hides and stops being required.
    var free = allocDest() === 'sample';
    var pw = row.querySelector('[data-line-price-wrap]'), pi = row.querySelector('[data-line-price]');
    show(pw, !free);
    if (pi) pi.required = !free && key !== '';
  };
  function refreshAlloc() {
    if (!recipient) return;
    var dest = allocDest();
    var isSample = dest === 'sample';
    // style.display, not [hidden]: the admin CSS gives these labels display:flex,
    // which would beat the hidden attribute. `required` moves with visibility —
    // a hidden required field blocks submit with an error nobody can see.
    show(sampleW, isSample);  sampleIn.required = isSample;
    show(distHint, dest === 'distributor');
    shopText.textContent = dest === 'online' ? 'Добави ги и към наличността в онлайн магазина' : 'Извади ги и от наличността в онлайн магазина';
    // Ticked by default for every recipient; re-ticked when the recipient changes.
    if (dest !== lastDest) { shopBox.checked = true; lastDest = dest; }
    lineRows('alloc').forEach(function (row) { lineHint.alloc(row); });
  }
  if (recipient) {
    recipient.addEventListener('change', refreshAlloc);
    refreshAlloc();
  }

  // Sale form: only the products the chosen distributor still holds.
  var saleDist = document.getElementById('saleDist');
  var saleHeld = document.getElementById('saleHeld');
  function heldNow() { return saleDist && saleDist.value ? (holdings[saleDist.value] || {}) : null; }
  lineHint.sale = function (row) {
    var held = heldNow(), sel = row.querySelector('[data-line-item]');
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.value) return;
      var n = held ? (held[o.value] || 0) : null;
      o.hidden = o.disabled = held !== null && n <= 0 && o.value !== sel.value;
      o.textContent = o.textContent.replace(/ \(има \d+\)$/, '') + (n ? ' (има ' + n + ')' : '');
    });
    var n = held && sel.value ? (held[sel.value] || 0) : null;
    row.querySelector('[data-line-hint]').textContent = n === null ? '' : 'при дистрибутора: ' + n + ' непродадени';
    var qty = row.querySelector('[data-line-qty]');
    if (n !== null) qty.max = n; else qty.removeAttribute('max');
  };
  function refreshSale() {
    var held = heldNow();
    var count = held ? Object.keys(held).length : 0;
    if (saleHeld) saleHeld.textContent = !held ? 'Изберете дистрибутор — в списъка с продукти остават само тези, които той има.'
      : (count ? 'Дистрибуторът има непродадени бройки от ' + count + (count === 1 ? ' продукт.' : ' продукта.') : 'Този дистрибутор няма непродадени бройки — първо му дайте стока от „Разпределения“.');
    lineRows('sale').forEach(function (row) { lineHint.sale(row); });
  }
  if (saleDist) {
    saleDist.addEventListener('change', refreshSale);
    refreshSale();
    document.getElementById('saleUseAll').addEventListener('click', function () {
      var held = heldNow();
      if (!held) { saleDist.focus(); return; }
      // One line per product they hold, each with everything they still have.
      lineRows('sale').forEach(function (row, i) { if (i > 0) row.remove(); });
      var keys = Object.keys(held), first = lineRows('sale')[0];
      keys.forEach(function (key, i) {
        if (i === 0) { first.querySelector('[data-line-item]').value = key; first.querySelector('[data-line-qty]').value = held[key]; lineHint.sale(first); }
        else addLine('sale', key, held[key]);
      });
      if (first) first.querySelector('[data-line-qty]').focus();
    });
  }

  // „Поправи наличността“: show what the shop has now for the chosen product.
  var stockItem = document.getElementById('stockItem');
  if (stockItem) stockItem.addEventListener('change', function () {
    var n = itemData[stockItem.value];
    document.getElementById('distStockHint').textContent = n ? 'сега в магазина: ' + n.shop + ' бр.' : '';
    if (n) document.getElementById('distStockQty').value = n.shop;
  });

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
  // After saving or filtering, the page opens on its tab: show the tabs, not the top of the page.
  if (tabKeys.indexOf(location.hash.slice(1)) !== -1) {
    // Instant and measured: the admin's smooth scrolling made scrollIntoView overshoot.
    var nav = document.getElementById('distTabs');
    // Leave room for the admin's sticky top bar, so the tabs aren't hidden under it.
    var bar = document.querySelector('.admin-topbar');
    var pos = bar ? getComputedStyle(bar).position : '';
    var gap = (pos === 'sticky' || pos === 'fixed' ? bar.getBoundingClientRect().height : 0) + 16;
    if (nav) window.scrollTo({ top: window.scrollY + nav.getBoundingClientRect().top - gap, behavior: 'instant' });
  }
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
