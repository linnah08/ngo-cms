<?php
$page_title_admin = 'Имейл';
$active_nav       = 'email-settings';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
admin_require_admin();

$errors       = [];
$success      = '';
$test_result  = null; // null | ['ok' => bool, 'msg' => string]
$form         = null; // re-populated values after a failed save (never includes the password)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Страницата беше отворена твърде дълго и сесията изтече. Презаредете страницата и опитайте отново.';
    } else {
        $section = is_string($_POST['section'] ?? null) ? $_POST['section'] : '';

        // ── Save SMTP settings ───────────────────────────────────────────────
        if ($section === 'smtp') {
            $result = mail_smtp_validate_input($_POST);
            if ($result['errors']) {
                $errors = $result['errors'];
                $form   = $result['values'];
                unset($form['smtp_password']);
            } else {
                mail_smtp_settings_save($result['values']);
                $success = 'Настройките бяха запазени. Натиснете „Изпрати тестов имейл“, за да проверите дали работят.';
            }
        }

        // ── Remove SMTP settings ─────────────────────────────────────────────
        if ($section === 'clear') {
            mail_smtp_settings_clear();
            $success = 'SMTP настройките бяха изтрити.';
        }

        // ── Send a test email to the logged-in admin ─────────────────────────
        if ($section === 'test') {
            $to = (string) (admin_user()['email'] ?? '');
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $test_result = ['ok' => false, 'msg' => 'Вашият профил няма валиден имейл адрес, на който да изпратим тестовия имейл.'];
            } elseif (!mail_is_configured()) {
                $test_result = ['ok' => false, 'msg' => 'Все още няма настроен начин за изпращане на имейли. Попълнете и запазете настройките по-долу, след това опитайте отново.'];
            } elseif (rate_limit_exceeded('email_settings_test', 10, 600)) {
                $test_result = ['ok' => false, 'msg' => 'Изпратихте много тестови имейли за кратко време. Изчакайте няколко минути и опитайте отново.'];
            } else {
                $site = defined('SITE_NAME_BG') ? SITE_NAME_BG : 'сайта';
                $body = email_wrap(
                    '<h2>Тестов имейл</h2>'
                    . '<p>Ако четете това, имейлите от ' . h($site) . ' се изпращат успешно.</p>'
                    . '<p style="color:#6b6560;font-size:13px;">Изпратен от страница „Имейл“ в администрацията, ' . h(date('d.m.Y H:i')) . '.</p>'
                );
                $transport = mail_transport();
                if (send_mail($to, 'Тестов имейл от ' . $site, $body)) {
                    $test_result = ['ok' => true, 'msg' => 'Тестовият имейл беше изпратен до ' . $to . '. Проверете пощата си (и папката „Спам“).'];
                } else {
                    // Raw details are already in the error log (send_mail logs them); show only a plain explanation.
                    $msg = $transport === 'graph'
                        ? 'Microsoft 365 не изпрати имейла. Свържете се с човека, който е настроил Microsoft 365 за сайта, или въведете SMTP настройки по-долу.'
                        : mail_explain_error(mail_last_error());
                    $test_result = ['ok' => false, 'msg' => $msg];
                }
            }
        }
    }
}

// Current values for the form (password is never loaded into the page).
$cfg          = mail_smtp_config();
$has_password = setting_is_set('smtp_password');
$smtp_saved   = mail_smtp_config_is_complete($cfg);
$transport    = mail_transport();

$val = static function (string $key, string $current) use ($form): string {
    return $form !== null && array_key_exists($key, $form) ? (string) $form[$key] : $current;
};
$v_host  = $val('smtp_host', $cfg['host']);
$v_enc   = $val('smtp_encryption', $smtp_saved ? $cfg['encryption'] : 'ssl');
$v_port  = $val('smtp_port', $smtp_saved ? (string) $cfg['port'] : '465');
$v_user  = $val('smtp_username', $cfg['username']);
$v_femail = $val('mail_from_email', $cfg['from_email']);
$v_fname  = $val('mail_from_name', $cfg['from_name']);
if ($v_port === '0') $v_port = '';

$admin_email   = (string) (admin_user()['email'] ?? '');
$site_email    = defined('SITE_EMAIL') ? (string) SITE_EMAIL : '';
$site_name     = defined('SITE_NAME_BG') ? (string) SITE_NAME_BG : '';

$box_ok  = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;';
$box_err = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;';
$box_warn = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;background:#fef3cd;border:1px solid #f0d98c;color:#856404;';

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if ($success !== ''): ?>
  <div class="admin-alert admin-alert--success" role="status" style="<?= $box_ok ?>"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="<?= $box_err ?>">
    <strong>Настройките не бяха запазени:</strong>
    <ul style="margin:.4rem 0 0 1.1rem;padding:0;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($test_result !== null): ?>
  <div class="admin-alert <?= $test_result['ok'] ? 'admin-alert--success' : 'admin-alert--error' ?>" role="<?= $test_result['ok'] ? 'status' : 'alert' ?>"
       style="<?= $test_result['ok'] ? $box_ok : $box_err ?>">
    <strong><?= $test_result['ok'] ? 'Успех.' : 'Тестовият имейл не беше изпратен.' ?></strong>
    <?= h($test_result['msg']) ?>
  </div>
<?php endif; ?>

<!-- ── Status ──────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Как се изпращат имейлите в момента</h2>
  <?php if ($transport === 'none'): ?>
    <div style="<?= $box_warn ?>margin-bottom:.75rem;">
      <strong>Имейлите не се изпращат.</strong>
      Писма за забравена парола, потвърждения на поръчки и съобщения от формата за контакт няма да стигат до никого,
      докато не попълните настройките по-долу.
    </div>
  <?php else: ?>
    <p style="margin:0 0 .75rem;">
      <span class="badge badge--published" style="display:inline-block;padding:.2rem .6rem;border-radius:10px;background:#e6f4ea;color:#2d6a35;font-weight:600;">
        <?= h(mail_transport_label($transport)) ?>
      </span>
    </p>
    <?php if ($transport === 'graph'): ?>
      <p class="admin-meta" style="margin:0 0 .75rem;">Ако попълните SMTP настройките по-долу, сайтът ще започне да изпраща чрез тях вместо чрез Microsoft 365.</p>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="test">
    <button type="submit" class="btn btn--outline">Изпрати тестов имейл</button>
    <span class="admin-meta" style="margin:0;">
      <?php if ($admin_email !== ''): ?>
        Ще изпратим писмо до <strong><?= h($admin_email) ?></strong> с текущите запазени настройки.
      <?php else: ?>
        Вашият профил няма имейл адрес.
      <?php endif; ?>
    </span>
  </form>
</section>

<!-- ── SMTP settings ───────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Настройки на имейл сървъра (SMTP)</h2>
  <div class="admin-meta" style="margin-bottom:1rem;line-height:1.6;">
    Тези данни ви дава <strong>вашият хостинг доставчик</strong> (фирмата, при която е сайтът) — обикновено ги има
    в контролния панел (cPanel) в раздел „Email Accounts“ → „Connect Devices“, или можете да ги поискате от поддръжката им.<br>
    Най-често са: сървър <strong>mail.вашият-домейн.bg</strong>, порт <strong>465</strong> със защита <strong>SSL</strong>
    (или порт <strong>587</strong> с <strong>TLS</strong>), потребителско име — <strong>целият имейл адрес</strong>, и паролата на тази пощенска кутия.
  </div>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="smtp">
    <div class="admin-form-grid">
      <label>Сървър (SMTP хост)
        <input type="text" name="smtp_host" value="<?= h($v_host) ?>" placeholder="mail.example.org" required maxlength="253" autocomplete="off" spellcheck="false">
      </label>
      <label>Вид защита
        <select name="smtp_encryption" id="smtpEnc">
          <option value="ssl"  <?= $v_enc === 'ssl'  ? 'selected' : '' ?>>SSL (обикновено порт 465)</option>
          <option value="tls"  <?= $v_enc === 'tls'  ? 'selected' : '' ?>>TLS (обикновено порт 587)</option>
          <option value="none" <?= $v_enc === 'none' ? 'selected' : '' ?>>Без защита (не се препоръчва)</option>
        </select>
      </label>
      <label>Порт
        <input type="number" name="smtp_port" id="smtpPort" value="<?= h($v_port) ?>" min="1" max="65535" placeholder="465" inputmode="numeric">
      </label>
      <label>Потребителско име
        <input type="text" name="smtp_username" value="<?= h($v_user) ?>" placeholder="info@example.org" maxlength="255" autocomplete="off" spellcheck="false">
      </label>
      <label>Парола
        <div style="position:relative;">
          <input type="password" name="smtp_password" value=""
                 placeholder="<?= $has_password ? '•••••• (запазена)' : 'Паролата на пощенската кутия' ?>"
                 autocomplete="new-password" style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle"
                  style="position:absolute;right:.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:.8rem;padding:.25rem .4rem;color:var(--text-muted);">Покажи</button>
        </div>
        <?php if ($has_password): ?>
          <small class="admin-meta" style="display:block;margin-top:.25rem;">Оставете полето празно, за да запазите сегашната парола.</small>
        <?php endif; ?>
      </label>
    </div>

    <h3 style="font-size:1rem;margin:1.5rem 0 .5rem;">Подател</h3>
    <p class="admin-meta" style="margin:0 0 .75rem;">Така ще изглеждат писмата при получателя. Имейлът на подателя обикновено трябва да е същият като потребителското име по-горе.</p>
    <div class="admin-form-grid">
      <label>Имейл на подателя
        <input type="email" name="mail_from_email" value="<?= h($v_femail) ?>" placeholder="<?= h($site_email !== '' ? $site_email : 'info@example.org') ?>" maxlength="255">
      </label>
      <label>Име на подателя
        <input type="text" name="mail_from_name" value="<?= h($v_fname) ?>" placeholder="<?= h($site_name) ?>" maxlength="150">
      </label>
    </div>

    <div style="margin-top:1rem;display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;">
      <button type="submit" class="btn btn--primary">Запази</button>
      <?php if ($smtp_saved): ?>
        <span class="badge badge--published">Настроен</span>
      <?php else: ?>
        <span class="badge badge--draft">Не е настроен</span>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($smtp_saved): ?>
    <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0 1rem;">
    <form method="post" id="smtpClearForm">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="clear">
      <button type="button" class="btn btn--outline" style="color:#c0392b;border-color:#f0c4c0;"
              onclick="_adminConfirm('Да изтрия ли SMTP настройките? Сайтът ще спре да изпраща имейли чрез този сървър.', 'Изтрий').then(function(ok){ if(ok){ document.getElementById('smtpClearForm').submit(); } })">
        Изтрий SMTP настройките
      </button>
    </form>
  <?php endif; ?>
</section>

<script>
function togglePwd(btn) {
    var input = btn.previousElementSibling;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Скрий' : 'Покажи';
}
// Suggest the usual port when the encryption type changes (only if the port is empty or a default).
(function () {
    var enc = document.getElementById('smtpEnc'), port = document.getElementById('smtpPort');
    if (!enc || !port) return;
    var defaults = { ssl: '465', tls: '587', none: '25' };
    enc.addEventListener('change', function () {
        if (port.value === '' || ['465', '587', '25'].indexOf(port.value) !== -1) {
            port.value = defaults[enc.value] || '';
        }
    });
})();
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
