<?php
/**
 * Encrypted site settings stored in the `settings` MySQL table.
 *
 * Values are encrypted at rest using AES-256-CBC.
 * The encryption key must be defined as SETTINGS_ENCRYPTION_KEY in db.config.php.
 *
 * Usage:
 *   setting_get('econt_user')          → decrypted string, '' if not set
 *   setting_set('econt_user', 'value') → encrypts and upserts
 *   setting_delete('econt_user')       → removes the row
 */

require_once __DIR__ . '/../admin/includes/db.php';

// ── Table bootstrap ────────────────────────────────────────────────────────────

function settings_ensure_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    try {
        get_pdo()->exec("
            CREATE TABLE IF NOT EXISTS settings (
                `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
                `value`    TEXT         NOT NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('settings_ensure_table: ' . $e->getMessage());
    }
    $ensured = true;
}

// ── Encryption helpers ─────────────────────────────────────────────────────────

function settings_encrypt(string $plain): string
{
    if (!defined('SETTINGS_ENCRYPTION_KEY') || SETTINGS_ENCRYPTION_KEY === '') {
        throw new RuntimeException('SETTINGS_ENCRYPTION_KEY is not defined in db.config.php');
    }
    $key  = hash('sha256', SETTINGS_ENCRYPTION_KEY, true); // 32 bytes
    $iv   = random_bytes(16);
    $enc  = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function settings_decrypt(string $stored): string
{
    if (!defined('SETTINGS_ENCRYPTION_KEY') || SETTINGS_ENCRYPTION_KEY === '') {
        return '';
    }
    $key  = hash('sha256', SETTINGS_ENCRYPTION_KEY, true);
    $raw  = base64_decode($stored);
    if (strlen($raw) <= 16) return '';
    $iv   = substr($raw, 0, 16);
    $enc  = substr($raw, 16);
    $dec  = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec === false ? '' : $dec;
}

// ── Public API ─────────────────────────────────────────────────────────────────

/** The open-source editor build (GPL-2.0-or-later), used when no Tiny Cloud key is set. */
const TINYMCE_OSS_URL = 'https://cdn.jsdelivr.net/npm/tinymce@7.9.3/tinymce.min.js';

/**
 * The <script> that loads the rich-text editor (TinyMCE 7) on an admin page.
 * With a Tiny Cloud key (Админ → Плащания) it comes from Tiny's cloud. Without
 * one — the default for a new site — it is the open-source build, which needs
 * no key or sign-up; before this, a new site's editors were read-only.
 */
function tinymce_script_tag(): string
{
    $key = trim(setting_get('tinymce_api_key', ''));
    if ($key !== '' && $key !== 'no-api-key') {
        return '<script src="https://cdn.tiny.cloud/1/' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';
    }
    return '<script src="' . TINYMCE_OSS_URL . '" referrerpolicy="origin"></script>'
         . '<script>tinymce.overrideDefaults({license_key: "gpl"});</script>';
}

function setting_get(string $key, string $default = ''): string
{
    try {
        settings_ensure_table();
        $stmt = get_pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) return $default;
        return settings_decrypt($row['value']);
    } catch (Throwable $e) {
        error_log('setting_get(' . $key . '): ' . $e->getMessage());
        return $default;
    }
}

function setting_set(string $key, string $value): void
{
    try {
        settings_ensure_table();
        $enc  = settings_encrypt($value);
        $stmt = get_pdo()->prepare("
            INSERT INTO settings (`key`, `value`, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()
        ");
        $stmt->execute([$key, $enc]);
    } catch (Throwable $e) {
        error_log('setting_set(' . $key . '): ' . $e->getMessage());
    }
}

function setting_delete(string $key): void
{
    try {
        settings_ensure_table();
        get_pdo()->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
    } catch (Throwable $e) {
        error_log('setting_delete(' . $key . '): ' . $e->getMessage());
    }
}

/**
 * Returns true if the setting has a non-empty saved value.
 * Used by the admin UI to show "••••••••" placeholders.
 */
function setting_is_set(string $key): bool
{
    return setting_get($key) !== '';
}

/**
 * Resolve a credential: DB value → constant fallback → default.
 * Used by courier classes so they work in both web and CLI/test contexts.
 */
function setting_resolve(string $key, string $constant, string $default = ''): string
{
    $db = setting_get($key);
    if ($db !== '') return $db;
    if (defined($constant)) return (string) constant($constant);
    return $default;
}

function setting_resolve_bool(string $key, string $constant, bool $default = true): bool
{
    $db = setting_get($key);
    if ($db !== '') return $db === '1';
    if (defined($constant)) return (bool) constant($constant);
    return $default;
}
