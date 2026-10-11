<?php
// „Моят профил“: every signed-in user (any role) changes their own password here.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title_admin = 'Моят профил';
$active_nav       = 'account';

admin_require_login();

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/password-field.php';

$me      = admin_user();
$problem = null;   // ['field', 'message'] from admin_change_password()

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    <dt style="font-weight:600;">Име</dt>   <dd style="margin:0;"><?= h((string) ($me['name'] ?? '')) ?></dd>
    <dt style="font-weight:600;">Имейл</dt> <dd style="margin:0;word-break:break-all;"><?= h((string) ($me['email'] ?? '')) ?></dd>
  </dl>
</section>

<section aria-labelledby="pw-heading" style="max-width:480px;">
  <h2 id="pw-heading" style="margin-bottom:.5rem;font-size:1.25rem;">Смяна на парола</h2>
  <p style="margin:0 0 1.25rem;color:#4b5563;">
    Не помните сегашната си парола? Излезте и натиснете „Забравена парола?“ на страницата за вход.
  </p>
  <form method="POST" action="/admin/account.php" class="admin-form" novalidate>
    <?= csrf_field() ?>
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
