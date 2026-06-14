<?php
$page_title_admin = 'Известия за грешки';
$active_nav       = 'error-alerts';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/error-alerts.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'test') {
        $email = setting_get('error_alert_email');
        if ($email === '') {
            flash_set('error', 'Добави имейл адрес преди да изпратиш тестово съобщение.');
        } else {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
            error_alert_ensure_table();
            $test_row = [
                'id'          => 0,
                'error_class' => 'TestException',
                'message'     => 'Това е тестово известие за грешка от ' . SITE_NAME_BG . ' Admin.',
                'file'        => '/var/www/html/index.php',
                'line'        => 42,
                'url'         => '/test-page',
                'user_agent'  => 'Admin test',
                'created_at'  => date('Y-m-d H:i:s'),
                'sent_at'     => null,
            ];
            setting_set('error_alert_last_hash',    '');
            setting_set('error_alert_last_hash_at', '');
            error_alert_send_immediate($test_row, $email);
            flash_set('success', 'Тестов имейл е изпратен на ' . $email . '.');
        }
    } else {
        $enabled   = isset($_POST['enabled']) ? '1' : '0';
        $raw_email = trim($_POST['email'] ?? '');
        $email     = filter_var($raw_email, FILTER_VALIDATE_EMAIL) !== false ? $raw_email : '';
        $freq_map  = ['immediate' => true, 'daily' => true, 'weekly' => true];
        $freq      = isset($freq_map[$_POST['frequency'] ?? '']) ? $_POST['frequency'] : 'immediate';

        setting_set('error_alert_enabled',   $enabled);
        setting_set('error_alert_email',     $email);
        setting_set('error_alert_frequency', $freq);
        flash_set('success', 'Настройките са запазени.');
    }
    header('Location: /admin/error-alerts.php');
    exit;
}

$enabled   = setting_get('error_alert_enabled',   '0');
$email_val = setting_get('error_alert_email',     '');
$freq      = setting_get('error_alert_frequency', 'immediate');
$flash     = flash_get();

$doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$cron_cmd = "0 8 * * * php {$doc_root}/admin/send-error-digest.php >> {$doc_root}/logs/error-digest-cron.log 2>&1";

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Известия за грешки</h1>
</div>

<?php foreach ($flash as $f): ?>
<div class="admin-alert admin-alert--<?= $f['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:1.5rem;">
  <?= h($f['message']) ?>
</div>
<?php endforeach; ?>

<div style="max-width:600px;">
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.75rem;">
    <form method="POST" id="alertForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">

      <div class="form-group" style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
        <label style="margin:0;font-weight:600;cursor:pointer;" for="enabled">
          <input type="checkbox" name="enabled" id="enabled" value="1"
                 <?= $enabled === '1' ? 'checked' : '' ?>
                 style="width:1rem;height:1rem;margin-right:.4rem;cursor:pointer;">
          Активирай известията за грешки
        </label>
      </div>

      <div class="form-group">
        <label for="alert_email">Имейл за известия</label>
        <input type="email" name="email" id="alert_email"
               value="<?= h($email_val) ?>"
               placeholder="admin@example.com"
               style="width:100%;box-sizing:border-box;">
      </div>

      <div class="form-group">
        <label>Честота на известията</label>
        <div style="display:flex;flex-direction:column;gap:.6rem;margin-top:.4rem;">
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="immediate"
                   <?= $freq === 'immediate' ? 'checked' : '' ?>>
            При всяка грешка (незабавно)
          </label>
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="daily"
                   <?= $freq === 'daily' ? 'checked' : '' ?>>
            Веднъж дневно — обобщение в 08:00
          </label>
          <label style="cursor:pointer;font-weight:400;">
            <input type="radio" name="frequency" value="weekly"
                   <?= $freq === 'weekly' ? 'checked' : '' ?>>
            Веднъж седмично — обобщение в понеделник в 08:00
          </label>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;align-items:center;margin-top:1.5rem;">
        <button type="submit" class="btn btn--primary">Запази</button>
        <button type="submit" form="testForm" class="btn btn--outline">Изпрати тестов имейл</button>
      </div>
    </form>

    <form method="POST" id="testForm" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
    </form>
  </div>

  <?php if ($freq === 'daily' || $freq === 'weekly'): ?>
  <div style="background:#e4f0f5;border:1px solid #b3d4e0;border-radius:var(--radius-lg);padding:1.25rem 1.5rem;margin-top:1.5rem;">
    <p style="margin:0 0 .75rem;font-weight:600;font-size:.9rem;">Настройка на Cron</p>
    <p style="margin:0 0 .75rem;font-size:.85rem;color:var(--text-muted);">
      Добави следния ред в crontab на сървъра (чрез <code>crontab -e</code>):
    </p>
    <code style="display:block;background:#fff;border:1px solid #b3d4e0;border-radius:6px;padding:.75rem 1rem;font-size:.78rem;word-break:break-all;">
      <?= h($cron_cmd) ?>
    </code>
    <p style="margin:.75rem 0 0;font-size:.8rem;color:var(--text-muted);">
      <?= $freq === 'daily'
          ? 'Скриптът се изпълнява всеки ден в 08:00 и изпраща грешките от последните 24 часа.'
          : 'Скриптът се изпълнява всеки ден в 08:00; обобщение се изпраща само в понеделник.' ?>
    </p>
  </div>
  <?php endif; ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
