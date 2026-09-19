<?php

function error_alert_ensure_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    if (!function_exists('get_pdo')) {
        require_once __DIR__ . '/../admin/includes/db.php';
    }
    get_pdo()->exec("
        CREATE TABLE IF NOT EXISTS error_alerts (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            error_class VARCHAR(100)  NOT NULL DEFAULT '',
            message     TEXT          NOT NULL,
            file        VARCHAR(500)  NOT NULL DEFAULT '',
            line        INT           NOT NULL DEFAULT 0,
            url         VARCHAR(2000) NOT NULL DEFAULT '',
            user_agent  VARCHAR(500)  NOT NULL DEFAULT '',
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at     DATETIME      DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ensured = true;
}

/**
 * @param bool $force_immediate email now even when alerts are set to a digest
 *                              (used for payment errors — money is involved)
 */
function error_alert_capture(string $error_class, string $message, string $file, int $line, bool $force_immediate = false): void
{
    try {
        if (!function_exists('get_pdo')) {
            require_once __DIR__ . '/../admin/includes/db.php';
        }
        if (!function_exists('setting_get')) {
            require_once __DIR__ . '/settings.php';
        }

        if (setting_get('error_alert_enabled') !== '1') return;
        $email = setting_get('error_alert_email');
        if ($email === '') return;

        $url        = $_SERVER['REQUEST_URI']     ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $url        = mb_substr($url,        0, 2000);
        $user_agent = mb_substr($user_agent, 0, 500);
        $message    = mb_substr($message, 0, 2000);

        error_alert_ensure_table();

        $stmt = get_pdo()->prepare("
            INSERT INTO error_alerts (error_class, message, file, line, url, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$error_class, $message, $file, $line, $url, $user_agent]);
        $id  = (int) get_pdo()->lastInsertId();
        $sel = get_pdo()->prepare('SELECT * FROM error_alerts WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);

        if ($force_immediate || setting_get('error_alert_frequency', 'immediate') === 'immediate') {
            error_alert_send_immediate($row, $email);
        }
    } catch (\Throwable $e) {
        error_log('error_alert_capture: ' . $e->getMessage());
    }
}

function error_alert_send_immediate(array $row, string $email): void
{
    try {
        if (!function_exists('get_pdo')) {
            require_once __DIR__ . '/../admin/includes/db.php';
        }
        if (!function_exists('setting_get')) {
            require_once __DIR__ . '/settings.php';
        }

        // Flood protection: skip if the same error was sent within the last 5 minutes
        $hash     = md5(($row['error_class'] ?? '') . ($row['message'] ?? '') . ($row['file'] ?? '') . (string)($row['line'] ?? 0));
        $lastHash = setting_get('error_alert_last_hash');
        $lastAt   = setting_get('error_alert_last_hash_at');
        if ($lastHash === $hash && $lastAt !== '' && (time() - (int)strtotime($lastAt)) < 300) {
            return;
        }

        if (!function_exists('render_email')) {
            require_once __DIR__ . '/mailer.php';
        }
        if (!function_exists('email_tpl_subject')) {
            require_once __DIR__ . '/email-templates.php';
        }

        $vars = [
            'error_class' => $row['error_class'] ?? '',
            'url'         => $row['url']         ?? '',
            'timestamp'   => $row['created_at']  ?? date('Y-m-d H:i:s'),
        ];
        $subject = email_tpl_subject('error-alert', 'bg', $vars);
        $body    = render_email('error-alert', ['row' => $row]);

        if (send_mail($email, $subject, $body)) {
            if (isset($row['id']) && (int)$row['id'] > 0) {
                get_pdo()->prepare('UPDATE error_alerts SET sent_at = NOW() WHERE id = ?')
                         ->execute([(int)$row['id']]);
            }
            setting_set('error_alert_last_hash',    $hash);
            setting_set('error_alert_last_hash_at', date('Y-m-d H:i:s'));
        }
    } catch (\Throwable $e) {
        error_log('error_alert_send_immediate: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Groundwork for a future hourly AI auto-fix routine (admin/api/error-alerts.php).
// That routine does not exist yet — this only exposes read access to captured
// errors plus the bearer token it would authenticate with. No status/outcome
// tracking columns exist on error_alerts, so there is nothing here yet for a
// routine to claim/mark-fixed; that lands together with the routine itself.
// ─────────────────────────────────────────────────────────────────────────

function error_alert_list_recent(int $limit = 30): array
{
    error_alert_ensure_table();
    $limit = max(1, min(200, $limit));
    $stmt = get_pdo()->prepare(
        "SELECT * FROM error_alerts ORDER BY created_at DESC LIMIT {$limit}"
    );
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function error_alert_api_token(): string
{
    return setting_get('error_alert_api_token');
}

function error_alert_generate_api_token(): string
{
    $token = bin2hex(random_bytes(32));
    setting_set('error_alert_api_token', $token);
    return $token;
}
