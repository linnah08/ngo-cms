<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

if (admin_logged_in() && admin_can_manage_iris()) {
    header('Location: /iris/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (rate_limit_exceeded('iris_login', 10, 900)) {
        $error = 'Твърде много неуспешни опити. Моля изчакайте 15 минути.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        if (admin_login($email, $password)) {
            if (admin_can_manage_iris()) {
                header('Location: /iris/');
                exit;
            }
            admin_logout();
            $error = 'Нямате достъп до тази страница.';
        } else {
            $error = 'Невалиден имейл или парола.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ЦСРИ Ирис — Вход</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login-body">

<div class="admin-login-wrap">
  <div class="admin-login-card">
    <h1 class="admin-login-title" style="font-size:1.1rem;margin-bottom:.25rem;">ЦСРИ Ирис</h1>
    <p style="text-align:center;color:var(--text-muted);font-size:.85rem;margin-bottom:1.5rem;">Сертификати за дарение</p>

    <?php if ($error): ?>
      <div class="admin-alert admin-alert--error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/iris/login.php">
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
  </div>
</div>

</body>
</html>
