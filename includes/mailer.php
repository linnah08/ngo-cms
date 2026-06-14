<?php
$_mailer_root = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
if (!defined('SITE_NAME_BG')) require_once $_mailer_root . '/config.php';
require_once $_mailer_root . '/graph.config.php';
if (!function_exists('setting_get')) require_once $_mailer_root . '/includes/settings.php';
require_once $_mailer_root . '/includes/email-templates.php';

function email_wrap(string $content): string {
    $logo = (defined('SITE_URL') ? SITE_URL : '') . '/assets/images/logo.png';
    $name = defined('SITE_NAME_BG') ? SITE_NAME_BG : 'Odd Minds Foundation';
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

/**
 * @param array<array{path:string,name:string}> $attachments
 */
function send_mail(string $to, string $subject, string $body_html, string $reply_to = '', array $attachments = []): bool
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
        return false;
    }

    $access_token = json_decode($token_response, true)['access_token'] ?? '';
    if ($access_token === '') {
        error_log('Graph API: empty access token');
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
        return false;
    }

    return true;
}
