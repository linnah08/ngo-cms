<?php
/**
 * "Report a problem" — sends support tickets from this install's admin panel
 * to the vendor's support relay (which turns them into Trello cards).
 *
 * This server never holds any Trello credentials. It only knows a per-install
 * "support code" (given to the NGO during onboarding), stored ENCRYPTED in the
 * `settings` table via setting_set()/setting_get() (AES-256-CBC through
 * settings_encrypt()/settings_decrypt()), exactly like other secrets
 * (payment / courier credentials).
 *
 * Relay contract:
 *   POST support_relay_url(), multipart/form-data
 *   Header  X-Support-Key: <support code>
 *   Fields  subject (≤150), description (≤5000), page (≤300),
 *           diagnostics (JSON string, ≤20000), optional file `screenshot`
 *           (png/jpeg/webp, ≤5 MB)
 *   200 {"ok":true,"reference":"..."} | non-200 {"ok":false,"error":"<code>"}
 *   codes: unauthorized, rate_limited, invalid, server_error
 *
 * Public API:
 *   support_relay_url(): string
 *   support_get_code(): string / support_set_code(string): bool / support_is_configured(): bool
 *   support_validate_page(string): string
 *   support_collect_diagnostics(array $context): array
 *   support_error_message(string $code): string
 *   support_validate_screenshot(array $upload, bool $requireUploaded = true): array
 *   support_send_ticket(...): array
 */

if (!function_exists('setting_get')) {
    require_once __DIR__ . '/settings.php';
}

const SUPPORT_SETTING_NAME         = 'support_code';
const SUPPORT_SUBJECT_MAX          = 150;
const SUPPORT_DESCRIPTION_MAX      = 5000;
const SUPPORT_PAGE_MAX             = 300;
const SUPPORT_DIAGNOSTICS_MAX      = 20000;
const SUPPORT_SCREENSHOT_MAX_BYTES = 5 * 1024 * 1024;
const SUPPORT_ERROR_MESSAGE_MAX    = 300;

// ── Relay URL ──────────────────────────────────────────────────────────────────

function support_relay_url(): string
{
    if (defined('SUPPORT_RELAY_URL') && is_string(SUPPORT_RELAY_URL) && SUPPORT_RELAY_URL !== '') {
        return SUPPORT_RELAY_URL;
    }
    return 'https://support.oddminds.org/ticket.php';
}

// ── Support code storage (encrypted at rest via the settings table) ────────────

function support_get_code(): string
{
    return trim(setting_get(SUPPORT_SETTING_NAME));
}

/**
 * The code is sent as an HTTP header, so it must be a single token of visible
 * ASCII characters (no spaces, no CR/LF — prevents header injection).
 */
function support_code_is_valid_format(string $code): bool
{
    return (bool) preg_match('/^[\x21-\x7E]{8,200}$/', $code);
}

/** Returns false (and stores nothing) if the code has an invalid format. */
function support_set_code(string $code): bool
{
    $code = trim($code);
    if (!support_code_is_valid_format($code)) {
        return false;
    }
    setting_set(SUPPORT_SETTING_NAME, $code);
    return support_get_code() === $code;
}

function support_is_configured(): bool
{
    return support_get_code() !== '';
}

// ── Input validation ───────────────────────────────────────────────────────────

/**
 * Accepts only a same-site admin path (optionally with a query string), e.g.
 * "/admin/orders.php?id=5". Anything else — absolute URLs, protocol-relative
 * "//evil.com", backslashes, control characters, non-/admin/ paths — returns ''.
 */
function support_validate_page(string $page): string
{
    $page = trim($page);
    if ($page === '' || strlen($page) > SUPPORT_PAGE_MAX) return '';
    if (preg_match('/[\x00-\x1F\x7F\\\\\s]/', $page)) return '';
    if (!str_starts_with($page, '/admin/')) return '';
    if (str_contains($page, '//')) return '';
    if (str_contains($page, '/../') || str_ends_with($page, '/..')) return '';
    $parts = parse_url($page);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) return '';
    if (!str_starts_with($parts['path'] ?? '', '/admin/')) return '';
    return $page;
}

/** Returns a plain-Bulgarian error message, or null if the text is OK. */
function support_validate_text(string $subject, string $description): ?string
{
    if (trim($subject) === '') {
        return 'Моля, напишете кратка тема на проблема.';
    }
    if (mb_strlen($subject) > SUPPORT_SUBJECT_MAX) {
        return 'Темата е твърде дълга — моля, съкратете я до ' . SUPPORT_SUBJECT_MAX . ' знака.';
    }
    if (trim($description) === '') {
        return 'Моля, опишете какво се случи.';
    }
    if (mb_strlen($description) > SUPPORT_DESCRIPTION_MAX) {
        return 'Описанието е твърде дълго — моля, съкратете го до ' . SUPPORT_DESCRIPTION_MAX . ' знака.';
    }
    return null;
}

/**
 * Validates an entry from $_FILES. The real type is detected from the file
 * content (finfo + getimagesize) — the browser-supplied type and file name are
 * ignored.
 *
 * Returns ['ok'=>bool, 'error'=>?string, 'path'=>?string, 'mime'=>?string, 'filename'=>?string].
 * $requireUploaded is only ever false in unit tests.
 */
function support_validate_screenshot(array $upload, bool $requireUploaded = true): array
{
    $fail = static fn(string $msg): array =>
        ['ok' => false, 'error' => $msg, 'path' => null, 'mime' => null, 'filename' => null];

    $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return $fail('Снимката е твърде голяма. Максималният размер е 5 MB.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $fail('Снимката не можа да бъде качена. Моля, опитайте отново.');
    }

    $path = (string)($upload['tmp_name'] ?? '');
    if ($path === '' || !is_file($path)) {
        return $fail('Снимката не можа да бъде качена. Моля, опитайте отново.');
    }
    if ($requireUploaded && !is_uploaded_file($path)) {
        return $fail('Снимката не можа да бъде качена. Моля, опитайте отново.');
    }

    $size = filesize($path);
    if ($size === false || $size <= 0) {
        return $fail('Снимката е празна. Моля, изберете друг файл.');
    }
    if ($size > SUPPORT_SCREENSHOT_MAX_BYTES) {
        return $fail('Снимката е твърде голяма. Максималният размер е 5 MB.');
    }

    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = (string) $finfo->file($path);
    $info    = @getimagesize($path);
    if (!isset($allowed[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
        return $fail('Снимката трябва да е във формат PNG, JPG или WEBP.');
    }

    return [
        'ok'       => true,
        'error'    => null,
        'path'     => $path,
        'mime'     => $mime,
        'filename' => 'screenshot.' . $allowed[$mime],
    ];
}

// ── Diagnostics (strict whitelist) ─────────────────────────────────────────────

/**
 * Last $limit errors from the error_alerts table: timestamp + message only.
 * Never creates the table; returns [] on any problem.
 */
function support_recent_errors(int $limit = 5): array
{
    try {
        if (!defined('DB_HOST')) return [];
        if (!function_exists('get_pdo')) {
            require_once __DIR__ . '/../admin/includes/db.php';
        }
        $limit = max(1, min(20, $limit));
        $stmt  = get_pdo()->query(
            "SELECT created_at, message FROM error_alerts ORDER BY created_at DESC, id DESC LIMIT {$limit}"
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Values of every user-defined constant whose name suggests a secret
 * (KEY/SECRET/PASS/TOKEN). Used to scrub them
 * out of anything we send, as defence in depth (e.g. an error message that
 * happens to contain a password).
 */
function support_secret_values(): array
{
    $values = [];
    foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
        if (!is_scalar($value)) continue;
        if (!preg_match('/KEY|SECRET|PASS|TOKEN|PWD|CREDENTIAL/i', (string)$name)) continue;
        $value = (string)$value;
        if (strlen($value) < 8) continue; // too short to scrub without mangling normal text
        $values[] = $value;
    }
    usort($values, static fn($a, $b) => strlen($b) <=> strlen($a));
    return array_values(array_unique($values));
}

function support_scrub(mixed $data, array $secrets): mixed
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = support_scrub($v, $secrets);
        }
        return $data;
    }
    if (is_string($data) && $secrets) {
        return str_replace($secrets, '[скрито]', $data);
    }
    return $data;
}

function support_truncate(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}

/**
 * Builds the diagnostics sent with a ticket. STRICT WHITELIST — only the keys
 * below are ever produced. Nothing from $_SERVER/$_ENV/config beyond these.
 *
 * $context (all optional; missing values are looked up):
 *   'page'          => string   reported admin path
 *   'user'          => array    ['name','email','role'] (default: admin session)
 *   'user_agent'    => string   (default: $_SERVER['HTTP_USER_AGENT'])
 *   'recent_errors' => array    rows with created_at/message (default: DB)
 *   'version'       => string   (default: updater_get_local_version())
 *   'mail_transport'=> string   (default: mail_transport() if it exists)
 *   'now'           => int      unix timestamp (default: time())
 */
function support_collect_diagnostics(array $context): array
{
    // Reporter
    if (array_key_exists('user', $context)) {
        $user = is_array($context['user']) ? $context['user'] : [];
    } elseif (session_status() === PHP_SESSION_ACTIVE && function_exists('admin_user')) {
        $user = admin_user();
    } else {
        $user = [];
    }

    // Installed version
    if (array_key_exists('version', $context)) {
        $version = (string)$context['version'];
    } else {
        $version = 'unknown';
        if (!function_exists('updater_get_local_version') && is_file(__DIR__ . '/updater.php')) {
            require_once __DIR__ . '/updater.php';
        }
        if (function_exists('updater_get_local_version')) {
            try { $version = updater_get_local_version(); } catch (\Throwable $e) { $version = 'unknown'; }
        }
    }

    // Mail transport (function is being added separately — optional)
    if (array_key_exists('mail_transport', $context)) {
        $transport = (string)$context['mail_transport'];
    } elseif (function_exists('mail_transport')) {
        try { $transport = (string) mail_transport(); } catch (\Throwable $e) { $transport = 'unknown'; }
    } else {
        $transport = 'unknown';
    }

    // Recent errors: timestamp + truncated message ONLY
    $rawErrors = array_key_exists('recent_errors', $context) && is_array($context['recent_errors'])
        ? $context['recent_errors']
        : support_recent_errors(5);
    $errors = [];
    foreach (array_slice(array_values($rawErrors), 0, 5) as $row) {
        if (!is_array($row)) continue;
        $errors[] = [
            'time'    => support_truncate((string)($row['created_at'] ?? ''), 40),
            'message' => support_truncate((string)($row['message'] ?? ''), SUPPORT_ERROR_MESSAGE_MAX),
        ];
    }

    $ua = array_key_exists('user_agent', $context)
        ? (string)$context['user_agent']
        : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $diag = [
        'site_url'       => defined('SITE_URL') ? (string)SITE_URL : '',
        'site_name'      => defined('SITE_NAME_BG') ? (string)SITE_NAME_BG : '',
        'version'        => support_truncate($version, 50),
        'php_version'    => PHP_VERSION,
        'mail_transport' => support_truncate($transport, 50),
        'reporter'       => [
            'name'  => support_truncate((string)($user['name']  ?? ''), 150),
            'email' => support_truncate((string)($user['email'] ?? ''), 200),
            'role'  => support_truncate((string)($user['role']  ?? ''), 50),
        ],
        'page'           => support_validate_page((string)($context['page'] ?? '')),
        'user_agent'     => support_truncate($ua, 300),
        'server_time'    => date('c', (int)($context['now'] ?? time())),
        'recent_errors'  => $errors,
    ];

    return support_scrub($diag, support_secret_values());
}

/** JSON-encodes diagnostics, dropping recent errors if needed to fit the limit. */
function support_encode_diagnostics(array $diag): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $json  = (string) json_encode($diag, $flags);
    while (strlen($json) > SUPPORT_DIAGNOSTICS_MAX && !empty($diag['recent_errors'])) {
        array_pop($diag['recent_errors']);
        $json = (string) json_encode($diag, $flags);
    }
    if (strlen($json) > SUPPORT_DIAGNOSTICS_MAX) {
        $json = (string) json_encode(['site_url' => $diag['site_url'] ?? '', 'truncated' => true], $flags);
    }
    return $json;
}

// ── Relay errors → plain Bulgarian ─────────────────────────────────────────────

function support_error_message(string $code): string
{
    return match ($code) {
        'unauthorized'   => 'Кодът за поддръжка не е валиден. Помолете администратора на сайта да провери кода в страница „Поддръжка“ или се свържете с нас.',
        'rate_limited'   => 'Изпратихте много сигнали за кратко време. Моля, опитайте отново след около час.',
        'invalid'        => 'Сървърът за поддръжка не прие сигнала. Проверете темата, описанието и снимката и опитайте отново.',
        'network'        => 'Не успяхме да се свържем със сървъра за поддръжка. Моля, опитайте отново след малко.',
        'not_configured' => 'Все още не е въведен код за поддръжка. Помолете администратора на сайта да го въведе.',
        default          => 'Сървърът за поддръжка има временен проблем. Моля, опитайте отново по-късно.',
    };
}

// ── Sending ────────────────────────────────────────────────────────────────────

/**
 * Default transport: multipart POST via curl.
 * Returns ['status'=>int, 'body'=>string, 'error'=>?string] (error = network failure).
 */
function support_http_post(string $url, array $headers, array $fields): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'curl not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields, // array → multipart/form-data
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = $body === false ? (curl_error($ch) ?: 'request failed') : null;
    return ['status' => $status, 'body' => $body === false ? '' : (string)$body, 'error' => $error];
}

/**
 * Validates and sends a ticket to the relay.
 *
 * @param array|null    $screenshotUpload a $_FILES entry, or null
 * @param callable|null $httpPost fn(string $url, array $headers, array $fields): array{status,body,error}
 * @param array         $options  test hooks: 'code' (override stored code),
 *                                'diagnostics_context' (merged into diagnostics context),
 *                                'require_uploaded' (bool, default true)
 * @return array{ok:bool, reference:?string, error:?string}
 */
function support_send_ticket(
    string $subject,
    string $description,
    string $page,
    ?array $screenshotUpload,
    ?callable $httpPost = null,
    array $options = []
): array {
    $fail = static fn(string $msg): array => ['ok' => false, 'reference' => null, 'error' => $msg];

    $subject     = trim($subject);
    $description = trim($description);
    $textError   = support_validate_text($subject, $description);
    if ($textError !== null) return $fail($textError);

    $code = array_key_exists('code', $options) ? trim((string)$options['code']) : support_get_code();
    if ($code === '') return $fail(support_error_message('not_configured'));
    if (!support_code_is_valid_format($code)) return $fail(support_error_message('unauthorized'));

    $page = support_validate_page($page);

    $screenshot = null;
    if ($screenshotUpload !== null && (int)($screenshotUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $screenshot = support_validate_screenshot($screenshotUpload, (bool)($options['require_uploaded'] ?? true));
        if (!$screenshot['ok']) return $fail($screenshot['error']);
    }

    $diagContext = array_merge((array)($options['diagnostics_context'] ?? []), ['page' => $page]);
    $diagnostics = support_encode_diagnostics(support_collect_diagnostics($diagContext));

    $fields = [
        'subject'     => $subject,
        'description' => $description,
        'page'        => $page,
        'diagnostics' => $diagnostics,
    ];
    if ($screenshot !== null) {
        $fields['screenshot'] = new CURLFile($screenshot['path'], $screenshot['mime'], $screenshot['filename']);
    }
    $headers = [
        'X-Support-Key: ' . $code,
        'Accept: application/json',
    ];

    $httpPost ??= 'support_http_post';
    try {
        $res = $httpPost(support_relay_url(), $headers, $fields);
    } catch (\Throwable $e) {
        error_log('support_send_ticket: ' . $e->getMessage());
        return $fail(support_error_message('network'));
    }

    $status = (int)($res['status'] ?? 0);
    if (!empty($res['error']) || $status === 0) {
        error_log('support_send_ticket: network error: ' . (string)($res['error'] ?? 'no response'));
        return $fail(support_error_message('network'));
    }

    $json = json_decode((string)($res['body'] ?? ''), true);
    if ($status === 200 && is_array($json) && ($json['ok'] ?? false) === true
        && is_string($json['reference'] ?? null) && $json['reference'] !== '') {
        return ['ok' => true, 'reference' => support_truncate($json['reference'], 60), 'error' => null];
    }

    $errCode = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : '';
    if ($errCode === '') {
        $errCode = match (true) {
            $status === 401, $status === 403 => 'unauthorized',
            $status === 429                  => 'rate_limited',
            $status === 400, $status === 413, $status === 422 => 'invalid',
            default                          => 'server_error',
        };
    }
    error_log('support_send_ticket: relay returned HTTP ' . $status . ' (' . $errCode . ')');
    return $fail(support_error_message($errCode));
}
