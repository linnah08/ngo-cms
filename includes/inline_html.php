<?php
/**
 * Short formatted texts saved from Edit mode and the inline "add" form: FAQ answers, the
 * campaign description, page texts in pages.json. They are printed on public pages as HTML,
 * so they keep simple formatting only:
 *   - tags: b strong em i a ul ol li br p h2 h3 — any other tag is dropped, its words kept
 *     (script, style and similar are dropped with their content);
 *   - attributes: none, except href on <a>, and only an http(s), mailto: or tel: address, a
 *     path on this site or a #anchor. "javascript:", "data:" and the like are removed.
 *
 * strip_tags() with an allowlist, used here before, kept every attribute of an allowed tag —
 * <a href="javascript:…"> and onclick="…" went straight to the page (stored XSS).
 *
 * Uses the classic DOM extension, so it works on PHP 8.3 and 8.4 alike.
 */

const INLINE_HTML_TAGS = ['b', 'strong', 'em', 'i', 'a', 'ul', 'ol', 'li', 'br', 'p', 'h2', 'h3'];
const INLINE_HTML_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript',
                          'svg', 'math', 'form', 'textarea', 'select', 'title', 'head'];

/** A link address an editor may use, or null. */
function inline_clean_href(string $href): ?string {
    $href = trim($href);
    if (preg_match('/^tel:/i', $href)) $href = preg_replace('/\s+/', '', $href);   // "tel:+359 88 123"
    if ($href === '' || preg_match('/[\x00-\x20\x7f<>"\'\\\\`]/', $href)) return null;
    if ($href[0] === '#') return $href;
    if ($href[0] === '/') return str_starts_with($href, '//') ? null : $href;
    if (preg_match('/^(https?:\/\/[^\/?#]+|mailto:[^\/?#]+@|tel:\+?[0-9().\-]+$)/i', $href)) return $href;
    // A relative path with no scheme ("kontakti", "../za-nas") — anything with a colon
    // before the first slash is a scheme, and an unknown scheme is refused.
    return preg_match('/^[^:\/?#]+(?:[\/?#]|$)/', $href) ? $href : null;
}

function inline_clean_html(mixed $html): string {
    $html = is_string($html) ? trim($html) : '';
    if ($html === '') return '';
    // No tag at all: nothing to clean, and the text is stored exactly as typed. Many of these
    // fields are printed with h(), where re-encoding "&" as "&amp;" would show on the page.
    if (!str_contains($html, '<')) return $html;
    if (!class_exists('DOMDocument')) {
        // Never store markup that could not be checked: keep the words only.
        return htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="inline-root">'
        . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('inline-root');
    if ($root === null) return '';

    // Deepest first, so unwrapping a parent never skips its children.
    $all = [];
    foreach ($root->getElementsByTagName('*') as $el) $all[] = $el;
    foreach (array_reverse($all) as $el) {
        $tag = strtolower($el->localName);
        if (in_array($tag, INLINE_HTML_DROP, true)) { $el->parentNode?->removeChild($el); continue; }
        if (!in_array($tag, INLINE_HTML_TAGS, true)) {
            while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
            $el->parentNode->removeChild($el);
            continue;
        }
        $href = $tag === 'a' ? inline_clean_href((string) $el->getAttribute('href')) : null;
        while ($el->attributes->length) $el->removeAttributeNode($el->attributes->item(0));
        if ($href !== null) {
            $el->setAttribute('href', $href);
            if (preg_match('#^https?://#i', $href)) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }
    // Comments could hide markup from a later reader; drop them too.
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('.//comment()', $root)) as $c) $c->parentNode->removeChild($c);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}
