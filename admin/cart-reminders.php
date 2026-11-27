<?php
$page_title_admin = 'Напомняния за поръчки';
$active_nav       = 'cart-reminders';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_shop();   // logged in, and an admin or shop admin (403 otherwise)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    setting_set('cart_reminder_email',  isset($_POST['cart_reminder_email'])  ? '1' : '0');
    setting_set('cart_reminder_notice', isset($_POST['cart_reminder_notice']) ? '1' : '0');
    flash_set('success', 'Настройките са запазени.');
    header('Location: /admin/cart-reminders.php');
    exit;
}

$email_on  = saved_cart_email_enabled();
$notice_on = saved_cart_notice_enabled();
$flash     = flash_get();

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Напомняния за поръчки</h1>
</div>

<?php foreach ($flash as $f): ?>
<div class="admin-alert admin-alert--<?= $f['type'] === 'success' ? 'success' : 'error' ?>" role="<?= $f['type'] === 'success' ? 'status' : 'alert' ?>" style="margin-bottom:1.5rem;">
  <?= $f['type'] === 'success' ? '✅ ' : '⚠️ ' ?><?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="max-width:600px;">
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;">
    <p style="margin:0 0 1.5rem;line-height:1.6;">Когато някой остави продукти в количката или не довърши подкрепа за кампания, сайтът може да му напомни. И двете възможности са изключени, докато не ги включите.</p>
    <form method="POST">
      <?= csrf_field() ?>

      <div class="form-group" style="margin-bottom:1.5rem;">
        <label for="cart_reminder_email" style="display:flex;align-items:flex-start;gap:.6rem;margin:0;font-weight:600;cursor:pointer;">
          <input type="checkbox" name="cart_reminder_email" id="cart_reminder_email" value="1" aria-describedby="cart_reminder_email_help"
                 <?= $email_on ? 'checked' : '' ?>
                 style="width:1.25rem;height:1.25rem;margin-top:.1rem;flex-shrink:0;cursor:pointer;">
          <span>Изпращай имейл напомняне за незавършени поръчки</span>
        </label>
        <p id="cart_reminder_email_help" style="margin:.4rem 0 0 1.85rem;font-size:.85rem;color:var(--text-muted);line-height:1.5;">
          При поръчката клиентът може да отбележи, че иска напомняне. Ако не завърши, след около ден получава един имейл с връзка към количката си.
        </p>
      </div>

      <div class="form-group" style="margin-bottom:1.5rem;">
        <label for="cart_reminder_notice" style="display:flex;align-items:flex-start;gap:.6rem;margin:0;font-weight:600;cursor:pointer;">
          <input type="checkbox" name="cart_reminder_notice" id="cart_reminder_notice" value="1" aria-describedby="cart_reminder_notice_help"
                 <?= $notice_on ? 'checked' : '' ?>
                 style="width:1.25rem;height:1.25rem;margin-top:.1rem;flex-shrink:0;cursor:pointer;">
          <span>Показвай известие „Оставихте продукти в количката“</span>
        </label>
        <p id="cart_reminder_notice_help" style="margin:.4rem 0 0 1.85rem;font-size:.85rem;color:var(--text-muted);line-height:1.5;">
          Когато клиентът се върне в сайта, му показваме кратко известие с връзка към количката му. Не изпраща имейл.
        </p>
      </div>

      <button type="submit" class="btn btn--primary">Запази</button>
    </form>
  </div>

  <p style="margin:1.25rem 0 0;font-size:.85rem;color:var(--text-muted);line-height:1.5;">
    След като включите имейл напомнянето, проверете и <a href="/admin/scheduled-jobs.php#job-cart_reminders">автоматичната задача</a>, която ги изпраща.
  </p>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
