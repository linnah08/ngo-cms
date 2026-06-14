<?php
/**
 * Email tracking endpoint.
 *
 * GET ?type=open&token=XXXX  — records an open, returns a 1×1 transparent GIF.
 * GET ?type=click&token=XXXX&url=ENCODED — records a click, redirects to url.
 *
 * Always responds successfully regardless of whether the token is recognised,
 * so tracking calls in stale emails (already-deleted sends) are silent.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

$type  = $_GET['type']  ?? '';
$token = $_GET['token'] ?? '';

// Validate token shape: 32 lowercase hex chars
if (strlen($token) === 32 && ctype_xdigit($token)) {
    try {
        $pdo = get_pdo();

        if ($type === 'open') {
            $pdo->prepare("
                UPDATE newsletter_sends
                SET open_count  = open_count + 1,
                    opened_at   = COALESCE(opened_at, NOW())
                WHERE token = ?
            ")->execute([$token]);

        } elseif ($type === 'click') {
            $pdo->prepare("
                UPDATE newsletter_sends
                SET click_count = click_count + 1,
                    clicked_at  = COALESCE(clicked_at, NOW())
                WHERE token = ?
            ")->execute([$token]);
        }
    } catch (Throwable) {
        // Never let tracking errors surface to the recipient
    }
}

if ($type === 'click') {
    // Redirect to the original URL
    $url = trim($_GET['url'] ?? '');
    // Only allow http/https targets
    if ($url && preg_match('#^https?://#i', $url)) {
        header('Location: ' . $url, true, 302);
    } else {
        header('Location: ' . (defined('SITE_URL') ? SITE_URL : '/'));
    }
    exit;
}

// For open (and any other type): return a 1×1 transparent GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Minimal valid 1×1 transparent GIF (35 bytes)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
