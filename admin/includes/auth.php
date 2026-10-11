<?php
// Admin auth — extends the base functions already defined in config.php.
// Requires config.php and db.php to have been loaded first.

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

/**
 * Simple IP-based rate limiter backed by the DB.
 * Returns true if the limit has been exceeded.
 * Creates the table on first use (idempotent).
 */
function rate_limit_exceeded(string $action, int $max, int $window_seconds): bool
{
    try {
        $pdo = get_pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action      VARCHAR(64)  NOT NULL,
            ip          VARCHAR(45)  NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_ip_time (action, ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $since = date('Y-m-d H:i:s', time() - $window_seconds);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE action=? AND ip=? AND created_at > ?'
        );
        $stmt->execute([$action, $ip, $since]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $max) return true;

        $pdo->prepare('INSERT INTO rate_limits (action, ip) VALUES (?, ?)')->execute([$action, $ip]);

        // Prune old entries opportunistically (1-in-50 chance)
        if (random_int(1, 50) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE created_at < ?')
                ->execute([date('Y-m-d H:i:s', time() - max($window_seconds, 86400))]);
        }

        return false;
    } catch (Exception $e) {
        error_log('rate_limit error: ' . $e->getMessage());
        return false; // fail open to avoid locking out on DB issues
    }
}

require_once dirname(__DIR__, 2) . '/includes/admin_session.php';   // admin_session_site(), also for a site that kept an older config.php

function admin_login(string $email, string $password): bool
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        admin_start_session($user);
        return true;
    } catch (Exception $e) {
        error_log('admin_login error: ' . $e->getMessage());
        return false;
    }
}

/** Log this admin_users row in (a fresh session id, bound to this installation). */
function admin_start_session(array $user): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_NAME] = [
        'id'   => $user['id'],
        'name' => $user['name'],
        'email'=> $user['email'],
        'role' => $user['role'],
        'time' => time(),
        'site' => admin_session_site(),
    ];
    admin_bar_token_set();
}

/**
 * The install wizard's one-time "log me in" link (admin/install-login.php):
 * a random token whose hash and expiry wait in settings.install_login. Returns
 * the first admin when the token matches and has not expired, else null. The
 * token is spent on the first try either way.
 */
function admin_redeem_install_login(PDO $pdo, string $token): ?array
{
    $row = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'install_login'")->fetchColumn();
    $pdo->exec("DELETE FROM settings WHERE `key` = 'install_login'");
    if (!is_string($row) || $token === '' || !str_contains($row, '|')) return null;
    [$hash, $exp] = explode('|', $row, 2);
    if ((int) $exp < time() || !hash_equals($hash, hash('sha256', $token))) return null;
    $user = $pdo->query("SELECT * FROM admin_users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
    return $user ?: null;
}

function admin_logout(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION[ADMIN_SESSION_NAME]);
    session_destroy();
    admin_bar_token_clear();
}

/** Shortest password the admin accepts. */
const ADMIN_PASSWORD_MIN = 8;

/**
 * A password as it is stored and checked. login.php has always trimmed what was
 * typed, so every place that sets a password trims too — otherwise a password
 * set with a stray space at either end could never be typed back in.
 */
function admin_password_normalize(string $password): string
{
    return trim($password);
}

/**
 * Why a new password can't be used, or null when it's fine:
 * ['field' => form field it is about, 'message' => plain-language reason].
 * Field names match the forms: new_password, new_password2 (and current_password
 * in admin_change_password()).
 */
function admin_password_problem(string $new, string $confirm): ?array
{
    $new = admin_password_normalize($new);
    if (mb_strlen($new) < ADMIN_PASSWORD_MIN) {
        return ['field' => 'new_password', 'message' => 'Новата парола трябва да е поне ' . ADMIN_PASSWORD_MIN . ' символа.'];
    }
    if ($new !== admin_password_normalize($confirm)) {
        return ['field' => 'new_password2', 'message' => 'Двете нови пароли не съвпадат. Въведете една и съща парола и в двете полета.'];
    }
    return null;
}

/**
 * A one-time link token for setting a password, valid for $hours. Used for
 * "Забравена парола" (1 hour), a link an admin sends a user (24 hours) and the
 * welcome email of a new user (7 days). A new token replaces any older one.
 */
function admin_generate_reset_token(string $email, int $hours = 1): string|false
{
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([trim($email)]);
        if (!$stmt->fetch()) return false;

        $token = bin2hex(random_bytes(32));
        // Set expiry via MySQL's clock so it matches the `reset_expires > NOW()`
        // check in admin_reset_password(). Using PHP's date() here breaks when
        // PHP (UTC) and MySQL (server-local) run in different timezones — the
        // offset instantly exceeds the 1h window and every link reads "expired".
        $pdo->prepare('UPDATE admin_users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE email = ?')
            ->execute([$token, max(1, $hours), trim($email)]);
        return $token;
    } catch (Exception $e) {
        error_log('admin_generate_reset_token error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sets a new password from an emailed link. Returns the user (id, name, email)
 * it was set for, or null when the link is unknown or has expired.
 */
function admin_reset_password(string $token, string $new_password): ?array
{
    if ($token === '') return null;
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT id, name, email FROM admin_users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if (!$user) return null;

        $hash = password_hash(admin_password_normalize($new_password), PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE admin_users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([$hash, $user['id']]);
        return $user;
    } catch (Exception $e) {
        error_log('admin_reset_password error: ' . $e->getMessage());
        return null;
    }
}

/**
 * A signed-in user changes their own password. Returns null on success, else the
 * same ['field', 'message'] shape as admin_password_problem() ('field' is '' when
 * the problem isn't one field's). Also cancels any reset link still waiting in
 * their inbox.
 */
function admin_change_password(int $user_id, string $current, string $new, string $confirm): ?array
{
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash)) return ['field' => '', 'message' => 'Профилът ви не беше намерен. Излезте и влезте отново.'];
        if (!password_verify(admin_password_normalize($current), $hash)) {
            return ['field' => 'current_password', 'message' => 'Сегашната парола е грешна.'];
        }
        if ($problem = admin_password_problem($new, $confirm)) return $problem;

        $pdo->prepare('UPDATE admin_users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash(admin_password_normalize($new), PASSWORD_BCRYPT), $user_id]);
        return null;
    } catch (Exception $e) {
        error_log('admin_change_password error: ' . $e->getMessage());
        return ['field' => '', 'message' => 'Паролата не беше сменена поради техническа грешка. Опитайте отново след малко.'];
    }
}

/**
 * Emails a link for setting a password. $kind: 'forgot' (the user asked, valid
 * 1 hour), 'admin' (an admin sent it, 24 hours) or 'welcome' (new user, 7 days).
 * Returns false when there is no such user or the email could not be sent.
 */
function admin_send_password_link(string $email, string $kind = 'forgot'): bool
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

    $hours = ['forgot' => 1, 'admin' => 24, 'welcome' => 24 * 7][$kind] ?? 1;
    $token = admin_generate_reset_token($email, $hours);
    if (!$token) return false;

    $link  = SITE_URL . '/admin/login-reset.php?token=' . urlencode($token) . ($kind === 'welcome' ? '&new=1' : '');
    $a     = '<p><a href="' . h($link) . '">' . h($link) . '</a></p>';
    $site  = h(SITE_NAME_BG);

    if ($kind === 'welcome') {
        $subject = 'Добре дошли в ' . SITE_NAME_BG . ' Admin';
        $body    = '<p>Създаден е акаунт за вас в администрацията на <strong>' . $site . '</strong>.</p>'
                 . '<p><strong>Имейл за вход:</strong> ' . h(trim($email)) . '</p>'
                 . '<p>Отворете линка по-долу, за да изберете своя парола:</p>' . $a
                 . '<p>Линкът е валиден 7 дни. Ако изтече, използвайте „Забравена парола?“ на страницата за вход.</p>';
    } else {
        $subject = 'Нулиране на парола — ' . SITE_NAME_BG . ' Admin';
        $body    = ($kind === 'admin'
                    ? '<p>Администратор на ' . $site . ' ви изпрати линк, с който да изберете нова парола.</p>'
                    : '<p>Получихме заявка за нулиране на вашата парола.</p>')
                 . $a
                 . '<p>Линкът е валиден ' . ($hours === 1 ? '1 час' : $hours . ' часа')
                 . '. Ако не сте очаквали този имейл, просто го игнорирайте — паролата ви няма да се промени.</p>';
    }
    return send_mail(trim($email), $subject, $body);
}

/** Tells a user their password was just changed, so a change they didn't make doesn't go unnoticed. */
function admin_send_password_changed_notice(string $email, string $name): bool
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

    $body = '<p>Здравейте' . ($name !== '' ? ', ' . h($name) : '') . ',</p>'
          . '<p>Паролата за вашия акаунт в администрацията на <strong>' . h(SITE_NAME_BG) . '</strong> току-що беше сменена.</p>'
          . '<p>Ако сте я сменили вие, не е нужно да правите нищо.</p>'
          . '<p>Ако не сте вие, веднага изберете нова парола чрез „Забравена парола?“ на '
          . '<a href="' . h(SITE_URL . '/admin/login-forgot.php') . '">страницата за вход</a> и уведомете администратора.</p>';
    return send_mail(trim($email), 'Паролата ви беше сменена — ' . SITE_NAME_BG . ' Admin', $body);
}
