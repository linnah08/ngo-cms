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
        session_regenerate_id(true);
        $_SESSION[ADMIN_SESSION_NAME] = [
            'id'   => $user['id'],
            'name' => $user['name'],
            'email'=> $user['email'],
            'role' => $user['role'],
            'time' => time(),
        ];
        admin_bar_token_set();
        return true;
    } catch (Exception $e) {
        error_log('admin_login error: ' . $e->getMessage());
        return false;
    }
}

function admin_logout(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION[ADMIN_SESSION_NAME]);
    session_destroy();
    admin_bar_token_clear();
}

function admin_generate_reset_token(string $email): string|false
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
        $pdo->prepare('UPDATE admin_users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?')
            ->execute([$token, trim($email)]);
        return $token;
    } catch (Exception $e) {
        error_log('admin_generate_reset_token error: ' . $e->getMessage());
        return false;
    }
}

function admin_reset_password(string $token, string $new_password): bool
{
    try {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if (!$user) return false;

        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE admin_users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([$hash, $user['id']]);
        return true;
    } catch (Exception $e) {
        error_log('admin_reset_password error: ' . $e->getMessage());
        return false;
    }
}
