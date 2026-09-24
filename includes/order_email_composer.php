<?php
/**
 * "Имейл до клиента" — an admin writes to the buyer of an order from the order
 * page (admin/order-view.php) or the pledge page (admin/pledge-view.php).
 *
 * The "Готов шаблон" picker fills in a ready-made message (admin_message_presets(),
 * editable under Съдържание → Имейл шаблони) in the buyer's language; the admin
 * can change the text before sending. Every message goes out through
 * send_order_mail(), so it lands in the order's email history next to the
 * automatic emails.
 *
 * The card itself is admin/includes/order-email-composer.php.
 */
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/mailer.php';

/** Picker value that only fills in a greeting, for a message written from scratch. */
const ORDER_EMAIL_BLANK = 'blank';

/** Language the buyer gets their email in. */
function order_email_lang(array $order): string
{
    return ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
}

/**
 * The choices in the "Готов шаблон" picker for this order, in display order,
 * already filled in with the order's details in the buyer's language.
 *
 * A choice with available=false is shown greyed out with its `note`, has no
 * text, and is never accepted by order_email_send().
 *
 * @return array<string,array{label:string,available:bool,note:string,subject:string,body:string}>
 */
function order_email_choices(array $order): array
{
    $lang = order_email_lang($order);
    $type = (string)($order['type'] ?? '');
    $vars = [
        'customer_name' => (string)($order['customer_name'] ?? ''),
        'order_number'  => (string)($order['order_number'] ?? ''),
        'amount_eur'    => number_format((float)($order['total_eur'] ?? 0), 2, '.', ''),
    ];

    $choices = [];
    foreach (admin_message_presets() as $key => $meta) {
        if (!in_array($type, $meta['types'], true)) continue;
        $t = email_tpl_get($key, $lang, $vars);
        $choices[$key] = [
            'label'     => $meta['label'],
            'available' => true,
            'note'      => '',
            'subject'   => $t['subject'],
            'body'      => trim($t['intro'] . "\n" . $t['outro']),
        ];
    }

    $choices[ORDER_EMAIL_BLANK] = [
        'label'     => 'Празно (само поздрав)',
        'available' => true,
        'note'      => '',
        'subject'   => '',
        'body'      => '<p>' . ($lang === 'en' ? 'Hello ' : 'Здравейте, ')
                     . htmlspecialchars($vars['customer_name'], ENT_QUOTES, 'UTF-8') . ',</p><p></p>',
    ];
    return $choices;
}

/**
 * Send the admin's message to the buyer and record it in the order's email history.
 *
 * $preset is the picker value the message started from; anything the picker
 * does not offer for this order is ignored (the message is then a plain
 * "Ръчно съобщение").
 *
 * @param callable|null $mailer fn(int $order_id, string $to, string $subject, string $html, array $opts): bool
 *                              — defaults to send_order_mail()
 * @return array{ok:bool,error:string,recipient:string,template_key:string}
 */
function order_email_send(PDO $pdo, int $order_id, array $order, string $subject, string $body, string $preset, ?callable $mailer = null): array
{
    $recipient = trim((string)($order['customer_email'] ?? ''));
    $subject   = trim(str_replace(["\r", "\n"], ' ', $subject));
    $body      = trim($body);
    $fail      = fn(string $error): array => ['ok' => false, 'error' => $error, 'recipient' => $recipient, 'template_key' => ''];

    if ($order_id <= 0) {
        return $fail('Поръчката не е намерена.');
    }
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return $fail('Поръчката няма валиден имейл адрес, затова не може да се изпрати имейл.');
    }
    if ($subject === '') {
        return $fail('Въведете тема на имейла.');
    }
    // TinyMCE's "empty" editor is still markup (<p>&nbsp;</p>), so look at the visible text.
    $visible = preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
    if ($visible === '' && !str_contains($body, '<img')) {
        return $fail('Въведете съобщение.');
    }

    $choices = order_email_choices($order);
    if (!isset($choices[$preset]) || !$choices[$preset]['available']) {
        $preset = '';
    }
    $template_key = ($preset === '' || $preset === ORDER_EMAIL_BLANK) ? 'admin-message' : $preset;

    $html   = render_email('admin-message', ['order' => $order, 'message_html' => $body]);
    $mailer ??= 'send_order_mail';
    $ok = (bool)$mailer($order_id, $recipient, mb_substr($subject, 0, 255), $html, [
        'template_key' => $template_key,
        'reply_to'     => defined('SITE_EMAIL') ? SITE_EMAIL : '',
    ]);

    if (!$ok) {
        return $fail('Имейлът не беше изпратен. Опитайте отново след малко. Ако пак не стане, може би изпращането на имейли не е настроено (меню „Имейл“) — обърнете се към администратора на сайта.');
    }
    return ['ok' => true, 'error' => '', 'recipient' => $recipient, 'template_key' => $template_key];
}
