<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/IrisCertGenerator.php';
admin_require_iris();

$pdo     = get_pdo();
$errors  = [];
$success = '';

// Pre-fill from existing order if order_id supplied
$order_id_param = (int)($_GET['order_id'] ?? 0);
$prefill        = [];
if ($order_id_param > 0) {
    $stmt = $pdo->prepare("
        SELECT o.*, d.id AS cert_id
        FROM orders o
        LEFT JOIN documents d ON d.order_id = o.id AND d.type = 'iris_donation_cert'
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id_param]);
    $source_order = $stmt->fetch();
    if ($source_order) {
        $inv = json_decode($source_order['invoice_data'] ?? '{}', true) ?? [];
        $items_arr = json_decode($source_order['items'] ?? '[]', true) ?? [];
        $don_amount = 0.0;
        foreach ($items_arr as $item) {
            if (($item['type'] ?? '') === 'donation') $don_amount += (float)($item['amount_eur'] ?? 0);
        }
        $prefill = [
            'order_id'       => $source_order['id'],
            'donor_type'     => $inv['donor_type'] ?? 'individual',
            'donor_name'     => $inv['donor_type'] === 'company' ? '' : $source_order['customer_name'],
            'company_name'   => $inv['company_name'] ?? '',
            'eik'            => $inv['eik'] ?? '',
            'vat_number'     => $inv['vat_number'] ?? '',
            'mol'            => $inv['mol'] ?? '',
            'email'          => $source_order['customer_email'],
            'amount_eur'     => $don_amount > 0 ? number_format($don_amount, 2, '.', '') : number_format((float)$source_order['total_eur'], 2, '.', ''),
            'payment_method' => $source_order['payment_method'] ?? 'card',
            'donation_date'  => substr($source_order['created_at'], 0, 10),
            'cert_lang'      => $inv['lang'] ?? 'bg',
        ];
    }
}

// Next suggested cert number
$seq = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ?');
$seq->execute(['iris_donation_cert']);
$seq_row     = $seq->fetch();
$next_number = $seq_row ? (int)$seq_row['last_number'] + 1 : 1;

// ── POST handling ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $cert_lang      = in_array($_POST['cert_lang'] ?? '', ['bg', 'en'], true) ? $_POST['cert_lang'] : 'bg';
    $donor_type     = in_array($_POST['donor_type'] ?? '', ['individual', 'company'], true) ? $_POST['donor_type'] : 'individual';
    $donor_name     = trim($_POST['donor_name']    ?? '');
    $company_name   = trim($_POST['company_name']  ?? '');
    $eik            = trim($_POST['eik']            ?? '');
    $vat_number     = trim($_POST['vat_number']    ?? '');
    $mol_field      = trim($_POST['mol']            ?? '');
    $email          = trim($_POST['email']          ?? '');
    $amount_eur     = round((float)($_POST['amount_eur'] ?? 0), 2);
    $payment_method = in_array($_POST['payment_method'] ?? '', ['card', 'bank_transfer', 'cod'], true) ? $_POST['payment_method'] : 'bank_transfer';
    $donation_date  = trim($_POST['donation_date']  ?? date('Y-m-d'));
    $cert_number    = (int)($_POST['cert_number']   ?? $next_number);
    $source_order_id = (int)($_POST['source_order_id'] ?? 0);

    if ($donor_type === 'individual' && $donor_name === '') {
        $errors[] = 'Моля, въведете пълно име на дарителя.';
    }
    if ($donor_type === 'company' && $company_name === '') {
        $errors[] = 'Моля, въведете наименование на фирмата.';
    }
    if ($donor_type === 'company' && $eik === '') {
        $errors[] = 'Моля, въведете ЕИК / Булстат.';
    }
    if ($amount_eur < 0.01) {
        $errors[] = 'Моля, въведете валидна сума.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Невалиден имейл адрес.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $donation_date)) {
        $errors[] = 'Невалидна дата.';
    }
    if ($cert_number < 1) {
        $errors[] = 'Невалиден номер на сертификата.';
    }

    if (empty($errors)) {
        $invoice_data = ['donor_type' => $donor_type, 'lang' => $cert_lang];
        if ($donor_type === 'company') {
            $invoice_data['company_name'] = $company_name;
            $invoice_data['eik']          = $eik;
            if ($vat_number !== '') $invoice_data['vat_number'] = $vat_number;
            if ($mol_field   !== '') $invoice_data['mol']       = $mol_field;
        }
        $display_name = $donor_type === 'company' ? $company_name : $donor_name;

        // Use source order if coming from queue, otherwise create synthetic order
        if ($source_order_id > 0) {
            $order_id = $source_order_id;
            // Update invoice_data on source order to reflect any edits
            $pdo->prepare('UPDATE orders SET invoice_data = ? WHERE id = ?')
                ->execute([json_encode($invoice_data), $order_id]);
            $order_row_stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
            $order_row_stmt->execute([$order_id]);
            $order_row = $order_row_stmt->fetch(PDO::FETCH_ASSOC);
            $items_arr = [['type' => 'donation', 'amount_eur' => $amount_eur, 'recipient' => 'iris']];
        } else {
            // Synthetic order for manual/offline donations
            $order_number = generate_order_number();
            $items_json   = json_encode([['type' => 'donation', 'amount_eur' => $amount_eur, 'recipient' => 'iris']]);
            try {
                $pdo->prepare("
                    INSERT INTO orders
                        (order_number, type, status, customer_name, customer_email,
                         items, subtotal_eur, shipping_eur, total_eur,
                         payment_method, payment_status, invoice_data, created_at)
                    VALUES (?, 'donation', 'confirmed', ?, ?, ?, ?, 0, ?, ?, 'paid', ?, ?)
                ")->execute([
                    $order_number,
                    $display_name,
                    $email,
                    $items_json,
                    $amount_eur,
                    $amount_eur,
                    $payment_method,
                    json_encode($invoice_data),
                    $donation_date . ' 00:00:00',
                ]);
                $order_id = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                $errors[] = 'Грешка при запис: ' . $e->getMessage();
            }
            if (empty($errors)) {
                $order_row_stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
                $order_row_stmt->execute([$order_id]);
                $order_row = $order_row_stmt->fetch(PDO::FETCH_ASSOC);
                $items_arr = [['type' => 'donation', 'amount_eur' => $amount_eur, 'recipient' => 'iris']];
            }
        }
    }

    if (empty($errors)) {
        // Update sequence (upsert)
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ? FOR UPDATE');
            $lock->execute(['iris_donation_cert']);
            if ($lock->fetch() === false) {
                $pdo->prepare('INSERT INTO document_sequences (type, last_number) VALUES (?, ?)')->execute(['iris_donation_cert', $cert_number]);
            } else {
                $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')->execute([$cert_number, 'iris_donation_cert']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Грешка при генериране на номер: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $doc_formatted = str_pad((string)$cert_number, 5, '0', STR_PAD_LEFT);
        $year     = date('Y', strtotime($donation_date));
        $dir      = $_SERVER['DOCUMENT_ROOT'] . '/documents/iris_donation_certs/' . $year;
        $filename = $doc_formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $order_row['order_number'] ?? 'manual') . '.pdf';
        $filepath = $dir . '/' . $filename;
        $rel_path = 'documents/iris_donation_certs/' . $year . '/' . $filename;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $errors[] = 'Грешка: не може да се създаде директория за документи.';
        }
    }

    if (empty($errors)) {
        try {
            $doc_arr = ['formatted_number' => $doc_formatted];
            $generator = new IrisCertGenerator();
            $pdf_bytes = $generator->generate($order_row, $items_arr, $doc_arr);

            if (file_put_contents($filepath, $pdf_bytes) === false) {
                throw new RuntimeException("file_put_contents failed for {$filepath}");
            }

            $pdo->prepare('
                INSERT INTO documents (order_id, type, number, formatted_number, file_path)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$order_id, 'iris_donation_cert', $cert_number, $doc_formatted, $rel_path]);

            $iris_admin_email = admin_user()['email'];
            send_mail(
                $iris_admin_email,
                'Сертификат за дарение #' . $doc_formatted . ' — ЦСРИ Ирис',
                '<p>Сертификатът е генериран успешно. Моля намерете го приложен към този имейл.</p>',
                '',
                [['path' => $filepath, 'name' => 'cert-' . $doc_formatted . '.pdf']]
            );

            $success = 'Сертификатът е генериран и изпратен на ' . h($iris_admin_email) . '.';
            $next_number = $cert_number + 1;
            $prefill = []; // reset form
        } catch (Throwable $e) {
            $errors[] = 'Грешка при генериране на PDF: ' . $e->getMessage();
        }
    }
}

$post = $_POST;
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ЦСРИ Ирис — Нов сертификат</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>

<div style="max-width:680px;margin:0 auto;padding:2rem 1.25rem;">

  <!-- Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;padding-bottom:1rem;border-bottom:2px solid var(--border);">
    <h1 style="margin:0;font-size:1.3rem;">Нов сертификат за дарение</h1>
    <div style="display:flex;gap:1rem;align-items:center;">
      <a href="/iris/" style="font-size:.85rem;color:var(--text-muted);">← Назад</a>
      <a href="/iris/logout.php" style="font-size:.85rem;color:var(--text-muted);">Изход</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert--success" style="margin-bottom:1.5rem;"><?= $success ?></div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert--error" style="margin-bottom:1.5rem;">
      <?php foreach ($errors as $e): ?>
        <div><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="admin-card" style="padding:1.75rem;">
    <form method="POST" action="/iris/cert.php">
      <?= csrf_field() ?>
      <?php if ($order_id_param > 0 && empty($post)): ?>
        <input type="hidden" name="source_order_id" value="<?= (int)$order_id_param ?>">
      <?php elseif (!empty($post['source_order_id'])): ?>
        <input type="hidden" name="source_order_id" value="<?= (int)$post['source_order_id'] ?>">
      <?php endif; ?>

      <!-- Cert number -->
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label class="form-label">Номер на сертификата</label>
        <input type="number" name="cert_number" class="form-input" min="1" style="max-width:160px;"
               value="<?= (int)($post['cert_number'] ?? $next_number) ?>">
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

      <!-- Cert language -->
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:.5rem;">Език на сертификата</label>
        <div style="display:flex;gap:1rem;">
          <?php $cert_lang_val = $post['cert_lang'] ?? $prefill['cert_lang'] ?? 'bg'; ?>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="cert_lang" value="bg" <?= $cert_lang_val === 'bg' ? 'checked' : '' ?>>
            Български
          </label>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="cert_lang" value="en" <?= $cert_lang_val === 'en' ? 'checked' : '' ?>>
            English
          </label>
        </div>
      </div>

      <!-- Donor type -->
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:.5rem;">Вид дарител</label>
        <?php $donor_type_val = $post['donor_type'] ?? $prefill['donor_type'] ?? 'individual'; ?>
        <div style="display:flex;gap:1rem;">
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="donor_type" value="individual"
                   <?= $donor_type_val === 'individual' ? 'checked' : '' ?>
                   onchange="toggleDonorType()">
            Физическо лице
          </label>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="donor_type" value="company"
                   <?= $donor_type_val === 'company' ? 'checked' : '' ?>
                   onchange="toggleDonorType()">
            Юридическо лице
          </label>
        </div>
      </div>

      <!-- Individual fields -->
      <div id="individualFields">
        <div class="form-group" style="margin-bottom:1rem;">
          <label class="form-label">Пълно име</label>
          <input type="text" name="donor_name" class="form-input"
                 value="<?= h($post['donor_name'] ?? $prefill['donor_name'] ?? '') ?>"
                 placeholder="Иван Иванов Иванов">
        </div>
      </div>

      <!-- Company fields -->
      <div id="companyFields" style="display:none;">
        <div class="form-group" style="margin-bottom:1rem;">
          <label class="form-label">Наименование на фирмата</label>
          <input type="text" name="company_name" class="form-input"
                 value="<?= h($post['company_name'] ?? $prefill['company_name'] ?? '') ?>"
                 placeholder="Примерна Фирма ЕООД">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
          <div class="form-group">
            <label class="form-label">ЕИК / Булстат</label>
            <input type="text" name="eik" class="form-input"
                   value="<?= h($post['eik'] ?? $prefill['eik'] ?? '') ?>" placeholder="123456789">
          </div>
          <div class="form-group">
            <label class="form-label">ДДС номер <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
            <input type="text" name="vat_number" class="form-input"
                   value="<?= h($post['vat_number'] ?? $prefill['vat_number'] ?? '') ?>" placeholder="BG123456789">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:1rem;">
          <label class="form-label">МОЛ <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
          <input type="text" name="mol" class="form-input"
                 value="<?= h($post['mol'] ?? $prefill['mol'] ?? '') ?>" placeholder="Управител">
        </div>
      </div>

      <!-- Email -->
      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Имейл на дарителя <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
        <input type="email" name="email" class="form-input"
               value="<?= h($post['email'] ?? $prefill['email'] ?? '') ?>" placeholder="donor@example.com">
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

      <!-- Amount + date -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <div class="form-group">
          <label class="form-label">Сума (EUR)</label>
          <input type="number" name="amount_eur" class="form-input" step="0.01" min="0.01"
                 value="<?= h($post['amount_eur'] ?? $prefill['amount_eur'] ?? '') ?>" placeholder="50.00">
        </div>
        <div class="form-group">
          <label class="form-label">Дата на получаване</label>
          <input type="date" name="donation_date" class="form-input"
                 value="<?= h($post['donation_date'] ?? $prefill['donation_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <!-- Payment method -->
      <div class="form-group" style="margin-bottom:1.5rem;">
        <label class="form-label">Начин на предаване</label>
        <?php $pm_val = $post['payment_method'] ?? $prefill['payment_method'] ?? 'bank_transfer'; ?>
        <select name="payment_method" class="form-input">
          <?php foreach (['bank_transfer' => 'Банков превод', 'card' => 'Банкова карта', 'cod' => 'В брой'] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $pm_val === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;padding:.75rem;">
        Генерирай и изпрати сертификата
      </button>
    </form>
  </div>
</div>

<script>
function toggleDonorType() {
  var type = document.querySelector('input[name="donor_type"]:checked').value;
  document.getElementById('individualFields').style.display = type === 'individual' ? '' : 'none';
  document.getElementById('companyFields').style.display    = type === 'company'    ? '' : 'none';
}
toggleDonorType();
</script>

</body>
</html>
