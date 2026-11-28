<?php
/**
 * Issue an invoice for a sale made outside the store (e.g. a phone/email order,
 * a market stall, a bank transfer). Creates a synthetic order row so the invoice
 * integrates with the normal order view, re-download and credit-note flow, and
 * draws its number from the SAME `document_sequences` counter as web invoices —
 * so the issuing order of all invoices stays unbroken.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/InvoiceGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/manual_invoice.php';

admin_require_shop();

$pdo     = get_pdo();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    // ── Recipient (B2B customer) ──────────────────────────────────────────────
    $company_name    = trim($_POST['company_name']    ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $eik             = trim($_POST['eik']             ?? '');
    $vat_number      = trim($_POST['vat_number']      ?? '');
    $mol             = trim($_POST['mol']             ?? '');
    $email           = trim($_POST['email']           ?? '');
    $invoice_date    = trim($_POST['invoice_date']    ?? date('Y-m-d'));
    $payment_method  = in_array($_POST['payment_method'] ?? '', ['cod', 'bank_transfer', 'card'], true)
                           ? $_POST['payment_method'] : 'bank_transfer';
    $shipping_eur    = round((float)($_POST['shipping_eur'] ?? 0), 2);

    // ── Line items (free text, or a shop product — includes/manual_invoice.php) ──
    $items        = manual_invoice_items($pdo, $_POST);
    $subtotal_eur = round(array_sum(array_column($items, 'subtotal_eur')), 2);
    $total_eur = round($subtotal_eur + $shipping_eur, 2);

    // ── Validate ──────────────────────────────────────────────────────────────
    if ($company_name === '') $errors[] = 'Моля, въведете наименование на получателя.';
    if ($eik === '') {
        $errors[] = 'Моля, въведете ЕИК / Булстат.';
    } elseif (($eik_error = org_eik_error($eik)) !== null) {
        $errors[] = $eik_error;
    } else {
        $eik = org_eik_normalize($eik);
    }
    if (empty($items))        $errors[] = 'Моля, добавете поне един артикул.';
    if ($total_eur < 0.01)    $errors[] = 'Сумата на фактурата трябва да е по-голяма от нула.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Невалиден имейл адрес.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice_date)) {
        $errors[] = 'Невалидна дата.';
    }

    // ── Create synthetic order row ────────────────────────────────────────────
    if (empty($errors)) {
        $invoice_data = [
            'needs_invoice'   => true,
            'company_name'    => $company_name,
            'company_address' => $company_address,
            'eik'             => $eik,
            'vat_number'      => $vat_number,
            'mol'             => $mol,
        ];
        $order_number = generate_order_number();
        $stock_short  = [];
        try {
            // The order and its stock change are saved together, or not at all.
            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO orders
                    (order_number, type, status, customer_name, customer_email,
                     items, subtotal_eur, shipping_eur, total_eur,
                     payment_method, payment_status, invoice_data, created_at)
                VALUES (?, 'physical', 'confirmed', ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
            ")->execute([
                $order_number,
                $company_name,
                $email,
                json_encode($items),
                $subtotal_eur,
                $shipping_eur,
                $total_eur,
                $payment_method,
                json_encode($invoice_data),
                $invoice_date . ' 00:00:00',
            ]);
            $order_id    = (int)$pdo->lastInsertId();
            $stock_short = manual_invoice_take_stock($pdo, $items);
            $pdo->commit();
            if ($stock_short) {
                // Shown once on the order page (admin/order-view.php), under the success message.
                start_session();
                $_SESSION['stock_warning'][$order_id] = $stock_short;
            }
        } catch (ManualInvoiceStockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }

    // ── Assign the next invoice number (shared counter) ───────────────────────
    if (empty($errors)) {
        try {
            $doc_number = document_sequence_next($pdo, 'invoice');
        } catch (Throwable $e) {
            $errors[] = 'Грешка при генериране на номер: ' . $e->getMessage();
        }
    }

    // ── Generate the PDF and record the document ──────────────────────────────
    if (empty($errors)) {
        $doc_formatted = str_pad((string)$doc_number, 10, '0', STR_PAD_LEFT);
        $year     = date('Y', strtotime($invoice_date));
        $dir      = $_SERVER['DOCUMENT_ROOT'] . '/documents/invoices/' . $year;
        $filename = $doc_formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $order_number) . '.pdf';
        $filepath = $dir . '/' . $filename;
        $rel_path = 'documents/invoices/' . $year . '/' . $filename;

        // nosemgrep: tainted-filename — year from date(); number is a padded sequence; order_number cleaned to [a-z0-9_-]
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $errors[] = 'Грешка: не може да се създаде директория за документи.';
        }
    }

    if (empty($errors)) {
        try {
            $order_row = [
                'order_number'   => $order_number,
                'customer_name'  => $company_name,
                'customer_email' => $email,
                'subtotal_eur'   => $subtotal_eur,
                'shipping_eur'   => $shipping_eur,
                'total_eur'      => $total_eur,
                'payment_method' => $payment_method,
                'created_at'     => $invoice_date . ' 00:00:00',
                'invoice_data'   => json_encode($invoice_data),
            ];
            $document  = ['number' => $doc_number, 'formatted_number' => $doc_formatted, 'type' => 'invoice'];

            $generator = new InvoiceGenerator();
            $pdf_bytes = $generator->generate($order_row, $items, $document);

            // nosemgrep: tainted-filename — year from date(); number is a padded sequence; order_number cleaned to [a-z0-9_-]
            if (file_put_contents($filepath, $pdf_bytes) === false) {
                throw new RuntimeException("file_put_contents failed for {$filepath}");
            }

            $pdo->prepare('
                INSERT INTO documents (order_id, type, number, formatted_number, file_path)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$order_id, 'invoice', $doc_number, $doc_formatted, $rel_path]);

            header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode('Фактура № ' . $doc_formatted . ' е генерирана успешно.'));
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Грешка при генериране на PDF: ' . $e->getMessage();
        }
    }
}

// Shop products for the line picker: active first, then inactive.
$product_options = manual_invoice_product_options($pdo);

/** One line-item row; also rendered empty into the <template> addRow() clones. */
$render_item_row = function (string $product = '', string $name = '', string $qty = '1', string $price = '') use ($product_options): void {
    $groups = ['Активни продукти' => $product_options['active'], 'Неактивни продукти' => $product_options['inactive']];
    ?>
    <div class="mi-row" style="border:1px solid var(--border);border-radius:8px;padding:.75rem;margin-bottom:.75rem;">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.75rem;margin-bottom:.5rem;">
        <label style="display:block;margin:0;">
          <span class="form-label" style="display:block;">Търси продукт</span>
          <input type="search" class="form-input mi-search" autocomplete="off" placeholder="Напишете част от името">
        </label>
        <label style="display:block;margin:0;">
          <span class="form-label" style="display:block;">Продукт от магазина</span>
          <select name="item_product[]" class="form-input mi-product">
            <option value="">— Без продукт (въведете ръчно) —</option>
            <?php foreach ($groups as $label => $entries): if (!$entries) continue; ?>
            <optgroup label="<?= h($label) ?>">
              <?php foreach ($entries as $e): ?>
              <option value="<?= h($e['value']) ?>" <?= $product === $e['value'] ? 'selected' : '' ?>><?= h(manual_invoice_option_label($e)) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <p class="mi-count" role="status" aria-live="polite" style="margin:0 0 .5rem;font-size:.8rem;color:var(--text-muted);min-height:1em;"></p>
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 5.5rem 8rem 44px;gap:.5rem;align-items:end;">
        <label style="display:block;margin:0;">
          <span class="form-label" style="display:block;">Наименование</span>
          <input type="text" name="item_name[]" class="form-input" value="<?= h($name) ?>" placeholder="Описание">
        </label>
        <label style="display:block;margin:0;">
          <span class="form-label" style="display:block;">К-во</span>
          <input type="number" name="item_qty[]" class="form-input" style="padding-left:.6rem;padding-right:.4rem;" min="1" step="1" value="<?= h($qty) ?>">
        </label>
        <label style="display:block;margin:0;">
          <span class="form-label" style="display:block;">Ед. цена (EUR)</span>
          <input type="number" name="item_price[]" class="form-input" style="padding-left:.6rem;padding-right:.4rem;" min="0" step="0.01" value="<?= h($price) ?>" placeholder="0.00">
        </label>
        <button type="button" onclick="removeRow(this)" aria-label="Премахни артикул"
                style="min-width:44px;min-height:44px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.1rem;line-height:1;"><span aria-hidden="true">×</span></button>
      </div>
    </div>
    <?php
};

$page_title_admin = 'Нова фактура';
$active_nav       = 'orders';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Нова фактура</h1>
  <a href="/admin/orders.php" class="btn btn--outline">← Назад</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert--error" style="margin-bottom:1.5rem;">
  <?php foreach ($errors as $e): ?>
    <div><?= h($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p style="max-width:640px;color:var(--text-muted);margin-bottom:1.25rem;">
  За продажба, направена извън сайта (по телефон, на място, по банков път). Фактурата
  получава следващия номер от общата редица — точно както фактурите от онлайн поръчките.
</p>

<div style="max-width:640px;">
  <div class="admin-card" style="padding:1.75rem;">
    <form method="POST">
      <?= csrf_field() ?>

      <!-- Recipient -->
      <h2 style="font-size:.95rem;margin:0 0 1rem;">Получател (фирма)</h2>
      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label" for="company_name">Наименование на фирмата</label>
        <input type="text" id="company_name" name="company_name" class="form-input"
               value="<?= h($_POST['company_name'] ?? '') ?>" placeholder="Примерна Фирма ЕООД" required>
      </div>
      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label" for="company_address">Адрес</label>
        <input type="text" id="company_address" name="company_address" class="form-input"
               value="<?= h($_POST['company_address'] ?? '') ?>" placeholder="гр. София, ул. Примерна 1">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <div class="form-group">
          <label class="form-label" for="eik">ЕИК / Булстат</label>
          <input type="text" id="eik" name="eik" class="form-input"
                 value="<?= h($_POST['eik'] ?? '') ?>" placeholder="9 цифри" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="vat_number">ДДС номер <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
          <input type="text" id="vat_number" name="vat_number" class="form-input"
                 value="<?= h($_POST['vat_number'] ?? '') ?>" placeholder="BG123456789">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
        <div class="form-group">
          <label class="form-label" for="mol">МОЛ <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
          <input type="text" id="mol" name="mol" class="form-input"
                 value="<?= h($_POST['mol'] ?? '') ?>" placeholder="Управител">
        </div>
        <div class="form-group">
          <label class="form-label" for="email">Имейл <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
          <input type="email" id="email" name="email" class="form-input"
                 value="<?= h($_POST['email'] ?? '') ?>" placeholder="office@example.com">
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

      <!-- Line items -->
      <h2 style="font-size:.95rem;margin:0 0 1rem;">Артикули</h2>
      <p style="margin:0 0 1rem;font-size:.85rem;color:var(--text-muted);">
        Изберете продукт от магазина — името и цената се попълват сами и можете да ги промените.
        Продуктът се изважда от наличността. Без продукт редът се въвежда ръчно и не променя наличността.
      </p>
      <div id="itemsBody">
        <?php
        // Re-render submitted rows on validation error, else one blank row
        $post_names    = $_POST['item_name']    ?? [''];
        $post_products = $_POST['item_product'] ?? [];
        $post_qtys     = $_POST['item_qty']     ?? ['1'];
        $post_prices   = $_POST['item_price']   ?? [''];
        foreach ($post_names as $i => $pn) {
            $render_item_row((string)($post_products[$i] ?? ''), (string)$pn, (string)($post_qtys[$i] ?? '1'), (string)($post_prices[$i] ?? ''));
        }
        ?>
      </div>
      <template id="itemRowTpl"><?php $render_item_row(); ?></template>
      <button type="button" onclick="addRow()" class="btn btn--outline" style="font-size:.85rem;padding:.35rem .9rem;margin-bottom:1.25rem;">+ Артикул</button>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

      <!-- Shipping / date / payment -->
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
        <div class="form-group">
          <label class="form-label" for="shipping_eur">Доставка (EUR)</label>
          <input type="number" id="shipping_eur" name="shipping_eur" class="form-input" min="0" step="0.01"
                 value="<?= h($_POST['shipping_eur'] ?? '0') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="invoice_date">Дата</label>
          <input type="date" id="invoice_date" name="invoice_date" class="form-input"
                 value="<?= h($_POST['invoice_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="payment_method">Плащане</label>
          <select id="payment_method" name="payment_method" class="form-input">
            <?php
            $pm = $_POST['payment_method'] ?? 'bank_transfer';
            $methods = ['bank_transfer' => 'Банков превод', 'card' => 'Банкова карта', 'cod' => 'В брой'];
            foreach ($methods as $val => $label): ?>
              <option value="<?= $val ?>" <?= $pm === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;padding:.75rem;">
        Генерирай фактура
      </button>
    </form>
  </div>
</div>

<script>
// Picker entries for the search box: [{value, name_bg, price_eur, label}], grouped.
var MI_GROUPS = <?= json_encode([
    ['label' => 'Активни продукти',   'entries' => array_map(fn($e) => ['value' => $e['value'], 'name' => $e['name_bg'], 'price' => $e['price_eur'], 'label' => manual_invoice_option_label($e)], $product_options['active'])],
    ['label' => 'Неактивни продукти', 'entries' => array_map(fn($e) => ['value' => $e['value'], 'name' => $e['name_bg'], 'price' => $e['price_eur'], 'label' => manual_invoice_option_label($e)], $product_options['inactive'])],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function miEntry(value) {
  for (var g = 0; g < MI_GROUPS.length; g++)
    for (var i = 0; i < MI_GROUPS[g].entries.length; i++)
      if (MI_GROUPS[g].entries[i].value === value) return MI_GROUPS[g].entries[i];
  return null;
}

// Rebuild a row's product list to the entries matching the search text.
// The chosen product always stays in the list, so filtering never loses it.
function miFilter(row) {
  var select = row.querySelector('.mi-product');
  var query  = row.querySelector('.mi-search').value.trim().toLowerCase();
  var chosen = select.value;
  while (select.options.length > 1) select.remove(1);
  select.querySelectorAll('optgroup').forEach(function (g) { g.remove(); });
  var found = 0;
  MI_GROUPS.forEach(function (group) {
    var og = document.createElement('optgroup');
    og.label = group.label;
    group.entries.forEach(function (e) {
      var match = query === '' || e.label.toLowerCase().indexOf(query) !== -1;
      if (!match && e.value !== chosen) return;
      if (match) found++;
      var o = new Option(e.label, e.value, false, e.value === chosen);
      og.appendChild(o);
    });
    if (og.children.length) select.appendChild(og);
  });
  select.value = chosen;
  row.querySelector('.mi-count').textContent = query === ''
    ? '' : (found === 0 ? 'Няма намерени продукти.' : (found === 1 ? 'Намерен е 1 продукт.' : 'Намерени са ' + found + ' продукта.'));
}

function miWire(row) {
  var search = row.querySelector('.mi-search');
  var select = row.querySelector('.mi-product');
  search.addEventListener('input', function () { miFilter(row); });
  // Enter in the search box moves to the list instead of sending the form.
  search.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); select.focus(); }
  });
  select.addEventListener('change', function () {
    var e = miEntry(select.value);
    if (!e) return;
    row.querySelector('input[name="item_name[]"]').value  = e.name;
    row.querySelector('input[name="item_price[]"]').value = Number(e.price).toFixed(2);
  });
}

function addRow() {
  var body = document.getElementById('itemsBody');
  var row  = document.getElementById('itemRowTpl').content.firstElementChild.cloneNode(true);
  body.appendChild(row);
  miWire(row);
  row.querySelector('.mi-search').focus();
}
function removeRow(btn) {
  var body = document.getElementById('itemsBody');
  var row  = btn.closest('.mi-row');
  if (body.querySelectorAll('.mi-row').length > 1) { row.remove(); return; }
  row.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_qty[]' ? '1' : ''; });
  row.querySelector('.mi-product').value = '';
  miFilter(row);
}
document.querySelectorAll('#itemsBody .mi-row').forEach(miWire);
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
