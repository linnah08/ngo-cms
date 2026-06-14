<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $pass1    = $_POST['password']  ?? '';
    $pass2    = $_POST['password2'] ?? '';
    if (strlen($pass1) < 8) {
        $error = 'Паролата трябва да е поне 8 символа.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Паролите не съвпадат.';
    } elseif (!admin_reset_password($token, $pass1)) {
        $error = 'Линкът е невалиден или е изтекъл.';
    } else {
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Нулиране на парола — Odd Minds Admin</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login-body">

<div class="admin-login-wrap">
  <div class="admin-login-card">
    <h1 class="admin-login-title">Нова парола</h1>

    <?php if ($done): ?>
      <div class="admin-alert admin-alert--success">Паролата е сменена успешно.</div>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php" class="btn btn--primary">Влезте сега</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="admin-alert admin-alert--error"><?= h($error) ?></div>
      <?php endif; ?>
      <?php if (!$token): ?>
        <div class="admin-alert admin-alert--error">Невалиден или липсващ токен.</div>
      <?php else: ?>
        <form method="POST" action="/admin/login-reset.php">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="form-group">
            <label for="password">Нова парола</label>
            <input type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="password2">Потвърди паролата</label>
            <input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Запази паролата</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
