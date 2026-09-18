<?php
$_mailer_root = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
if (!defined('SITE_NAME_BG')) require_once $_mailer_root . '/config.php';
// Microsoft Graph mail credentials (gitignored). Optional: when absent the site
// still runs, but send_mail() no-ops with a logged warning until configured.
if (is_file($_mailer_root . '/graph.config.php')) require_once $_mailer_root . '/graph.config.php';
if (!function_exists('setting_get')) require_once $_mailer_root . '/includes/settings.php';
require_once $_mailer_root . '/includes/email-templates.php';

function email_wrap(string $content): string {
    $logo = (defined('SITE_URL') ? SITE_URL : '') . '/assets/images/logo.png';
    $name = defined('SITE_NAME_BG') ? SITE_NAME_BG : 'Your Organisation';
    $email = defined('SITE_EMAIL') ? SITE_EMAIL : '';
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
  body{font-family:Arial,sans-serif;background:#f8f6f2;margin:0;padding:20px;color:#1a1916;}
  .ew{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);}
  .eh{background:#f8f6f2;padding:24px 32px;text-align:center;}
  .eh img{height:48px;}
  .eb{padding:32px;line-height:1.75;}
  h2{color:#0387A5;margin-top:0;}
  .ef{background:#f8f6f2;padding:16px 32px;font-size:12px;color:#9b9590;text-align:center;border-top:1px solid #e8ddd5;}
  table{width:100%;border-collapse:collapse;margin:16px 0;}
  th{background:#f8f6f2;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#6b6560;letter-spacing:.05em;}
  td{padding:10px 12px;border-top:1px solid #e8ddd5;font-size:14px;}
  .box{background:#e4f0f5;padding:16px;border-radius:6px;margin:16px 0;}
  a{color:#0387A5;}
  @media only screen and (max-width:480px){
    .eh{padding:20px !important;}
    .eb{padding:20px !important;}
    .nl-card-img{display:block !important;width:100% !important;max-width:100% !important;padding-right:0 !important;padding-bottom:12px !important;}
    .nl-card-img img{max-width:160px !important;margin:0 auto;}
    .nl-card-body{display:block !important;width:100% !important;}
  }
</style>
</head>
<body style="font-family:Arial,sans-serif;background:#f8f6f2;margin:0;padding:20px;color:#1a1916;">
<div class="ew" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
  <div class="eh" style="background:#f8f6f2;padding:24px 32px;text-align:center;"><img src="{$logo}" alt="{$name}" style="height:48px;display:block;margin:0 auto;"></div>
  <div class="eb" style="padding:32px;line-height:1.75;background:#ffffff;color:#1a1916;">{$content}</div>
  <div class="ef" style="background:#f8f6f2;padding:16px 32px;font-size:12px;color:#9b9590;text-align:center;border-top:1px solid #e8ddd5;">{$name} &bull; <a href="mailto:{$email}" style="color:#0387A5;">{$email}</a></div>
</div>
</body>
</html>
HTML;
}

function render_email(string $template, array $vars = []): string {
    $path = ($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__)) . '/includes/emails/' . $template . '.php';
    extract($vars, EXTR_SKIP);
    ob_start();
    include $path;
    $body = ob_get_clean();
    return email_wrap($body);
}

/**
 * Return the rendered subject line for a template from email_tpl_get().
 * Falls back to an empty string if the helper isn't loaded.
 *
 * @param array<string,scalar> $vars
 */
function render_email_subject(string $key, string $lang = 'bg', array $vars = []): string {
    if (!function_exists('email_tpl_get')) {
        require_once ($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__)) . '/includes/email-templates.php';
    }
    return email_tpl_get($key, $lang, $vars)['subject'];
}

// ══════════════════════════════════════════════════════════════════════════════
// Mail transport selection
//
//   SMTP (DB settings, admin/email-settings.php)  →  Microsoft Graph
//   (graph.config.php constants)  →  none (send_mail() no-ops + logs a warning)
// ══════════════════════════════════════════════════════════════════════════════

/** Settings keys used by the SMTP transport (all encrypted at rest by setting_set). */
const MAIL_SMTP_SETTING_KEYS = [
    'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
    'mail_from_email', 'mail_from_name',
];

/** Allowed values for smtp_encryption. */
const MAIL_SMTP_ENCRYPTIONS = ['tls', 'ssl', 'none'];

/** Default port for each encryption mode (cPanel conventions). */
function mail_smtp_default_port(string $encryption): int
{
    return match ($encryption) {
        'ssl'  => 465,
        'none' => 25,
        default => 587,
    };
}

/** True when the Microsoft Graph constants from graph.config.php are all present. */
function mail_graph_is_configured(): bool
{
    return defined('GRAPH_TENANT_ID') && defined('GRAPH_CLIENT_ID')
        && defined('GRAPH_CLIENT_SECRET') && defined('GRAPH_FROM');
}

/**
 * Current SMTP config from the settings table, with defaults applied.
 *
 * @return array{host:string,port:int,encryption:string,username:string,password:string,from_email:string,from_name:string}
 */
function mail_smtp_config(): array
{
    $enc = setting_get('smtp_encryption', 'tls');
    if (!in_array($enc, MAIL_SMTP_ENCRYPTIONS, true)) $enc = 'tls';
    $port = (int) setting_get('smtp_port', '0');
    if ($port < 1 || $port > 65535) $port = mail_smtp_default_port($enc);

    return [
        'host'       => trim(setting_get('smtp_host')),
        'port'       => $port,
        'encryption' => $enc,
        'username'   => setting_get('smtp_username'),
        'password'   => setting_get('smtp_password'),
        'from_email' => setting_get('mail_from_email'),
        'from_name'  => setting_get('mail_from_name'),
    ];
}

/** An SMTP config is usable once a server address is set. Auth is optional. */
function mail_smtp_config_is_complete(array $cfg): bool
{
    return trim((string) ($cfg['host'] ?? '')) !== '';
}

/**
 * Pure transport-selection rule: SMTP wins, then Graph, else none.
 *
 * @return 'smtp'|'graph'|'none'
 */
function mail_select_transport(bool $smtp_configured, bool $graph_configured): string
{
    if ($smtp_configured)  return 'smtp';
    if ($graph_configured) return 'graph';
    return 'none';
}

/** @return 'smtp'|'graph'|'none' */
function mail_transport(): string
{
    return mail_select_transport(
        mail_smtp_config_is_complete(mail_smtp_config()),
        mail_graph_is_configured()
    );
}

function mail_is_configured(): bool
{
    return mail_transport() !== 'none';
}

/** Human (Bulgarian) label for a transport key, for the admin UI. */
function mail_transport_label(string $transport): string
{
    return match ($transport) {
        'smtp'  => 'SMTP (имейл сървър на хостинга)',
        'graph' => 'Microsoft 365',
        default => 'Няма — имейлите не се изпращат',
    };
}

// ── Settings form: validation + "empty password keeps existing" ────────────────

/**
 * Validate the SMTP settings form. Pure — no DB access.
 *
 * Returns the values to write and any plain-language errors. The password is
 * only included in `values` when a new one was typed: an empty submitted
 * password means "keep the saved one", so it is simply left out.
 *
 * @param array<string,mixed> $post
 * @return array{values: array<string,string>, errors: list<string>}
 */
function mail_smtp_validate_input(array $post): array
{
    $errors = [];
    $str = static fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';

    $host = strtolower($str('smtp_host'));
    if ($host === '') {
        $errors[] = 'Попълнете адреса на имейл сървъра (например mail.вашият-сайт.bg).';
    } elseif (!mail_is_valid_host($host)) {
        $errors[] = 'Адресът на сървъра не изглежда правилен. Въведете само името, например mail.example.org — без „https://“ и без наклонени черти.';
    }

    $enc = $str('smtp_encryption');
    if (!in_array($enc, MAIL_SMTP_ENCRYPTIONS, true)) {
        $errors[] = 'Изберете вид защита на връзката от списъка.';
        $enc = 'tls';
    }

    $port_raw = $str('smtp_port');
    if ($port_raw === '') {
        $port = mail_smtp_default_port($enc);
    } elseif (!ctype_digit($port_raw) || (int) $port_raw < 1 || (int) $port_raw > 65535) {
        $errors[] = 'Портът трябва да е число между 1 и 65535 (обикновено 465 или 587).';
        $port = 0;
    } else {
        $port = (int) $port_raw;
    }

    $username = $str('smtp_username');
    if (strlen($username) > 255) {
        $errors[] = 'Потребителското име е твърде дълго.';
    }

    // Password: never trimmed (spaces can be part of it), only length-checked.
    $password = is_string($post['smtp_password'] ?? null) ? $post['smtp_password'] : '';
    if (strlen($password) > 1000) {
        $errors[] = 'Паролата е твърде дълга.';
    }

    $from_email = $str('mail_from_email');
    if ($from_email !== '' && filter_var($from_email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Имейлът на подателя не е валиден адрес.';
    }

    $from_name = $str('mail_from_name');
    // Strip control chars / newlines (header injection safety; PHPMailer also guards this).
    $from_name = preg_replace('/[\x00-\x1F\x7F]/u', '', $from_name) ?? '';
    if (mb_strlen($from_name) > 150) {
        $errors[] = 'Името на подателя е твърде дълго (до 150 знака).';
    }

    $values = [
        'smtp_host'       => $host,
        'smtp_port'       => (string) $port,
        'smtp_encryption' => $enc,
        'smtp_username'   => $username,
        'mail_from_email' => $from_email,
        'mail_from_name'  => $from_name,
    ];
    if ($password !== '') {
        $values['smtp_password'] = $password;
    }

    return ['values' => $values, 'errors' => $errors];
}

/** Hostname (RFC 1123, IDN allowed after punycode-free check) or IP address. */
function mail_is_valid_host(string $host): bool
{
    if ($host === '' || strlen($host) > 253) return false;
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
    if (!str_contains($host, '.') && $host !== 'localhost') return false;
    return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
}

/** Persist validated values (from mail_smtp_validate_input). Encryption is done by setting_set. */
function mail_smtp_settings_save(array $values): void
{
    foreach ($values as $key => $value) {
        if (in_array($key, MAIL_SMTP_SETTING_KEYS, true)) {
            setting_set($key, (string) $value);
        }
    }
}

/** Remove all SMTP settings (falls back to Microsoft 365 / none). */
function mail_smtp_settings_clear(): void
{
    foreach (MAIL_SMTP_SETTING_KEYS as $key) {
        setting_delete($key);
    }
}

// ── SMTP sending ────────────────────────────────────────────────────────────────

/**
 * Build a fully-configured PHPMailer instance for one message WITHOUT sending it.
 * Separated from sending so it can be unit-tested with no network access.
 *
 * @param array<array{path:string,name:string}> $attachments
 * @throws \PHPMailer\PHPMailer\Exception on invalid addresses / unreadable attachments
 */
function mail_build_smtp_mailer(array $cfg, string $to, string $subject, string $body_html,
                                string $reply_to = '', array $attachments = []): \PHPMailer\PHPMailer\PHPMailer
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    $m = new \PHPMailer\PHPMailer\PHPMailer(true); // exceptions on
    $m->isSMTP();
    $m->Host       = (string) $cfg['host'];
    $m->Port       = (int) $cfg['port'];
    $m->SMTPDebug  = 0;      // never echo protocol chatter to the browser
    $m->Timeout    = 15;
    $m->CharSet    = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $m->Encoding   = 'base64';

    switch ($cfg['encryption'] ?? 'tls') {
        case 'ssl':
            $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $m->SMTPAutoTLS = false;
            break;
        case 'none':
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
            break;
        default:
            $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $username = (string) ($cfg['username'] ?? '');
    if ($username !== '') {
        $m->SMTPAuth = true;
        $m->Username = $username;
        $m->Password = (string) ($cfg['password'] ?? '');
    } else {
        $m->SMTPAuth = false;
    }

    // Shared hosts usually reject or spam-flag mail whose From differs from the
    // authenticated mailbox, so the login address beats SITE_EMAIL as a default.
    $from_email = (string) ($cfg['from_email'] ?? '');
    if ($from_email === '' && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $from_email = $username;
    }
    if ($from_email === '') $from_email = defined('SITE_EMAIL') ? (string) SITE_EMAIL : '';
    $from_name = (string) ($cfg['from_name'] ?? '');
    if ($from_name === '') $from_name = defined('SITE_NAME_BG') ? (string) SITE_NAME_BG : '';

    $m->setFrom($from_email, $from_name);
    $m->addAddress($to);
    if ($reply_to !== '') {
        $m->addReplyTo($reply_to);
    }
    foreach ($attachments as $att) {
        $m->addAttachment($att['path'], $att['name']);
    }

    $m->isHTML(true);
    $m->Subject = $subject;
    $m->Body    = $body_html;
    $m->AltBody = trim(html_entity_decode(strip_tags(
        preg_replace(['/<(br|\/p|\/div|\/h[1-6]|\/tr|\/li)[^>]*>/i', '/<(style|script)[^>]*>.*?<\/\1>/is'], ["\n", ''], $body_html) ?? $body_html
    ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return $m;
}

/**
 * Test seam: replace the function that actually delivers a built PHPMailer.
 * Pass null to restore the real sender. The callable receives the PHPMailer
 * instance and must return bool (or throw).
 */
function mail_set_smtp_sender(?callable $sender): void
{
    $GLOBALS['_mail_smtp_sender'] = $sender;
}

/** Raw technical reason for the last failed send (server-side use only — never echo it). */
function mail_last_error(): string
{
    return (string) ($GLOBALS['_mail_last_error'] ?? '');
}

function _mail_set_last_error(string $msg): void
{
    $GLOBALS['_mail_last_error'] = $msg;
}

/** @param array<array{path:string,name:string}> $attachments */
function mail_send_smtp(array $cfg, string $to, string $subject, string $body_html,
                        string $reply_to = '', array $attachments = []): bool
{
    $mailer = null;
    try {
        $mailer = mail_build_smtp_mailer($cfg, $to, $subject, $body_html, $reply_to, $attachments);
        $sender = $GLOBALS['_mail_smtp_sender'] ?? null;
        $ok = is_callable($sender) ? (bool) $sender($mailer) : $mailer->send();
        if (!$ok) {
            _mail_set_last_error($mailer->ErrorInfo ?: 'send() returned false');
            error_log('send_mail (SMTP): ' . mail_last_error());
        }
        return $ok;
    } catch (Throwable $e) {
        $info = $mailer instanceof \PHPMailer\PHPMailer\PHPMailer && $mailer->ErrorInfo !== ''
            ? $mailer->ErrorInfo : $e->getMessage();
        _mail_set_last_error($info);
        error_log('send_mail (SMTP) to ' . $to . ' failed: ' . $info);
        return false;
    }
}

/**
 * Turn a raw PHPMailer / SMTP error into a short plain-Bulgarian explanation
 * for non-technical staff. Never includes the raw text.
 */
function mail_explain_error(string $raw): string
{
    $r = strtolower($raw);
    return match (true) {
        str_contains($r, 'could not authenticate'), str_contains($r, 'authentication'),
        str_contains($r, '535'), str_contains($r, 'username and password')
            => 'Сървърът не прие потребителското име или паролата. Проверете ги (обикновено потребителското име е целият имейл адрес) и опитайте отново.',
        str_contains($r, 'certificate'), str_contains($r, 'crypto'),
        str_contains($r, 'ssl operation'), str_contains($r, 'starttls'),
        str_contains($r, 'wrong version number'), str_contains($r, 'handshake')
            => 'Не успяхме да установим защитена връзка със сървъра. Опитайте другата комбинация: порт 465 със „SSL“ или порт 587 с „TLS“.',
        str_contains($r, 'could not connect'), str_contains($r, 'failed to connect'),
        str_contains($r, 'connection refused'), str_contains($r, 'timed out'),
        str_contains($r, 'getaddrinfo'), str_contains($r, 'name or service not known'),
        str_contains($r, 'network is unreachable')
            => 'Не успяхме да се свържем с имейл сървъра. Проверете адреса на сървъра и порта (обикновено 465 или 587).',
        str_contains($r, 'sender'), str_contains($r, 'from address'),
        str_contains($r, 'mail from'), str_contains($r, 'not owned'), str_contains($r, 'not permitted')
            => 'Сървърът отказа адреса на подателя. Имейлът на подателя обикновено трябва да е същият като потребителското име.',
        str_contains($r, 'recipient'), str_contains($r, 'rcpt')
            => 'Сървърът отказа адреса на получателя. Проверете дали имейлът е написан правилно.',
        str_contains($r, 'invalid address')
            => 'Един от имейл адресите (подател или получател) не е валиден.',
        default
            => 'Имейлът не беше изпратен. Проверете настройките и опитайте отново. Ако проблемът продължава, свържете се с хостинг доставчика си.',
    };
}

// ══════════════════════════════════════════════════════════════════════════════
// send_mail — public API used across the site
// ══════════════════════════════════════════════════════════════════════════════

/**
 * @param array<array{path:string,name:string}> $attachments
 */
function send_mail(string $to, string $subject, string $body_html, string $reply_to = '', array $attachments = []): bool
{
    _mail_set_last_error('');

    $smtp = mail_smtp_config();
    $transport = mail_select_transport(mail_smtp_config_is_complete($smtp), mail_graph_is_configured());

    if ($transport === 'smtp') {
        return mail_send_smtp($smtp, $to, $subject, $body_html, $reply_to, $attachments);
    }

    if ($transport === 'none') {
        // Without any transport we fail gracefully rather than fatally so the
        // rest of the site keeps working. Admins see a warning on the dashboard.
        _mail_set_last_error('No mail transport configured');
        error_log('send_mail: no mail transport configured (no SMTP settings, no graph.config.php) — email not sent.');
        return false;
    }

    return mail_send_graph($to, $subject, $body_html, $reply_to, $attachments);
}

/**
 * Microsoft Graph transport (unchanged behaviour; requires graph.config.php).
 *
 * @param array<array{path:string,name:string}> $attachments
 */
function mail_send_graph(string $to, string $subject, string $body_html, string $reply_to = '', array $attachments = []): bool
{
    // Fetch OAuth2 access token via client credentials flow
    $ch = curl_init('https://login.microsoftonline.com/' . GRAPH_TENANT_ID . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => GRAPH_CLIENT_ID,
            'client_secret' => GRAPH_CLIENT_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $token_response = curl_exec($ch);
    $token_status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($token_status !== 200) {
        error_log('Graph API token error (' . $token_status . '): ' . $token_response);
        _mail_set_last_error('Microsoft 365 authentication failed (HTTP ' . $token_status . ')');
        return false;
    }

    $access_token = json_decode($token_response, true)['access_token'] ?? '';
    if ($access_token === '') {
        error_log('Graph API: empty access token');
        _mail_set_last_error('Microsoft 365 authentication failed (empty token)');
        return false;
    }

    // Build message payload
    $message = [
        'subject' => $subject,
        'body'    => ['contentType' => 'HTML', 'content' => $body_html],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
    ];

    if ($reply_to !== '') {
        $message['replyTo'] = [['emailAddress' => ['address' => $reply_to]]];
    }

    if (!empty($attachments)) {
        $message['attachments'] = [];
        foreach ($attachments as $att) {
            $message['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $att['name'],
                'contentBytes' => base64_encode((string) file_get_contents($att['path'])),
            ];
        }
    }

    $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . GRAPH_FROM . '/sendMail');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['message' => $message, 'saveToSentItems' => false]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
    ]);
    $send_response = curl_exec($ch);
    $send_status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($send_status !== 202) {
        error_log('Graph API send error (' . $send_status . '): ' . $send_response);
        _mail_set_last_error('Microsoft 365 send failed (HTTP ' . $send_status . ')');
        return false;
    }

    return true;
}
