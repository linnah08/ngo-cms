<?php
/**
 * The one donation form (→ donation/checkout.php), in the donor's language.
 * Used by the donation page (donation/index.php, /en/donation/ wraps it) —
 * the only place a donor fills it in. Expects $lang in scope.
 *
 * After a rejected submission donation/checkout.php sends the donor back to
 * donation_path('form'); the reasons are shown inside the form (announced and
 * focused) and what they typed is filled back in.
 *
 * One general donation: there is no purpose to choose — the money supports
 * the organisation's work as a whole.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';

$_donation_pay_methods = [];
if (DSKBankPayment::isEnabled()) {
    $_donation_pay_methods['card'] = ['💳',
        t_or('donation.pay.card', 'Плащане с карта', 'Card payment', $lang),
        t_or('donation.pay.card_hint', 'Visa / Mastercard през DSK Bank', 'Visa / Mastercard via DSK Bank', $lang)];
}
if (IRISPayment::isEnabled()) {
    $_donation_pay_methods['iris'] = ['🏦',
        t_or('donation.pay.iris', 'Банков превод (Pay by Bank)', 'Bank transfer (Pay by Bank)', $lang),
        t_or('donation.pay.iris_hint', 'Директно от сметката ви през IRIS', 'Directly from your bank account via IRIS', $lang)];
}
// After a rejected submission: the reasons (shown inside the form) and what the donor typed.
$_don      = donation_form_state();
$_don_old  = $_don['old'];
$_don_val  = static fn (string $f): string => h($_don_old[$f] ?? '');
$_don_company = ($_don_old['donor_type'] ?? '') === 'company';
$_donation_pay_default = isset($_donation_pay_methods[$_don_old['payment_method'] ?? ''])
    ? $_don_old['payment_method']
    : array_key_first($_donation_pay_methods);
?>
<style>@media(max-width:640px){.company-fields-grid{grid-template-columns:1fr!important;}}</style>
<form method="POST" action="/donation/checkout.php" id="donationForm"
      style="max-width:520px;margin:0 auto;background:#fff;padding:2rem;border-radius:var(--radius-lg);">
  <?= csrf_field() ?>
  <input type="hidden" name="_lang" value="<?= h($lang) ?>">

  <?php if ($_don['errors']): ?>
  <div id="donationErrors" role="alert" tabindex="-1"
       style="margin:0 0 1.25rem;padding:.9rem 1.1rem;border:2px solid #c0392b;border-radius:var(--radius);background:#fdf0ef;color:#a93226;font-size:.9rem;">
    <p style="margin:0 0 .4rem;font-weight:700;">
      <span aria-hidden="true">⚠ </span><?= h(t_or('donation.err.title', 'Дарението не е изпратено. Моля, поправете:', 'Your donation was not sent. Please fix the following:')) ?>
    </p>
    <ul style="margin:0;padding-left:1.25rem;">
      <?php foreach ($_don['errors'] as $_e): ?>
      <li><?= h($_e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <script>document.getElementById('donationErrors').focus();</script>
  <?php endif; ?>

  <div class="form-group">
    <label for="donAmount" style="font-weight:600;font-size:.875rem;">
      <?= h(t_or('donation.amount', 'Сума', 'Amount')) ?> (€) *
    </label>
    <div style="position:relative;">
      <input type="number" id="donAmount" name="amount" min="1" max="50000" step="1" required
             value="<?= $_don_val('amount') ?>"
             placeholder="<?= h(t_or('donation.amount_ph', 'напр. 20', 'e.g. 20')) ?>"
             style="width:100%;box-sizing:border-box;padding-right:2.5rem;">
      <span aria-hidden="true" style="position:absolute;right:.85rem;top:50%;transform:translateY(-50%);font-weight:600;color:var(--text-muted);pointer-events:none;">€</span>
    </div>
  </div>
  <div class="form-group">
    <label for="donName" style="font-weight:600;font-size:.875rem;">
      <?= h(t_or('donation.name', 'Вашите имена', 'Your name')) ?> *
    </label>
    <input type="text" id="donName" name="donor_name" required autocomplete="name"
           value="<?= $_don_val('donor_name') ?>" style="width:100%;box-sizing:border-box;">
  </div>
  <div class="form-group">
    <label for="donEmail" style="font-weight:600;font-size:.875rem;">
      <?= h(t_or('donation.email', 'Имейл', 'Email')) ?> *
    </label>
    <input type="email" id="donEmail" name="donor_email" required autocomplete="email"
           value="<?= $_don_val('donor_email') ?>" style="width:100%;box-sizing:border-box;">
  </div>
  <div class="form-group">
    <label for="donMessage" style="font-weight:600;font-size:.875rem;">
      <?= h(t_or('donation.message', 'Съобщение или посвещение (по желание)', 'Message or dedication (optional)')) ?>
    </label>
    <textarea id="donMessage" name="donation_message" rows="3"
              style="width:100%;box-sizing:border-box;padding:.6rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-family:var(--font-body);font-size:.95rem;resize:vertical;"><?= $_don_val('donation_message') ?></textarea>
  </div>


  <!-- Donor type -->
  <fieldset style="border:none;padding:0;margin:0 0 1.25rem;">
    <legend style="font-weight:600;font-size:.875rem;display:block;margin-bottom:.5rem;padding:0;">
      <?= h(t_or('donation.donor_type', 'Вид дарител', 'Donor type')) ?>
    </legend>
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;min-height:44px;">
        <input type="radio" name="donor_type" value="individual" <?= $_don_company ? '' : 'checked' ?>
               aria-controls="donorCompanyFields"
               onchange="toggleDonorCompany(false)" style="accent-color:var(--teal);">
        <?= h(t_or('donation.individual', 'Физическо лице', 'Individual')) ?>
      </label>
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.9rem;min-height:44px;">
        <input type="radio" name="donor_type" value="company" <?= $_don_company ? 'checked' : '' ?>
               aria-controls="donorCompanyFields"
               onchange="toggleDonorCompany(true)" style="accent-color:var(--teal);">
        <?= h(t_or('donation.company', 'Юридическо лице (фирма)', 'Legal entity (company)')) ?>
      </label>
    </div>
  </fieldset>

  <!-- Company fields (shown only for legal entities) -->
  <div id="donorCompanyFields" style="<?= $_don_company ? '' : 'display:none;' ?>">
    <div class="form-group">
      <label for="donCompany" style="font-weight:600;font-size:.875rem;">
        <?= h(t_or('donation.company_name', 'Наименование на фирмата', 'Company name')) ?> *
      </label>
      <input type="text" id="donCompany" name="invoice_company" autocomplete="organization"
             value="<?= $_don_val('invoice_company') ?>" style="width:100%;box-sizing:border-box;">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;" class="company-fields-grid">
      <div class="form-group" style="margin-bottom:0;">
        <label for="donEik" style="font-weight:600;font-size:.875rem;">
          <?= h(t_or('donation.eik', 'ЕИК / Булстат', 'Company ID (EIK)')) ?> *
        </label>
        <input type="text" id="donEik" name="invoice_eik"
               value="<?= $_don_val('invoice_eik') ?>" style="width:100%;box-sizing:border-box;">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="donVat" style="font-weight:600;font-size:.875rem;">
          <?= h(t_or('donation.vat', 'ДДС номер', 'VAT number')) ?>
          <span style="font-weight:400;opacity:.7;">(<?= h(t_or('donation.if_applicable', 'ако е приложимо', 'if applicable')) ?>)</span>
        </label>
        <input type="text" id="donVat" name="invoice_vat"
               value="<?= $_don_val('invoice_vat') ?>" style="width:100%;box-sizing:border-box;">
      </div>
    </div>
  </div>

  <!-- Payment method -->
  <fieldset style="border:none;padding:0;margin:0 0 1.5rem;">
    <legend style="font-weight:600;font-size:.875rem;display:block;margin-bottom:.5rem;padding:0;">
      <?= h(t_or('donation.pay.legend', 'Начин на плащане', 'Payment method')) ?>
    </legend>
    <?php if (empty($_donation_pay_methods)): ?>
    <p style="padding:.85rem 1rem;border:2px solid var(--border);border-radius:var(--radius);color:#c0392b;font-size:.9rem;">
      <span aria-hidden="true">⚠ </span><?= h(t_or('donation.pay.unavailable', 'Онлайн плащането не е налично в момента. Моля свържете се с нас.', 'Online payment is not available right now. Please get in touch with us.')) ?>
    </p>
    <?php endif; ?>
    <div style="display:flex;flex-direction:column;gap:.6rem;">
      <?php foreach ($_donation_pay_methods as $_pm => $_info): ?>
      <label style="display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius);cursor:pointer;font-size:.9rem;">
        <input type="radio" name="payment_method" value="<?= h($_pm) ?>" <?= $_pm === $_donation_pay_default ? 'checked' : '' ?>
               style="width:1.05rem;height:1.05rem;accent-color:var(--teal);">
        <span aria-hidden="true" style="font-size:1.2rem;line-height:1;"><?= $_info[0] ?></span>
        <span>
          <span style="display:block;font-weight:600;"><?= h($_info[1]) ?></span>
          <span style="display:block;font-size:.8rem;color:var(--text-muted);"><?= h($_info[2]) ?></span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;margin-top:1rem;">
    <?= h(t_or('donation.submit', 'Дари сега', 'Donate now')) ?>
  </button>

  <script>
  function toggleDonorCompany(show) {
      document.getElementById('donorCompanyFields').style.display = show ? '' : 'none';
  }
  </script>
</form>
