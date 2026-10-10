<?php
/*
 * Its own file, not config.php: a site whose config.php the updater keeps still
 * gets it through admin/includes/auth.php.
 */

/**
 * Which installation an admin session belongs to: derived from this site's own
 * secret, which a fresh install regenerates. A login left over from an earlier
 * install on the same address (same session cookie, a new admin with the same
 * id) is therefore not accepted.
 */
if (!function_exists('admin_session_site')) {
function admin_session_site(): string {
    if (!defined('SETTINGS_ENCRYPTION_KEY') && is_file(dirname(__DIR__) . '/db.config.php')) require_once dirname(__DIR__) . '/db.config.php';
    $secret = defined('SETTINGS_ENCRYPTION_KEY') ? (string) SETTINGS_ENCRYPTION_KEY : (defined('DB_NAME') ? (string) DB_NAME : '');
    return substr(hash_hmac('sha256', 'admin-session', $secret), 0, 32);
}
}
