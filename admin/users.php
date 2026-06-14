<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
$page_title_admin = 'Потребители';
$active_nav       = 'users';

admin_require_login();
admin_require_admin();

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

$success = '';
$error   = '';
$current = admin_user();
$pdo     = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name']     ?? '');
        $email = trim($_POST['email']    ?? '');
        $pass  = trim($_POST['password'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin','author','shop_admin','iris_admin']) ? $_POST['role'] : 'author';
        if (!$name || !$email || !$pass) {
            $error = 'Моля попълнете всички полета.';
        } elseif (strlen($pass) < 8) {
            $error = 'Паролата трябва да е поне 8 символа.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Невалиден имейл.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, $hash, $role]);

                send_mail(
                    $email,
                    'Добре дошли в Odd Minds Admin',
                    '<p>Здравейте, ' . h($name) . ',</p>'
                    . '<p>Акаунтът Ви за администрацията на <strong>' . SITE_NAME_BG . '</strong> е създаден.</p>'
                    . '<p><strong>Имейл:</strong> ' . h($email) . '<br>'
                    . '<strong>Временна парола:</strong> ' . h($pass) . '</p>'
                    . '<p><a href="' . SITE_URL . '/admin/login.php">Влезте в системата</a> и сменете паролата си.</p>'
                    . '<p>— Екипът на Odd Minds</p>'
                );

                $success = 'Потребителят е добавен и е изпратен имейл с достъп.';
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'Имейлът вече съществува.';
                } else {
                    $error = 'Грешка при създаване на потребител: ' . h($e->getMessage());
                }
            }
        }

    } elseif ($action === 'change_role') {
        $id   = (int) ($_POST['id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['admin','author','shop_admin','iris_admin']) ? $_POST['role'] : 'author';
        if ($id === (int)($current['id'] ?? 0)) {
            $error = 'Не може да промените собствената си роля.';
        } else {
            // Protect last admin
            if ($role !== 'admin') {
                $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role='admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $row = $pdo->prepare('SELECT role FROM admin_users WHERE id=?');
                    $row->execute([$id]);
                    $r = $row->fetch();
                    if (($r['role'] ?? '') === 'admin') {
                        $error = 'Не може да деградирате последния администратор.';
                    }
                }
            }
            if (!$error) {
                $pdo->prepare('UPDATE admin_users SET role=? WHERE id=?')->execute([$role, $id]);
                flash_set('success', 'Ролята е обновена.');
                header('Location: /admin/users.php');
                exit;
            }
        }

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int)($current['id'] ?? 0)) {
            $error = 'Не може да изтриете собствения си акаунт.';
        } else {
            $row = $pdo->prepare('SELECT role FROM admin_users WHERE id=?');
            $row->execute([$id]);
            $r = $row->fetch();
            if (($r['role'] ?? '') === 'admin') {
                $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role='admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $error = 'Не може да изтриете последния администратор.';
                }
            }
            if (!$error) {
                $pdo->prepare('DELETE FROM admin_users WHERE id=?')->execute([$id]);
                flash_set('success', 'Потребителят е изтрит.');
                header('Location: /admin/users.php');
                exit;
            }
        }

    } elseif ($action === 'reset_password') {
        $id      = (int) ($_POST['id'] ?? 0);
        $newpass = trim($_POST['new_password'] ?? '');
        if (strlen($newpass) < 8) {
            $error = 'Паролата трябва да е поне 8 символа.';
        } else {
            $hash = password_hash($newpass, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')->execute([$hash, $id]);
            $success = 'Паролата е сменена.';
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$users = $pdo->query('SELECT id, name, email, role, created_at FROM admin_users ORDER BY created_at')->fetchAll();
?>

<div class="admin-page-header">
  <h1>Потребители</h1>
</div>

<?php
$_flash = flash_get();
if ($_flash || $success || $error):
?>
<?php if ($success || ($_flash && $_flash['type'] === 'success')): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;"><?= h($success ?: $_flash['message']) ?></div>
<?php endif; ?>
<?php if ($error || ($_flash && $_flash['type'] === 'error')): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;"><?= h($error ?: $_flash['message']) ?></div>
<?php endif; ?>
<?php endif; ?>

<div class="admin-table-wrap" style="margin-bottom:2.5rem;overflow-x:hidden;">
  <table class="admin-table" style="table-layout:fixed;width:100%;">
    <colgroup>
      <col style="width:22%;">
      <col style="width:32%;">
      <col style="width:14%;">
      <col style="width:18%;">
      <col style="width:14%;">
    </colgroup>
    <thead>
      <tr>
        <th data-sort style="overflow:hidden;">ИМЕ</th>
        <th data-sort style="overflow:hidden;">Имейл</th>
        <th data-sort style="overflow:hidden;">Роля</th>
        <th data-sort style="overflow:hidden;">Дата на регистрация</th>
        <th style="overflow:hidden;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user): ?>
        <tr>
          <td><?= h($user['name']) ?></td>
          <td><?= h($user['email']) ?></td>
          <td>
            <?php
              $badge_class = match($user['role']) {
                'admin'       => 'badge--published',
                'shop_admin'  => 'badge--warning',
                'iris_admin'  => 'badge--info',
                default       => 'badge--draft',
              };
            ?>
            <span class="badge <?= $badge_class ?>">
              <?= h($user['role']) ?>
            </span>
          </td>
          <td><?= h(substr($user['created_at'], 0, 10)) ?></td>
          <td>
            <!-- Change role -->
            <form method="POST" action="/admin/users.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
              <select name="role" onchange="this.form.submit()" style="font-size:0.8rem;padding:2px 6px;">
                <option value="author"     <?= $user['role']==='author'     ?'selected':'' ?>>author</option>
                <option value="shop_admin" <?= $user['role']==='shop_admin' ?'selected':'' ?>>shop_admin</option>
                <option value="iris_admin" <?= $user['role']==='iris_admin' ?'selected':'' ?>>iris_admin</option>
                <option value="admin"      <?= $user['role']==='admin'      ?'selected':'' ?>>admin</option>
              </select>
            </form>

            <!-- Reset password (inline toggle) -->
            <button type="button" class="btn-link"
                    onclick="document.getElementById('reset-<?= $user['id'] ?>').classList.toggle('hidden')">
              Парола
            </button>
            <div id="reset-<?= $user['id'] ?>" class="hidden" style="margin-top:0.5rem;">
              <form method="POST" action="/admin/users.php" style="display:flex;gap:0.5rem;align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <input type="password" name="new_password" placeholder="Нова парола" minlength="8" style="font-size:0.85rem;padding:0.35rem 0.6rem;width:160px;">
                <button type="submit" class="btn btn--primary" style="padding:0.35rem 0.75rem;font-size:0.8rem;">OK</button>
              </form>
            </div>

            <?php if ((int)$user['id'] !== (int)($current['id'] ?? 0)): ?>
              <!-- Delete -->
              <form method="POST" action="/admin/users.php" style="display:inline;"
                    data-confirm="Изтриване на потребителя?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <button type="submit" class="btn-link btn-link--danger">Изтрий</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add user -->
<h2 style="margin-bottom:1.5rem;">Добавяне на потребител</h2>
<form method="POST" action="/admin/users.php" class="admin-form" style="max-width:480px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="form-group">
    <label>Имена</label>
    <input type="text" name="name" required>
  </div>
  <div class="form-group">
    <label>Имейл</label>
    <input type="email" name="email" required>
  </div>
  <div class="form-group">
    <label>Временна парола</label>
    <input type="password" name="password" minlength="8" required>
  </div>
  <div class="form-group">
    <label>Роля</label>
    <select name="role">
      <option value="author">author</option>
      <option value="shop_admin">shop_admin</option>
      <option value="admin">admin</option>
    </select>
  </div>
  <button type="submit" class="btn btn--primary">Добавяне</button>
</form>

<style>.hidden { display:none; }</style>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
