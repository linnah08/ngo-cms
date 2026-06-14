<?php
/**
 * Email template content management.
 *
 * Content is stored in the settings table as JSON under the key
 * "email_tpl_{key}", with fields: subject_bg, intro_bg, outro_bg,
 * subject_en, intro_en, outro_en.
 *
 * Templates support simple {{variable}} placeholders that are replaced
 * via email_tpl_render(). The PHP template files handle dynamic data
 * blocks (tables, conditional sections); only the free-text parts
 * (subject, intro, outro) are stored here.
 */

function _email_tpl_defaults(): array {
    return [

        'campaign-confirmation' => [
            'subject_bg' => 'Благодарим ти! Твоята подкрепа за кампанията — {{pledge_number}}',
            'intro_bg'   => '<h2>Благодарим за твоята подкрепа!</h2>'
                          . '<p>Здравей, {{name}},</p>'
                          . '<p>Получихме успешно твоята подкрепа за кампанията. Ти си невероятен!</p>',
            'outro_bg'   => '<p>Твоят принос директно помага на децата от програмата на нашата фондация.</p>',
            'subject_en' => 'Thank you! Your support for our campaign — {{pledge_number}}',
            'intro_en'   => '<h2>Thank you for your support!</h2>'
                          . '<p>Hello {{name}},</p>'
                          . '<p>We have successfully received your support for our campaign. You are amazing!</p>',
            'outro_en'   => '<p>Your contribution directly helps the children in our foundation’s programme.</p>',
        ],

        'campaign-ticket' => [
            'subject_bg' => 'Твоят билет за {{event_name}} — {{pledge_number}}',
            'intro_bg'   => '<h2>Твоят билет за {{event_name}}!</h2>'
                          . '<p>Здравей, {{name}},</p>'
                          . '<p>Твоята покупка е потвърдена. Намираш билета си в прикачения PDF файл. Моля, представи го (на хартия или на екран) при влизане на събитието.</p>',
            'outro_bg'   => '<p>Ще се видим скоро! Благодарим, че подкрепяш децата от програмата на нашата фондация.</p>',
            'subject_en' => 'Your ticket for {{event_name}} — {{pledge_number}}',
            'intro_en'   => '<h2>Your ticket for {{event_name}}!</h2>'
                          . '<p>Hello {{name}},</p>'
                          . '<p>Your purchase is confirmed. Your ticket is attached as a PDF. Please present it (printed or on screen) when entering the event.</p>',
            'outro_en'   => '<p>See you soon! Thank you for supporting the children in our foundation’s programme.</p>',
        ],

        'order-confirmation-customer' => [
            'subject_bg' => 'Благодарим за вашата поръчка! — {{order_number}}',
            'intro_bg'   => '<h2>Благодарим за вашата поръчка!</h2>'
                          . '<p>Здравейте, {{customer_name}},</p>'
                          . '<p>Получихме вашата поръчка и ще я обработим в рамките на 24 часа.</p>',
            'outro_bg'   => '<p>Ще получите потвърждение от нас в рамките на 24 часа.</p>',
            'subject_en' => 'Thank you for your order! — {{order_number}}',
            'intro_en'   => '<h2>Thank you for your order!</h2>'
                          . '<p>Hello {{customer_name}},</p>'
                          . '<p>We have received your order and will process it within 24 hours.</p>',
            'outro_en'   => '<p>You will receive a confirmation from us within 24 hours.</p>',
        ],

        'order-shipped-customer' => [
            'subject_bg' => 'Вашата поръчка е изпратена — {{order_number}}',
            'intro_bg'   => '<h2>Вашата поръчка е изпратена! 📦</h2>'
                          . '<p>Здравейте, {{customer_name}},</p>'
                          . '<p>Поръчка <strong>{{order_number}}</strong> е изпратена с {{courier}}.</p>',
            'outro_bg'   => '<p>Очаквана доставка: 1–3 работни дни.</p>',
            'subject_en' => 'Your order has been shipped — {{order_number}}',
            'intro_en'   => '<h2>Your order has been shipped! 📦</h2>'
                          . '<p>Hello {{customer_name}},</p>'
                          . '<p>Order <strong>{{order_number}}</strong> has been dispatched via {{courier}}.</p>',
            'outro_en'   => '<p>Expected delivery: 1–3 working days.</p>',
        ],

        'donation-confirmation-customer' => [
            'subject_bg' => 'Благодарим за вашето дарение!',
            'intro_bg'   => '<h2>Благодарим за вашето дарение!</h2>'
                          . '<p>Здравейте, {{donor_name}},</p>'
                          . '<p>Получихме успешно вашето дарение от <strong>{{amount_eur}} €</strong> за Фондация Различни умове. Вашата подкрепа прави истинска разлика в живота на децата, на които помагаме.</p>',
            'outro_bg'   => '<p>Вашето дарение ще помогне на деца с увреждания и деца, лишени от родителска грижа, да имат по-добро бъдеще.</p>',
            'subject_en' => 'Thank you for your donation!',
            'intro_en'   => '<h2>Thank you for your donation!</h2>'
                          . '<p>Hello {{donor_name}},</p>'
                          . '<p>We have successfully received your donation of <strong>{{amount_eur}} €</strong> to the Different Minds Foundation. Your support makes a real difference in the lives of the children we help.</p>',
            'outro_en'   => '<p>Your donation will help children with disabilities and children deprived of parental care to have a better future.</p>',
        ],

        'order-cancelled-customer' => [
            'subject_bg' => 'Вашата поръчка {{order_number}} е отменена',
            'intro_bg'   => '<h2>Поръчката е отменена</h2>'
                          . '<p>Здравейте, {{customer_name}},</p>'
                          . '<p>Вашата поръчка <strong>{{order_number}}</strong> е отменена и сумата ще бъде върната по картата, с която е извършено плащането, в рамките на 3–5 работни дни.</p>',
            'outro_bg'   => '<p>Ако имате въпроси, не се колебайте да се свържете с нас.</p>',
            'subject_en' => 'Your order {{order_number}} has been cancelled',
            'intro_en'   => '<h2>Order cancelled</h2>'
                          . '<p>Hello {{customer_name}},</p>'
                          . '<p>Your order <strong>{{order_number}}</strong> has been cancelled and the amount will be returned to your card within 3–5 working days.</p>',
            'outro_en'   => '<p>If you have any questions, please don\'t hesitate to contact us.</p>',
        ],

        'credit-note-customer' => [
            'subject_bg' => 'Кредитен документ към поръчка {{order_number}}',
            'intro_bg'   => '<h2>Кредитен документ</h2>'
                          . '<p>Здравейте, {{customer_name}},</p>'
                          . '<p>Вашата поръчка <strong>{{order_number}}</strong> е отменена. '
                          . 'Намирате кредитното известие (№{{doc_number}}) като прикачен PDF файл.</p>',
            'outro_bg'   => '<p>Сумата ще бъде върната по картата, с която е извършено плащането, в рамките на 3–5 работни дни.</p>',
            'subject_en' => 'Credit document for order {{order_number}}',
            'intro_en'   => '<h2>Credit document</h2>'
                          . '<p>Hello {{customer_name}},</p>'
                          . '<p>Your order <strong>{{order_number}}</strong> has been cancelled. '
                          . 'Please find the credit note (№{{doc_number}}) attached as a PDF.</p>',
            'outro_en'   => '<p>The amount will be returned to your card within 3–5 working days.</p>',
        ],

        'pledge-reversed-customer' => [
            'subject_bg' => 'Върнато плащане — {{pledge_number}}',
            'intro_bg'   => '<h2>Върнато плащане</h2>'
                          . '<p>Здравей {{name}},</p>'
                          . '<p>Плащането ти по кампанията в размер на <strong>{{amount_eur}} EUR</strong> '
                          . '(референтен номер <strong>{{pledge_number}}</strong>) е отменено и сумата '
                          . 'ще бъде върната по картата, с която е извършено плащането, '
                          . 'в рамките на 3–5 работни дни.</p>',
            'outro_bg'   => '<p>При въпроси се свържи с нас на <a href="mailto:' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '">' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '</a>.</p>',
            'subject_en' => 'Refunded payment — {{pledge_number}}',
            'intro_en'   => '<h2>Payment refunded</h2>'
                          . '<p>Hi {{name}},</p>'
                          . '<p>Your campaign contribution of <strong>{{amount_eur}} EUR</strong> '
                          . '(reference <strong>{{pledge_number}}</strong>) has been cancelled and '
                          . 'the amount will be returned to your card within 3–5 working days.</p>',
            'outro_en'   => '<p>If you have questions, contact us at <a href="mailto:' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '">' . (defined('SITE_EMAIL') ? SITE_EMAIL : '') . '</a>.</p>',
        ],

        'error-alert' => [
            'subject_bg' => '[Odd Minds] Грешка на сайта — {{error_class}}',
            'intro_bg'   => '<h2>Грешка на сайта</h2>'
                          . '<p>Засечена е нова грешка на <strong>{{timestamp}}</strong>.</p>',
            'outro_bg'   => '<p>Провери логовете на сървъра за повече детайли.</p>',
            'subject_en' => '[Odd Minds] Site error — {{error_class}}',
            'intro_en'   => '<h2>Site error detected</h2>'
                          . '<p>A new error was captured at <strong>{{timestamp}}</strong>.</p>',
            'outro_en'   => '<p>Check the server logs for more details.</p>',
        ],

        'error-digest' => [
            'subject_bg' => '[Odd Minds] {{count}} грешки — {{period}}',
            'intro_bg'   => '<h2>Обобщение на грешките</h2>'
                          . '<p>За периода <strong>{{from_date}} – {{to_date}}</strong> са засечени <strong>{{count}}</strong> грешки.</p>',
            'outro_bg'   => '<p>Провери логовете на сървъра за повече детайли.</p>',
            'subject_en' => '[Odd Minds] {{count}} errors — {{period}}',
            'intro_en'   => '<h2>Error digest</h2>'
                          . '<p>For the period <strong>{{from_date}} – {{to_date}}</strong>, <strong>{{count}}</strong> errors were captured.</p>',
            'outro_en'   => '<p>Check the server logs for more details.</p>',
        ],

    ];
}

/**
 * Get rendered template fields for a given key and language.
 * Returns ['subject', 'intro', 'outro'] with {{vars}} already substituted.
 *
 * @param array<string,scalar> $vars
 */
function email_tpl_get(string $key, string $lang = 'bg', array $vars = []): array {
    $defaults = _email_tpl_defaults();
    $stored   = [];
    $json     = setting_get('email_tpl_' . $key, '');
    if ($json !== '') {
        $stored = json_decode($json, true) ?? [];
    }
    $def = $defaults[$key] ?? [];

    $result = [];
    foreach (['subject', 'intro', 'outro'] as $field) {
        $bg = $stored[$field . '_bg'] ?? $def[$field . '_bg'] ?? '';
        $en = $stored[$field . '_en'] ?? $def[$field . '_en'] ?? '';
        $raw = ($lang === 'en' && $en !== '') ? $en : $bg;
        $result[$field] = email_tpl_render($raw, $vars);
    }
    return $result;
}

/**
 * Replace {{placeholder}} tokens in $html with escaped values from $vars.
 *
 * @param array<string,scalar> $vars
 */
function email_tpl_render(string $html, array $vars): string {
    foreach ($vars as $k => $v) {
        $html = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $html);
    }
    return $html;
}

/**
 * Convenience: return only the rendered subject for a template.
 *
 * @param array<string,scalar> $vars
 */
function email_tpl_subject(string $key, string $lang = 'bg', array $vars = []): string {
    return email_tpl_get($key, $lang, $vars)['subject'];
}

/**
 * Return raw (unrendered) stored or default content for the admin UI.
 * Returns all six fields: subject_bg, intro_bg, outro_bg, subject_en, intro_en, outro_en.
 */
function email_tpl_raw(string $key): array {
    $defaults = _email_tpl_defaults();
    $stored   = [];
    $json     = setting_get('email_tpl_' . $key, '');
    if ($json !== '') {
        $stored = json_decode($json, true) ?? [];
    }
    $def = $defaults[$key] ?? [];

    $out = [];
    foreach (['subject_bg', 'intro_bg', 'outro_bg', 'subject_en', 'intro_en', 'outro_en'] as $f) {
        $out[$f] = $stored[$f] ?? $def[$f] ?? '';
    }
    return $out;
}
