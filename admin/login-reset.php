<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/password-field.php';

// ?new=1 comes from a new user's welcome email: same form, worded as choosing a first password.
$is_new      = ($_GET['new'] ?? $_POST['new'] ?? '') === '1';
$token       = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$problem     = null;  // ['field', 'message'] when the password itself is not acceptable
$link_error  = false; // the link is unknown or has expired
$done        = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = (string) ($_POST['new_password']  ?? '');
    $pass2 = (string) ($_POST['new_password2'] ?? '');
    if ($problem = admin_password_problem($pass1, $pass2)) {
        // shown under its field
    } elseif (!($user = admin_reset_password($token, $pass1))) {
        $link_error = true;
    } else {
        admin_send_password_changed_notice($user['email'], (string) $user['name']);
        $done = true;
    }
}
$heading = $is_new ? 'Изберете парола' : 'Нова парола';
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($heading) ?> — <?= h(SITE_NAME_BG) ?> Admin</title>
  <link rel="stylesheet" href="<?= h(versioned_asset('/assets/css/main.css')) ?>">
  <link rel="stylesheet" href="<?= h(versioned_asset('/admin/assets/admin.css')) ?>">
</head>
<body class="admin-login-body">

<main class="admin-login-wrap">
  <div class="admin-login-card">
    <h1 class="admin-login-title"><?= h($heading) ?></h1>

    <?php if ($done): ?>
      <div class="admin-alert admin-alert--success" role="status">✔ Паролата е запазена. Вече можете да влезете с нея.</div>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php" class="btn btn--primary">Към входа</a>
      </div>
    <?php elseif (!$token || $link_error): ?>
      <div class="admin-alert admin-alert--error" role="alert">
        <?= $link_error
              ? 'Линкът е невалиден или е изтекъл. Паролата не е сменена.'
              : 'Линкът е непълен. Отворете го отново от имейла.' ?>
        Можете да поискате нов линк.
      </div>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login-forgot.php" class="btn btn--primary">Поискай нов линк</a>
      </div>
    <?php else: ?>
      <?php if ($problem): ?>
        <div class="admin-alert admin-alert--error">Паролата не беше запазена — вижте полето по-долу.</div>
      <?php endif; ?>
      <form method="POST" action="/admin/login-reset.php" novalidate>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <?php if ($is_new): ?><input type="hidden" name="new" value="1"><?php endif; ?>
        <?php $err = static fn(string $f): string => ($problem['field'] ?? '') === $f ? $problem['message'] : ''; ?>
        <?= admin_password_field('new_password', 'Нова парола', 'new-password', $err('new_password'),
              'Поне ' . ADMIN_PASSWORD_MIN . ' символа.', $err('new_password2') === '') ?>
        <?= admin_password_field('new_password2', 'Повторете новата парола', 'new-password', $err('new_password2'),
              '', $err('new_password2') !== '') ?>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Запази паролата</button>
      </form>
      <?= admin_password_field_script() ?>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
