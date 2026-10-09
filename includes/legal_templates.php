<?php
/**
 * Starting texts for the legal pages (Админ → Съдържание → Политика за
 * поверителност, Бисквитки, Правна информация, Условия за ползване).
 *
 * The editor's „Попълни с примерен текст“ button puts one into the editor, to be
 * read, adapted and saved by a person — never published on its own. Each text is
 * built from the organisation's own data (Админ → Организация) and covers only
 * what this site does: the modules switched on, analytics only if set up, and so
 * on. Anything the site cannot know is a highlighted gap, LEGAL_GAP_OPEN…, which
 * the go-live checklist treats as unfinished (includes/launch.php).
 *
 * These are templates, not legal advice.
 */

/** How a gap starts in the HTML; launch_text_filled() looks for it. */
const LEGAL_GAP_OPEN = '<mark>[';

/** A highlighted gap for the admin to fill in. */
function legal_gap(string $what): string
{
    return LEGAL_GAP_OPEN . h($what) . ']</mark>';
}

/** The organisation's details, escaped for HTML; missing ones are gaps. */
function legal_facts(string $lang): array
{
    $en    = $lang === 'en';
    $c     = static fn(string $k): string => defined($k) ? trim((string) constant($k)) : '';
    $legal = function_exists('org_legal_name') ? org_legal_name($lang) : $c($en ? 'SITE_NAME_EN' : 'SITE_NAME_BG');
    $fill  = static fn(string $v, string $gap_bg, string $gap_en): string => $v !== '' ? h($v) : legal_gap($en ? $gap_en : $gap_bg);
    $email = $c('SITE_EMAIL');
    return [
        'name'    => $fill($legal, 'юридическо име', 'legal name'),
        'eik'     => $fill($c('SITE_EIK'), 'ЕИК', 'company ID (EIK)'),
        'address' => $fill($c('SITE_ADDRESS'), 'адрес на управление', 'registered address'),
        'email'   => $email !== '' ? '<a href="mailto:' . h($email) . '">' . h($email) . '</a>' : legal_gap($en ? 'email' : 'имейл'),
        'phone'   => $c('SITE_PHONE') !== '' ? h($c('SITE_PHONE')) : '',
        'mol'     => $c('SITE_MOL') !== '' ? h($c('SITE_MOL')) : '',
        'url'     => h($c('SITE_URL')),
        'privacy' => $en ? '/en/privacy-policy/' : '/politika-za-poveritelnost/',
        'cookies' => $en ? '/en/cookie-policy/' : '/politika-za-biskvitki/',
    ];
}

/** Which parts of the texts apply to this site. */
function legal_scope(): array
{
    $on = static fn(string $m): bool => function_exists('module_enabled_with_needs') && module_enabled_with_needs($m);
    $c  = static fn(string $k): string => defined($k) ? trim((string) constant($k)) : '';
    $turnstile = false;
    if (function_exists('turnstile_is_configured')) {
        try { $turnstile = turnstile_is_configured(); } catch (Throwable) {}
    }
    return [
        'shop'       => $on('shop'),
        'donations'  => $on('donations'),
        'campaign'   => $on('campaign'),
        'events'     => $on('events'),
        'newsletter' => $on('newsletter'),
        'comments'   => $on('comments_reviews'),
        'analytics'  => $c('GTM_ID') !== '' || $c('GA4_ID') !== '' || $c('GOOGLE_ADS_ID') !== '',
        'turnstile'  => $turnstile,
        'money'      => $on('shop') || $on('donations') || $on('campaign') || $on('events'),
    ];
}

/** The template for one legal page: privacy, cookies, legal_info or terms. '' for an unknown key. */
function legal_template(string $key, string $lang, ?array $facts = null, ?array $scope = null): string
{
    $facts ??= legal_facts($lang);
    $scope ??= legal_scope();
    $en = $lang === 'en';
    return match ($key) {
        'privacy'    => $en ? legal_privacy_en($facts, $scope) : legal_privacy_bg($facts, $scope),
        'cookies'    => $en ? legal_cookies_en($facts, $scope) : legal_cookies_bg($facts, $scope),
        'legal_info' => $en ? legal_info_en($facts, $scope)    : legal_info_bg($facts, $scope),
        'terms'      => $en ? legal_terms_en($facts, $scope)   : legal_terms_bg($facts, $scope),
        default      => '',
    };
}

function legal_li(array $items): string
{
    return '<ul>' . implode('', array_map(static fn($i) => '<li>' . $i . '</li>', array_filter($items))) . '</ul>';
}

function legal_contact_block(array $f, bool $en): string
{
    $rows = [
        '<strong>' . $f['name'] . '</strong>',
        ($en ? 'Company ID (EIK): ' : 'ЕИК: ') . $f['eik'],
        ($en ? 'Address: ' : 'Адрес: ') . $f['address'],
        ($en ? 'Email: ' : 'Имейл: ') . $f['email'],
    ];
    if ($f['phone'] !== '') $rows[] = ($en ? 'Phone: ' : 'Телефон: ') . $f['phone'];
    return '<p>' . implode('<br>', $rows) . '</p>';
}

// ── Privacy policy ───────────────────────────────────────────────────────────

function legal_privacy_bg(array $f, array $s): string
{
    $collect = [
        $s['shop'] || $s['events'] ? '<strong>Поръчки' . ($s['events'] ? ' и билети' : '') . ':</strong> име, имейл, телефон, адрес за доставка, данни за фактура (ако поискате), какво сте поръчали и дали е платено.' : '',
        $s['donations'] || $s['campaign'] ? '<strong>Дарения:</strong> име, имейл, сума, по желание адрес и данни за документ за дарение.' : '',
        $s['newsletter'] ? '<strong>Бюлетин:</strong> имейл и по желание име, темите, които сте избрали, и кога сте се абонирали.' : '',
        $s['comments'] ? '<strong>Коментари и отзиви:</strong> име, имейл и текстът, който публикувате.' : '',
        '<strong>Съобщения до нас:</strong> това, което ни пишете, и данните ви за контакт.',
        '<strong>Посещения на сайта:</strong> IP адрес и технически данни за браузъра, които сървърът записва автоматично за сигурност' . ($s['analytics'] ? '; статистика за посещенията — само ако приемете бисквитките за статистика' : '') . '.',
    ];
    $purposes = [
        $s['shop'] || $s['events'] ? 'да изпълним поръчката ви, да я доставим и да издадем документите, които законът изисква (договор и законово задължение);' : '',
        $s['donations'] || $s['campaign'] ? 'да приемем дарението ви и да издадем документ за него (договор и законово задължение);' : '',
        $s['newsletter'] ? 'да ви изпращаме бюлетина, за който сте се абонирали (съгласие — можете да се отпишете с връзката във всеки бюлетин);' : '',
        'да отговорим на съобщенията ви (легитимен интерес);',
        'да пазим сайта от злоупотреби и спам (легитимен интерес).',
    ];
    $share = [
        $s['money'] ? 'банката или платежната система, която обработва плащането с карта — ние не виждаме и не пазим данните на картата ви;' : '',
        $s['shop'] ? 'куриерската фирма, която доставя поръчката (име, телефон, адрес);' : '',
        'хостинг доставчика, на чиито сървъри работи сайтът, и доставчика на имейл услугата;',
        $s['analytics'] ? 'Google — за статистиката на посещенията, само ако сте я приели;' : '',
        $s['turnstile'] ? 'Cloudflare — проверката срещу спам във формите (Turnstile);' : '',
        'счетоводител и държавни органи — когато законът го изисква.',
    ];
    $keep = [
        $s['money'] ? 'Поръчки, плащания и документи за дарения: 10 години, както изисква Законът за счетоводството.' : '',
        $s['newsletter'] ? 'Бюлетин: докато не се отпишете.' : '',
        $s['comments'] ? 'Коментари и отзиви: докато са публикувани или докато не поискате да ги изтрием.' : '',
        'Съобщения до нас: ' . legal_gap('напр. до 2 години') . '.',
    ];
    return '<h2>1. Кои сме ние</h2>'
        . '<p>Администратор на личните данни, които събираме чрез сайта ' . $f['url'] . ', е:</p>' . legal_contact_block($f, false)
        . '<p>Обработваме личните данни съгласно Регламент (ЕС) 2016/679 (GDPR) и Закона за защита на личните данни.</p>'
        . '<h2>2. Какви данни събираме</h2>' . legal_li($collect)
        . '<h2>3. За какво ги използваме и на какво основание</h2>' . legal_li($purposes)
        . '<p>Не продаваме и не предоставяме личните ви данни на други за реклама.</p>'
        . '<h2>4. С кого ги споделяме</h2>' . legal_li($share)
        . '<h2>5. Колко дълго ги пазим</h2>' . legal_li($keep)
        . '<h2>6. Вашите права</h2>'
        . '<p>Имате право да поискате достъп до данните си, да ги поправим или изтрием, да ограничим обработването им, да ги получите във формат, който можете да пренесете, и да възразите срещу обработването. Когато обработваме данни със съгласието ви, можете да го оттеглите по всяко време. Пишете ни на ' . $f['email'] . ' — отговаряме до един месец.</p>'
        . '<p>Ако смятате, че нарушаваме правата ви, можете да подадете жалба до Комисията за защита на личните данни: гр. София 1592, бул. „Проф. Цветан Лазаров“ № 2, <a href="https://www.cpdp.bg">www.cpdp.bg</a>.</p>'
        . '<h2>7. Бисквитки</h2>'
        . '<p>Какви бисквитки ползва сайтът и как да управлявате избора си, е описано в <a href="' . $f['cookies'] . '">Политиката за бисквитки</a>.</p>'
        . '<h2>8. Промени</h2>'
        . '<p>Ако променим тази политика, ще публикуваме новия текст на тази страница.</p>';
}

function legal_privacy_en(array $f, array $s): string
{
    $collect = [
        $s['shop'] || $s['events'] ? '<strong>Orders' . ($s['events'] ? ' and tickets' : '') . ':</strong> name, email, phone, delivery address, invoice details (if you ask for an invoice), what you ordered and whether it was paid.' : '',
        $s['donations'] || $s['campaign'] ? '<strong>Donations:</strong> name, email, amount and, if you choose, an address and details for a donation certificate.' : '',
        $s['newsletter'] ? '<strong>Newsletter:</strong> email and optionally name, the topics you chose and when you subscribed.' : '',
        $s['comments'] ? '<strong>Comments and reviews:</strong> name, email and the text you publish.' : '',
        '<strong>Messages to us:</strong> what you write and your contact details.',
        '<strong>Visits:</strong> IP address and technical browser data that the server records automatically for security' . ($s['analytics'] ? '; visit statistics — only if you accept statistics cookies' : '') . '.',
    ];
    $purposes = [
        $s['shop'] || $s['events'] ? 'to fulfil and deliver your order and issue the documents the law requires (contract and legal obligation);' : '',
        $s['donations'] || $s['campaign'] ? 'to accept your donation and issue a document for it (contract and legal obligation);' : '',
        $s['newsletter'] ? 'to send the newsletter you subscribed to (consent — unsubscribe with the link in any issue);' : '',
        'to answer your messages (legitimate interest);',
        'to protect the site from abuse and spam (legitimate interest).',
    ];
    $share = [
        $s['money'] ? 'the bank or payment provider that processes card payments — we never see or store your card details;' : '',
        $s['shop'] ? 'the courier delivering your order (name, phone, address);' : '',
        'the hosting provider whose servers run the site, and our email provider;',
        $s['analytics'] ? 'Google — for visit statistics, only if you accepted them;' : '',
        $s['turnstile'] ? 'Cloudflare — the anti-spam check on forms (Turnstile);' : '',
        'our accountant and public authorities — where the law requires it.',
    ];
    $keep = [
        $s['money'] ? 'Orders, payments and donation documents: 10 years, as the Accountancy Act requires.' : '',
        $s['newsletter'] ? 'Newsletter: until you unsubscribe.' : '',
        $s['comments'] ? 'Comments and reviews: while published, or until you ask us to delete them.' : '',
        'Messages to us: ' . legal_gap('e.g. up to 2 years') . '.',
    ];
    return '<h2>1. Who we are</h2>'
        . '<p>The controller of the personal data collected through ' . $f['url'] . ' is:</p>' . legal_contact_block($f, true)
        . '<p>We process personal data under Regulation (EU) 2016/679 (GDPR) and the Bulgarian Personal Data Protection Act.</p>'
        . '<h2>2. What we collect</h2>' . legal_li($collect)
        . '<h2>3. Why we use it, and on what basis</h2>' . legal_li($purposes)
        . '<p>We do not sell your personal data or share it with others for advertising.</p>'
        . '<h2>4. Who we share it with</h2>' . legal_li($share)
        . '<h2>5. How long we keep it</h2>' . legal_li($keep)
        . '<h2>6. Your rights</h2>'
        . '<p>You may ask for access to your data, to have it corrected or deleted, to restrict its processing, to receive it in a portable format, and to object to its processing. Where we rely on your consent, you can withdraw it at any time. Write to ' . $f['email'] . ' — we reply within one month.</p>'
        . '<p>If you believe we have breached your rights, you can complain to the Commission for Personal Data Protection: 2 Prof. Tsvetan Lazarov Blvd, Sofia 1592, <a href="https://www.cpdp.bg">www.cpdp.bg</a>.</p>'
        . '<h2>7. Cookies</h2>'
        . '<p>Which cookies the site uses and how to manage your choice is explained in the <a href="' . $f['cookies'] . '">Cookie policy</a>.</p>'
        . '<h2>8. Changes</h2>'
        . '<p>If we change this policy, we will publish the new text on this page.</p>';
}

// ── Cookie policy ────────────────────────────────────────────────────────────

function legal_cookie_rows(array $rows): string
{
    $html = '<table><thead><tr>';
    foreach (array_shift($rows) as $th) $html .= '<th>' . $th . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) $html .= '<tr><td>' . implode('</td><td>', $r) . '</td></tr>';
    return $html . '</tbody></table>';
}

function legal_cookies_bg(array $f, array $s): string
{
    $needed = [['Бисквитка', 'За какво е', 'Колко дълго'],
        ['OMSESSID', 'Сесията на сайта: количката, защитата на формите' . ($s['money'] ? ', данните при поръчка' : '') . '.', 'Докато затворите браузъра'],
        ['om_cookie_consent', 'Запомня избора ви за бисквитките.', '1 година'],
    ];
    if ($s['newsletter']) $needed[] = ['om_nl_sub', 'Запомня, че сте се абонирали, за да не показваме отново поканата за бюлетина.', '1 година'];
    if ($s['turnstile'])  $needed[] = ['Cloudflare Turnstile', 'Проверка срещу спам във формите.', 'Кратко, според Cloudflare'];
    $html = '<h2>1. Какво са бисквитките</h2>'
        . '<p>Бисквитките са малки текстови файлове, които сайтът записва в браузъра ви, за да запомни определени неща — например какво има в количката ви.</p>'
        . '<h2>2. Задължителни бисквитки</h2>'
        . '<p>Без тях сайтът не работи, затова не искаме съгласие за тях.</p>' . legal_cookie_rows($needed);
    if ($s['analytics']) {
        $html .= '<h2>3. Бисквитки за статистика (само с ваше съгласие)</h2>'
            . '<p>Ако изберете „Приемам всички“, зареждаме услугите на Google за статистика на посещенията. Без съгласието ви те не се зареждат.</p>'
            . legal_cookie_rows([['Услуга', 'Бисквитки', 'Колко дълго'], ['Google Analytics / Tag Manager', '_ga, _ga_* и подобни', 'До 2 години']]);
    }
    $n = $s['analytics'] ? 4 : 3;
    return $html
        . '<h2>' . $n . '. Шрифтове</h2>'
        . '<p>Сайтът зарежда шрифтове от Google Fonts. При това Google получава IP адреса ви, но не записва бисквитки.</p>'
        . '<h2>' . ($n + 1) . '. Как да промените избора си</h2>'
        . '<p>При първото посещение ви питаме дали приемате бисквитките. За да промените избора си, изтрийте бисквитките на сайта от настройките на браузъра и заредете страницата отново.</p>'
        . '<p>Въпроси: ' . $f['email'] . '. Вижте и <a href="' . $f['privacy'] . '">Политиката за поверителност</a>.</p>';
}

function legal_cookies_en(array $f, array $s): string
{
    $needed = [['Cookie', 'What it does', 'How long'],
        ['OMSESSID', 'The site session: your basket, form protection' . ($s['money'] ? ', order details' : '') . '.', 'Until you close the browser'],
        ['om_cookie_consent', 'Remembers your cookie choice.', '1 year'],
    ];
    if ($s['newsletter']) $needed[] = ['om_nl_sub', 'Remembers that you subscribed, so we don\'t show the newsletter invitation again.', '1 year'];
    if ($s['turnstile'])  $needed[] = ['Cloudflare Turnstile', 'Anti-spam check on forms.', 'Short, set by Cloudflare'];
    $html = '<h2>1. What cookies are</h2>'
        . '<p>Cookies are small text files the site stores in your browser to remember things — for example what is in your basket.</p>'
        . '<h2>2. Necessary cookies</h2>'
        . '<p>The site cannot work without them, so we do not ask for consent.</p>' . legal_cookie_rows($needed);
    if ($s['analytics']) {
        $html .= '<h2>3. Statistics cookies (only with your consent)</h2>'
            . '<p>If you choose “Accept all”, we load Google\'s visit statistics. Without your consent they are not loaded.</p>'
            . legal_cookie_rows([['Service', 'Cookies', 'How long'], ['Google Analytics / Tag Manager', '_ga, _ga_* and similar', 'Up to 2 years']]);
    }
    $n = $s['analytics'] ? 4 : 3;
    return $html
        . '<h2>' . $n . '. Fonts</h2>'
        . '<p>The site loads fonts from Google Fonts. Google receives your IP address but sets no cookies.</p>'
        . '<h2>' . ($n + 1) . '. Changing your choice</h2>'
        . '<p>On your first visit we ask whether you accept cookies. To change your choice, delete this site\'s cookies in your browser settings and reload the page.</p>'
        . '<p>Questions: ' . $f['email'] . '. See also the <a href="' . $f['privacy'] . '">Privacy policy</a>.</p>';
}

// ── Legal information ────────────────────────────────────────────────────────

function legal_info_bg(array $f, array $s): string
{
    $who = legal_contact_block($f, false);
    if ($f['mol'] !== '') $who .= '<p>Представляващ: ' . $f['mol'] . '</p>';
    return '<h2>1. Кой стои зад сайта</h2>' . $who
        . '<p>Регистрирана в ' . legal_gap('Търговския регистър и регистъра на ЮЛНЦ') . '.</p>'
        . ($s['money'] ? '<h2>2. Надзорни органи</h2><p>Комисия за защита на личните данни — <a href="https://www.cpdp.bg">www.cpdp.bg</a><br>Комисия за защита на потребителите — <a href="https://kzp.bg">kzp.bg</a></p>'
                       : '<h2>2. Надзорен орган</h2><p>Комисия за защита на личните данни — <a href="https://www.cpdp.bg">www.cpdp.bg</a></p>')
        . '<h2>3. Авторски права</h2>'
        . '<p>Текстовете, снимките и графиките на сайта принадлежат на организацията или на техните автори. Можете да ги споделяте с лична, нетърговска цел, като посочите източника. За друго ползване ни пишете на ' . $f['email'] . '.</p>'
        . '<h2>4. Връзки към други сайтове</h2>'
        . '<p>Не отговаряме за съдържанието на сайтове, към които водят връзки от нашия.</p>'
        . ($s['donations'] || $s['campaign'] ? '<h2>5. Дарения</h2><p>Даренията могат да дадат право на данъчно облекчение по Закона за данъците върху доходите на физическите лица (за граждани) или по Закона за корпоративното подоходно облагане (за фирми), при условията на тези закони. При поискване издаваме документ за дарението. Дарение се връща само ако е направено по техническа грешка.</p>' : '')
        . '<h2>' . ($s['donations'] || $s['campaign'] ? 6 : 5) . '. Лични данни и приложимо право</h2>'
        . '<p>Как обработваме личните данни, е описано в <a href="' . $f['privacy'] . '">Политиката за поверителност</a>. За сайта се прилага българското законодателство.</p>';
}

function legal_info_en(array $f, array $s): string
{
    $who = legal_contact_block($f, true);
    if ($f['mol'] !== '') $who .= '<p>Represented by: ' . $f['mol'] . '</p>';
    return '<h2>1. Who runs this site</h2>' . $who
        . '<p>Registered in ' . legal_gap('the Commercial Register and Register of Non-Profit Legal Entities') . '.</p>'
        . ($s['money'] ? '<h2>2. Supervisory authorities</h2><p>Commission for Personal Data Protection — <a href="https://www.cpdp.bg">www.cpdp.bg</a><br>Commission for Consumer Protection — <a href="https://kzp.bg">kzp.bg</a></p>'
                       : '<h2>2. Supervisory authority</h2><p>Commission for Personal Data Protection — <a href="https://www.cpdp.bg">www.cpdp.bg</a></p>')
        . '<h2>3. Copyright</h2>'
        . '<p>The texts, photos and graphics on this site belong to the organisation or their authors. You may share them for personal, non-commercial use with credit to the source. For anything else, write to ' . $f['email'] . '.</p>'
        . '<h2>4. Links to other sites</h2>'
        . '<p>We are not responsible for the content of sites our links lead to.</p>'
        . ($s['donations'] || $s['campaign'] ? '<h2>5. Donations</h2><p>Donations may qualify for tax relief under the Bulgarian Personal Income Tax Act (individuals) or Corporate Income Tax Act (companies), on the terms of those laws. We issue a donation document on request. A donation is refunded only if it was made by technical mistake.</p>' : '')
        . '<h2>' . ($s['donations'] || $s['campaign'] ? 6 : 5) . '. Personal data and applicable law</h2>'
        . '<p>How we process personal data is described in the <a href="' . $f['privacy'] . '">Privacy policy</a>. Bulgarian law applies to this site.</p>';
}

// ── Terms of use (sites that take money) ─────────────────────────────────────

function legal_terms_bg(array $f, array $s): string
{
    $n = 0;
    $h = static function (string $t) use (&$n): string { return '<h2>' . (++$n) . '. ' . $t . '</h2>'; };
    $html = $h('Общи положения')
        . '<p>Тези условия уреждат отношенията между ' . $f['name'] . ', ЕИК ' . $f['eik'] . ', адрес ' . $f['address'] . ', и всеки, който ' . ($s['shop'] || $s['events'] ? 'поръчва' : 'дарява') . ' чрез сайта ' . $f['url'] . '. Като завършите поръчка или плащане, приемате тези условия.</p>';
    if ($s['shop']) {
        $html .= $h('Поръчки')
            . '<p>Поръчвате без регистрация. Моля, въвеждайте точни данни — по тях доставяме и издаваме документите. Можем да откажем поръчка при непълни данни или съмнение за злоупотреба, като ви уведомим.</p>'
            . $h('Цени и плащане')
            . '<p>Цените са в евро и са крайни. Плащате с карта чрез сигурната страница на банката' . ' ' . legal_gap('или друг начин, който предлагате') . '. Не виждаме и не пазим данните на картата ви.</p>'
            . $h('Доставка')
            . '<p>Доставяме с куриер на територията на ' . legal_gap('Република България') . '. Цената и срокът на доставката се показват преди плащане; сроковете са ориентировъчни.</p>'
            . $h('Право на отказ')
            . '<p>Можете да се откажете от поръчката в 14-дневен срок от получаването ѝ, без да посочвате причина (чл. 50 от Закона за защита на потребителите). Пишете ни на ' . $f['email'] . ' с номера на поръчката. Върнете продукта неупотребяван, в оригиналната опаковка; разходите по връщането са за ваша сметка. Възстановяваме сумата до 14 дни от отказа, по начина, по който сте платили.</p>'
            . $h('Рекламации')
            . '<p>Продуктите имат законова гаранция за съответствие от 2 години от получаването. За рекламация пишете на ' . $f['email'] . ' с номера на поръчката и описание на проблема. Отговаряме в срок до един месец.</p>';
    }
    if ($s['events']) {
        $html .= $h('Билети за събития')
            . '<p>Билетът се изпраща на имейла ви като PDF след плащането. Тъй като събитията са на определена дата, правото на отказ в 14-дневен срок не се прилага за билети (чл. 57, т. 12 от Закона за защита на потребителите). Ако отменим събитие, възстановяваме цената на билета.</p>';
    }
    if ($s['donations'] || $s['campaign']) {
        $html .= $h('Дарения')
            . '<p>Дарението е доброволно и безвъзмездно. Връщаме го само ако е направено по техническа грешка — пишете ни на ' . $f['email'] . '.' . ($s['campaign'] ? ' Наградите в кампаниите са благодарност за подкрепата и се изпращат, както е описано в кампанията.' : '') . '</p>';
    }
    return $html
        . $h('Лични данни')
        . '<p>Обработваме личните ви данни, както е описано в <a href="' . $f['privacy'] . '">Политиката за поверителност</a>.</p>'
        . $h('Спорове')
        . '<p>Опитваме се да решим всеки проблем по взаимно съгласие. Можете да се обърнете и към Комисията за защита на потребителите (<a href="https://kzp.bg">kzp.bg</a>). За неуредените въпроси се прилага българското законодателство.</p>'
        . $h('Контакт') . legal_contact_block($f, false);
}

function legal_terms_en(array $f, array $s): string
{
    $n = 0;
    $h = static function (string $t) use (&$n): string { return '<h2>' . (++$n) . '. ' . $t . '</h2>'; };
    $html = $h('General')
        . '<p>These terms govern the relationship between ' . $f['name'] . ', company ID (EIK) ' . $f['eik'] . ', address ' . $f['address'] . ', and anyone who ' . ($s['shop'] || $s['events'] ? 'orders' : 'donates') . ' through ' . $f['url'] . '. By completing an order or payment you accept these terms.</p>';
    if ($s['shop']) {
        $html .= $h('Orders')
            . '<p>You can order without an account. Please enter accurate details — we deliver and issue documents based on them. We may refuse an order with incomplete details or suspected misuse, and will tell you if we do.</p>'
            . $h('Prices and payment')
            . '<p>Prices are in euro and final. You pay by card on the bank\'s secure page ' . legal_gap('or another method you offer') . '. We never see or store your card details.</p>'
            . $h('Delivery')
            . '<p>We deliver by courier within ' . legal_gap('Bulgaria') . '. The delivery price and time are shown before payment; times are approximate.</p>'
            . $h('Right of withdrawal')
            . '<p>You may withdraw from your order within 14 days of receiving it, without giving a reason (Art. 50 of the Bulgarian Consumer Protection Act). Write to ' . $f['email'] . ' with your order number. Return the product unused, in its original packaging; return costs are yours. We refund within 14 days of your withdrawal, using the payment method you used.</p>'
            . $h('Complaints')
            . '<p>Products carry a 2-year legal guarantee of conformity from delivery. To complain, write to ' . $f['email'] . ' with your order number and a description of the problem. We reply within one month.</p>';
    }
    if ($s['events']) {
        $html .= $h('Event tickets')
            . '<p>Your ticket is emailed to you as a PDF after payment. As events take place on a specific date, the 14-day right of withdrawal does not apply to tickets (Art. 57(12) of the Consumer Protection Act). If we cancel an event, we refund the ticket price.</p>';
    }
    if ($s['donations'] || $s['campaign']) {
        $html .= $h('Donations')
            . '<p>A donation is voluntary and unconditional. It is refunded only if made by technical mistake — write to ' . $f['email'] . '.' . ($s['campaign'] ? ' Campaign rewards are a thank-you for your support and are sent as described in the campaign.' : '') . '</p>';
    }
    return $html
        . $h('Personal data')
        . '<p>We process your personal data as described in the <a href="' . $f['privacy'] . '">Privacy policy</a>.</p>'
        . $h('Disputes')
        . '<p>We try to settle any problem by agreement. You may also contact the Commission for Consumer Protection (<a href="https://kzp.bg">kzp.bg</a>). Bulgarian law applies to anything not covered here.</p>'
        . $h('Contact') . legal_contact_block($f, true);
}
