<?php
// „Моят профил“: every signed-in user (any role) changes their own names and password here.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title_admin = 'Моят профил';
$active_nav       = 'account';

admin_require_login();

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/password-field.php';

$me      = admin_user();
$problem = null;   // ['field', 'message'] from admin_change_password()
$name_error = null;
$pdo     = get_pdo();

$row = $pdo->prepare('SELECT first_name, last_name, first_name_en, last_name_en FROM admin_users WHERE id = ?');
$row->execute([(int) ($me['id'] ?? 0)]);
$names = $row->fetch(PDO::FETCH_ASSOC) ?: array_fill_keys(ADMIN_NAME_FIELDS, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'names') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $in    = admin_names_from_input($_POST);
    $names = $in['names'];
    if (!($name_error = $in['error'])) {
        admin_save_names($pdo, (int) ($me['id'] ?? 0), $names);
        admin_session_recheck($pdo);   // the header and new articles' byline use the new name straight away
        flash_set('success', 'Имената са запазени.');
        header('Location: /admin/account.php');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    if (rate_limit_exceeded('admin_change_password', 10, 900)) {
        $problem = ['field' => '', 'message' => 'Твърде много опити. Изчакайте 15 минути и опитайте отново.'];
    } else {
        $problem = admin_change_password(
            (int) ($me['id'] ?? 0),
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password']     ?? ''),
            (string) ($_POST['new_password2']    ?? '')
        );
    }

    if ($problem === null) {
        // A new session id, so a session cookie copied before the change is worth nothing.
        session_regenerate_id(true);
        admin_send_password_changed_notice((string) ($me['email'] ?? ''), (string) ($me['name'] ?? ''));
        flash_set('success', 'Паролата е сменена. Следващия път влизайте с новата парола.');
        header('Location: /admin/account.php');
        exit;
    }
}

$flashes = flash_get();
$err     = static fn(string $f): string => ($problem['field'] ?? null) === $f ? $problem['message'] : '';

$box_ok  = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.95rem;margin-bottom:1.5rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;';
$box_err = 'padding:.9rem 1.25rem;border-radius:8px;font-size:.95rem;margin-bottom:1.5rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#a5281b;';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Моят профил</h1>
</div>

<div role="status">
<?php foreach ($flashes as $f): ?>
  <div class="admin-alert admin-alert--<?= ($f['type'] ?? '') === 'success' ? 'success' : 'error' ?>"
       style="<?= ($f['type'] ?? '') === 'success' ? $box_ok : $box_err ?>">
    <?= ($f['type'] ?? '') === 'success' ? '✔' : '✖' ?> <?= h((string) ($f['message'] ?? '')) ?>
  </div>
<?php endforeach; ?>
</div>

<?php if ($problem): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="<?= $box_err ?>">
    ✖ <strong>Паролата не беше сменена.</strong>
    <?= ($problem['field'] ?? '') === '' ? h($problem['message']) : 'Вижте отбелязаното поле по-долу.' ?>
  </div>
<?php endif; ?>

<section style="max-width:480px;margin-bottom:2.5rem;">
  <dl style="display:grid;grid-template-columns:auto 1fr;gap:.4rem 1rem;margin:0;">
    <dt style="font-weight:600;">Имейл</dt> <dd style="margin:0;word-break:break-all;"><?= h((string) ($me['email'] ?? '')) ?></dd>
  </dl>
  <p style="margin:.4rem 0 0;color:#4b5563;">С него влизате в панела.</p>
</section>

<section aria-labelledby="names-heading" style="max-width:480px;margin-bottom:2.5rem;">
  <h2 id="names-heading" style="margin-bottom:.5rem;font-size:1.25rem;">Име и фамилия</h2>
  <p style="margin:0 0 1.25rem;color:#4b5563;">
    Показват се в панела и под статиите, които пишете. Имената на латиница са за английския сайт —
    ако ги оставите празни, там се показват българските.
  </p>
  <?php if ($name_error): ?>
    <div class="admin-alert admin-alert--error" role="alert" style="<?= $box_err ?>">✖ <strong>Имената не бяха запазени.</strong> <?= h($name_error) ?></div>
  <?php endif; ?>
  <form method="POST" action="/admin/account.php" class="admin-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="names">
    <div class="form-group">
      <label for="first_name">Име</label>
      <input type="text" id="first_name" name="first_name" maxlength="100" required autocomplete="given-name"
             value="<?= h((string) $names['first_name']) ?>"<?= $name_error ? ' aria-invalid="true" autofocus' : '' ?>>
    </div>
    <div class="form-group">
      <label for="last_name">Фамилия <span style="font-weight:400;color:#4b5563;">(по желание)</span></label>
      <input type="text" id="last_name" name="last_name" maxlength="100" autocomplete="family-name"
             value="<?= h((string) $names['last_name']) ?>">
    </div>
    <div class="form-group">
      <label for="first_name_en">Име на латиница <span style="font-weight:400;color:#4b5563;">(по желание)</span></label>
      <input type="text" id="first_name_en" name="first_name_en" maxlength="100" lang="en" data-translate-from="first_name" data-translate-mode="transliterate"
             value="<?= h((string) $names['first_name_en']) ?>">
    </div>
    <div class="form-group">
      <label for="last_name_en">Фамилия на латиница <span style="font-weight:400;color:#4b5563;">(по желание)</span></label>
      <input type="text" id="last_name_en" name="last_name_en" maxlength="100" lang="en" data-translate-from="last_name" data-translate-mode="transliterate"
             value="<?= h((string) $names['last_name_en']) ?>">
    </div>
    <button type="submit" class="btn btn--primary">Запази имената</button>
  </form>
</section>

<section aria-labelledby="pw-heading" style="max-width:480px;">
  <h2 id="pw-heading" style="margin-bottom:.5rem;font-size:1.25rem;">Смяна на парола</h2>
  <p style="margin:0 0 1.25rem;color:#4b5563;">
    Не помните сегашната си парола? Излезте и натиснете „Забравена парола?“ на страницата за вход.
  </p>
  <form method="POST" action="/admin/account.php" class="admin-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <?php /* After a failed try, the cursor goes straight to the field that needs fixing. */ ?>
    <?= admin_password_field('current_password', 'Сегашна парола', 'current-password', $err('current_password'),
          '', $err('current_password') !== '') ?>
    <?= admin_password_field('new_password', 'Нова парола', 'new-password', $err('new_password'),
          'Поне ' . ADMIN_PASSWORD_MIN . ' символа.', $err('new_password') !== '') ?>
    <?= admin_password_field('new_password2', 'Повторете новата парола', 'new-password', $err('new_password2'),
          '', $err('new_password2') !== '') ?>
    <button type="submit" class="btn btn--primary">Смени паролата</button>
  </form>
</section>

<?= admin_password_field_script() ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
