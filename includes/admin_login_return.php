<?php
/*
 * Its own file, not config.php: a site whose config.php the updater keeps still
 * gets these through admin/includes/auth.php (admin/login.php needs them).
 */

/**
 * Where to send someone after they log in: $raw when it is an admin page on this
 * site (a path, never another host), otherwise the dashboard.
 */
if (!function_exists('admin_login_return_path')) {
function admin_login_return_path(?string $raw): string {
    $fallback = '/admin/dashboard.php';
    $raw = (string) $raw;
    if ($raw === '' || strlen($raw) > 2000) return $fallback;
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $raw)) return $fallback;
    if (!str_starts_with($raw, '/admin/')) return $fallback;
    $path = (string) parse_url($raw, PHP_URL_PATH);
    if ($path === '' || str_contains($path, '..')) return $fallback;
    if (in_array(basename($path), ['login.php', 'logout.php', 'session-ping.php'], true)) return $fallback;
    return $raw;
}
}

/**
 * The login URL for a request that found no session, carrying the page to come back
 * to. A form sent after the session ran out returns to the page the form was on
 * (its editor still holds the text in the browser's backup).
 */
if (!function_exists('admin_login_url_for_request')) {
function admin_login_url_for_request(): string {
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        $ref  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $back = '';
        if ($ref !== '' && $host !== '' && strcasecmp((string) parse_url($ref, PHP_URL_HOST), explode(':', $host)[0]) === 0) {
            $q    = parse_url($ref, PHP_URL_QUERY);
            $back = (string) parse_url($ref, PHP_URL_PATH) . ($q ? '?' . $q : '');
        }
    }
    $back = admin_login_return_path($back);
    return '/admin/login.php' . ($back === '/admin/dashboard.php' ? '' : '?next=' . rawurlencode($back));
}
}
