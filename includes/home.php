<?php
// includes/home.php — configurable front-page sections: the model.
// Storage is content/home.json. Spec: docs/superpowers/specs/2026-09-21-home-sections-design.md

const HOME_IMAGE_RE  = '#^/assets/images/[a-zA-Z0-9/_.\-]+$#';
const HOME_HTML_TAGS = ['p', 'br', 'b', 'strong', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li',
                        'h2', 'h3', 'h4', 'blockquote', 'hr', 'img',
                        'table', 'thead', 'tbody', 'tr', 'th', 'td'];

// ── Field cleaners ────────────────────────────────────────────────────────────

/** '' for empty, the trimmed link if safe, null if it must be rejected. */
function home_clean_link(string $url): ?string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('/[\s<>"\'\\\\]/', $url)) return null;
    if ($url[0] === '/') return str_starts_with($url, '//') ? null : $url;
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
}

function home_valid_image_path(string $path): bool {
    return $path !== '' && !str_contains($path, '..') && preg_match(HOME_IMAGE_RE, $path) === 1;
}

/** Keep simple formatting only: allowlisted tags, href on <a>, src/alt on <img>. */
function home_clean_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    if (!class_exists('Dom\\HTMLDocument')) return home_clean_html_plain($html);
    $doc  = Dom\HTMLDocument::createFromString(
        '<!DOCTYPE html><html><body><div>' . $html . '</div></body></html>', LIBXML_NOERROR, 'UTF-8'
    );
    $root = $doc->body->firstElementChild;
    foreach (['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form'] as $tag) {
        foreach ($root->querySelectorAll($tag) as $el) $el->remove();
    }
    // Deepest first, so unwrapping a parent never skips its children.
    $all = array_reverse(iterator_to_array($root->querySelectorAll('*')));
    foreach ($all as $el) {
        $tag = strtolower($el->localName);
        if (!in_array($tag, HOME_HTML_TAGS, true)) {
            while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
            $el->remove();
            continue;
        }
        $keep = [];
        if ($tag === 'a') {
            $href = home_clean_link($el->getAttribute('href') ?? '');
            if ($href) $keep['href'] = $href;
        } elseif ($tag === 'img') {
            $src = trim($el->getAttribute('src') ?? '');
            if (!home_valid_image_path($src) && !preg_match('#^https://[^\s"<>]+$#', $src)) { $el->remove(); continue; }
            $keep = ['src' => $src, 'alt' => (string) ($el->getAttribute('alt') ?? '')];
        }
        foreach ($el->getAttributeNames() as $name) $el->removeAttribute($name);
        foreach ($keep as $name => $value) $el->setAttribute($name, $value);
        if ($tag === 'a' && isset($keep['href']) && preg_match('#^https?://#i', $keep['href'])) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
    return trim($root->innerHTML);
}

/** Without PHP 8.4's HTML parser: keep the words, drop all formatting, escape everything. */
function home_clean_html_plain(string $html): string {
    static $logged = false;
    if (!$logged) {
        $logged = true;
        error_log('home_clean_html: PHP 8.4 or newer (Dom\\HTMLDocument) is required to keep text formatting — saving plain text');
    }
    $html = (string) preg_replace('#<br\s*/?>|</(?:p|li|h[2-4]|blockquote|tr)>#i', "\n", $html);
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $text === '' ? '' : '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>';
}

function home_parse_video_url(string $url): ?array {
    $url = trim($url);
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#\s]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $url, $m)) {
        return ['provider' => 'youtube', 'id' => $m[1]];
    }
    if (preg_match('~^https?://(?:www\.)?vimeo\.com/(\d{3,12})(?!\d)(?:[/?#]|$)~', $url, $m)
        || preg_match('~^https?://player\.vimeo\.com/video/(\d{3,12})(?!\d)~', $url, $m)) {
        return ['provider' => 'vimeo', 'id' => $m[1]];
    }
    return null;
}

function home_video_valid(array $v): bool {
    return match ($v['provider'] ?? '') {
        'youtube' => is_string($v['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{11}$/', $v['id']) === 1,
        'vimeo'   => is_string($v['id'] ?? null) && preg_match('/^\d{3,12}$/', $v['id']) === 1,
        default   => false,
    };
}

function home_video_watch_url(array $v): string {
    return $v['provider'] === 'youtube'
        ? 'https://www.youtube.com/watch?v=' . $v['id']
        : 'https://vimeo.com/' . $v['id'];
}

function home_video_embed_url(array $v): string {
    return $v['provider'] === 'youtube'
        ? 'https://www.youtube-nocookie.com/embed/' . $v['id'] . '?autoplay=1&rel=0'
        : 'https://player.vimeo.com/video/' . $v['id'] . '?autoplay=1&dnt=1';
}

/** Any input → ['bg' => string, 'en' => string]. */
function home_pair(mixed $raw): array {
    if (is_string($raw)) return ['bg' => $raw, 'en' => ''];
    if (!is_array($raw)) return ['bg' => '', 'en' => ''];
    return [
        'bg' => is_string($raw['bg'] ?? null) ? $raw['bg'] : '',
        'en' => is_string($raw['en'] ?? null) ? $raw['en'] : '',
    ];
}

// ── Section types ─────────────────────────────────────────────────────────────

// Teal is offered only for the call-to-action block: its template switches text and
// buttons to white there. On teal, the other blocks' links and primary buttons (also
// teal) would disappear.
const HOME_BACKGROUNDS     = ['white' => 'Бял', 'grey' => 'Светлосив', 'warm' => 'Топъл (бежов)'];
const HOME_BACKGROUNDS_CTA = ['white' => 'Бял', 'grey' => 'Светлосив', 'teal' => 'Основният цвят на сайта', 'warm' => 'Топъл (бежов)'];

const HOME_CARD_FIELDS = [
    'image'     => ['kind' => 'image', 'label' => 'Снимка'],
    'image_alt' => ['kind' => 'alt', 'label' => 'Описание на снимката', 'max' => 200],
    'title'     => ['kind' => 'text', 'label' => 'Заглавие', 'max' => 100, 'required' => true],
    'text'      => ['kind' => 'textarea', 'label' => 'Кратък текст', 'max' => 300],
    'link'      => ['kind' => 'link', 'label' => 'Линк', 'hint' => 'Страница от сайта (напр. /za-nas/) или пълен адрес (https://…). По желание.'],
];

function home_types(): array {
    static $types = null;
    if ($types !== null) return $types;

    $bg   = fn(string $default, array $options = HOME_BACKGROUNDS): array => ['kind' => 'choice', 'label' => 'Фон на секцията', 'options' => $options, 'default' => $default];
    $head = ['kind' => 'text', 'label' => 'Заглавие', 'max' => 150];
    $btn  = fn(int $n): array => [
        "btn{$n}_label" => ['kind' => 'text', 'label' => "Бутон $n — надпис", 'max' => 60],
        "btn{$n}_url"   => ['kind' => 'link', 'label' => "Бутон $n — линк",
                            'hint' => 'Страница от сайта (напр. /za-nas/) или пълен адрес (https://…). Оставете надписа и линка празни, ако не искате бутон.'],
    ];
    $img  = fn(string $label): array => [
        'image'     => ['kind' => 'image', 'label' => $label],
        'image_alt' => ['kind' => 'alt', 'label' => 'Описание на снимката', 'max' => 200,
                        'hint' => 'Опишете снимката с няколко думи — за хора, които не виждат. Оставете празно, ако е само за украса.'],
    ];
    $rich = ['kind' => 'html', 'label' => 'Текст', 'max' => 20000,
             'hint' => 'Размерът и цветът на шрифта не се запазват — сайтът използва своите стилове.'];
    $items_note = 'Самите %s се добавят и редактират направо на началната страница, когато сте влезли като администратор.';

    return $types = [
        'hero' => ['builtin' => true, 'icon' => '🏠', 'label' => 'Начален банер',
            'desc' => 'Голямото заглавие и снимка най-горе на страницата.', 'note' => null,
            'fields' => ['title' => $head, 'text' => ['kind' => 'textarea', 'label' => 'Текст', 'max' => 600]]
                + $img('Снимка') + $btn(1) + $btn(2)],
        'products' => ['builtin' => true, 'icon' => '🛍', 'label' => 'Продукти от магазина',
            'desc' => 'Продуктите, отбелязани за началната страница.',
            'note' => "Кои продукти се показват, избирате в Продукти → \u{201E}Показвай на началната страница\u{201C}. Ако няма отбелязани, секцията не се показва.",
            'fields' => ['heading' => $head] + $btn(1) + ['background' => $bg('white')]],
        'impact' => ['builtin' => true, 'icon' => '🔢', 'label' => 'Показатели (числа)',
            'desc' => 'Числата, които показват вашето въздействие.', 'note' => sprintf($items_note, 'числа'),
            'fields' => ['heading' => $head]],
        'campaign' => ['builtin' => true, 'icon' => '📣', 'label' => 'Кампания',
            'desc' => 'Блокът на текущата кампания.', 'note' => null, 'fields' => []],
        'centres' => ['builtin' => true, 'icon' => '🤝', 'label' => 'С кого работим',
            'desc' => 'Центровете и организациите, с които работите.', 'note' => sprintf($items_note, 'центрове'),
            'fields' => ['heading' => $head, 'intro' => ['kind' => 'textarea', 'label' => 'Въвеждащ текст', 'max' => 600], 'background' => $bg('white')]],
        'mission' => ['builtin' => true, 'icon' => '🎯', 'label' => 'Мисия',
            'desc' => 'Текст за вашата мисия, по желание със снимка.', 'note' => null,
            'fields' => ['title' => $head, 'text' => $rich] + $img('Снимка (по желание)') + $btn(1) + $btn(2) + ['background' => $bg('grey')]],
        'news' => ['builtin' => true, 'icon' => '📰', 'label' => 'Последни новини',
            'desc' => 'Най-новите публикувани статии.', 'note' => 'Статиите се пишат в меню Статии. Ако няма публикувани, секцията не се показва.',
            'fields' => ['heading' => $head,
                         'count' => ['kind' => 'choice', 'label' => 'Колко статии да се показват', 'options' => ['3' => '3 статии', '6' => '6 статии'], 'default' => '3']]
                + $btn(1) + ['background' => $bg('white')]],
        'partners' => ['builtin' => true, 'icon' => '🏷', 'label' => 'Партньори',
            'desc' => 'Логата на вашите партньори.', 'note' => sprintf($items_note, 'партньори'),
            'fields' => ['heading' => $head, 'background' => $bg('grey')]],

        'text_image' => ['builtin' => false, 'icon' => '🖼', 'label' => 'Текст и снимка',
            'desc' => 'Заглавие, текст и снимка отляво или отдясно, по желание с бутон.', 'note' => null,
            'fields' => ['heading' => $head, 'body' => $rich] + $img('Снимка')
                + ['image_side' => ['kind' => 'choice', 'label' => 'Къде да е снимката', 'options' => ['left' => 'Отляво на текста', 'right' => 'Отдясно на текста'], 'default' => 'left']]
                + $btn(1) + ['background' => $bg('white')]],
        'cta' => ['builtin' => false, 'icon' => '📢', 'label' => 'Призив за действие',
            'desc' => 'Цветна лента със заглавие, кратък текст и до два бутона.', 'note' => null,
            'fields' => ['heading' => $head, 'text' => ['kind' => 'textarea', 'label' => 'Кратък текст', 'max' => 400]]
                + $btn(1) + $btn(2) + ['background' => $bg('teal', HOME_BACKGROUNDS_CTA)]],
        'richtext' => ['builtin' => false, 'icon' => '📝', 'label' => 'Свободен текст',
            'desc' => 'Заглавие и текст със списъци, връзки и таблици.', 'note' => null,
            'fields' => ['heading' => $head, 'body' => $rich, 'background' => $bg('white')]],
        'cards' => ['builtin' => false, 'icon' => '🗂', 'label' => 'Карти',
            'desc' => 'От 2 до 4 карти, всяка със снимка, заглавие, кратък текст и линк.', 'note' => null,
            'fields' => ['heading' => $head, 'cards' => ['kind' => 'cards', 'label' => 'Карти'], 'background' => $bg('white')]],
        'video' => ['builtin' => false, 'icon' => '▶️', 'label' => 'Видео',
            'desc' => "Видео от YouTube или Vimeo. Зарежда се едва когато посетителят натисне \u{201E}Пусни\u{201C}.", 'note' => null,
            'fields' => ['heading' => $head,
                         'video'   => ['kind' => 'video', 'label' => 'Линк към видеото', 'required' => true],
                         'caption' => ['kind' => 'text', 'label' => 'Надпис под видеото', 'max' => 200],
                         'thumb'   => ['kind' => 'internal', 'label' => 'Картинка на видеото'],
                         'background' => $bg('white')]],
    ];
}

function home_is_builtin(string $type): bool {
    return (home_types()[$type]['builtin'] ?? false) === true;
}

// ── Validation ───────────────────────────────────────────────────────────────

/** @return array{0: mixed, 1: array<string,string>} cleaned value (the raw input if invalid) and errors. */
function home_clean_field(array $def, mixed $raw, string $key): array {
    $err = [];
    switch ($def['kind']) {
        case 'text': case 'textarea': case 'alt': case 'html': case 'link':
            $out = [];
            foreach (home_pair($raw) as $l => $v) {
                if ($def['kind'] === 'html') {
                    $v = home_clean_html($v);
                } elseif ($def['kind'] === 'link') {
                    $clean = home_clean_link($v);
                    if ($clean === null) {
                        $err["$key.$l"] = 'Линкът трябва да започва с / (страница от този сайт) или с https:// (друг сайт).';
                        $clean = trim($v);
                    }
                    $v = $clean;
                } else {
                    $v = trim(strip_tags($v));
                    if ($def['kind'] !== 'textarea') $v = (string) preg_replace('/\s+/u', ' ', $v);
                }
                $max = (int) ($def['max'] ?? 0);
                if ($max > 0 && mb_strlen($v) > $max) $err["$key.$l"] = "Текстът е твърде дълъг — най-много $max знака.";
                $out[$l] = $v;
            }
            if (!empty($def['required']) && $out['bg'] === '') $err["$key.bg"] ??= 'Това поле е задължително.';
            return [$out, $err];

        case 'image':
            $v = is_string($raw) ? trim($raw) : '';
            if ($v !== '' && !home_valid_image_path($v)) {
                $err[$key] = 'Снимката не е намерена. Качете я отново или я изберете от библиотеката.';
            }
            return [$v, $err];

        case 'choice':
            $default = $def['default'] ?? (string) array_key_first($def['options']);
            if (!is_string($raw)) return [$default, []];
            if (!array_key_exists($raw, $def['options'])) return [$default, [$key => 'Изберете една от възможностите.']];
            return [$raw, []];

        case 'video':
            if (is_array($raw) && home_video_valid($raw)) return [['provider' => $raw['provider'], 'id' => $raw['id']], []];
            $url = is_string($raw) ? trim($raw) : '';
            if ($url === '') return ['', [$key => 'Поставете линк към видео в YouTube или Vimeo.']];
            $v = home_parse_video_url($url);
            return $v !== null
                ? [$v, []]
                : [$url, [$key => 'Това не е линк към видео в YouTube или Vimeo. Отворете видеото, копирайте адреса от браузъра и го поставете тук.']];

        case 'internal':
            $v = is_string($raw) ? $raw : '';
            return [home_valid_image_path($v) ? $v : '', []];

        case 'cards':
            $items = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
            $out = [];
            foreach ($items as $i => $card) {
                $c = [];
                foreach (HOME_CARD_FIELDS as $ck => $cdef) {
                    [$c[$ck], $e] = home_clean_field($cdef, $card[$ck] ?? null, "$key.$i.$ck");
                    $err += $e;
                }
                $out[] = $c;
            }
            if (count($out) < 2 || count($out) > 4) $err[$key] = 'Добавете между 2 и 4 карти.';
            return [$out, $err];
    }
    return [null, [$key => 'Непознато поле.']];
}

/** @return array{0: array, 1: array<string,string>} */
function home_validate_section(string $type, array $in): array {
    $types = home_types();
    if (!isset($types[$type])) return [[], ['_type' => 'Непознат вид секция.']];
    $fields = [];
    $errors = [];
    foreach ($types[$type]['fields'] as $key => $def) {
        [$fields[$key], $e] = home_clean_field($def, $in[$key] ?? null, $key);
        $errors += $e;
    }
    // A button needs both a label and a link. EN falls back to BG for either.
    foreach ([1, 2] as $n) {
        if (!isset($fields["btn{$n}_label"])) continue;
        $label = $fields["btn{$n}_label"];
        $url   = $fields["btn{$n}_url"];
        if ($label['bg'] !== '' && $url['bg'] === '') {
            $errors["btn{$n}_url.bg"] ??= 'Добавете линк за бутона или изтрийте надписа му.';
        }
        if ($label['en'] !== '' && $url['en'] === '' && $url['bg'] === '') {
            $errors["btn{$n}_url.en"] ??= 'Добавете линк за бутона или изтрийте надписа му.';
        }
        if ($url['bg'] !== '' && $label['bg'] === '') {
            $errors["btn{$n}_label.bg"] ??= 'Добавете надпис на бутона или изтрийте линка му.';
        }
        if ($url['en'] !== '' && $label['en'] === '' && $label['bg'] === '') {
            $errors["btn{$n}_label.en"] ??= 'Добавете надпис на бутона или изтрийте линка му.';
        }
    }
    return [$fields, $errors];
}

// ── Storage ──────────────────────────────────────────────────────────────────

function home_file(): string {
    return $GLOBALS['_om_home_file'] ?? CONTENT_PATH . '/home.json';
}

/**
 * The default front page, built from what the site shows today: saved values in
 * pages.json['home'] first, then the strings.json defaults, then the old hard-coded text.
 */
function home_seed(array $home, array $sbg, array $sen): array {
    $p = fn(string $key, string $skey = '', string $dbg = '', string $den = ''): array => [
        'bg' => (string) (($home[$key] ?? '') ?: ($skey !== '' ? ($sbg[$skey] ?? '') : '') ?: $dbg),
        'en' => (string) (($home[$key . '_en'] ?? '') ?: ($skey !== '' ? ($sen[$skey] ?? '') : '') ?: $den),
    ];
    $pair = fn(string $bg, string $en): array => ['bg' => $bg, 'en' => $en];
    $sec  = fn(string $type, array $fields): array => ['id' => 's_' . $type, 'type' => $type, 'visible' => true, 'fields' => $fields];
    $name = $pair(SITE_NAME_BG, SITE_NAME_EN);
    $img  = fn(string $path, string $fallback = ''): string => home_valid_image_path($path) ? $path : $fallback;

    return ['version' => 1, 'rev' => 0, 'sections' => [
        $sec('hero', [
            'title' => $p('hero_title', 'home.hero.title'),
            'text'  => $p('hero_text', 'home.hero.text'),
            'image' => $img((string) ($home['hero_image'] ?? ''), '/assets/images/hero.webp'),
            'image_alt'  => $name,
            'btn1_label' => $p('hero_cta_primary', 'home.hero.cta_primary', 'Как да помогна', 'How to help'),
            'btn1_url'   => $pair('/kak-da-pomogna/', '/en/how-to-help/'),
            'btn2_label' => $p('hero_cta_secondary', 'home.hero.cta_secondary', 'Научи повече', 'Learn more'),
            'btn2_url'   => $pair('/za-nas/', '/en/about/'),
        ]),
        $sec('products', [
            'heading'    => $p('section_shop', 'home.shop.title'),
            'btn1_label' => $p('shop_btn_all', 'home.shop.all', 'Отидете на магазина', 'Visit shop'),
            'btn1_url'   => $pair('/magazin/', '/en/shop/'),
            'background' => 'white',
        ]),
        $sec('impact', ['heading' => $p('section_impact')]),
        $sec('campaign', []),
        $sec('centres', [
            'heading'    => $p('section_centres', 'home.centres.title'),
            'intro'      => $pair('', ''),
            'background' => 'white',
        ]),
        $sec('mission', [
            'title' => $pair(
                (string) (($home['section_mission'] ?? '') ?: ($home['mission_title'] ?? '') ?: ($sbg['home.mission.title'] ?? '')),
                (string) (($home['section_mission_en'] ?? '') ?: ($home['mission_title_en'] ?? '') ?: ($sen['home.mission.title'] ?? ''))
            ),
            'text' => $pair(home_clean_html((string) ($home['mission_text'] ?? '')), home_clean_html((string) ($home['mission_text_en'] ?? ''))),
            'image'      => $img((string) ($home['mission_image'] ?? '')),
            'image_alt'  => $name,
            'btn1_label' => $p('mission_cta_primary', '', 'Разберете повече за нас', 'Learn more about us'),
            'btn1_url'   => $pair('/za-nas/', '/en/about/'),
            'btn2_label' => $p('mission_cta_secondary', '', 'Подкрепете ни', 'Support us'),
            'btn2_url'   => $pair('/magazin/', '/en/shop/'),
            'background' => 'grey',
        ]),
        $sec('news', [
            'heading'    => $p('section_news', 'home.news.title'),
            'count'      => '3',
            'btn1_label' => $p('news_btn_all', 'home.news.all', 'Всички новини', 'All news'),
            'btn1_url'   => $pair('/novini/', '/en/news/'),
            'background' => 'white',
        ]),
        $sec('partners', ['heading' => $p('section_partners', 'home.partners.title'), 'background' => 'grey']),
        $sec('cta', [
            'heading'    => $p('cta_heading', '', 'Всяко дете заслужава шанс', 'Every child deserves a chance'),
            'text'       => $p('cta_body', '',
                'С вашата подкрепа можем да достигнем до повече деца, да финансираме повече терапии и да изградим по-добро бъдеще за всяко от тях.',
                'With your support we can reach more children, fund more therapies, and build a better future for each of them.'),
            'btn1_label' => $p('cta_btn_donate', '', 'Дарете сега', 'Donate now'),
            'btn1_url'   => $pair('/magazin/', '/en/shop/'),
            'btn2_label' => $p('cta_btn_help', '', 'Как да помогна', 'How to help'),
            'btn2_url'   => $pair('/kak-da-pomogna/', '/en/how-to-help/'),
            'background' => 'teal',
        ]),
    ]];
}

function home_seed_from_site(): array {
    $pages = load_json(CONTENT_PATH . '/pages.json');
    return home_seed($pages['home'] ?? [], load_json(CONTENT_PATH . '/bg/strings.json'), load_json(CONTENT_PATH . '/en/strings.json'));
}

/**
 * A built-in that went missing (hand edit, older file) comes back hidden, so it can always be restored.
 * The seed (pages.json + both strings files + HTML cleaning) is built only when one is missing —
 * this runs on every front-page view.
 * @param ?callable $seed defaults to home_seed_from_site(); injectable for tests.
 */
function home_ensure_builtins(array $doc, ?callable $seed = null): array {
    $have    = array_column($doc['sections'], 'type');
    $missing = array_diff(array_keys(array_filter(home_types(), fn($t) => $t['builtin'])), $have);
    if (!$missing) return $doc;
    foreach (($seed ?? 'home_seed_from_site')()['sections'] as $s) {
        if (in_array($s['type'], $missing, true)) {
            $s['visible'] = false;
            $doc['sections'][] = $s;
        }
    }
    return $doc;
}

/** @return array{doc: array, corrupt: bool, exists: bool} */
function home_load(): array {
    $path = home_file();
    if (!file_exists($path)) return ['doc' => home_seed_from_site(), 'corrupt' => false, 'exists' => false];
    $raw = @file_get_contents($path);
    $doc = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($doc) || !is_array($doc['sections'] ?? null)) {
        error_log('home.json is unreadable or invalid — showing the default front page');
        return ['doc' => home_seed_from_site(), 'corrupt' => true, 'exists' => true];
    }
    $doc['version']  = 1;
    $doc['rev']      = (int) ($doc['rev'] ?? 0);
    $doc['sections'] = array_values(array_filter($doc['sections'], fn($s) =>
        is_array($s) && is_string($s['id'] ?? null) && is_string($s['type'] ?? null)));
    foreach ($doc['sections'] as &$s) {
        $s['visible'] = !empty($s['visible']);
        $s['fields']  = is_array($s['fields'] ?? null) ? $s['fields'] : [];
    }
    unset($s);
    return ['doc' => home_ensure_builtins($doc), 'corrupt' => false, 'exists' => true];
}

/**
 * Write the whole document if nobody saved since $expected_rev was read.
 * @return array{ok: bool, error: ?string, doc?: array}
 */
function home_save(array $doc, int $expected_rev): array {
    $path = home_file();
    $dir  = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return ['ok' => false, 'error' => 'write'];
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) return ['ok' => false, 'error' => 'write'];
    try {
        $current = 0;
        $damaged = false;
        if (file_exists($path)) {
            $cur     = json_decode((string) file_get_contents($path), true);
            $current = is_array($cur) ? (int) ($cur['rev'] ?? 0) : 0;
            $damaged = !is_array($cur) || !is_array($cur['sections'] ?? null);
        }
        if ($current !== $expected_rev) return ['ok' => false, 'error' => 'conflict'];
        // Never overwrite a damaged file without keeping a copy — it may be fixable by hand.
        if ($damaged) {
            $backup = $path . '.corrupt-' . date('YmdHis');
            if (file_exists($backup)) $backup .= '-' . bin2hex(random_bytes(2));
            if (!copy($path, $backup)) return ['ok' => false, 'error' => 'write'];
            error_log('home.json was damaged — kept a copy as ' . basename($backup) . ' before saving');
        }

        $doc['version']  = 1;
        $doc['rev']      = $current + 1;
        $doc['sections'] = array_values($doc['sections']);
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));
        if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'write'];
        }
        return ['ok' => true, 'error' => null, 'doc' => $doc];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

const HOME_CONFLICT_FORM_MESSAGE = 'Междувременно някой друг е променил началната страница. Вашият текст е запазен тук — проверете го и натиснете „Запази“ отново.';

function home_save_error_message(?string $error): string {
    return $error === 'conflict'
        ? 'Междувременно някой друг е променил началната страница. Презаредете страницата и направете промяната отново.'
        : 'Промените не можаха да се запазят. Опитайте отново след малко.';
}

// ── List actions ─────────────────────────────────────────────────────────────

const HOME_ACTIONS = ['move_up', 'move_down', 'toggle', 'duplicate', 'delete'];

function home_find(array $doc, string $id): ?int {
    foreach ($doc['sections'] as $i => $s) {
        if (($s['id'] ?? null) === $id) return $i;
    }
    return null;
}

function home_new_id(): string {
    return 's_' . bin2hex(random_bytes(4));
}

/** Short human name for messages: the section's own heading, else its type. */
function home_section_name(array $s): string {
    $f = $s['fields'] ?? [];
    foreach (['heading', 'title'] as $k) {
        $v = trim(strip_tags((string) ($f[$k]['bg'] ?? '')));
        if ($v !== '') return mb_strimwidth($v, 0, 60, '…');
    }
    return home_types()[$s['type'] ?? '']['label'] ?? 'Секция';
}

/** One line shown under the section name in the admin list. */
function home_section_preview(array $s): string {
    $f = $s['fields'] ?? [];
    if (($s['type'] ?? '') === 'video' && is_array($f['video'] ?? null) && home_video_valid($f['video'])) {
        return ($f['video']['provider'] === 'youtube' ? 'YouTube' : 'Vimeo') . ' видео';
    }
    if (($s['type'] ?? '') === 'cards') return count($f['cards'] ?? []) . ' карти';
    foreach (['heading', 'title', 'text', 'body', 'intro'] as $k) {
        $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($f[$k]['bg'] ?? ''))));
        if ($v !== '') return mb_strimwidth($v, 0, 90, '…');
    }
    return home_types()[$s['type'] ?? '']['desc'] ?? '';
}

/** @return array{ok: bool, doc: array, message: string, focus: ?string} */
function home_apply_action(array $doc, string $action, string $id): array {
    $fail = fn(string $msg) => ['ok' => false, 'doc' => $doc, 'message' => $msg, 'focus' => $id];
    $i = home_find($doc, $id);
    if ($i === null) return $fail('Секцията не е намерена — може би е изтрита междувременно. Презаредете страницата.');
    $s    = $doc['sections'][$i];
    $name = home_section_name($s);
    $list = $doc['sections'];
    $last = count($list) - 1;

    switch ($action) {
        case 'move_up':
            if ($i === 0) return $fail("\u{201E}{$name}\u{201C} вече е най-горе.");
            [$list[$i - 1], $list[$i]] = [$list[$i], $list[$i - 1]];
            $msg = "\u{201E}{$name}\u{201C} е преместена нагоре.";
            break;
        case 'move_down':
            if ($i === $last) return $fail("\u{201E}{$name}\u{201C} вече е най-долу.");
            [$list[$i + 1], $list[$i]] = [$list[$i], $list[$i + 1]];
            $msg = "\u{201E}{$name}\u{201C} е преместена надолу.";
            break;
        case 'toggle':
            $list[$i]['visible'] = empty($s['visible']);
            $msg = $list[$i]['visible'] ? "\u{201E}{$name}\u{201C} вече се показва на сайта." : "\u{201E}{$name}\u{201C} е скрита от сайта.";
            break;
        case 'duplicate':
            if (home_is_builtin((string) $s['type'])) return $fail('Тази секция съществува само веднъж и не може да се дублира.');
            $copy = $s;
            $copy['id'] = home_new_id();
            array_splice($list, $i + 1, 0, [$copy]);
            $doc['sections'] = $list;
            return ['ok' => true, 'doc' => $doc, 'message' => "\u{201E}{$name}\u{201C} е дублирана. Копието е точно под нея.", 'focus' => $copy['id']];
        case 'delete':
            if (home_is_builtin((string) $s['type'])) return $fail('Тази секция не може да се изтрие, но можете да я скриете.');
            array_splice($list, $i, 1);
            $doc['sections'] = $list;
            return ['ok' => true, 'doc' => $doc, 'message' => "\u{201E}{$name}\u{201C} е изтрита.", 'focus' => null];
        default:
            return $fail('Непознато действие.');
    }
    $doc['sections'] = $list;
    return ['ok' => true, 'doc' => $doc, 'message' => $msg, 'focus' => $id];
}

function home_upsert(array $doc, array $section): array {
    $i = home_find($doc, (string) $section['id']);
    if ($i === null) {
        $doc['sections'][] = $section;
    } else {
        $doc['sections'][$i] = $section;
    }
    return $doc;
}

// ── Video thumbnails ─────────────────────────────────────────────────────────
// Fetched once, when the admin saves, so visitors' browsers never contact
// YouTube/Vimeo until they press play.

const HOME_THUMB_MAX_BYTES = 5_000_000;

function home_http_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'ngo-cms',
        CURLOPT_MAXFILESIZE    => HOME_THUMB_MAX_BYTES,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_XFERINFOFUNCTION => fn($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) => $dlNow > HOME_THUMB_MAX_BYTES ? 1 : 0,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($body) && $code === 200 ? $body : null;
}

/** @return string site path of the saved thumbnail, or '' (the block then shows a plain play panel). */
function home_fetch_video_thumb(array $v, string $sid, ?callable $get = null, ?string $root = null): string {
    if (!home_video_valid($v) || !preg_match('/^s_[a-z0-9_]{1,24}$/', $sid)) return '';
    $get  ??= 'home_http_get';
    $root ??= ROOT_PATH;
    if ($v['provider'] === 'youtube') {
        $src = 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg';
    } else {
        $json = json_decode((string) $get('https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $v['id'])), true);
        $src  = is_array($json) ? (string) ($json['thumbnail_url'] ?? '') : '';
        if (!preg_match('#^https://i\.vimeocdn\.com/[^\s"<>]+$#', $src)) return '';
    }
    $bytes = $get($src);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > HOME_THUMB_MAX_BYTES) return '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)] ?? null;
    if ($ext === null) return '';
    $rel = '/assets/images/pages/home/' . $sid . '-video-' . $v['id'] . '.' . $ext;
    $abs = $root . $rel;
    $d   = dirname($abs);
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) return '';
    return file_put_contents($abs, $bytes) !== false ? $rel : '';
}

// ── On-page editing ──────────────────────────────────────────────────────────

/** Save fields edited on the live page. Links, choices and cards are admin-form only. */
function home_inline_save(string $id, array $fields): array {
    $loaded = home_load();
    if ($loaded['corrupt']) {
        return ['ok' => false, 'error' => 'Файлът на началната страница е повреден — отворете Админ → Съдържание → Начална страница.'];
    }
    $doc = $loaded['doc'];
    $i   = home_find($doc, $id);
    if ($i === null) return ['ok' => false, 'error' => 'unknown section'];
    $defs = home_types()[$doc['sections'][$i]['type']]['fields'] ?? [];
    $accepted = 0;
    foreach ($fields as $key => $values) {
        $def = $defs[$key] ?? null;
        if ($def === null || !in_array($def['kind'], ['text', 'textarea', 'html', 'alt', 'image'], true) || !is_array($values)) continue;
        if ($def['kind'] === 'image') {
            $raw = (string) ($values['bg'] ?? '');
        } else {
            // A language the editor did not send keeps its stored value. One that is
            // sent — even as '' — replaces it, so clearing a field on purpose still works.
            $raw = home_pair($doc['sections'][$i]['fields'][$key] ?? null);
            foreach (['bg', 'en'] as $l) {
                if (array_key_exists($l, $values)) $raw[$l] = is_string($values[$l]) ? $values[$l] : '';
            }
        }
        [$value, $err] = home_clean_field($def, $raw, (string) $key);
        if ($err) return ['ok' => false, 'error' => (string) reset($err)];
        $doc['sections'][$i]['fields'][$key] = $value;
        $accepted++;
    }
    if ($accepted === 0) {
        return ['ok' => false, 'error' => 'Тези полета не могат да се променят оттук. Отворете Админ → Съдържание → Начална страница.'];
    }
    $saved = home_save($doc, (int) $doc['rev']);
    return $saved['ok'] ? ['ok' => true] : ['ok' => false, 'error' => home_save_error_message($saved['error'])];
}

// ── Uploads (admin form) ─────────────────────────────────────────────────────

/** One file out of PHP's $_FILES[$name][...][$key] layout, or null. */
function home_files_entry(array $files, string $name, string|int $key): ?array {
    if (!isset($files[$name]['tmp_name'][$key])) return null;
    return [
        'tmp_name' => $files[$name]['tmp_name'][$key],
        'error'    => $files[$name]['error'][$key] ?? UPLOAD_ERR_NO_FILE,
        'name'     => $files[$name]['name'][$key] ?? '',
    ];
}

/** @return array{path: ?string, error: ?string} both null when no file was chosen. */
function home_store_upload(array $file, string $sid, string $key): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) return ['path' => null, 'error' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['path' => null, 'error' => 'Снимката не можа да се качи. Опитайте с по-малък файл.'];
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(string) mime_content_type($file['tmp_name'])] ?? null;
    if ($ext === null) return ['path' => null, 'error' => 'Снимката трябва да е JPEG, PNG или WebP.'];
    if (!preg_match('/^s_[a-z0-9_]{1,24}$/', $sid)) return ['path' => null, 'error' => 'Снимката не можа да се запази.'];
    $rel = '/assets/images/pages/home/' . $sid . '-' . preg_replace('/[^a-z0-9_]/', '', $key) . '-' . time() . '.' . $ext;
    $abs = ROOT_PATH . $rel;
    if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $abs)) return ['path' => null, 'error' => 'Снимката не можа да се запази. Опитайте отново.'];
    require_once ROOT_PATH . '/includes/images.php';
    image_resize_to_fit($abs);
    return ['path' => $rel, 'error' => null];
}

/** Store chosen files and put their paths into $in. Upload fields: up[<key>], up_card[<index>]. */
function home_apply_uploads(array &$in, string $type, array $files, string $sid): array {
    $errors = [];
    foreach (home_types()[$type]['fields'] ?? [] as $key => $def) {
        if ($def['kind'] === 'image' && ($file = home_files_entry($files, 'up', $key))) {
            $r = home_store_upload($file, $sid, $key);
            if ($r['error']) $errors[$key] = $r['error'];
            elseif ($r['path']) $in[$key] = $r['path'];
        }
        if ($def['kind'] === 'cards' && is_array($in['cards'] ?? null)) {
            $pos = 0;
            foreach (array_keys($in['cards']) as $ci) {
                if (is_array($in['cards'][$ci]) && ($file = home_files_entry($files, 'up_card', $ci))) {
                    $r = home_store_upload($file, $sid, 'card' . (int) $ci);
                    if ($r['error']) $errors["cards.$pos.image"] = $r['error'];
                    elseif ($r['path']) $in['cards'][$ci]['image'] = $r['path'];
                }
                $pos++;
            }
        }
    }
    return $errors;
}

/**
 * Handle admin/home-sections.php's action=save POST: resolve the target section
 * (existing id, or a brand-new non-builtin type), apply uploads, validate, fetch
 * a video thumbnail when needed, and persist. Pure w.r.t. the caller's globals —
 * takes $doc/$post/$files in, returns a status the controller maps to a response.
 *
 * @param ?callable $fetch_thumb defaults to home_fetch_video_thumb(); injectable for tests.
 * @return array{status: string, doc: ?array, sid: string, message: string, errors: array, form: ?array, rev?: int}
 *   status: 'bad_type' (unknown/builtin type — caller should 400),
 *           'not_found' (posted id no longer exists — caller should flash+redirect),
 *           'saved' (persisted — caller should flash+redirect to ?focus=edit:<sid>),
 *           'invalid' (validation/upload/save-conflict errors — caller should re-render the form
 *                      with 'rev': the posted revision, or the current one after a conflict).
 */
function home_admin_save(array $doc, array $post, array $files, ?callable $fetch_thumb = null): array {
    $fetch_thumb ??= 'home_fetch_video_thumb';
    $types    = home_types();
    $post_id  = (string) ($post['id'] ?? '');
    $post_rev = (int) ($post['rev'] ?? -1);

    $idx = $post_id !== '' ? home_find($doc, $post_id) : null;
    if ($post_id !== '' && $idx === null) {
        return ['status' => 'not_found', 'doc' => null, 'sid' => $post_id,
                'message' => 'Секцията не е намерена — може би е изтрита междувременно.', 'errors' => [], 'form' => null];
    }
    $type = $idx !== null ? (string) $doc['sections'][$idx]['type'] : (string) ($post['type'] ?? '');
    if (!isset($types[$type]) || ($idx === null && home_is_builtin($type))) {
        return ['status' => 'bad_type', 'doc' => null, 'sid' => '', 'message' => '', 'errors' => [], 'form' => null];
    }

    $sid = $idx !== null ? $post_id : home_new_id();
    $old = $idx !== null ? ($doc['sections'][$idx]['fields'] ?? []) : [];
    $in  = is_array($post['f'] ?? null) ? $post['f'] : [];
    $upload_errors     = home_apply_uploads($in, $type, $files, $sid);
    [$fields, $errors] = home_validate_section($type, $in);
    $errors += $upload_errors;

    if (!$errors && $type === 'video') {
        $fields['thumb'] = (($old['video'] ?? null) === $fields['video'] && !empty($old['thumb']))
            ? $old['thumb'] : $fetch_thumb($fields['video'], $sid);
    }
    if (!$errors) {
        $section = ['id' => $sid, 'type' => $type, 'visible' => $idx !== null ? !empty($doc['sections'][$idx]['visible']) : true, 'fields' => $fields];
        $saved   = home_save(home_upsert($doc, $section), $post_rev);
        if ($saved['ok']) {
            $name = home_section_name($section);
            $message = $idx !== null ? "„{$name}“ е запазена." : "„{$name}“ е добавена най-долу на страницата и вече се вижда.";
            return ['status' => 'saved', 'doc' => $saved['doc'], 'sid' => $sid, 'message' => $message, 'errors' => [], 'form' => null];
        }
        if ($saved['error'] === 'conflict') {
            // Only this section is written (upserted into the file as it is now), so the
            // typed values can simply be saved again — against the current revision.
            $errors['_form'] = HOME_CONFLICT_FORM_MESSAGE;
            $rev_out = (int) home_load()['doc']['rev'];
        } else {
            $errors['_form'] = home_save_error_message($saved['error']);
        }
    }
    return ['status' => 'invalid', 'doc' => null, 'sid' => $sid, 'message' => '', 'errors' => $errors,
            'form' => ['id' => $idx !== null ? $post_id : '', 'type' => $type, 'fields' => $fields],
            'rev' => $rev_out ?? $post_rev];
}
