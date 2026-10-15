<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

$done  = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_exceeded('admin_forgot', 3, 3600)) {
        $email = trim($_POST['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            admin_send_password_link($email, 'forgot');
            // Always show the same message to avoid revealing whether email exists
        }
    }
    $done = true;
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Забравена парола — <?= h(SITE_NAME_BG) ?> Admin</title>
  <link rel="stylesheet" href="<?= h(versioned_asset('/assets/css/main.css')) ?>">
  <link rel="stylesheet" href="<?= h(versioned_asset('/admin/assets/admin.css')) ?>">
</head>
<body class="admin-login-body">

<div class="admin-login-wrap">
  <div class="admin-login-card">
    <h1 class="admin-login-title">Забравена парола</h1>

    <?php if ($done): ?>
      <div class="admin-alert admin-alert--success">
        Ако имейлът съществува в системата, ще получите линк за нулиране.
      </div>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php" class="btn btn--outline">Обратно към вход</a>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:0.95rem;margin-bottom:1.5rem;">
        Въведете имейла си и ще получите линк за нулиране на паролата.
      </p>
      <form method="POST" action="/admin/login-forgot.php">
        <div class="form-group">
          <label for="email">Имейл</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="email">
        </div>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Изпрати линк</button>
      </form>
      <div style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php" style="font-size:0.85rem;color:var(--text-muted);">Обратно към вход</a>
      </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
