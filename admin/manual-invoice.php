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

    // ── Line items ────────────────────────────────────────────────────────────
    $names  = $_POST['item_name']  ?? [];
    $qtys   = $_POST['item_qty']   ?? [];
    $prices = $_POST['item_price'] ?? [];
    $items  = [];
    $subtotal_eur = 0.0;
    foreach ($names as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;                      // skip blank rows
        $qty   = max(1, (int)($qtys[$i] ?? 1));
        $price = round((float)($prices[$i] ?? 0), 2);
        $line  = round($price * $qty, 2);
        $subtotal_eur += $line;
        $items[] = [
            'type'         => 'product',
            'name_bg'      => $name,
            'name'         => $name,
            'quantity'     => $qty,
            'price_eur'    => $price,
            'subtotal_eur' => $line,
        ];
    }
    $total_eur = round($subtotal_eur + $shipping_eur, 2);

    // ── Validate ──────────────────────────────────────────────────────────────
    if ($company_name === '') $errors[] = 'Моля, въведете наименование на получателя.';
    if ($eik === '')          $errors[] = 'Моля, въведете ЕИК / Булстат.';
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
        try {
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
            $order_id = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }

    // ── Assign the next invoice number (shared counter) ───────────────────────
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ? FOR UPDATE');
            $lock->execute(['invoice']);
            $last = $lock->fetchColumn();
            if ($last === false) throw new RuntimeException("No sequence row for type 'invoice'");
            $doc_number = (int)$last + 1;
            $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')
                ->execute([$doc_number, 'invoice']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
                 value="<?= h($_POST['eik'] ?? '') ?>" placeholder="123456789" required>
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
      <table style="width:100%;border-collapse:collapse;margin-bottom:.75rem;">
        <thead>
          <tr style="text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);">
            <th style="padding:.25rem .5rem .25rem 0;font-weight:600;">Наименование</th>
            <th style="padding:.25rem .5rem;font-weight:600;width:5rem;">К-во</th>
            <th style="padding:.25rem .5rem;font-weight:600;width:8rem;">Ед. цена (EUR)</th>
            <th style="width:2rem;"></th>
          </tr>
        </thead>
        <tbody id="itemsBody">
          <?php
          // Re-render submitted rows on validation error, else one blank row
          $post_names  = $_POST['item_name']  ?? [''];
          $post_qtys   = $_POST['item_qty']   ?? ['1'];
          $post_prices = $_POST['item_price'] ?? [''];
          foreach ($post_names as $i => $pn): ?>
          <tr>
            <td style="padding:.25rem .5rem .25rem 0;">
              <input type="text" name="item_name[]" class="form-input" aria-label="Наименование на артикул"
                     value="<?= h($pn) ?>" placeholder="Описание">
            </td>
            <td style="padding:.25rem .5rem;">
              <input type="number" name="item_qty[]" class="form-input" aria-label="Количество" min="1" step="1"
                     value="<?= h($post_qtys[$i] ?? '1') ?>">
            </td>
            <td style="padding:.25rem .5rem;">
              <input type="number" name="item_price[]" class="form-input" aria-label="Единична цена" min="0" step="0.01"
                     value="<?= h($post_prices[$i] ?? '') ?>" placeholder="0.00">
            </td>
            <td style="padding:.25rem 0;text-align:center;">
              <button type="button" onclick="removeRow(this)" aria-label="Премахни артикул"
                      style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.1rem;line-height:1;">×</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
function addRow() {
  var body = document.getElementById('itemsBody');
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td style="padding:.25rem .5rem .25rem 0;"><input type="text" name="item_name[]" class="form-input" aria-label="Наименование на артикул" placeholder="Описание"></td>' +
    '<td style="padding:.25rem .5rem;"><input type="number" name="item_qty[]" class="form-input" aria-label="Количество" min="1" step="1" value="1"></td>' +
    '<td style="padding:.25rem .5rem;"><input type="number" name="item_price[]" class="form-input" aria-label="Единична цена" min="0" step="0.01" placeholder="0.00"></td>' +
    '<td style="padding:.25rem 0;text-align:center;"><button type="button" onclick="removeRow(this)" aria-label="Премахни артикул" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.1rem;line-height:1;">×</button></td>';
  body.appendChild(tr);
}
function removeRow(btn) {
  var body = document.getElementById('itemsBody');
  if (body.rows.length > 1) btn.closest('tr').remove();
  else btn.closest('tr').querySelectorAll('input').forEach(function(i){ i.value = i.type === 'number' && i.min === '1' ? '1' : ''; });
}
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
