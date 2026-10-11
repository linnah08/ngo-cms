<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
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
        $role  = in_array($_POST['role'] ?? '', ['admin','author','shop_admin']) ? $_POST['role'] : 'author';
        if (!$name || !$email) {
            $error = 'Моля попълнете име и имейл.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Невалиден имейл.';
        } else {
            try {
                // Nobody knows this password: the new user picks their own from the welcome email's link.
                $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, $hash, $role]);

                if (admin_send_password_link($email, 'welcome')) {
                    $success = 'Потребителят е добавен. Изпратихме му имейл с линк, от който да избере парола (валиден 7 дни).';
                } else {
                    $error = 'Потребителят е добавен, но имейлът с линка не можа да бъде изпратен. '
                           . 'Проверете настройките в „Имейл“ и натиснете „Изпрати линк за парола“ до името му.';
                }
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                    $error = 'Имейлът вече съществува.';
                } else {
                    error_log('users.php add user error: ' . $e->getMessage());
                    $error = 'Потребителят не беше създаден поради техническа грешка. Опитайте отново.';
                }
            }
        }

    } elseif ($action === 'change_role') {
        $id   = (int) ($_POST['id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['admin','author','shop_admin']) ? $_POST['role'] : 'author';
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

    } elseif ($action === 'send_password_link') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT email FROM admin_users WHERE id=?');
        $row->execute([$id]);
        $to  = $row->fetchColumn();
        if (!is_string($to)) {
            $error = 'Потребителят не беше намерен.';
        } elseif (admin_send_password_link($to, 'admin')) {
            $success = 'Изпратихме линк за нова парола на ' . $to . '. Линкът е валиден 24 часа. Сегашната парола работи, докато не бъде сменена.';
        } else {
            $error = 'Имейлът до ' . $to . ' не можа да бъде изпратен. Проверете настройките в „Имейл“ и опитайте отново.';
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
// flash_get() returns a list of messages.
$_msgs = flash_get();
if ($success) $_msgs[] = ['type' => 'success', 'message' => $success];
if ($error)   $_msgs[] = ['type' => 'error',   'message' => $error];
?>
<div role="status">
<?php foreach ($_msgs as $_m): $_ok = ($_m['type'] ?? '') === 'success'; ?>
  <div class="admin-alert admin-alert--<?= $_ok ? 'success' : 'error' ?>" style="margin-bottom:1.5rem;">
    <?= $_ok ? '✔' : '✖' ?> <?= h((string) ($_m['message'] ?? '')) ?>
  </div>
<?php endforeach; ?>
</div>

<div class="admin-table-wrap" style="margin-bottom:2.5rem;overflow-x:hidden;">
  <table class="admin-table" style="table-layout:fixed;width:100%;">
    <colgroup>
      <col style="width:19%;">
      <col style="width:27%;">
      <col style="width:12%;">
      <col style="width:16%;">
      <col style="width:26%;">
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
                <option value="admin"      <?= $user['role']==='admin'      ?'selected':'' ?>>admin</option>
              </select>
            </form>

            <?php if ((int)$user['id'] === (int)($current['id'] ?? 0)): ?>
              <!-- Own password: changed on „Моят профил“, which asks for the current one -->
              <a href="/admin/account.php" class="btn-link">Смени паролата</a>
            <?php else: ?>
              <!-- Email the user a link to choose a new password; the admin never sees it -->
              <form method="POST" action="/admin/users.php" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_password_link">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <button type="submit" class="btn-link"
                        aria-label="Изпрати линк за нова парола на <?= h($user['email']) ?>">Изпрати линк за парола</button>
              </form>
            <?php endif; ?>

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
<p style="max-width:480px;margin:-1rem 0 1.25rem;color:#4b5563;">
  Новият потребител ще получи имейл с линк, от който сам да избере парола.
</p>
<form method="POST" action="/admin/users.php" class="admin-form" style="max-width:480px;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="form-group">
    <label for="new-user-name">Имена</label>
    <input type="text" id="new-user-name" name="name" required autocomplete="off">
  </div>
  <div class="form-group">
    <label for="new-user-email">Имейл</label>
    <input type="email" id="new-user-email" name="email" required autocomplete="off">
  </div>
  <div class="form-group">
    <label for="new-user-role">Роля</label>
    <select id="new-user-role" name="role">
      <option value="author">author</option>
      <option value="shop_admin">shop_admin</option>
      <option value="admin">admin</option>
    </select>
  </div>
  <button type="submit" class="btn btn--primary">Добавяне</button>
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
