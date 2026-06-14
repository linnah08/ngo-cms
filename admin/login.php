<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

if (admin_logged_in()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (rate_limit_exceeded('admin_login', 10, 900)) {
        $error = 'Твърде много неуспешни опити. Моля изчакайте 15 минути.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        if (admin_login($email, $password)) {
            header('Location: /admin/dashboard.php');
            exit;
        }
        $error = 'Невалиден имейл или парола.';
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Админ панел — <?= h(SITE_NAME_BG) ?></title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login-body">

<div class="admin-login-wrap">
  <div class="admin-login-card">
    <div class="admin-login-logo">
      <img src="/assets/images/logo.png" alt="<?= h(SITE_NAME_BG) ?>">
    </div>
    <h1 class="admin-login-title">Админ панел</h1>

    <?php if ($error): ?>
      <div class="admin-alert admin-alert--error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/login.php">
      <div class="form-group">
        <label for="email">Имейл</label>
        <input type="email" id="email" name="email"
               value="<?= h($_POST['email'] ?? '') ?>"
               required autofocus autocomplete="email">
      </div>
      <div class="form-group">
        <label for="password">Парола</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Вход</button>
    </form>

    <div style="text-align:center;margin-top:1.5rem;">
      <a href="/admin/login-forgot.php" style="font-size:0.85rem;color:var(--text-muted);">Забравена парола?</a>
    </div>
  </div>
</div>

</body>
</html>
