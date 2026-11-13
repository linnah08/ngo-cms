<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title_admin = 'Потребители';
$active_nav       = 'users';

admin_require_login();
admin_require_admin();

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';   // admin_roles(): a site may add its own

$success = '';
$error   = '';
$current = admin_user();
$pdo     = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $in    = admin_names_from_input($_POST);
        $names = $in['names'];
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $role  = isset(admin_roles()[$_POST['role'] ?? '']) ? $_POST['role'] : 'author';
        if ($in['error'] || !$email) {
            $error = 'Моля попълнете име и имейл.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Невалиден имейл.';
        } else {
            try {
                // Nobody knows this password: the new user picks their own from the welcome email's link.
                $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $pdo->prepare('INSERT INTO admin_users (name, first_name, last_name, first_name_en, last_name_en, email, password_hash, role) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([admin_full_name($names['first_name'], $names['last_name']),
                               $names['first_name'], $names['last_name'], $names['first_name_en'], $names['last_name_en'],
                               $email, $hash, $role]);

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

    } elseif ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $in = admin_names_from_input($_POST);
        if ($in['error']) {
            $error = 'Имената не бяха запазени: ' . $in['error'];
        } else {
            admin_save_names($pdo, $id, $in['names']);
            flash_set('success', 'Имената са запазени.');
            header('Location: /admin/users.php');
            exit;
        }

    } elseif ($action === 'change_role') {
        $id   = (int) ($_POST['id'] ?? 0);
        $role = isset(admin_roles()[$_POST['role'] ?? '']) ? $_POST['role'] : 'author';
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

$users = $pdo->query('SELECT id, name, first_name, last_name, first_name_en, last_name_en, email, role, created_at FROM admin_users ORDER BY created_at')->fetchAll();

/** The four name inputs, for the add form ($p = 'new-user') and each user's rename form. */
$name_inputs = static function (string $p, array $u): string {
    $row = static fn(string $f, string $label, bool $req, string $ac, string $extra = '') =>
        '<div class="form-group"><label for="' . h($p . '-' . $f) . '">' . h($label) . ($req ? '' : ' <span style="font-weight:400;color:#4b5563;">(по желание)</span>') . '</label>'
        . '<input type="text" id="' . h($p . '-' . $f) . '" name="' . $f . '" maxlength="100" value="' . h((string) ($u[$f] ?? '')) . '"'
        . ($req ? ' required' : '') . ' autocomplete="' . $ac . '"' . $extra . '></div>';
    return $row('first_name', 'Име', true, 'off')
         . $row('last_name', 'Фамилия', false, 'off')
         . $row('first_name_en', 'Име на латиница (за английския сайт)', false, 'off', ' lang="en" data-translate-from="' . h($p . '-first_name') . '"')
         . $row('last_name_en', 'Фамилия на латиница', false, 'off', ' lang="en" data-translate-from="' . h($p . '-last_name') . '"');
};
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
      <col style="width:27%;">
      <col style="width:23%;">
      <col style="width:12%;">
      <col style="width:13%;">
      <col style="width:25%;">
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
          <td>
            <?= h($user['name']) ?>
            <details style="margin-top:.25rem;">
              <summary class="btn-link" style="cursor:pointer;">Промени имената</summary>
              <form method="POST" action="/admin/users.php" class="admin-form" style="margin-top:.5rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <?= $name_inputs('user-' . (int)$user['id'], $user) ?>
                <button type="submit" class="btn btn--primary btn--sm">Запази имената</button>
              </form>
            </details>
          </td>
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
              <?= h(admin_roles()[$user['role']] ?? $user['role']) ?>
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
                <?php foreach (admin_roles() as $rk => $rl): ?>
                <option value="<?= h($rk) ?>" <?= $user['role'] === $rk ? 'selected' : '' ?>><?= h($rl) ?></option>
                <?php endforeach; ?>
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
  <?= $name_inputs('new-user', []) ?>
  <div class="form-group">
    <label for="new-user-email">Имейл</label>
    <input type="email" id="new-user-email" name="email" required autocomplete="off" aria-describedby="new-user-email-hint">
    <p id="new-user-email-hint" style="margin:.3rem 0 0;font-size:.85rem;color:#4b5563;">С този имейл ще влиза в панела. Имената се показват в панела и под статиите.</p>
  </div>
  <div class="form-group">
    <label for="new-user-role">Роля</label>
    <select id="new-user-role" name="role">
      <?php foreach (admin_roles() as $rk => $rl): ?>
      <option value="<?= h($rk) ?>"<?= $rk === 'author' ? ' selected' : '' ?>><?= h($rl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn--primary">Добавяне</button>
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
