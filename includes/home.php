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
