<?php
/**
 * Paid orders and donations reported to Google Analytics 4 from the server
 * (Measurement Protocol), so Google Ads gets them even when the buyer never
 * comes back to the thank-you page — an IRIS payment confirmed later, a closed
 * tab after paying by card.
 *
 *   - At checkout, ga4_order_tracking_ids() reads the visitor's GA cookies (only
 *     when they accepted cookies) and the order row keeps them.
 *   - When the order turns paid (process_dsk_result / process_iris_result),
 *     ga4_send_conversion() sends `purchase` (shop) or `donate` (donation) once.
 *   - cron/ga4-conversions-cron.php re-sends the ones that failed, up to 72 hours
 *     after payment — the furthest back Google accepts an event.
 *
 * Without consent there is no client id, and nothing is sent.
 * Needs GA4_ID and GA4_API_SECRET (Admin → Организация → Google, or site.config.php).
 */

/** Cookie keeping the Google Ads click id (?gclid=) — written only with consent. */
const GA4_GCLID_COOKIE = 'om_gclid';
const GA4_GCLID_DAYS   = 90;

function ga4_consent_given(?array $cookies = null): bool
{
    return (($cookies ?? $_COOKIE)['om_cookie_consent'] ?? '') === 'all';
}

// Tests set $GLOBALS['ga4_test_config'] = ['id' => …, 'secret' => …] instead of the site's constants.
function ga4_measurement_id(): string
{
    return $GLOBALS['ga4_test_config']['id'] ?? (defined('GA4_ID') ? (string) GA4_ID : '');
}

function ga4_api_secret(): string
{
    return $GLOBALS['ga4_test_config']['secret'] ?? (defined('GA4_API_SECRET') ? (string) GA4_API_SECRET : '');
}

/** A Google Ads click id as it appears in ?gclid=, or null for anything else. */
function ga4_clean_gclid(mixed $v): ?string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9_\-]{10,255}$/', $v) ? $v : null;
}

/** "GA1.1.123456789.1730000000" (the _ga cookie) → "123456789.1730000000". */
function ga4_client_id_from_cookie(?string $ga): ?string
{
    if ($ga === null || !preg_match('/^GA\d+\.\d+\.(\d+)\.(\d+)$/', trim($ga), $m)) return null;
    return $m[1] . '.' . $m[2];
}

/**
 * The session id from the _ga_<stream> cookie, in either format Google writes:
 * "GS1.1.1730000000.3.1.1730000100.0.0.0" or "GS2.1.s1730000000$o3$g1$t1730000100$j0$l0$h0".
 */
function ga4_session_id_from_cookie(?string $v): ?string
{
    $v = trim((string) $v);
    if (preg_match('/^GS1\.\d+\.(\d+)\./', $v, $m)) return $m[1];
    if (preg_match('/^GS2\.\d+\.s(\d+)(\$|$)/', $v, $m)) return $m[1];
    return null;
}

/**
 * What the order row keeps for the server-side conversion: GA client and session
 * id, and the Google Ads click id. All null without cookie consent — then nothing
 * may be sent about this visitor.
 *
 * @return array{ga_client_id: ?string, ga_session_id: ?string, gclid: ?string}
 */
function ga4_order_tracking_ids(?array $cookies = null, ?array $session = null, string $measurementId = ''): array
{
    $cookies ??= $_COOKIE;
    $session ??= $_SESSION ?? [];
    $none = ['ga_client_id' => null, 'ga_session_id' => null, 'gclid' => null];
    if (!ga4_consent_given($cookies)) return $none;

    $measurementId = $measurementId !== '' ? $measurementId : ga4_measurement_id();
    $stream = preg_replace('/^G-/i', '', $measurementId);
    return [
        'ga_client_id'  => ga4_client_id_from_cookie($cookies['_ga'] ?? null),
        'ga_session_id' => $stream !== '' ? ga4_session_id_from_cookie($cookies['_ga_' . $stream] ?? null) : null,
        'gclid'         => ga4_clean_gclid($cookies[GA4_GCLID_COOKIE] ?? null) ?? ga4_clean_gclid($session['ga_gclid'] ?? null),
    ];
}

/**
 * Keep the Google Ads click id from the landing page. It stays in the session
 * for the visit (a session already exists, or the landing URL carries ?gclid=),
 * and goes into a 90-day cookie only once the visitor accepted cookies.
 * Called from templates/header.php before any output.
 */
function ga4_remember_gclid(): void
{
    $gclid = ga4_clean_gclid($_GET['gclid'] ?? null);
    if ($gclid === null && !isset($_COOKIE[session_name()])) return;
    if (headers_sent()) return;
    start_session();
    if ($gclid !== null) $_SESSION['ga_gclid'] = $gclid;

    $keep = $_SESSION['ga_gclid'] ?? null;
    if ($keep !== null && ga4_consent_given() && ($_COOKIE[GA4_GCLID_COOKIE] ?? '') !== $keep) {
        setcookie(GA4_GCLID_COOKIE, $keep, [
            'expires'  => time() + GA4_GCLID_DAYS * 86400,
            'path'     => '/',
            'secure'   => str_starts_with(SITE_URL, 'https://'),
            'httponly' => false,   // the banner's „Само необходими“ must be able to delete it
            'samesite' => 'Lax',
        ]);
        $_COOKIE[GA4_GCLID_COOKIE] = $keep;
    }
}

/** The GA4 event for a paid order: [name, params]. Donations get "D-" so they never share an id with a purchase. */
function ga4_conversion_event(array $order): array
{
    $params = [
        'value'    => round((float) $order['total_eur'], 2),
        'currency' => 'EUR',
    ];
    if (($order['type'] ?? '') === 'donation') {
        return ['donate', ['transaction_id' => 'D-' . $order['order_number']] + $params];
    }

    $items = [];
    foreach (json_decode((string) ($order['items'] ?? '[]'), true) ?: [] as $it) {
        $name = (string) (($it['name_bg'] ?? '') ?: ($it['name_en'] ?? ''));
        if (!empty($it['variant_label'])) $name .= ' — ' . $it['variant_label'];
        $items[] = [
            'item_id'   => isset($it['product_id']) ? (string) $it['product_id'] : (string) ($it['type'] ?? 'item'),
            'item_name' => $name,
            'price'     => round((float) ($it['price_eur'] ?? $it['amount_eur'] ?? 0), 2),
            'quantity'  => max(1, (int) ($it['quantity'] ?? 1)),
        ];
    }
    return ['purchase', ['transaction_id' => $order['order_number']] + $params + ['items' => $items]];
}

/** The Measurement Protocol body for a paid order, or null when it may not be sent (no consent, so no client id). */
function ga4_conversion_payload(array $order): ?array
{
    $client = (string) ($order['ga_client_id'] ?? '');
    if ($client === '') return null;

    [$name, $params] = ga4_conversion_event($order);
    if (!empty($order['ga_session_id'])) $params['session_id'] = (string) $order['ga_session_id'];
    $params['engagement_time_msec'] = 1;
    if (defined('GA4_DEBUG_MODE') && GA4_DEBUG_MODE) $params['debug_mode'] = 1;

    $body = ['client_id' => $client, 'events' => [['name' => $name, 'params' => $params]]];
    // A resend from the cron is dated when it was paid. The age comes from the
    // database (paid_age_s), whose clock wrote paid_at — PHP's time zone may differ.
    if (isset($order['paid_age_s']) && (int) $order['paid_age_s'] > 0) {
        $body['timestamp_micros'] = (time() - (int) $order['paid_age_s']) * 1000000;
    }
    return $body;
}

/** POST the JSON body; returns the HTTP status (0 when the request did not go through). */
function ga4_http_post(string $url, string $json): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function ga4_collect_url(bool $validate = false): string
{
    return 'https://www.google-analytics.com/' . ($validate ? 'debug/mp/collect' : 'mp/collect')
        . '?measurement_id=' . rawurlencode(ga4_measurement_id())
        . '&api_secret=' . rawurlencode(ga4_api_secret());
}

/**
 * Report a paid order or donation to GA4 — at most once. The order is claimed
 * first (conversion_sent_at set only if still empty), so a bank callback racing
 * the buyer's return, or a replayed IRIS callback, cannot send it twice. If
 * Google does not answer 2xx the claim is released for the cron to retry.
 * Never throws: a failure here must not touch the payment.
 *
 * @param callable(string $url, string $json): int|null $post  for tests
 */
function ga4_send_conversion(PDO $pdo, array $order, ?callable $post = null): bool
{
    try {
        if (($order['payment_status'] ?? '') !== 'paid') return false;
        if (ga4_measurement_id() === '' || ga4_api_secret() === '') return false;
        $payload = ga4_conversion_payload($order);
        if ($payload === null) return false;

        $claim = $pdo->prepare("UPDATE orders SET conversion_sent_at = NOW() WHERE id = ? AND conversion_sent_at IS NULL AND payment_status = 'paid'");
        $claim->execute([$order['id']]);
        if ($claim->rowCount() === 0) return false;   // already sent, or being sent right now

        $status = ($post ?? 'ga4_http_post')(ga4_collect_url(), json_encode($payload, JSON_UNESCAPED_UNICODE));
        if ($status >= 200 && $status < 300) return true;

        $pdo->prepare('UPDATE orders SET conversion_sent_at = NULL WHERE id = ?')->execute([$order['id']]);
        error_log('ga4: conversion for ' . $order['order_number'] . ' not accepted (HTTP ' . $status . '), the cron retries it');
    } catch (Throwable $e) {
        error_log('ga4: conversion for ' . ($order['order_number'] ?? '?') . ' failed: ' . $e->getMessage());
    }
    return false;
}

/**
 * The order just turned paid: note when (the cron's 72-hour window counts from
 * it) and report it. Separate from the payment's own UPDATE, so a database not
 * migrated yet can never stop an order being marked paid. Never throws.
 */
function ga4_order_paid(PDO $pdo, array $order, ?callable $post = null): void
{
    try {
        $pdo->prepare('UPDATE orders SET paid_at = COALESCE(paid_at, NOW()) WHERE id = ?')->execute([$order['id']]);
    } catch (Throwable $e) {
        error_log('ga4: could not note when order ' . ($order['order_number'] ?? '?') . ' was paid: ' . $e->getMessage());
        return;
    }
    ga4_send_conversion($pdo, $order, $post);
}

/** Paid orders whose conversion is still owed and that Google would still accept (it takes 72 hours back; 71 leaves a margin). */
function ga4_conversions_due(PDO $pdo): array
{
    return $pdo->query(
        "SELECT *, TIMESTAMPDIFF(SECOND, paid_at, NOW()) AS paid_age_s FROM orders
          WHERE payment_status = 'paid' AND conversion_sent_at IS NULL
            AND ga_client_id IS NOT NULL AND ga_client_id <> ''
            AND paid_at IS NOT NULL AND paid_at > NOW() - INTERVAL 71 HOUR
          ORDER BY paid_at"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Cron: resend what is owed. Returns [sent, failed]. */
function run_ga4_conversions_job(PDO $pdo, ?callable $post = null): array
{
    $sent = $failed = 0;
    if (ga4_measurement_id() === '' || ga4_api_secret() === '') return [0, 0];
    foreach (ga4_conversions_due($pdo) as $order) {
        ga4_send_conversion($pdo, $order, $post) ? $sent++ : $failed++;
    }
    return [$sent, $failed];
}

/**
 * Save the visitor's GA ids on a just-created order (a separate UPDATE, so a site
 * whose database is not migrated yet still takes the order). Nothing without consent.
 */
function ga4_store_order_tracking_ids(PDO $pdo, int $orderId, ?array $ids = null): void
{
    $ids ??= ga4_order_tracking_ids();
    if ($ids['ga_client_id'] === null && $ids['gclid'] === null) return;
    try {
        $pdo->prepare('UPDATE orders SET ga_client_id = ?, ga_session_id = ?, gclid = ? WHERE id = ?')
            ->execute([$ids['ga_client_id'], $ids['ga_session_id'], $ids['gclid'], $orderId]);
    } catch (Throwable $e) {
        error_log('ga4: could not save the GA ids on order ' . $orderId . ': ' . $e->getMessage());
    }
}
