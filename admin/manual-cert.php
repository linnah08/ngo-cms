<?php
/**
 * Generate a donation certificate for a donation made outside the store
 * (e.g. bank transfer, cash, direct card payment).
 * Creates a synthetic order row so the cert integrates with the normal
 * order view, signing, and resend flow.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

admin_require_shop();

$pdo    = get_pdo();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    // ── Validate inputs ───────────────────────────────────────────────────────
    $cert_lang     = in_array($_POST['cert_lang'] ?? '', ['bg', 'en'], true)
                         ? $_POST['cert_lang'] : 'bg';
    $donor_type    = in_array($_POST['donor_type'] ?? '', ['individual', 'company'], true)
                         ? $_POST['donor_type'] : 'individual';
    $donor_name    = trim($_POST['donor_name']    ?? '');
    $company_name  = trim($_POST['company_name']  ?? '');
    $eik           = trim($_POST['eik']           ?? '');
    $vat_number    = trim($_POST['vat_number']    ?? '');
    $mol           = trim($_POST['mol']           ?? '');
    $email         = trim($_POST['email']         ?? '');
    $amount_eur    = round((float)($_POST['amount_eur'] ?? 0), 2);
    $payment_method = in_array($_POST['payment_method'] ?? '', ['card', 'bank_transfer', 'cod'], true)
                         ? $_POST['payment_method'] : 'bank_transfer';
    $donation_date = trim($_POST['donation_date'] ?? date('Y-m-d'));
    $recipient     = in_array($_POST['recipient'] ?? '', ['foundation', 'iris'], true)
                         ? $_POST['recipient'] : 'foundation';

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

    if (empty($errors)) {
        // ── Build invoice_data JSON (donor details for the cert) ──────────────
        $invoice_data = ['donor_type' => $donor_type, 'lang' => $cert_lang];
        if ($donor_type === 'company') {
            $invoice_data['company_name'] = $company_name;
            $invoice_data['eik']          = $eik;
            if ($vat_number !== '') $invoice_data['vat_number'] = $vat_number;
            if ($mol !== '')        $invoice_data['mol']        = $mol;
        }

        $display_name = $donor_type === 'company' ? $company_name : $donor_name;

        // ── Create synthetic orders row ───────────────────────────────────────
        $order_number = generate_order_number();
        $items_json = json_encode([[
            'type'       => 'donation',
            'amount_eur' => $amount_eur,
            'recipient'  => $recipient,
        ]]);

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
    }

    if (empty($errors)) {
        // ── Generate cert (same logic as generate-document.php) ───────────────
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ? FOR UPDATE');
            $lock->execute(['donation_cert']);
            $last = (int)$lock->fetchColumn();
            $doc_number = $last + 1;
            $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')
                ->execute([$doc_number, 'donation_cert']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Грешка при генериране на номер: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $doc_formatted = str_pad((string)$doc_number, 5, '0', STR_PAD_LEFT);
        $year     = date('Y', strtotime($donation_date));
        $dir      = $_SERVER['DOCUMENT_ROOT'] . '/documents/donation_certs/' . $year;
        $filename = $doc_formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $order_number) . '.pdf';
        $filepath = $dir . '/' . $filename;
        $rel_path = 'documents/donation_certs/' . $year . '/' . $filename;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $errors[] = 'Грешка: не може да се създаде директория за документи.';
        }
    }

    if (empty($errors)) {
        try {
            $order_row = [
                'id'            => $order_id,
                'order_number'  => $order_number,
                'customer_name' => $display_name,
                'customer_email'=> $email,
                'total_eur'     => $amount_eur,
                'payment_method'=> $payment_method,
                'created_at'    => $donation_date . ' 00:00:00',
                'invoice_data'  => json_encode($invoice_data),
            ];
            $items_arr = [['type' => 'donation', 'amount_eur' => $amount_eur, 'recipient' => $recipient]];
            $doc_arr   = ['formatted_number' => $doc_formatted];

            $generator = new DonationCertGenerator();
            $pdf_bytes = $generator->generate($order_row, $items_arr, $doc_arr);

            if (file_put_contents($filepath, $pdf_bytes) === false) {
                throw new RuntimeException("file_put_contents failed for {$filepath}");
            }

            $pdo->prepare('
                INSERT INTO documents (order_id, type, number, formatted_number, file_path)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$order_id, 'donation_cert', $doc_number, $doc_formatted, $rel_path]);

            // Notify signing admin
            send_mail(
                SIGNING_ADMIN_EMAIL,
                'Сертификат за дарение #' . $doc_formatted . ' — нужен подпис',
                render_email('cert-needs-signature', [
                    'order'    => $order_row,
                    'document' => ['formatted_number' => $doc_formatted],
                    'order_id' => $order_id,
                ])
            );

            header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode('Сертификатът е генериран успешно.'));
            exit;

        } catch (Throwable $e) {
            $errors[] = 'Грешка при генериране на PDF: ' . $e->getMessage();
        }
    }
}

$page_title_admin = 'Нов сертификат за дарение';
$active_nav       = 'orders';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Нов сертификат за дарение</h1>
  <a href="/admin/orders.php" class="btn btn--outline">← Назад</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert--error" style="margin-bottom:1.5rem;">
  <?php foreach ($errors as $e): ?>
    <div><?= h($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="max-width:640px;">
  <div class="admin-card" style="padding:1.75rem;">
    <form method="POST">
      <?= csrf_field() ?>

      <!-- Certificate language -->
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:.5rem;">Език на сертификата</label>
        <div style="display:flex;gap:1rem;">
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="cert_lang" value="bg"
                   <?= (($_POST['cert_lang'] ?? 'bg') === 'bg') ? 'checked' : '' ?>>
            Български
          </label>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="cert_lang" value="en"
                   <?= (($_POST['cert_lang'] ?? '') === 'en') ? 'checked' : '' ?>>
            English
          </label>
        </div>
      </div>

      <!-- Donor type -->
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:.5rem;">Вид дарител</label>
        <div style="display:flex;gap:1rem;">
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="donor_type" value="individual"
                   <?= (($_POST['donor_type'] ?? 'individual') === 'individual') ? 'checked' : '' ?>
                   onchange="toggleDonorType()">
            Физическо лице
          </label>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:normal;">
            <input type="radio" name="donor_type" value="company"
                   <?= (($_POST['donor_type'] ?? '') === 'company') ? 'checked' : '' ?>
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
                 value="<?= h($_POST['donor_name'] ?? '') ?>"
                 placeholder="Иван Иванов Иванов">
        </div>
      </div>

      <!-- Company fields -->
      <div id="companyFields" style="display:none;">
        <div class="form-group" style="margin-bottom:1rem;">
          <label class="form-label">Наименование на фирмата</label>
          <input type="text" name="company_name" class="form-input"
                 value="<?= h($_POST['company_name'] ?? '') ?>"
                 placeholder="Примерна Фирма ЕООД">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
          <div class="form-group">
            <label class="form-label">ЕИК / Булстат</label>
            <input type="text" name="eik" class="form-input"
                   value="<?= h($_POST['eik'] ?? '') ?>" placeholder="123456789">
          </div>
          <div class="form-group">
            <label class="form-label">ДДС номер <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
            <input type="text" name="vat_number" class="form-input"
                   value="<?= h($_POST['vat_number'] ?? '') ?>" placeholder="BG123456789">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:1rem;">
          <label class="form-label">МОЛ <span style="font-weight:400;color:var(--text-muted);">(незадължително)</span></label>
          <input type="text" name="mol" class="form-input"
                 value="<?= h($_POST['mol'] ?? '') ?>" placeholder="Управител">
        </div>
      </div>

      <!-- Email -->
      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Имейл <span style="font-weight:400;color:var(--text-muted);">(незадължително — за изпращане на сертификата)</span></label>
        <input type="email" name="email" class="form-input"
               value="<?= h($_POST['email'] ?? '') ?>" placeholder="donor@example.com">
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">

      <!-- Amount + date -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <div class="form-group">
          <label class="form-label">Сума (EUR)</label>
          <input type="number" name="amount_eur" class="form-input" step="0.01" min="0.01"
                 value="<?= h($_POST['amount_eur'] ?? '') ?>" placeholder="50.00">
        </div>
        <div class="form-group">
          <label class="form-label">Дата на получаване</label>
          <input type="date" name="donation_date" class="form-input"
                 value="<?= h($_POST['donation_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <!-- Payment method -->
      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Начин на предаване</label>
        <select name="payment_method" class="form-input">
          <?php
          $pm = $_POST['payment_method'] ?? 'bank_transfer';
          $methods = ['bank_transfer' => 'Банков превод', 'card' => 'Банкова карта', 'cod' => 'В брой'];
          foreach ($methods as $val => $label): ?>
            <option value="<?= $val ?>" <?= $pm === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Recipient/purpose -->
      <div class="form-group" style="margin-bottom:1.5rem;">
        <label class="form-label">Цел на дарението</label>
        <select name="recipient" class="form-input">
          <?php
          $rec = $_POST['recipient'] ?? 'foundation';
          $purposes = [
              'foundation' => 'За дейността и програмите на Фондация Различни Умове',
              'iris'       => 'За биофийдбек, невробийдбек и сензорни терапии — ЦСРИ Ирис',
          ];
          foreach ($purposes as $val => $label): ?>
            <option value="<?= $val ?>" <?= $rec === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;padding:.75rem;">
        Генерирай сертификат
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
// Init on load
toggleDonorType();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
