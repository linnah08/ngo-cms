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

const HOME_BACKGROUNDS = ['white' => 'Бял', 'grey' => 'Светлосив', 'teal' => 'Основният цвят на сайта', 'warm' => 'Топъл (бежов)'];

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

    $bg   = fn(string $default): array => ['kind' => 'choice', 'label' => 'Фон на секцията', 'options' => HOME_BACKGROUNDS, 'default' => $default];
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
            'note' => 'Кои продукти се показват, избирате в Продукти → „Показвай на началната страница". Ако няма отбелязани, секцията не се показва.',
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
                + $btn(1) + $btn(2) + ['background' => $bg('teal')]],
        'richtext' => ['builtin' => false, 'icon' => '📝', 'label' => 'Свободен текст',
            'desc' => 'Заглавие и текст със списъци, връзки и таблици.', 'note' => null,
            'fields' => ['heading' => $head, 'body' => $rich, 'background' => $bg('white')]],
        'cards' => ['builtin' => false, 'icon' => '🗂', 'label' => 'Карти',
            'desc' => 'От 2 до 4 карти, всяка със снимка, заглавие, кратък текст и линк.', 'note' => null,
            'fields' => ['heading' => $head, 'cards' => ['kind' => 'cards', 'label' => 'Карти'], 'background' => $bg('white')]],
        'video' => ['builtin' => false, 'icon' => '▶️', 'label' => 'Видео',
            'desc' => 'Видео от YouTube или Vimeo. Зарежда се едва когато посетителят натисне „Пусни".', 'note' => null,
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
    }
    return [$fields, $errors];
}
