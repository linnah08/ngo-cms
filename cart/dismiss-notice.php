<?php
/**
 * Closes the returning-visitor notice: marks as dismissed the lines it showed
 * (what[] = cart / pledge). The rows are this browser's own, found by the om_cart
 * cookie, never by an id from the request; a line that was not shown (say a
 * pledge started in another tab since) stays. Then goes back to the page the
 * visitor was on — same-site paths only.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
start_session();

$lang = get_lang();

/** A same-site relative path starting with a single "/", else null. */
function dismiss_safe_return(mixed $v): ?string
{
    if (!is_string($v) || $v === '' || strlen($v) > 500) return null;
    if ($v[0] !== '/' || (isset($v[1]) && ($v[1] === '/' || $v[1] === '\\'))) return null;
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $v)) return null;
    return $v;
}

$return = dismiss_safe_return($_POST['return'] ?? null) ?? shop_path('shop', $lang);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    try {
        $pdo   = get_pdo();
        $posted = $_POST['what'] ?? [];
        $posted = is_array($posted) ? $posted : [];
        $shown  = array_filter(['cart', 'pledge'], fn($k) => in_array($k, $posted, true));
        if ($shown) {
            $found = saved_cart_for_browser($pdo);
            foreach ($shown as $k) {
                if ($found[$k] !== null) saved_cart_dismiss_row($pdo, (int)$found[$k]['id']);
            }
        }
    } catch (Throwable $e) {
        error_log('Saved cart dismiss failed: ' . $e->getMessage());
    }
}

header('Location: ' . $return);
exit;
