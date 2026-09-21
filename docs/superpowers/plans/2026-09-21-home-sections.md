# Configurable Front-Page Sections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a non-technical admin reorder, hide, edit, add, duplicate and delete front-page sections (BG + EN) from a new admin screen, with both front pages rendered from one shared section list.

**Architecture:** An ordered section list lives in `content/home.json`. `includes/home.php` is the model (type registry, field cleaning, validation, load/seed/save, list actions, uploads, video thumbnails, inline save). `includes/home_render.php` renders sections through one template per type in `templates/home/`. `index.php` and `en/index.php` become thin wrappers. `admin/home-sections.php` (+ form helpers in `includes/home_admin.php`) is the editing UI.

**Tech Stack:** PHP 8.4 (no framework, `Dom\HTMLDocument` for HTML cleaning), flat-file JSON, PHPUnit 13, Playwright, TinyMCE (shared `window._tinyBase`).

**Spec:** `docs/superpowers/specs/2026-09-21-home-sections-design.md`

## Global Constraints

- Work in a worktree, not the main checkout — another session commits in `oddminds-oss` concurrently (CLAUDE.md "One session per checkout"). Setup is Task 1, Step 1.
- Stage files by name; never `git add -A`. Commit after each task; **never push**.
- Every admin endpoint: `admin_require_login()` + `admin_require_admin()` before any output or data access; `csrf_verify()` at the top of every POST handler.
- Escape all output with `h()` (or `asset_url()` for image paths). Rich text is cleaned on save by `home_clean_html()` and echoed raw only after that.
- Layout-critical CSS (grid/flex/dimensions) in new public markup is inline, or in the one inline `<style>` block emitted by `home_render()`. No new classes in `main.css`/`admin.css`.
- Every EN admin text field carries `data-translate-from="<bg field name>"` (links excepted — they are not text).
- All UI copy in plain Bulgarian (public copy BG/EN), no jargon. Errors persistent, never colour-only (text + ⚠).
- Accessibility per CLAUDE.md: real `<button>`/`<label for>`, 44px targets, visible focus, `role="status"` for changes, `alt` on every `<img>`.
- Section id pattern: `/^s_[a-z0-9_]{1,24}$/`. Built-in ids: `s_hero s_products s_impact s_campaign s_centres s_mission s_news s_partners`.
- Test file override: tests point the model at a temp file via `$GLOBALS['_om_home_file']`.
- Run the default suite with `php vendor/bin/phpunit`. Note: `phpunit.xml` lists suites explicitly, so tests outside them (e.g. `tests/InlineSaveTest.php`, `tests/Admin/TranslateButtonCoverageTest.php`) must be run by path — steps below say when.

## Spec deltas (decided while planning — keep the spec in sync in Task 1's commit)

1. No HTML sanitiser exists in the codebase (articles store TinyMCE output unfiltered; inline-save uses `strip_tags` allowlists, which keep `onclick`/`javascript:`). So this feature adds `home_clean_html()` (tag allowlist + attribute strip via `Dom\HTMLDocument`).
2. The mission section also gets its two buttons (label + link), which today are hard-coded — needed for "fully edit".
3. The campaign form stays in `admin/pages.php`, moved to its own view `?page=home_campaign`; the campaign section's Edit view links there. Moving its POST handler buys nothing.
4. Card fields are edited only in the admin form (no inline editing inside cards).

## File map

| File | Responsibility |
|---|---|
| `includes/home.php` (new) | Model: registry, field cleaners, validation, seed/load/save, actions, uploads, video thumbs, inline save |
| `includes/home_render.php` (new) | `hf()`, attribute helpers, `home_context()`, `home_render()`, shared style/script |
| `templates/home/*.php` (new, 13) | One template per section type |
| `includes/home_admin.php` (new) | Admin form-field renderers |
| `admin/home-sections.php` (new) | Admin list / picker / edit screen and POST handler |
| `index.php`, `en/index.php` | Thin wrappers around `home_render()` |
| `admin/inline-save.php` | Route `home:<id>` sections to `home_inline_save()` |
| `admin/pages.php` | `?page=home` → redirect; campaign form under `?page=home_campaign`; old home form removed |
| `.gitignore` | `/content/home.json`, its lock file, `/assets/images/pages/home/` |
| `phpunit.xml` | New `Home` suite |
| `tests/FeatureFlagsTest.php` | Campaign gate now lives in `templates/home/campaign.php` |
| `tests/Home/*.php`, `tests/Admin/HomeSectionsAdminTest.php`, `tests/Browser/home-sections.spec.js` (new) | Tests |

---

### Task 1: Worktree, field cleaners (links, images, HTML, video URLs)

**Files:**
- Create: `includes/home.php`
- Create: `tests/Home/HomeFieldsTest.php`
- Modify: `phpunit.xml` (add suite), `.gitignore`, `docs/superpowers/specs/2026-09-21-home-sections-design.md` (spec deltas)

**Interfaces:**
- Produces: `home_clean_link(string): ?string` (`''` for empty, `null` if invalid), `home_valid_image_path(string): bool`, `home_clean_html(string): string`, `home_parse_video_url(string): ?array{provider:string,id:string}`, `home_video_valid(array): bool`, `home_video_watch_url(array): string`, `home_video_embed_url(array): string`, `home_pair(mixed): array{bg:string,en:string}`, constants `HOME_IMAGE_RE`, `HOME_HTML_TAGS`.

- [ ] **Step 1: Create the worktree**

```bash
cd /Users/detelinavasileva/Code/oddminds-oss
git worktree add ../oddminds-oss-wt/home-sections -b home-sections
cd ../oddminds-oss-wt/home-sections
ln -s ../../oddminds-oss/vendor vendor
ln -s ../../oddminds-oss/node_modules node_modules
for f in site.config.php smtp.config.php graph.config.php db.config.php courier.config.php local.config.php; do [ -f ../../oddminds-oss/$f ] && ln -s ../../oddminds-oss/$f $f; done
php vendor/bin/phpunit 2>&1 | tail -3
```
Expected: the suite runs from the worktree (same pass/skip counts as in the main checkout). All later paths are relative to this worktree.

- [ ] **Step 2: Register the test suite and ignore runtime files**

In `phpunit.xml`, before `</testsuites>` add:
```xml
        <testsuite name="Home">
            <directory>tests/Home</directory>
            <file>tests/Admin/HomeSectionsAdminTest.php</file>
        </testsuite>
```
Append to `.gitignore` (under the existing `/content/pages.json` line):
```
/content/home.json
/content/home.json.lock
/content/home.json.tmp-*
/assets/images/pages/home/
```

- [ ] **Step 3: Write the failing tests**

`tests/Home/HomeFieldsTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeFieldsTest extends TestCase
{
    public static function goodLinks(): array
    {
        return [
            ['/za-nas/'], ['/en/how-to-help/'], ['/magazin/?cat=2#top'],
            ['https://example.org/page'], ['http://example.org'],
        ];
    }

    #[DataProvider('goodLinks')]
    public function test_accepts_site_paths_and_web_addresses(string $url): void
    {
        $this->assertSame($url, home_clean_link($url));
    }

    public static function badLinks(): array
    {
        return [
            ['javascript:alert(1)'], ['JAVASCRIPT:alert(1)'], ['data:text/html,x'],
            ['//evil.example/x'], ['/\\evil.example'], ['ftp://example.org'],
            ['https://exa mple.org'], ['"><script>'], ['vbscript:x'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_rejects_dangerous_or_malformed_links(string $url): void
    {
        $this->assertNull(home_clean_link($url));
    }

    public function test_empty_link_is_allowed_and_trimmed(): void
    {
        $this->assertSame('', home_clean_link('   '));
        $this->assertSame('/za-nas/', home_clean_link('  /za-nas/ '));
    }

    public function test_image_paths_must_live_under_assets_images(): void
    {
        $this->assertTrue(home_valid_image_path('/assets/images/pages/home/s_ab12-image-1.webp'));
        $this->assertTrue(home_valid_image_path('/assets/images/hero.webp'));
        $this->assertFalse(home_valid_image_path('/config.php'));
        $this->assertFalse(home_valid_image_path('/assets/images/../../config.php'));
        $this->assertFalse(home_valid_image_path('/assets/images/a.jpg?x=1'));
        $this->assertFalse(home_valid_image_path('https://evil.example/a.jpg'));
        $this->assertFalse(home_valid_image_path(''));
    }

    public function test_html_keeps_formatting_and_cyrillic(): void
    {
        $in = '<p>Здравейте, <strong>свят</strong> <em>и</em> <a href="/za-nas/">нас</a></p><ul><li>едно</li></ul>';
        $this->assertSame($in, home_clean_html($in));
    }

    public function test_html_strips_scripts_handlers_and_bad_hrefs(): void
    {
        $out = home_clean_html(
            '<p onclick="x()" style="color:red">A<script>alert(1)</script></p>'
            . '<a href="javascript:alert(1)">B</a><img src="x" onerror="y()"><div>C</div>'
        );
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img src="x"', $out);   // src outside /assets/images and not https
        $this->assertStringContainsString('<p>A', $out);
        $this->assertStringContainsString('C', $out);                  // <div> dropped, text kept
    }

    public function test_html_external_links_open_safely(): void
    {
        $out = home_clean_html('<a href="https://example.org">x</a>');
        $this->assertSame('<a href="https://example.org" target="_blank" rel="noopener noreferrer">x</a>', $out);
    }

    public function test_html_keeps_library_images_with_alt(): void
    {
        $out = home_clean_html('<img src="/assets/images/pages/a.jpg" alt="Деца" class="x">');
        $this->assertSame('<img src="/assets/images/pages/a.jpg" alt="Деца">', $out);
    }

    public function test_html_empty_stays_empty(): void
    {
        $this->assertSame('', home_clean_html('   '));
    }

    public static function videoUrls(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=10', 'youtube', 'dQw4w9WgXcQ'],
            ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/live/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://vimeo.com/76979871', 'vimeo', '76979871'],
            ['https://vimeo.com/76979871?share=copy', 'vimeo', '76979871'],
            ['https://player.vimeo.com/video/76979871', 'vimeo', '76979871'],
        ];
    }

    #[DataProvider('videoUrls')]
    public function test_parses_video_urls(string $url, string $provider, string $id): void
    {
        $this->assertSame(['provider' => $provider, 'id' => $id], home_parse_video_url($url));
    }

    public function test_rejects_non_video_urls(): void
    {
        foreach ([
            '', 'https://example.org/watch?v=dQw4w9WgXcQ', 'https://youtube.com/watch?v=short',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)',
            'https://vimeo.com/channels/staffpicks',
        ] as $url) {
            $this->assertNull(home_parse_video_url($url), $url);
        }
    }

    public function test_video_urls_round_trip(): void
    {
        $yt = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'];
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', home_video_watch_url($yt));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', home_video_embed_url($yt));
        $vm = ['provider' => 'vimeo', 'id' => '76979871'];
        $this->assertSame('https://player.vimeo.com/video/76979871?autoplay=1&dnt=1', home_video_embed_url($vm));
        $this->assertFalse(home_video_valid(['provider' => 'youtube', 'id' => 'x"><']));
    }

    public function test_pair_normalises_any_input(): void
    {
        $this->assertSame(['bg' => 'a', 'en' => ''], home_pair('a'));
        $this->assertSame(['bg' => '', 'en' => ''], home_pair(null));
        $this->assertSame(['bg' => '', 'en' => 'b'], home_pair(['bg' => ['x'], 'en' => 'b']));
    }
}
```

- [ ] **Step 4: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeFieldsTest.php`
Expected: FAIL — `includes/home.php` not found.

- [ ] **Step 5: Implement**

`includes/home.php`:
```php
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
```

- [ ] **Step 6: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home/HomeFieldsTest.php`
Expected: PASS. If `test_html_keeps_formatting_and_cyrillic` fails on serialisation details (e.g. `Dom\HTMLDocument` re-ordering attributes), adjust the cleaner, not the expectation — admins will see this HTML again in TinyMCE.

- [ ] **Step 7: Update the spec with the four "Spec deltas" above** (add a "Changes made while planning" section at the end of the spec, copying the four numbered points verbatim).

- [ ] **Step 8: Commit**

```bash
git add includes/home.php tests/Home/HomeFieldsTest.php phpunit.xml .gitignore docs/superpowers/specs/2026-09-21-home-sections-design.md
git commit -m "feat(home): field cleaners for front-page sections (links, images, HTML, video)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Type registry and section validation

**Files:**
- Modify: `includes/home.php` (append)
- Create: `tests/Home/HomeValidateTest.php`

**Interfaces:**
- Consumes: Task 1 cleaners.
- Produces: `home_types(): array` — `type => ['builtin'=>bool,'icon'=>string,'label'=>string,'desc'=>string,'note'=>?string,'fields'=>[key => def]]`; field def keys: `kind` (`text|textarea|alt|html|link|image|choice|video|internal|cards`), `label`, optional `max`, `hint`, `required`, `options`, `default`. `HOME_CARD_FIELDS` (card field defs), `HOME_BACKGROUNDS`, `home_is_builtin(string): bool`, `home_clean_field(array $def, mixed $raw, string $key): array{0:mixed,1:array<string,string>}`, `home_validate_section(string $type, array $in): array{0:array $fields,1:array $errors}`. Error keys: `field.bg`, `field.en`, `field`, `cards`, `cards.<i>.<field>[.bg|.en]`, `_type`.

- [ ] **Step 1: Write the failing tests**

`tests/Home/HomeValidateTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeValidateTest extends TestCase
{
    public function test_registry_has_all_builtins_and_blocks(): void
    {
        $types = home_types();
        foreach (['hero', 'products', 'impact', 'campaign', 'centres', 'mission', 'news', 'partners'] as $t) {
            $this->assertTrue($types[$t]['builtin'], $t);
        }
        foreach (['text_image', 'cta', 'richtext', 'cards', 'video'] as $t) {
            $this->assertFalse($types[$t]['builtin'], $t);
        }
    }

    public function test_every_field_kind_is_known(): void
    {
        $kinds = ['text', 'textarea', 'alt', 'html', 'link', 'image', 'choice', 'video', 'internal', 'cards'];
        foreach (home_types() as $type => $def) {
            foreach ($def['fields'] as $key => $f) {
                $this->assertContains($f['kind'], $kinds, "$type.$key");
                $this->assertNotSame('', $f['label'] ?? '', "$type.$key needs a label");
            }
        }
    }

    public function test_unknown_type_is_an_error(): void
    {
        [, $errors] = home_validate_section('evil', []);
        $this->assertArrayHasKey('_type', $errors);
    }

    public function test_text_is_stripped_and_length_checked(): void
    {
        [$f, $e] = home_validate_section('richtext', ['heading' => ['bg' => '<b>Здравей</b>  свят', 'en' => str_repeat('x', 151)]]);
        $this->assertSame('Здравей свят', $f['heading']['bg']);
        $this->assertArrayHasKey('heading.en', $e);
    }

    public function test_bad_link_is_an_error_and_keeps_what_was_typed(): void
    {
        [$f, $e] = home_validate_section('cta', [
            'heading' => ['bg' => 'Помогнете', 'en' => ''],
            'btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => 'javascript:alert(1)', 'en' => ''],
        ]);
        $this->assertArrayHasKey('btn1_url.bg', $e);
        $this->assertSame('javascript:alert(1)', $f['btn1_url']['bg']);
    }

    public function test_button_label_without_link_is_an_error(): void
    {
        [, $e] = home_validate_section('cta', ['btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => '', 'en' => '']]);
        $this->assertArrayHasKey('btn1_url.bg', $e);
    }

    public function test_english_label_may_reuse_the_bulgarian_link(): void
    {
        [, $e] = home_validate_section('cta', [
            'btn1_label' => ['bg' => 'Дари', 'en' => 'Donate'], 'btn1_url' => ['bg' => '/magazin/', 'en' => ''],
        ]);
        $this->assertArrayNotHasKey('btn1_url.en', $e);
    }

    public function test_rich_text_is_cleaned(): void
    {
        [$f] = home_validate_section('richtext', ['body' => ['bg' => '<p onclick="x">Hi</p>', 'en' => '']]);
        $this->assertSame('<p>Hi</p>', $f['body']['bg']);
    }

    public function test_choice_defaults_and_rejects_tampering(): void
    {
        [$f, $e] = home_validate_section('cta', []);
        $this->assertSame('teal', $f['background']);
        $this->assertSame([], array_intersect_key($e, ['background' => 1]));
        [$f, $e] = home_validate_section('cta', ['background' => 'hotpink']);
        $this->assertSame('teal', $f['background']);
        $this->assertArrayHasKey('background', $e);
    }

    public function test_image_outside_assets_is_an_error(): void
    {
        [, $e] = home_validate_section('text_image', ['image' => '/etc/passwd']);
        $this->assertArrayHasKey('image', $e);
    }

    public function test_video_is_required_and_stored_as_provider_and_id(): void
    {
        [, $e] = home_validate_section('video', []);
        $this->assertArrayHasKey('video', $e);
        [, $e] = home_validate_section('video', ['video' => 'https://example.org/x']);
        $this->assertArrayHasKey('video', $e);
        [$f, $e] = home_validate_section('video', ['video' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->assertSame([], $e);
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], $f['video']);
        $this->assertSame('', $f['thumb']);
    }

    public function test_cards_need_two_to_four_with_titles(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => ''], 'link' => ['bg' => '/za-nas/', 'en' => '']];
        [, $e] = home_validate_section('cards', ['cards' => [$card('A')]]);
        $this->assertArrayHasKey('cards', $e);
        [, $e] = home_validate_section('cards', ['cards' => array_fill(0, 5, $card('A'))]);
        $this->assertArrayHasKey('cards', $e);
        [$f, $e] = home_validate_section('cards', ['cards' => ['7' => $card('A'), '3' => $card('')]]);
        $this->assertArrayHasKey('cards.1.title.bg', $e);
        $this->assertArrayNotHasKey('cards', $e);
        $this->assertSame('A', $f['cards'][0]['title']['bg']);   // re-indexed in submitted order
    }

    public function test_builtin_campaign_has_no_fields(): void
    {
        [$f, $e] = home_validate_section('campaign', ['anything' => 'x']);
        $this->assertSame([], $f);
        $this->assertSame([], $e);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeValidateTest.php`
Expected: FAIL — `home_types()` undefined.

- [ ] **Step 3: Implement** — append to `includes/home.php`:

```php
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
            'note' => 'Кои продукти се показват, избирате в Продукти → „Показвай на началната страница“. Ако няма отбелязани, секцията не се показва.',
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
            'desc' => 'Видео от YouTube или Vimeo. Зарежда се едва когато посетителят натисне „Пусни“.', 'note' => null,
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
```

- [ ] **Step 4: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home`
Expected: PASS (both test files).

- [ ] **Step 5: Commit**

```bash
git add includes/home.php tests/Home/HomeValidateTest.php
git commit -m "feat(home): section type registry and validation

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Seed, load and save `content/home.json`

**Files:**
- Modify: `includes/home.php` (append)
- Create: `tests/Home/HomeStoreTest.php`

**Interfaces:**
- Consumes: `home_types()`, `home_clean_html()`, `home_valid_image_path()`.
- Produces: `home_file(): string`, `home_seed(array $home, array $strings_bg, array $strings_en): array` (doc), `home_seed_from_site(): array`, `home_ensure_builtins(array $doc): array`, `home_load(): array{doc: array, corrupt: bool, exists: bool}`, `home_save(array $doc, int $expected_rev): array{ok: bool, error: ?string ('conflict'|'write'), doc?: array}`, `home_save_error_message(?string): string`. Doc shape: `['version'=>1,'rev'=>int,'sections'=>list<array{id,type,visible,fields}>]`.

- [ ] **Step 1: Write the failing tests**

`tests/Home/HomeStoreTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/home-store-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    private function seed(array $home = []): array
    {
        return home_seed($home, ['home.hero.title' => 'Добре дошли', 'home.shop.title' => 'Продукти'],
                                ['home.hero.title' => 'Welcome', 'home.shop.title' => 'Products']);
    }

    public function test_seed_keeps_todays_order(): void
    {
        $types = array_column($this->seed()['sections'], 'type');
        $this->assertSame(['hero', 'products', 'impact', 'campaign', 'centres', 'mission', 'news', 'partners', 'cta'], $types);
    }

    public function test_seed_prefers_saved_page_values_over_defaults(): void
    {
        $doc  = $this->seed(['hero_title' => 'Нашият дом', 'hero_title_en' => '', 'section_mission' => '', 'mission_title' => 'Мисия X',
                             'mission_text' => '<p onclick="x">Текст</p>', 'mission_image' => '/assets/images/pages/mission.jpg']);
        $hero = $doc['sections'][0]['fields'];
        $this->assertSame('Нашият дом', $hero['title']['bg']);
        $this->assertSame('Welcome', $hero['title']['en']);
        $this->assertSame('/assets/images/hero.webp', $hero['image']);
        $this->assertSame(['bg' => '/kak-da-pomogna/', 'en' => '/en/how-to-help/'], $hero['btn1_url']);
        $mission = $doc['sections'][5]['fields'];
        $this->assertSame('Мисия X', $mission['title']['bg']);
        $this->assertSame('<p>Текст</p>', $mission['text']['bg']);
        $this->assertSame('/assets/images/pages/mission.jpg', $mission['image']);
    }

    public function test_seed_ignores_unsafe_saved_images(): void
    {
        $doc = $this->seed(['hero_image' => 'javascript:x', 'mission_image' => '/etc/passwd']);
        $this->assertSame('/assets/images/hero.webp', $doc['sections'][0]['fields']['image']);
        $this->assertSame('', $doc['sections'][5]['fields']['image']);
    }

    public function test_every_seeded_section_validates_cleanly(): void
    {
        foreach ($this->seed()['sections'] as $s) {
            [, $errors] = home_validate_section($s['type'], $s['fields']);
            $this->assertSame([], $errors, $s['type']);
        }
    }

    public function test_missing_file_loads_the_seed_without_writing(): void
    {
        $r = home_load();
        $this->assertFalse($r['corrupt']);
        $this->assertFalse($r['exists']);
        $this->assertSame(0, $r['doc']['rev']);
        $this->assertFileDoesNotExist($GLOBALS['_om_home_file']);
    }

    public function test_corrupt_file_falls_back_and_says_so(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{not json');
        $r = home_load();
        $this->assertTrue($r['corrupt']);
        $this->assertSame('hero', $r['doc']['sections'][0]['type']);
    }

    public function test_missing_builtin_is_restored_hidden(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => 1, 'rev' => 3, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => []],
        ]]));
        $doc = home_load()['doc'];
        $partners = array_values(array_filter($doc['sections'], fn($s) => $s['type'] === 'partners'))[0];
        $this->assertFalse($partners['visible']);
        $this->assertSame('s_partners', $partners['id']);
        $this->assertSame(3, $doc['rev']);
    }

    public function test_unknown_type_is_preserved_on_save(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => 1, 'rev' => 1, 'sections' => [
            ['id' => 's_future', 'type' => 'from_a_newer_version', 'visible' => true, 'fields' => ['x' => 1]],
        ]]));
        $doc = home_load()['doc'];
        $this->assertTrue(home_save($doc, 1)['ok']);
        $this->assertStringContainsString('from_a_newer_version', file_get_contents($GLOBALS['_om_home_file']));
    }

    public function test_save_bumps_revision_and_refuses_stale_revision(): void
    {
        $doc = home_load()['doc'];
        $r1  = home_save($doc, 0);
        $this->assertTrue($r1['ok']);
        $this->assertSame(1, $r1['doc']['rev']);
        $r2 = home_save($doc, 0);          // someone else saved in between
        $this->assertFalse($r2['ok']);
        $this->assertSame('conflict', $r2['error']);
        $this->assertSame(1, home_load()['doc']['rev']);
    }

    public function test_save_leaves_no_temp_files(): void
    {
        home_save(home_load()['doc'], 0);
        $this->assertSame([], glob($this->dir . '/home.json.tmp-*'));
        $this->assertIsArray(json_decode(file_get_contents($GLOBALS['_om_home_file']), true));
    }

    public function test_corrupt_file_can_be_overwritten_from_revision_zero(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{not json');
        $this->assertTrue(home_save(home_load()['doc'], 0)['ok']);
        $this->assertFalse(home_load()['corrupt']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeStoreTest.php`
Expected: FAIL — `home_seed()` undefined.

- [ ] **Step 3: Implement** — append to `includes/home.php`:

```php
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
            'btn1_label' => $p('hero_cta_primary', 'home.hero.cta_primary'),
            'btn1_url'   => $pair('/kak-da-pomogna/', '/en/how-to-help/'),
            'btn2_label' => $p('hero_cta_secondary', 'home.hero.cta_secondary'),
            'btn2_url'   => $pair('/za-nas/', '/en/about/'),
        ]),
        $sec('products', [
            'heading'    => $p('section_shop', 'home.shop.title'),
            'btn1_label' => $p('shop_btn_all', 'home.shop.all'),
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
            'btn1_label' => $p('news_btn_all', 'home.news.all'),
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

/** A built-in that went missing (hand edit, older file) comes back hidden, so it can always be restored. */
function home_ensure_builtins(array $doc): array {
    $have = array_column($doc['sections'], 'type');
    foreach (home_seed_from_site()['sections'] as $s) {
        if (home_is_builtin($s['type']) && !in_array($s['type'], $have, true)) {
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
        if (file_exists($path)) {
            $cur     = json_decode((string) file_get_contents($path), true);
            $current = is_array($cur) ? (int) ($cur['rev'] ?? 0) : 0;
        }
        if ($current !== $expected_rev) return ['ok' => false, 'error' => 'conflict'];

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

function home_save_error_message(?string $error): string {
    return $error === 'conflict'
        ? 'Междувременно някой друг е променил началната страница. Презаредете страницата и направете промяната отново.'
        : 'Промените не можаха да се запазят. Опитайте отново след малко.';
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home`
Expected: PASS. If `test_every_seeded_section_validates_cleanly` fails because a real `strings.json` default is longer than a `max`, raise that field's `max`, not the test.

- [ ] **Step 5: Commit**

```bash
git add includes/home.php tests/Home/HomeStoreTest.php
git commit -m "feat(home): seed, load and conflict-safe save of the front-page sections

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: List actions (move, hide/show, duplicate, delete, upsert)

**Files:**
- Modify: `includes/home.php` (append)
- Create: `tests/Home/HomeActionsTest.php`

**Interfaces:**
- Consumes: doc shape, `home_types()`, `home_is_builtin()`.
- Produces: `HOME_ACTIONS` (`['move_up','move_down','toggle','duplicate','delete']`), `home_find(array $doc, string $id): ?int`, `home_new_id(): string`, `home_section_name(array $s): string`, `home_section_preview(array $s): string`, `home_apply_action(array $doc, string $action, string $id): array{ok: bool, doc: array, message: string, focus: ?string}`, `home_upsert(array $doc, array $section): array`.

- [ ] **Step 1: Write the failing tests**

`tests/Home/HomeActionsTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeActionsTest extends TestCase
{
    private function doc(): array
    {
        return ['version' => 1, 'rev' => 4, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => ['title' => ['bg' => 'Здравейте', 'en' => '']]],
            ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['heading' => ['bg' => 'Помогнете', 'en' => '']]],
            ['id' => 's_cd34', 'type' => 'video', 'visible' => false, 'fields' => []],
        ]];
    }

    private function ids(array $doc): array { return array_column($doc['sections'], 'id'); }

    public function test_move_up_and_down(): void
    {
        $r = home_apply_action($this->doc(), 'move_up', 's_ab12');
        $this->assertTrue($r['ok']);
        $this->assertSame(['s_ab12', 's_hero', 's_cd34'], $this->ids($r['doc']));
        $this->assertSame('„Помогнете“ е преместена нагоре.', $r['message']);
        $r = home_apply_action($this->doc(), 'move_down', 's_ab12');
        $this->assertSame(['s_hero', 's_cd34', 's_ab12'], $this->ids($r['doc']));
    }

    public function test_cannot_move_past_the_ends(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'move_up', 's_hero')['ok']);
        $this->assertFalse(home_apply_action($this->doc(), 'move_down', 's_cd34')['ok']);
    }

    public function test_toggle_flips_visibility_with_a_clear_message(): void
    {
        $r = home_apply_action($this->doc(), 'toggle', 's_cd34');
        $this->assertTrue($r['doc']['sections'][2]['visible']);
        $this->assertStringContainsString('вече се показва', $r['message']);
        $r = home_apply_action($this->doc(), 'toggle', 's_hero');
        $this->assertFalse($r['doc']['sections'][0]['visible']);
        $this->assertStringContainsString('скрита', $r['message']);
    }

    public function test_duplicate_inserts_a_copy_below_with_a_new_id(): void
    {
        $r = home_apply_action($this->doc(), 'duplicate', 's_ab12');
        $ids = $this->ids($r['doc']);
        $this->assertCount(4, $ids);
        $this->assertSame('s_ab12', $ids[1]);
        $this->assertMatchesRegularExpression('/^s_[a-z0-9_]{1,24}$/', $ids[2]);
        $this->assertNotSame('s_ab12', $ids[2]);
        $this->assertSame($r['doc']['sections'][1]['fields'], $r['doc']['sections'][2]['fields']);
        $this->assertSame($ids[2], $r['focus']);
    }

    public function test_delete_removes_blocks_only(): void
    {
        $r = home_apply_action($this->doc(), 'delete', 's_ab12');
        $this->assertSame(['s_hero', 's_cd34'], $this->ids($r['doc']));
        $this->assertStringContainsString('изтрита', $r['message']);
        $r = home_apply_action($this->doc(), 'delete', 's_hero');
        $this->assertFalse($r['ok']);
        $this->assertCount(3, $r['doc']['sections']);
    }

    public function test_builtins_cannot_be_duplicated(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'duplicate', 's_hero')['ok']);
    }

    public function test_unknown_id_or_action_is_refused(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'toggle', 's_nope')['ok']);
        $this->assertFalse(home_apply_action($this->doc(), 'explode', 's_hero')['ok']);
    }

    public function test_upsert_replaces_or_appends(): void
    {
        $doc = home_upsert($this->doc(), ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['x' => 1]]);
        $this->assertSame(['x' => 1], $doc['sections'][1]['fields']);
        $doc = home_upsert($doc, ['id' => 's_new1', 'type' => 'richtext', 'visible' => true, 'fields' => []]);
        $this->assertSame('s_new1', end($doc['sections'])['id']);
    }

    public function test_name_and_preview_fall_back_to_type_label(): void
    {
        $this->assertSame('Видео', home_section_name(['type' => 'video', 'fields' => []]));
        $this->assertSame('Помогнете', home_section_name($this->doc()['sections'][1]));
        $this->assertSame('YouTube видео', home_section_preview(['type' => 'video', 'fields' => ['video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ']]]));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeActionsTest.php`
Expected: FAIL — `home_apply_action()` undefined.

- [ ] **Step 3: Implement** — append to `includes/home.php`:

```php
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
            if ($i === 0) return $fail("„{$name}“ вече е най-горе.");
            [$list[$i - 1], $list[$i]] = [$list[$i], $list[$i - 1]];
            $msg = "„{$name}“ е преместена нагоре.";
            break;
        case 'move_down':
            if ($i === $last) return $fail("„{$name}“ вече е най-долу.");
            [$list[$i + 1], $list[$i]] = [$list[$i], $list[$i + 1]];
            $msg = "„{$name}“ е преместена надолу.";
            break;
        case 'toggle':
            $list[$i]['visible'] = empty($s['visible']);
            $msg = $list[$i]['visible'] ? "„{$name}“ вече се показва на сайта." : "„{$name}“ е скрита от сайта.";
            break;
        case 'duplicate':
            if (home_is_builtin((string) $s['type'])) return $fail('Тази секция съществува само веднъж и не може да се дублира.');
            $copy = $s;
            $copy['id'] = home_new_id();
            array_splice($list, $i + 1, 0, [$copy]);
            $doc['sections'] = $list;
            return ['ok' => true, 'doc' => $doc, 'message' => "„{$name}“ е дублирана. Копието е точно под нея.", 'focus' => $copy['id']];
        case 'delete':
            if (home_is_builtin((string) $s['type'])) return $fail('Тази секция не може да се изтрие, но можете да я скриете.');
            array_splice($list, $i, 1);
            $doc['sections'] = $list;
            return ['ok' => true, 'doc' => $doc, 'message' => "„{$name}“ е изтрита.", 'focus' => null];
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
```

- [ ] **Step 4: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/home.php tests/Home/HomeActionsTest.php
git commit -m "feat(home): reorder, hide, duplicate and delete front-page sections

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Renderer, section templates, and the two front pages

**Files:**
- Create: `includes/home_render.php`
- Create: `templates/home/{hero,products,impact,campaign,centres,mission,news,partners,text_image,cta,richtext,cards,video}.php`
- Modify: `index.php` (full rewrite), `en/index.php` (full rewrite), `tests/FeatureFlagsTest.php` (gated surfaces list)
- Create: `tests/Home/HomeRenderTest.php`

**Interfaces:**
- Consumes: `home_types()`, video helpers, doc shape.
- Produces: `hf(array $f, string $key, string $lang): string`, `home_cms_attrs(string $sid, string $key, array $f, string $type = 'text'): string`, `home_img_attrs(string $sid, string $key): string`, `home_bg_class(array $f, string $default = 'white'): string`, `home_buttons(string $sid, array $f, string $lang, array $styles, string $group_style = ''): string`, `home_context(array $doc, string $lang): array`, `home_render(array $doc, string $lang): void`. Template variables available in every `templates/home/*.php`: `$s` (section), `$f` (fields), `$sid`, `$lang`, `$ctx`, `$show_admin` (bool).

- [ ] **Step 1: Take "before" screenshots** (for the no-visual-change check in Step 9)

Start the dev server with preview_start (`.claude/launch.json` in the worktree; create an entry `{"name":"php","runtimeExecutable":"php","runtimeArgs":["-S","localhost:8080","-t","."],"port":8080}` if missing). Screenshot `/` and `/en/` at desktop width and 375px width, logged out. Save the four images in the scratchpad.

- [ ] **Step 2: Write the failing tests**

`tests/Home/HomeRenderTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';

final class HomeRenderTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
    }

    private function render(array $sections, string $lang = 'bg'): string
    {
        ob_start();
        home_render(['version' => 1, 'rev' => 1, 'sections' => $sections], $lang);
        return (string) ob_get_clean();
    }

    private function s(string $type, array $fields, bool $visible = true, string $id = ''): array
    {
        return ['id' => $id ?: 's_' . $type, 'type' => $type, 'visible' => $visible, 'fields' => $fields];
    }

    public function test_hf_falls_back_to_bulgarian(): void
    {
        $f = ['t' => ['bg' => 'Здравей', 'en' => ''], 'u' => ['bg' => 'А', 'en' => 'B'], 'img' => '/assets/images/x.jpg'];
        $this->assertSame('Здравей', hf($f, 't', 'en'));
        $this->assertSame('B', hf($f, 'u', 'en'));
        $this->assertSame('/assets/images/x.jpg', hf($f, 'img', 'en'));
        $this->assertSame('', hf($f, 'missing', 'bg'));
    }

    public function test_renders_the_right_language_and_skips_hidden_sections(): void
    {
        $html = $this->render([
            $this->s('cta', ['heading' => ['bg' => 'Помогнете', 'en' => 'Help us'], 'background' => 'teal']),
            $this->s('richtext', ['heading' => ['bg' => 'Скрито', 'en' => 'Hidden']], false),
        ], 'en');
        $this->assertStringContainsString('Help us', $html);
        $this->assertStringNotContainsString('Помогнете', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    public function test_unknown_type_is_skipped(): void
    {
        $this->assertStringNotContainsString('<section', $this->render([$this->s('from_the_future', [])]));
    }

    public function test_text_is_escaped_and_rich_text_is_not(): void
    {
        $html = $this->render([$this->s('richtext', [
            'heading' => ['bg' => '<script>x</script>', 'en' => ''],
            'body'    => ['bg' => '<p><strong>Здравей</strong></p>', 'en' => ''],
        ])]);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<p><strong>Здравей</strong></p>', $html);
    }

    public function test_buttons_need_label_and_link_and_open_external_links_safely(): void
    {
        $html = $this->render([$this->s('cta', [
            'heading'    => ['bg' => 'X', 'en' => ''],
            'btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => 'https://example.org/give', 'en' => ''],
            'btn2_label' => ['bg' => 'Без линк', 'en' => ''], 'btn2_url' => ['bg' => '', 'en' => ''],
        ])]);
        $this->assertStringContainsString('href="https://example.org/give" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('Без линк', $html);
    }

    public function test_video_makes_no_third_party_request_before_click(): void
    {
        $html = $this->render([$this->s('video', [
            'heading' => ['bg' => 'Нашата история', 'en' => ''],
            'video'   => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            'thumb'   => '/assets/images/pages/home/s_video-video-dQw4w9WgXcQ.jpg',
        ])]);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src="https://', $html);
        $this->assertStringNotContainsString('i.ytimg.com', $html);
        $this->assertStringContainsString('<button type="button" class="home-video__play"', $html);
        $this->assertStringContainsString('aria-label="Пусни видео: Нашата история"', $html);
        $this->assertStringContainsString('data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0"', $html);
    }

    public function test_invalid_video_renders_nothing(): void
    {
        $this->assertStringNotContainsString('<section', $this->render([$this->s('video', ['video' => ['provider' => 'youtube', 'id' => 'bad']])]));
    }

    public function test_cards_render_as_a_list_with_headings(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => ''], 'text' => ['bg' => '', 'en' => ''], 'link' => ['bg' => '/za-nas/', 'en' => ''], 'image' => '', 'image_alt' => ['bg' => '', 'en' => '']];
        $html = $this->render([$this->s('cards', ['cards' => [$card('Едно'), $card('Две'), $card('Три')]])]);
        $this->assertStringContainsString('grid-template-columns:repeat(3,minmax(0,1fr))', $html);
        $this->assertSame(3, substr_count($html, '<h3'));
        $this->assertStringContainsString('<a href="/za-nas/">Едно</a>', $html);
    }

    public function test_empty_alt_is_rendered_as_decorative(): void
    {
        $html = $this->render([$this->s('text_image', ['image' => '/assets/images/a.jpg', 'image_alt' => ['bg' => '', 'en' => '']])]);
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_default_page_has_exactly_one_h1_and_no_database_sections(): void
    {
        $doc = home_seed([], [], []);
        foreach ($doc['sections'] as &$s) {
            if (in_array($s['type'], ['products', 'news', 'campaign'], true)) $s['visible'] = false;
        }
        unset($s);
        $html = $this->render($doc['sections']);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_inline_edit_attributes_point_at_the_section(): void
    {
        $html = $this->render([$this->s('cta', ['heading' => ['bg' => 'Помогнете', 'en' => 'Help']], true, 's_ab12')]);
        $this->assertStringContainsString('data-cms-section="home:s_ab12" data-cms-field="heading"', $html);
    }

    public function test_shared_style_is_emitted_once(): void
    {
        $html = $this->render([$this->s('cta', []), $this->s('richtext', [])]);
        $this->assertSame(1, substr_count($html, 'id="home-sections-css"'));
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeRenderTest.php`
Expected: FAIL — `includes/home_render.php` not found.

- [ ] **Step 4: Implement `includes/home_render.php`**

```php
<?php
// includes/home_render.php — draws the front page from content/home.json.
// One template per section type lives in templates/home/<type>.php.

require_once __DIR__ . '/home.php';

/** Field value for a language; an empty English value falls back to Bulgarian. */
function hf(array $f, string $key, string $lang): string {
    $v = $f[$key] ?? '';
    if (!is_array($v)) return is_scalar($v) ? (string) $v : '';
    $out = (string) ($v[$lang] ?? '');
    return $out !== '' ? $out : (string) ($v['bg'] ?? '');
}

/** data-cms-* attributes for on-page editing of one text field. */
function home_cms_attrs(string $sid, string $key, array $f, string $type = 'text'): string {
    $v = home_pair($f[$key] ?? null);
    return ' data-cms-section="home:' . h($sid) . '" data-cms-field="' . h($key) . '" data-cms-type="' . h($type) . '"'
         . ' data-cms-bg="' . h($v['bg']) . '" data-cms-en="' . h($v['en']) . '"';
}

function home_img_attrs(string $sid, string $key): string {
    return ' data-cms-section="home:' . h($sid) . '" data-cms-field="' . h($key) . '"';
}

function home_bg_key(array $f, string $default = 'white'): string {
    $bg = (string) ($f['background'] ?? $default);
    return array_key_exists($bg, HOME_BACKGROUNDS) ? $bg : $default;
}

function home_bg_class(array $f, string $default = 'white'): string {
    return ['white' => '', 'grey' => ' section--grey', 'teal' => ' section--teal', 'warm' => ' section--warm'][home_bg_key($f, $default)];
}

/**
 * Up to two buttons. $styles: [[class, inline style], [class, inline style]].
 * A button renders only with both a label and a link.
 */
function home_buttons(string $sid, array $f, string $lang, array $styles, string $group_style = ''): string {
    $out = '';
    foreach ([1, 2] as $n) {
        if (!isset($f["btn{$n}_label"], $styles[$n - 1])) continue;
        $label = hf($f, "btn{$n}_label", $lang);
        $url   = hf($f, "btn{$n}_url", $lang);
        if ($label === '' || $url === '' || home_clean_link($url) === null) continue;
        [$class, $style] = $styles[$n - 1];
        $ext  = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $out .= '<a href="' . h($url) . '"' . $ext . ' class="' . h($class) . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '')
              . home_cms_attrs($sid, "btn{$n}_label", $f) . '>' . h($label) . '</a>';
    }
    return $out === '' ? '' : '<div class="btn-group"' . ($group_style !== '' ? ' style="' . h($group_style) . '"' : '') . '>' . $out . '</div>';
}

/** Load only the data that visible built-in sections need. */
function home_context(array $doc, string $lang): array {
    $on = [];
    foreach ($doc['sections'] as $s) {
        if (!empty($s['visible'])) $on[$s['type']] = $s;
    }
    $ctx = ['impact' => [], 'centres' => [], 'partners' => [], 'articles' => [],
            'featured_products' => [], 'variant_images' => [], 'variant_stock' => [],
            'campaign' => [], 'campaign_url' => ''];
    if (isset($on['products'])) {
        require_once ROOT_PATH . '/admin/includes/db.php';
        require_once ROOT_PATH . '/includes/products.php';
        $pdo = get_pdo();
        $ctx['featured_products'] = product_featured_list($pdo);
        $support = product_variant_support_data($pdo, $ctx['featured_products']);
        $ctx['variant_images'] = $support['images'];
        $ctx['variant_stock']  = $support['stock'];
    }
    if (isset($on['impact']))   $ctx['impact']   = get_impact();
    if (isset($on['centres']))  $ctx['centres']  = get_centres();
    if (isset($on['partners'])) $ctx['partners'] = get_partners();
    if (isset($on['news'])) {
        $ctx['articles'] = get_articles($lang, ($on['news']['fields']['count'] ?? '3') === '6' ? 6 : 3);
    }
    if (isset($on['campaign']) && feature_enabled('campaign')) {
        $ctx['campaign_url'] = setting_get('campaign_url');
        $ctx['campaign']     = load_json(CONTENT_PATH . '/pages.json')['campaign'] ?? [];
    }
    return $ctx;
}

/** Layout rules shared by the blocks, and the click-to-play script. Printed once. */
function home_shared_head(): string {
    return <<<'HTML'
<style id="home-sections-css">
.home-video__play:focus-visible{outline:4px solid #fff;outline-offset:-8px;box-shadow:inset 0 0 0 8px #000}
.home-rich>*:first-child{margin-top:0}.home-rich>*+*{margin-top:1rem}
@media(max-width:640px){
  .home-ti,.campaign-block-grid{grid-template-columns:1fr!important}
  .home-ti>div{order:0!important}
  .home-cards{grid-template-columns:1fr!important}
}
</style>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('.home-video__play');
  if (!b) return;
  var f = document.createElement('iframe');
  f.src = b.getAttribute('data-embed');
  f.title = b.getAttribute('data-title');
  f.allow = 'autoplay; fullscreen; picture-in-picture';
  f.setAttribute('allowfullscreen', '');
  f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;';
  b.replaceWith(f);
  f.focus();
});
</script>
HTML;
}

function home_render(array $doc, string $lang): void {
    $types      = home_types();
    $ctx        = home_context($doc, $lang);
    $show_admin = (bool) ($GLOBALS['_show_admin_bar'] ?? false);
    echo home_shared_head();
    foreach ($doc['sections'] as $s) {
        if (empty($s['visible']) || !isset($types[$s['type'] ?? ''])) continue;
        home_render_section($s, $lang, $ctx, $show_admin);
    }
}

function home_render_section(array $s, string $lang, array $ctx, bool $show_admin): void {
    $sid = (string) $s['id'];
    $f   = is_array($s['fields'] ?? null) ? $s['fields'] : [];
    require ROOT_PATH . '/templates/home/' . $s['type'] . '.php';
}
```

- [ ] **Step 5: Write the block templates**

`templates/home/cta.php`:
```php
<?php /* CTA banner block. Vars: $s $f $sid $lang $ctx $show_admin */
$bg   = home_bg_key($f, 'teal');
$dark = $bg === 'teal';
$text = hf($f, 'text', $lang);
?>
<section class="section<?= home_bg_class($f, 'teal') ?>">
  <div class="container" style="text-align:center;">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    <?php endif; ?>
    <?php if ($text !== '' || $show_admin): ?>
    <p class="lead" style="<?= $dark ? 'color:rgba(255,255,255,0.85);' : '' ?>margin:1.5rem auto;max-width:600px;"<?= home_cms_attrs($sid, 'text', $f) ?>><?= h($text) ?></p>
    <?php endif; ?>
    <?= home_buttons($sid, $f, $lang, $dark
        ? [['btn btn--white', ''], ['btn btn--outline btn--white-outline', 'border-color:white;color:white;']]
        : [['btn btn--primary', ''], ['btn btn--outline', '']], 'justify-content:center;') ?>
  </div>
</section>
```

`templates/home/richtext.php`:
```php
<?php /* Free rich-text block. Vars: $s $f $sid $lang $ctx $show_admin */ ?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container container--narrow">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <h2 style="margin-bottom:1.5rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    <?php endif; ?>
    <div class="home-rich"<?= home_cms_attrs($sid, 'body', $f, 'richtext') ?>><?= hf($f, 'body', $lang) /* cleaned by home_clean_html() on save */ ?></div>
  </div>
</section>
```

`templates/home/text_image.php`:
```php
<?php /* Text + image block. Vars: $s $f $sid $lang $ctx $show_admin */
$img  = (string) ($f['image'] ?? '');
$img  = home_valid_image_path($img) ? $img : '';
$side = ($f['image_side'] ?? 'left') === 'right' ? 'right' : 'left';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="home-ti" style="display:grid;grid-template-columns:<?= $img !== '' ? '1fr 1fr' : '1fr' ?>;gap:2.5rem;align-items:center;">
      <?php if ($img !== ''): ?>
      <div style="order:<?= $side === 'right' ? 2 : 0 ?>;border-radius:8px;overflow:hidden;">
        <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?>>
          <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" loading="lazy"
               style="width:100%;height:auto;display:block;">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <div style="order:1;">
        <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
        <h2 style="margin-bottom:1.25rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
        <?php endif; ?>
        <div class="home-rich"<?= home_cms_attrs($sid, 'body', $f, 'richtext') ?>><?= hf($f, 'body', $lang) ?></div>
        <?= home_buttons($sid, $f, $lang, [['btn btn--primary', '']], 'margin-top:1.75rem;') ?>
      </div>
    </div>
  </div>
</section>
```

`templates/home/cards.php`:
```php
<?php /* Cards row (2–4). Vars: $s $f $sid $lang $ctx $show_admin */
$cards = array_values(array_filter(is_array($f['cards'] ?? null) ? $f['cards'] : [], 'is_array'));
if (!$cards) return;
$cols = max(1, min(4, count($cards)));
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <?php if (hf($f, 'heading', $lang) !== '' || $show_admin): ?>
    <div class="section-header"><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
    <?php endif; ?>
    <ul class="home-cards" role="list" style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(<?= $cols ?>,minmax(0,1fr));gap:2rem;">
      <?php foreach ($cards as $c):
        $title = hf($c, 'title', $lang);
        $link  = hf($c, 'link', $lang);
        $link  = home_clean_link($link) ? $link : '';
        $img   = home_valid_image_path((string) ($c['image'] ?? '')) ? $c['image'] : '';
        $ext   = preg_match('#^https?://#i', $link) ? ' target="_blank" rel="noopener noreferrer"' : '';
      ?>
      <li class="card" style="display:flex;flex-direction:column;">
        <?php if ($img !== ''): ?>
        <div class="card__image"><img src="<?= asset_url($img) ?>" alt="<?= h(hf($c, 'image_alt', $lang)) ?>" loading="lazy"></div>
        <?php endif; ?>
        <div class="card__body">
          <h3 class="card__title"><?php if ($link !== ''): ?><a href="<?= h($link) ?>"<?= $ext ?>><?= h($title) ?></a><?php else: ?><?= h($title) ?><?php endif; ?></h3>
          <?php if (hf($c, 'text', $lang) !== ''): ?><p class="card__excerpt"><?= h(hf($c, 'text', $lang)) ?></p><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
```

`templates/home/video.php`:
```php
<?php /* Video block — nothing loads from YouTube/Vimeo until the visitor clicks. Vars: $s $f $sid $lang $ctx $show_admin */
$v = $f['video'] ?? null;
if (!is_array($v) || !home_video_valid($v)) return;
$heading  = hf($f, 'heading', $lang);
$caption  = hf($f, 'caption', $lang);
$label    = $heading ?: ($caption ?: ($lang === 'bg' ? 'видео' : 'video'));
$thumb    = home_valid_image_path((string) ($f['thumb'] ?? '')) ? $f['thumb'] : '';
$provider = $v['provider'] === 'youtube' ? 'YouTube' : 'Vimeo';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container container--narrow">
    <?php if ($heading !== '' || $show_admin): ?>
    <h2 style="margin-bottom:1.5rem;"<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h($heading) ?></h2>
    <?php endif; ?>
    <figure style="margin:0;">
      <div style="position:relative;aspect-ratio:16/9;border-radius:8px;overflow:hidden;background:#111;">
        <button type="button" class="home-video__play"
                data-embed="<?= h(home_video_embed_url($v)) ?>"
                data-title="<?= h($label) ?>"
                aria-label="<?= h(($lang === 'bg' ? 'Пусни видео: ' : 'Play video: ') . $label) ?>"
                style="position:absolute;inset:0;width:100%;height:100%;border:0;padding:0;margin:0;cursor:pointer;background:#111;color:#fff;display:flex;align-items:center;justify-content:center;">
          <?php if ($thumb !== ''): ?>
          <img src="<?= asset_url($thumb) ?>" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
          <?php endif; ?>
          <span aria-hidden="true" style="position:relative;display:inline-flex;align-items:center;gap:.6rem;background:rgba(0,0,0,.8);padding:.9rem 1.5rem;border-radius:999px;font-size:1.1rem;font-weight:600;">▶ <?= $lang === 'bg' ? 'Пусни видеото' : 'Play video' ?></span>
        </button>
      </div>
      <?php if ($caption !== '' || $show_admin): ?>
      <figcaption style="margin-top:.75rem;"<?= home_cms_attrs($sid, 'caption', $f) ?>><?= h($caption) ?></figcaption>
      <?php endif; ?>
      <p style="margin:.35rem 0 0;font-size:.85rem;color:var(--text-muted);"><?= h($lang === 'bg' ? "При пускане видеото се зарежда от $provider." : "The video loads from $provider when you play it.") ?></p>
    </figure>
  </div>
</section>
```

- [ ] **Step 6: Write the built-in templates** (markup moved from today's `index.php`; the inline-edit attributes for items — impact/centre/partner/campaign — stay exactly as they are today)

`templates/home/hero.php`:
```php
<?php /* Built-in: hero. Vars: $s $f $sid $lang $ctx $show_admin */
$img = home_valid_image_path((string) ($f['image'] ?? '')) ? $f['image'] : '';
?>
<section class="hero">
  <div class="container">
    <div class="hero__text">
      <span class="section-label"><?= h($lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN) ?></span>
      <h1<?= home_cms_attrs($sid, 'title', $f) ?>><?= h(hf($f, 'title', $lang)) ?></h1>
      <?php if (hf($f, 'text', $lang) !== '' || $show_admin): ?>
      <p<?= home_cms_attrs($sid, 'text', $f) ?>><?= h(hf($f, 'text', $lang)) ?></p>
      <?php endif; ?>
      <?= home_buttons($sid, $f, $lang, [['btn btn--primary', ''], ['btn btn--outline', '']]) ?>
    </div>
    <?php if ($img !== ''): ?>
    <div class="hero__image">
      <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?>>
        <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" width="560" height="420">
        <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</section>
```

`templates/home/products.php`:
```php
<?php /* Built-in: featured products. Vars: $s $f $sid $lang $ctx $show_admin */
if (empty($ctx['featured_products'])) return;
// templates/product-card.php expects these names.
$variant_images  = $ctx['variant_images'];
$variant_stock   = $ctx['variant_stock'];
$_show_admin_bar = $show_admin;
$card_removable  = false;
$card_redirect   = 'home';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
      <div><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
      <?= home_buttons($sid, $f, $lang, [['btn btn--outline', '']]) ?>
    </div>
    <div class="grid grid--3" style="gap:2rem;align-items:stretch;">
      <?php foreach ($ctx['featured_products'] as $p): ?>
        <?php require ROOT_PATH . '/templates/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
```

`templates/home/impact.php`:
```php
<?php /* Built-in: impact numbers (items edited inline, section="impact"). Vars: $s $f $sid $lang $ctx $show_admin */
$impact  = $ctx['impact'];
$admin   = admin_logged_in();
if (empty($impact) && !$admin) return;
$heading = hf($f, 'heading', $lang);
?>
<section class="section section--sm section--teal">
  <div class="container">
    <?php if ($heading !== '' || $admin): ?>
    <div class="section-header section-header--center" style="margin-bottom:1.5rem;">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h($heading) ?></h2>
    </div>
    <?php endif; ?>
    <div class="impact-grid">
      <?php $i = 0; foreach ($impact as $item): ?>
        <div class="impact-item om-removable" data-cms-remove-type="impact" data-cms-remove-id="<?= $i ?>">
          <div class="impact-item__number" data-cms-field="number" data-cms-section="impact" data-cms-type="text"
               data-cms-bg="<?= h($item['number'] ?? '') ?>" data-cms-en="<?= h($item['number'] ?? '') ?>"><?= h($item['number'] ?? '') ?></div>
          <div class="impact-item__label" style="color:rgba(255,255,255,0.8);" data-cms-field="label" data-cms-section="impact" data-cms-type="text"
               data-cms-bg="<?= h($item['label_bg'] ?? '') ?>" data-cms-en="<?= h($item['label_en'] ?? '') ?>"><?= h($lang === 'bg' ? ($item['label_bg'] ?? '') : (($item['label_en'] ?? '') ?: ($item['label_bg'] ?? ''))) ?></div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="impact">+ Add impact number</button><?php endif; ?>
  </div>
</section>
```

`templates/home/campaign.php`:
```php
<?php /* Built-in: campaign (edited at admin/pages.php?page=home_campaign). Vars: $s $f $sid $lang $ctx $show_admin */
if (!feature_enabled('campaign')) return;
$campaign     = $ctx['campaign'];
$campaign_url = $ctx['campaign_url'];
$pick = fn(string $k) => $lang === 'bg' ? (string) ($campaign[$k] ?? '') : ((string) ($campaign[$k . '_en'] ?? '') ?: (string) ($campaign[$k] ?? ''));
$title = $pick('title');
if ($campaign_url === '' || $title === '') return;
$cta = $pick('cta') ?: ($lang === 'bg' ? 'Подкрепи →' : 'Support →');
?>
<section class="section section--warm">
  <div class="container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;align-items:center;" class="campaign-block-grid">
      <?php if (!empty($campaign['image'])): ?>
      <div style="border-radius:8px;overflow:hidden;aspect-ratio:4/3;">
        <span class="om-img-wrap" data-cms-field="image" data-cms-section="campaign">
          <img src="<?= h($campaign['image']) ?>" alt="<?= h($title) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:1.5rem;">
        <span style="font-size:.75rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--amber);"><?= $lang === 'bg' ? 'Кампания' : 'Campaign' ?></span>
        <h2 style="margin:0;" data-cms-field="title" data-cms-section="campaign" data-cms-type="text"
            data-cms-bg="<?= h($campaign['title'] ?? '') ?>" data-cms-en="<?= h($campaign['title_en'] ?? '') ?>"><?= h($title) ?></h2>
        <p style="color:var(--text-muted);line-height:1.7;margin:0;" data-cms-field="text" data-cms-section="campaign" data-cms-type="text"
           data-cms-bg="<?= h($campaign['text'] ?? '') ?>" data-cms-en="<?= h($campaign['text_en'] ?? '') ?>"><?= h($pick('text')) ?></p>
        <div>
          <a href="<?= h($campaign_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn--campaign"
             data-cms-field="cta" data-cms-section="campaign" data-cms-type="text"
             data-cms-bg="<?= h($campaign['cta'] ?? 'Подкрепи →') ?>" data-cms-en="<?= h($campaign['cta_en'] ?? 'Support →') ?>"><?= h($cta) ?></a>
        </div>
      </div>
    </div>
  </div>
</section>
```

`templates/home/centres.php`:
```php
<?php /* Built-in: centres (items edited inline, section="centre"). Vars: $s $f $sid $lang $ctx $show_admin */
$centres = $ctx['centres'];
$admin   = admin_logged_in();
if (empty($centres) && !$admin) return;
$intro = hf($f, 'intro', $lang);
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
      <?php if ($intro !== '' || $show_admin): ?><p class="lead" style="margin-top:1rem;"<?= home_cms_attrs($sid, 'intro', $f) ?>><?= h($intro) ?></p><?php endif; ?>
    </div>
    <div class="grid grid--2">
      <?php $i = 0; foreach ($centres as $centre):
        $cname = $lang === 'bg' ? ($centre['name_bg'] ?? '') : (($centre['name_en'] ?? '') ?: ($centre['name_bg'] ?? ''));
        $cdesc = $lang === 'bg' ? ($centre['description_bg'] ?? '') : (($centre['description_en'] ?? '') ?: ($centre['description_bg'] ?? '')); ?>
        <div class="centre-card om-removable" data-cms-remove-type="centre" data-cms-remove-id="<?= $i ?>">
          <?php if (!empty($centre['image'])): ?>
            <div class="centre-card__image">
              <span class="om-img-wrap" data-cms-field="image_<?= $i ?>" data-cms-section="centre">
                <img src="<?= h($centre['image']) ?>" alt="<?= h($cname) ?>" loading="lazy">
                <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
          <div class="centre-card__body">
            <h3 data-cms-field="name" data-cms-section="centre" data-cms-type="text"
                data-cms-bg="<?= h($centre['name_bg'] ?? '') ?>" data-cms-en="<?= h($centre['name_en'] ?? '') ?>"><?= h($cname) ?></h3>
            <p data-cms-field="description" data-cms-section="centre" data-cms-type="text"
               data-cms-bg="<?= h($centre['description_bg'] ?? '') ?>" data-cms-en="<?= h($centre['description_en'] ?? '') ?>"><?= h($cdesc) ?></p>
          </div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="centre">+ Add centre</button><?php endif; ?>
  </div>
</section>
```

`templates/home/mission.php`:
```php
<?php /* Built-in: mission. Vars: $s $f $sid $lang $ctx $show_admin */
$img = home_valid_image_path((string) ($f['image'] ?? '')) ? $f['image'] : '';
$title_html = '<h2' . home_cms_attrs($sid, 'title', $f) . '>' . h(hf($f, 'title', $lang)) . '</h2>';
$text_html  = '<div class="lead home-rich" style="margin-top:1.5rem;"' . home_cms_attrs($sid, 'text', $f, 'richtext') . '>' . hf($f, 'text', $lang) . '</div>';
$btn_styles = [['btn btn--primary', ''], ['btn btn--outline', '']];
?>
<section class="section<?= home_bg_class($f, 'grey') ?>">
  <?php if ($img !== ''): ?>
  <div class="container">
    <div class="mission-split">
      <div class="mission-split__image">
        <span class="om-img-wrap"<?= home_img_attrs($sid, 'image') ?>>
          <img src="<?= asset_url($img) ?>" alt="<?= h(hf($f, 'image_alt', $lang)) ?>" loading="lazy">
          <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      </div>
      <div class="mission-split__text">
        <?= $title_html ?><?= $text_html ?>
        <?= home_buttons($sid, $f, $lang, $btn_styles, 'margin-top:2rem;') ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="container container--narrow" style="text-align:center;">
    <?= $title_html ?><?= $text_html ?>
    <?= home_buttons($sid, $f, $lang, $btn_styles, 'justify-content:center;margin-top:2rem;') ?>
  </div>
  <?php endif; ?>
</section>
```

`templates/home/news.php`:
```php
<?php /* Built-in: latest news. Vars: $s $f $sid $lang $ctx $show_admin */
$articles = $ctx['articles'];
if (empty($articles)) return;
$base = $lang === 'bg' ? '/novini/' : '/en/news/';
?>
<section class="section<?= home_bg_class($f) ?>">
  <div class="container">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
      <div><h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2></div>
      <?= home_buttons($sid, $f, $lang, [['btn btn--outline', '']]) ?>
    </div>
    <div class="article-grid">
      <?php foreach ($articles as $article): $url = $base . rawurlencode((string) $article['slug']) . '/'; ?>
        <article class="card">
          <?php if (!empty($article['image'])): ?>
            <div class="card__image">
              <a href="<?= h($url) ?>" tabindex="-1" aria-hidden="true">
                <img src="<?= asset_url($article['image']) ?>" alt="" loading="lazy">
              </a>
            </div>
          <?php endif; ?>
          <div class="card__body">
            <div class="article-meta">
              <time datetime="<?= h($article['date'] ?? '') ?>"><?= h(format_date($article['date'] ?? '', $lang)) ?></time>
              <?php if (!empty($article['author'])): ?><span><?= t('news.by') ?> <?= h($article['author']) ?></span><?php endif; ?>
            </div>
            <h3 class="card__title"><a href="<?= h($url) ?>"><?= h($article['title']) ?></a></h3>
            <?php if (!empty($article['excerpt'])): ?><p class="card__excerpt"><?= h($article['excerpt']) ?></p><?php endif; ?>
            <a href="<?= h($url) ?>" class="btn btn--outline" style="margin-top:1rem;font-size:0.8rem;"><?= t('news.read_more') ?><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">: <?= h($article['title']) ?></span></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
```
(Check before committing: if today's slugs contain characters `rawurlencode` changes and the old markup did not encode, compare one real article link before/after; keep whichever the router resolves.)

`templates/home/partners.php`:
```php
<?php /* Built-in: partners (logos edited inline, section="partner"). Vars: $s $f $sid $lang $ctx $show_admin */
$partners = $ctx['partners'];
$admin    = admin_logged_in();
if (empty($partners) && !$admin) return;
?>
<section class="section<?= home_bg_class($f, 'grey') ?>">
  <div class="container">
    <div class="section-header section-header--center">
      <h2<?= home_cms_attrs($sid, 'heading', $f) ?>><?= h(hf($f, 'heading', $lang)) ?></h2>
    </div>
    <div class="partners-grid">
      <?php $i = 0; foreach ($partners as $partner): $tag = !empty($partner['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="partner-item om-removable" data-cms-remove-type="partner" data-cms-remove-id="<?= $i ?>"
          <?php if (!empty($partner['url'])): ?> href="<?= h($partner['url']) ?>" target="_blank" rel="noopener"<?php endif; ?>>
          <span class="om-img-wrap" data-cms-field="logo_<?= $i ?>" data-cms-section="partner">
            <img src="<?= h($partner['logo']) ?>" alt="<?= h($partner['name']) ?>" loading="lazy">
            <?php if ($show_admin): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
          </span>
        </<?= $tag ?>>
      <?php $i++; endforeach; ?>
    </div>
    <?php if ($admin): ?><button class="om-add-btn" data-cms-add="partner">+ Add partner</button><?php endif; ?>
  </div>
</section>
```

- [ ] **Step 7: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home`
Expected: PASS.

- [ ] **Step 8: Rewrite the two front pages and move the campaign flag check**

`index.php` (entire file):
```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';
$lang = get_lang();
$page_title = 'Начало';
// Keyword-rich homepage title (overrides the "Page — Site" template)
$page_title_full  = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;
$page_description = $lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN;

// Sections, their order and their content are edited in admin/home-sections.php.
$home_doc = home_load()['doc'];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
home_render($home_doc, $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
```

`en/index.php` (entire file):
```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_render.php';
$lang             = 'en';
$page_title       = 'Home';
$page_description = SITE_NAME_EN;

// Sections, their order and their content are edited in admin/home-sections.php.
$home_doc = home_load()['doc'];

require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';
home_render($home_doc, $lang);
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
```

In `tests/FeatureFlagsTest.php::gatedSurfaces()` replace `['index.php'], ['en/index.php'],` with `['templates/home/campaign.php'], ['admin/home-sections.php'],` (the admin screen is created in Task 8; that data-set row fails until then — acceptable inside this plan, but do not push between Task 5 and Task 8).

Also make `$_show_admin_bar` reachable from `home_render()`: `templates/header.php:153` assigns it in the including scope, which for `index.php` is global scope, so `$GLOBALS['_show_admin_bar']` works. Verify with `grep -n '_show_admin_bar' templates/header.php` that the assignment is not inside a function; if it is, add `$GLOBALS['_show_admin_bar'] = $_show_admin_bar;` right after it.

- [ ] **Step 9: Verify no visual change**

With the dev server from Step 1 (and no `content/home.json` in the worktree): reload `/` and `/en/`, check `read_console_messages` and `preview_logs` for PHP warnings, then take the same four screenshots and compare with Step 1. Expected differences, and only these: the EN page now shows an impact heading only if one is saved (same rule as BG), the EN mission text now keeps its formatting, and read-more links carry hidden article titles. Anything else is a regression — fix it before committing.

- [ ] **Step 10: Run the suites**

Run: `php vendor/bin/phpunit` then `php vendor/bin/phpunit tests/FeatureFlagsTest.php tests/Shop/FeaturedProductsTest.php`
Expected: all PASS except the `admin/home-sections.php` row of `test_surface_consults_the_flag` (file created in Task 8).

- [ ] **Step 11: Commit**

```bash
git add includes/home_render.php templates/home index.php en/index.php tests/Home/HomeRenderTest.php tests/FeatureFlagsTest.php
git commit -m "feat(home): draw both front pages from the shared section list

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Video thumbnails stored locally

**Files:**
- Modify: `includes/home.php` (append)
- Create: `tests/Home/HomeVideoThumbTest.php`

**Interfaces:**
- Consumes: `home_video_valid()`.
- Produces: `home_http_get(string $url): ?string`, `home_fetch_video_thumb(array $v, string $sid, ?callable $get = null, ?string $root = null): string` (site path or `''`).

- [ ] **Step 1: Check the real endpoints first** (CLAUDE.md external-API rule)

```bash
curl -sI https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg | head -3
curl -s "https://vimeo.com/api/oembed.json?url=https%3A%2F%2Fvimeo.com%2F76979871" | head -c 600
```
Expected: `HTTP/2 200` + `content-type: image/jpeg`; JSON with a `thumbnail_url` starting `https://i.vimeocdn.com/`. If either differs, update the code below to match the real response before writing tests.

- [ ] **Step 2: Write the failing tests**

`tests/Home/HomeVideoThumbTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeVideoThumbTest extends TestCase
{
    private string $root;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/home-thumb-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_youtube_thumbnail_is_saved_locally(): void
    {
        $asked = [];
        $get = function (string $url) use (&$asked) { $asked[] = $url; return base64_decode(self::PNG); };
        $path = home_fetch_video_thumb(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'], 's_ab12', $get, $this->root);
        $this->assertSame('/assets/images/pages/home/s_ab12-video-dQw4w9WgXcQ.png', $path);
        $this->assertFileExists($this->root . $path);
        $this->assertSame(['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'], $asked);
    }

    public function test_vimeo_uses_oembed_and_only_trusts_vimeocdn(): void
    {
        $get = fn(string $url) => str_contains($url, 'oembed')
            ? json_encode(['thumbnail_url' => 'https://i.vimeocdn.com/video/1_640.jpg'])
            : base64_decode(self::PNG);
        $this->assertSame('/assets/images/pages/home/s_ab12-video-76979871.png',
            home_fetch_video_thumb(['provider' => 'vimeo', 'id' => '76979871'], 's_ab12', $get, $this->root));

        $evil = fn(string $url) => str_contains($url, 'oembed')
            ? json_encode(['thumbnail_url' => 'https://evil.example/x.jpg'])
            : base64_decode(self::PNG);
        $this->assertSame('', home_fetch_video_thumb(['provider' => 'vimeo', 'id' => '76979871'], 's_ab12', $evil, $this->root));
    }

    public function test_failures_are_not_errors(): void
    {
        $v = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'];
        $this->assertSame('', home_fetch_video_thumb($v, 's_ab12', fn() => null, $this->root));
        $this->assertSame('', home_fetch_video_thumb($v, 's_ab12', fn() => '<html>not an image</html>', $this->root));
        $this->assertSame('', home_fetch_video_thumb(['provider' => 'youtube', 'id' => '../../x'], 's_ab12', fn() => base64_decode(self::PNG), $this->root));
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeVideoThumbTest.php`
Expected: FAIL — `home_fetch_video_thumb()` undefined.

- [ ] **Step 4: Implement** — append to `includes/home.php`:

```php
// ── Video thumbnails ─────────────────────────────────────────────────────────
// Fetched once, when the admin saves, so visitors' browsers never contact
// YouTube/Vimeo until they press play.

function home_http_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'ngo-cms',
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
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 5_000_000) return '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)] ?? null;
    if ($ext === null) return '';
    $rel = '/assets/images/pages/home/' . $sid . '-video-' . $v['id'] . '.' . $ext;
    $abs = $root . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0755, true)) return '';
    return file_put_contents($abs, $bytes) !== false ? $rel : '';
}
```

- [ ] **Step 5: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/home.php tests/Home/HomeVideoThumbTest.php
git commit -m "feat(home): keep video thumbnails on the site so nothing loads from YouTube before play

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: On-page editing writes to `home.json`

**Files:**
- Modify: `includes/home.php` (append), `admin/inline-save.php` (new branch before the `// Allowed field map` comment)
- Create: `tests/Home/HomeInlineSaveTest.php`

**Interfaces:**
- Consumes: `home_load()`, `home_find()`, `home_clean_field()`, `home_save()`, `home_save_error_message()`.
- Produces: `home_inline_save(string $id, array $fields): array{ok: bool, error?: string}` — `$fields` is the inline editor's `{field: {bg, en}}` map.

- [ ] **Step 1: Write the failing tests**

`tests/Home/HomeInlineSaveTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeInlineSaveTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/home-inline-' . bin2hex(random_bytes(4)) . '.json';
        $GLOBALS['_om_home_file'] = $this->file;
        file_put_contents($this->file, json_encode(['version' => 1, 'rev' => 2, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => ['title' => ['bg' => 'Стар', 'en' => 'Old']]],
            ['id' => 's_ab12', 'type' => 'richtext', 'visible' => true, 'fields' => []],
        ]]));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->file . '*') as $f) unlink($f);
    }

    private function section(string $id): array
    {
        $doc = home_load()['doc'];
        return $doc['sections'][home_find($doc, $id)];
    }

    public function test_saves_text_both_languages(): void
    {
        $r = home_inline_save('s_hero', ['title' => ['bg' => 'Нов <b>дом</b>', 'en' => 'New home']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['bg' => 'Нов дом', 'en' => 'New home'], $this->section('s_hero')['fields']['title']);
        $this->assertSame(3, home_load()['doc']['rev']);
    }

    public function test_rich_text_is_cleaned(): void
    {
        home_inline_save('s_ab12', ['body' => ['bg' => '<p onclick="x">Hi</p>', 'en' => '']]);
        $this->assertSame('<p>Hi</p>', $this->section('s_ab12')['fields']['body']['bg']);
    }

    public function test_image_path_is_validated(): void
    {
        $r = home_inline_save('s_hero', ['image' => ['bg' => '/etc/passwd', 'en' => '/etc/passwd']]);
        $this->assertFalse($r['ok']);
        $r = home_inline_save('s_hero', ['image' => ['bg' => '/assets/images/pages/x.jpg', 'en' => '/assets/images/pages/x.jpg']]);
        $this->assertTrue($r['ok']);
        $this->assertSame('/assets/images/pages/x.jpg', $this->section('s_hero')['fields']['image']);
    }

    public function test_links_and_unknown_fields_cannot_be_set_inline(): void
    {
        home_inline_save('s_hero', ['btn1_url' => ['bg' => 'javascript:x', 'en' => ''], '__proto__' => ['bg' => 'x', 'en' => 'x']]);
        $f = $this->section('s_hero')['fields'];
        $this->assertArrayNotHasKey('__proto__', $f);
        $this->assertNotSame('javascript:x', $f['btn1_url']['bg'] ?? '');
    }

    public function test_unknown_section_is_refused(): void
    {
        $this->assertFalse(home_inline_save('s_nope', ['title' => ['bg' => 'x', 'en' => '']])['ok']);
    }

    public function test_inline_save_endpoint_routes_home_sections(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/inline-save.php');
        $route = strpos($src, "str_starts_with(\$section, 'home:')");
        $this->assertNotFalse($route, 'inline-save.php must route home:<id> sections');
        $this->assertLessThan(strpos($src, '// Allowed field map'), $route);
        $this->assertLessThan($route, strpos($src, "hash_equals(csrf_token()"), 'auth + CSRF checks must run first');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Home/HomeInlineSaveTest.php`
Expected: FAIL — `home_inline_save()` undefined.

- [ ] **Step 3: Implement** — append to `includes/home.php`:

```php
// ── On-page editing ──────────────────────────────────────────────────────────

/** Save fields edited on the live page. Links, choices and cards are admin-form only. */
function home_inline_save(string $id, array $fields): array {
    $doc = home_load()['doc'];
    $i   = home_find($doc, $id);
    if ($i === null) return ['ok' => false, 'error' => 'unknown section'];
    $defs = home_types()[$doc['sections'][$i]['type']]['fields'] ?? [];
    foreach ($fields as $key => $values) {
        $def = $defs[$key] ?? null;
        if ($def === null || !in_array($def['kind'], ['text', 'textarea', 'html', 'alt', 'image'], true) || !is_array($values)) continue;
        $raw = $def['kind'] === 'image' ? (string) ($values['bg'] ?? '') : home_pair($values);
        [$value, $err] = home_clean_field($def, $raw, (string) $key);
        if ($err) return ['ok' => false, 'error' => (string) reset($err)];
        $doc['sections'][$i]['fields'][$key] = $value;
    }
    $saved = home_save($doc, (int) $doc['rev']);
    return $saved['ok'] ? ['ok' => true] : ['ok' => false, 'error' => home_save_error_message($saved['error'])];
}
```

In `admin/inline-save.php`, insert immediately above the line `// Allowed field map: section → [field_name => [bg_key, en_key]]`:
```php
// Front-page sections (content/home.json): section is "home:<section id>".
if (str_starts_with($section, 'home:')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';
    save_json_response(home_inline_save(substr($section, 5), $fields));
}

```

- [ ] **Step 4: Run to verify pass**

Run: `php vendor/bin/phpunit tests/Home && php vendor/bin/phpunit tests/InlineSaveTest.php tests/InlineUploadTest.php`
Expected: PASS. (`InlineSaveTest` needs `content/pages.json`; if it errors on a missing file in the worktree, that failure pre-dates this work — confirm by running it in the main checkout, and note it rather than fix it here.)

- [ ] **Step 5: Manual check in the browser**

Log in (Playwright auth or the dev login), open `/` and `/en/`, edit the hero title inline, save, reload: the change persists and `content/home.json` has `rev` incremented. Replace the hero image inline: the new path is stored under `fields.image`.

- [ ] **Step 6: Commit**

```bash
git add includes/home.php admin/inline-save.php tests/Home/HomeInlineSaveTest.php
git commit -m "feat(home): on-page editing saves front-page sections, now on the EN page too

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Admin screen `admin/home-sections.php`

**Files:**
- Create: `includes/home_admin.php`, `admin/home-sections.php`
- Modify: `includes/home.php` (append upload helpers), `admin/pages.php`
- Create: `tests/Admin/HomeSectionsAdminTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `home_files_entry(array $files, string $name, string|int $key): ?array`, `home_store_upload(array $file, string $sid, string $key): array{path: ?string, error: ?string}`, `home_apply_uploads(array &$in, string $type, array $files, string $sid): array` (errors); `hs_field(...)`, `hs_cards(...)`, `hs_action_form(...)`, `hs_error_summary(...)`, `hs_error_label(...)`, `HS_SR`.

- [ ] **Step 1: Write the failing tests**

`tests/Admin/HomeSectionsAdminTest.php`:
```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

#[Group('admin')]
final class HomeSectionsAdminTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/home-sections.php');
    }

    public function test_requires_an_admin_before_anything_else(): void
    {
        $src   = $this->src();
        $login = strpos($src, 'admin_require_login();');
        $admin = strpos($src, 'admin_require_admin();');
        $this->assertNotFalse($login);
        $this->assertNotFalse($admin);
        foreach (['home_load()', "\$_SERVER['REQUEST_METHOD']", 'echo', '?>'] as $later) {
            $pos = strpos($src, $later);
            if ($pos !== false) $this->assertLessThan($pos, $admin, "admin check must precede $later");
        }
    }

    public function test_post_handler_verifies_csrf_first(): void
    {
        $src  = $this->src();
        $post = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        $csrf = strpos($src, 'csrf_verify()', (int) $post);
        $this->assertNotFalse($csrf);
        $this->assertLessThan(strpos($src, '$_POST[', (int) $post), $csrf);
    }

    public function test_new_sections_cannot_be_builtins_or_unknown_types(): void
    {
        $this->assertStringContainsString('home_is_builtin($type)', $this->src());
        $this->assertStringContainsString('http_response_code(400)', $this->src());
    }

    public function test_english_text_fields_have_a_translate_hook_but_links_do_not(): void
    {
        $types = home_types();
        $html  = hs_field('heading', $types['cta']['fields']['heading'], ['bg' => 'А', 'en' => ''], []);
        $this->assertStringContainsString('name="f[heading][en]"', $html);
        $this->assertStringContainsString('data-translate-from="f[heading][bg]"', $html);
        $html = hs_field('btn1_url', $types['cta']['fields']['btn1_url'], ['bg' => '/', 'en' => ''], []);
        $this->assertStringNotContainsString('data-translate-from', $html);
    }

    public function test_every_input_has_a_label(): void
    {
        foreach (home_types() as $type => $def) {
            foreach ($def['fields'] as $key => $fdef) {
                $html = $fdef['kind'] === 'cards' ? hs_cards([], []) : hs_field($key, $fdef, null, []);
                preg_match_all('/<(?:input|textarea|select)\b(?![^>]*type="hidden")[^>]*\bid="([^"]+)"/', $html, $m);
                foreach ($m[1] as $id) {
                    $this->assertStringContainsString('for="' . $id . '"', $html, "$type.$key: #$id has no label");
                }
            }
        }
    }

    public function test_errors_are_announced_next_to_the_field(): void
    {
        $html = hs_field('heading', home_types()['cta']['fields']['heading'], ['bg' => '', 'en' => ''], ['heading.bg' => 'Твърде дълго.']);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="f_heading_bg_err"', $html);
        $this->assertStringContainsString('id="f_heading_bg_err"', $html);
        $this->assertStringContainsString('⚠', $html);
    }

    public function test_error_summary_links_to_fields_with_their_labels(): void
    {
        $html = hs_error_summary('cards', ['cards.1.title.bg' => 'Това поле е задължително.', '_form' => 'Опитайте пак.']);
        $this->assertStringContainsString('href="#f_cards_1_title_bg"', $html);
        $this->assertStringContainsString('Карта 2 — Заглавие (BG)', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_action_buttons_have_names_and_delete_asks_first(): void
    {
        $s = ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['heading' => ['bg' => 'Помогнете', 'en' => '']]];
        $up = hs_action_form('move_up', $s, 4, '↑ Нагоре', 'Премести „Помогнете“ нагоре', true, 'вече е най-горе');
        $this->assertStringContainsString('aria-label="Премести „Помогнете“ нагоре (вече е най-горе)"', $up);
        $this->assertStringContainsString(' disabled', $up);
        $this->assertStringContainsString('name="rev" value="4"', $up);
        $this->assertStringContainsString('name="csrf_token"', $up);
        $del = hs_action_form('delete', $s, 4, 'Изтрий', 'Изтрий „Помогнете“');
        $this->assertStringContainsString('data-confirm="Да изтрия ли „Помогнете“? Това не може да се върне."', $del);
    }

    public function test_upload_rejects_non_images(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, '<?php echo 1;');
        $r = home_store_upload(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'x.jpg'], 's_ab12', 'image');
        unlink($tmp);
        $this->assertNull($r['path']);
        $this->assertSame('Снимката трябва да е JPEG, PNG или WebP.', $r['error']);
        $this->assertSame(['path' => null, 'error' => null],
            home_store_upload(['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], 's_ab12', 'image'));
    }

    public function test_files_entry_reads_nested_upload_arrays(): void
    {
        $files = ['up_card' => ['tmp_name' => [3 => '/tmp/x'], 'error' => [3 => 0], 'name' => [3 => 'a.jpg']]];
        $this->assertSame(['tmp_name' => '/tmp/x', 'error' => 0, 'name' => 'a.jpg'], home_files_entry($files, 'up_card', 3));
        $this->assertNull(home_files_entry($files, 'up', 'image'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php vendor/bin/phpunit tests/Admin/HomeSectionsAdminTest.php`
Expected: FAIL — `includes/home_admin.php` not found.

- [ ] **Step 3: Add upload helpers** — append to `includes/home.php`:

```php
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
```

- [ ] **Step 4: Create `includes/home_admin.php`**

```php
<?php
// includes/home_admin.php — form pieces for admin/home-sections.php.
// Field ids follow the error keys: error "cards.1.title.bg" ↔ input id "f_cards_1_title_bg".

require_once __DIR__ . '/home.php';

const HS_SR = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';

function hs_badge(string $lang): string {
    return $lang === 'bg'
        ? '<span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>'
        : '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';
}

function hs_error_html(string $id, ?string $msg): string {
    return $msg === null ? '' : '<p id="' . h($id) . '" style="color:#b91c1c;font-weight:600;margin:.35rem 0 0;"><span aria-hidden="true">⚠ </span>' . h($msg) . '</p>';
}

/**
 * One field of a section form.
 * $nb: name base ("f" or "f[cards][2]"), $ib: id base ("f" or "f_cards_2"),
 * $eb: error-key prefix ("" or "cards.2."), $upload: file input name for images.
 */
function hs_field(string $key, array $def, mixed $val, array $errors, string $nb = 'f', string $ib = 'f', string $eb = '', string $upload = ''): string {
    $name    = "{$nb}[{$key}]";
    $id      = "{$ib}_{$key}";
    $ek      = $eb . $key;
    $label   = h($def['label']) . (!empty($def['required']) ? ' <span style="font-weight:400;">(задължително)</span>' : '');
    $hint_id = $id . '_hint';
    $hint    = isset($def['hint']) ? '<p id="' . h($hint_id) . '" style="font-size:.82rem;color:var(--text-muted);margin:.3rem 0 0;">' . h($def['hint']) . '</p>' : '';

    switch ($def['kind']) {
        case 'text': case 'textarea': case 'alt': case 'html': case 'link':
            $pair = home_pair($val);
            $cols = '';
            foreach (['bg', 'en'] as $l) {
                $fid  = "{$id}_{$l}";
                $err  = $errors["{$ek}.{$l}"] ?? null;
                $desc = trim((isset($def['hint']) ? $hint_id : '') . ($err ? " {$fid}_err" : ''));
                $attrs = ' id="' . h($fid) . '" name="' . h("{$name}[{$l}]") . '"'
                       . ($desc !== '' ? ' aria-describedby="' . h($desc) . '"' : '')
                       . ($err ? ' aria-invalid="true"' : '')
                       . (!empty($def['required']) && $l === 'bg' ? ' aria-required="true"' : '')
                       . ($l === 'en' && $def['kind'] !== 'link' ? ' data-translate-from="' . h("{$name}[bg]") . '"' : '');
                $v = h($pair[$l]);
                $control = match ($def['kind']) {
                    'textarea' => "<textarea{$attrs} rows=\"3\">{$v}</textarea>",
                    'html'     => "<textarea{$attrs} rows=\"8\" class=\"hs-rich\">{$v}</textarea>",
                    'link'     => "<input type=\"text\" inputmode=\"url\" autocomplete=\"off\"{$attrs} value=\"{$v}\">",
                    default    => "<input type=\"text\"{$attrs} value=\"{$v}\">",
                };
                $cols .= '<div class="form-group" style="margin:0;"><label for="' . h($fid) . '">' . $label . hs_badge($l) . '</label>'
                       . $control . hs_error_html("{$fid}_err", $err) . '</div>';
            }
            return '<div style="margin-bottom:1.5rem;"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem 1.5rem;">'
                 . $cols . '</div>' . $hint . '</div>';

        case 'image':
            $v   = is_string($val) ? $val : '';
            $err = $errors[$ek] ?? null;
            $up  = $upload !== '' ? $upload : "up[{$key}]";
            $describe = trim((isset($def['hint']) ? $hint_id : '') . ($err ? " {$id}_err" : ''));
            return '<div class="form-group" data-hs-image style="margin-bottom:1.5rem;">'
                 . '<label for="' . h($id) . '">' . $label . '</label>'
                 . '<img src="' . h($v) . '" alt="" data-hs-preview style="max-width:240px;max-height:160px;border-radius:4px;margin-bottom:.5rem;display:' . ($v !== '' ? 'block' : 'none') . ';">'
                 . '<input type="hidden" name="' . h($name) . '" value="' . h($v) . '" data-hs-path>'
                 . '<input type="file" id="' . h($id) . '" name="' . h($up) . '" accept="image/jpeg,image/png,image/webp" data-om-crop'
                 . ($describe !== '' ? ' aria-describedby="' . h($describe) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>'
                 . '<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem;">'
                 . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-pick>Избери от библиотека</button>'
                 . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-clear>Премахни снимката</button>'
                 . '</div>' . $hint . hs_error_html($id . '_err', $err) . '</div>';

        case 'choice':
            $opts = '';
            foreach ($def['options'] as $k => $lbl) {
                $opts .= '<option value="' . h((string) $k) . '"' . ((string) $val === (string) $k ? ' selected' : '') . '>' . h($lbl) . '</option>';
            }
            $err = $errors[$ek] ?? null;
            return '<div class="form-group" style="margin-bottom:1.5rem;"><label for="' . h($id) . '">' . $label . '</label>'
                 . '<select id="' . h($id) . '" name="' . h($name) . '" style="min-height:44px;"' . ($err ? ' aria-invalid="true" aria-describedby="' . h($id) . '_err"' : '') . '>' . $opts . '</select>'
                 . hs_error_html($id . '_err', $err) . '</div>';

        case 'video':
            $url = is_array($val) && home_video_valid($val) ? home_video_watch_url($val) : (is_string($val) ? $val : '');
            $err = $errors[$ek] ?? null;
            return '<div class="form-group" style="margin-bottom:1.5rem;"><label for="' . h($id) . '">' . $label . '</label>'
                 . '<input type="text" inputmode="url" autocomplete="off" id="' . h($id) . '" name="' . h($name) . '" value="' . h($url) . '"'
                 . ' aria-describedby="' . h($id) . '_hint' . ($err ? ' ' . h($id) . '_err' : '') . '"' . ($err ? ' aria-invalid="true"' : '') . ' aria-required="true">'
                 . '<p id="' . h($id) . '_hint" style="font-size:.82rem;color:var(--text-muted);margin:.3rem 0 0;">Отворете видеото в YouTube или Vimeo, копирайте адреса от браузъра и го поставете тук.</p>'
                 . hs_error_html($id . '_err', $err) . '</div>';

        case 'cards':
            return hs_cards(is_array($val) ? $val : [], $errors);
    }
    return '';   // 'internal' fields are not shown
}

function hs_card_row(string $i, array $card, array $errors, int $number): string {
    $out = '<li data-hs-card style="border:1px solid var(--border);border-radius:8px;padding:1rem;">'
         . '<fieldset style="border:0;padding:0;margin:0;min-width:0;"><legend data-hs-card-legend style="font-weight:600;margin-bottom:.75rem;">Карта ' . $number . '</legend>';
    foreach (HOME_CARD_FIELDS as $k => $def) {
        $out .= hs_field($k, $def, $card[$k] ?? null, $errors, "f[cards][$i]", "f_cards_$i", "cards.$i.", "up_card[$i]");
    }
    return $out . '<div style="display:flex;gap:.5rem;flex-wrap:wrap;">'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-card-up>↑ Нагоре</button>'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-card-down>↓ Надолу</button>'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;color:#b91c1c;border-color:#b91c1c;" data-hs-card-remove>Премахни картата</button>'
         . '</div></fieldset></li>';
}

function hs_cards(array $cards, array $errors): string {
    $cards = array_values(array_filter($cards, 'is_array'));
    while (count($cards) < 2) $cards[] = [];
    $err  = $errors['cards'] ?? null;
    $html = '<fieldset id="f_cards" tabindex="-1" style="border:0;padding:0;margin:0 0 1.5rem;min-width:0;"' . ($err ? ' aria-describedby="f_cards_err"' : '') . '>'
          . '<legend style="font-weight:600;font-size:1.05rem;margin-bottom:.5rem;">Карти (от 2 до 4)</legend>'
          . hs_error_html('f_cards_err', $err)
          . '<ol data-hs-cards style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;">';
    foreach ($cards as $i => $card) $html .= hs_card_row((string) $i, $card, $errors, $i + 1);
    return $html . '</ol>'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;margin-top:1rem;" data-hs-card-add>+ Добави карта</button>'
         . '<template id="hsCardTpl">' . hs_card_row('__I__', [], [], 0) . '</template></fieldset>';
}

/** Human label for an error key, e.g. "cards.1.title.bg" → "Карта 2 — Заглавие (BG)". */
function hs_error_label(string $type, string $key): string {
    $parts = explode('.', $key);
    $lang  = in_array(end($parts), ['bg', 'en'], true) && count($parts) > 1 ? strtoupper(array_pop($parts)) : '';
    if ($parts[0] === 'cards' && isset($parts[2])) {
        $label = 'Карта ' . ((int) $parts[1] + 1) . ' — ' . (HOME_CARD_FIELDS[$parts[2]]['label'] ?? '');
    } else {
        $label = home_types()[$type]['fields'][$parts[0]]['label'] ?? '';
    }
    return $label . ($lang !== '' ? " ($lang)" : '');
}

function hs_error_summary(string $type, array $errors): string {
    if (!$errors) return '';
    $items = '';
    foreach ($errors as $k => $msg) {
        $items .= $k === '_form' || $k === '_type'
            ? '<li>' . h($msg) . '</li>'
            : '<li><a href="#f_' . h(str_replace('.', '_', $k)) . '">' . h(hs_error_label($type, $k) . ': ' . $msg) . '</a></li>';
    }
    return '<div id="hsErrSummary" role="alert" tabindex="-1" style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.5rem;">'
         . '<h2 style="font-size:1rem;margin:0 0 .5rem;"><span aria-hidden="true">⚠ </span>Секцията не е запазена. Поправете следното:</h2>'
         . '<ul style="margin:0;padding-left:1.25rem;">' . $items . '</ul></div>';
}

function hs_action_form(string $action, array $s, int $rev, string $label, string $aria, bool $disabled = false, string $why = '', bool $danger = false): string {
    $id      = (string) $s['id'];
    $confirm = $action === 'delete'
        ? ' data-confirm="' . h('Да изтрия ли „' . home_section_name($s) . '“? Това не може да се върне.') . '" data-confirm-ok="Да, изтрий"'
        : '';
    return '<form method="POST" action="/admin/home-sections.php" style="display:inline;margin:0;"' . $confirm . '>'
         . csrf_field()
         . '<input type="hidden" name="action" value="' . h($action) . '">'
         . '<input type="hidden" name="id" value="' . h($id) . '">'
         . '<input type="hidden" name="rev" value="' . $rev . '">'
         . '<button type="submit" id="' . h("btn-$action-$id") . '" class="btn btn--outline"'
         . ' aria-label="' . h($disabled && $why !== '' ? "$aria ($why)" : $aria) . '"' . ($disabled ? ' disabled' : '')
         . ' style="min-height:44px;min-width:44px;' . ($danger ? 'color:#b91c1c;border-color:#b91c1c;' : '') . '">' . h($label) . '</button>'
         . '</form>';
}
```

- [ ] **Step 5: Create `admin/home-sections.php`**

```php
<?php
// admin/home-sections.php — build and edit the front page (content/home.json).
// Spec: docs/superpowers/specs/2026-09-21-home-sections-design.md
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_admin.php';

admin_require_login();
admin_require_admin();

$types  = home_types();
$loaded = home_load();
$doc    = $loaded['doc'];
$rev    = (int) $doc['rev'];
$errors = [];
$form   = null;   // section shown in the edit form: ['id' => '' for new, 'type', 'fields']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Невалидна заявка — презаредете страницата и опитайте отново.'); }
    $action   = (string) ($_POST['action'] ?? '');
    $post_rev = (int) ($_POST['rev'] ?? -1);
    $post_id  = (string) ($_POST['id'] ?? '');

    if (in_array($action, HOME_ACTIONS, true)) {
        $r = home_apply_action($doc, $action, $post_id);
        if ($r['ok']) {
            $saved = home_save($r['doc'], $post_rev);
            if (!$saved['ok']) $r = ['ok' => false, 'message' => home_save_error_message($saved['error']), 'focus' => $post_id];
        }
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
        header('Location: /admin/home-sections.php?focus=' . rawurlencode($action . ':' . ($r['focus'] ?? ''))); exit;
    }
    if ($action !== 'save') { http_response_code(400); exit('Непознато действие.'); }

    $idx = $post_id !== '' ? home_find($doc, $post_id) : null;
    if ($post_id !== '' && $idx === null) {
        flash_set('error', 'Секцията не е намерена — може би е изтрита междувременно.');
        header('Location: /admin/home-sections.php'); exit;
    }
    $type = $idx !== null ? (string) $doc['sections'][$idx]['type'] : (string) ($_POST['type'] ?? '');
    if (!isset($types[$type]) || ($idx === null && home_is_builtin($type))) { http_response_code(400); exit('Непознат вид секция.'); }

    $sid = $idx !== null ? $post_id : home_new_id();
    $old = $idx !== null ? ($doc['sections'][$idx]['fields'] ?? []) : [];
    $in  = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $upload_errors     = home_apply_uploads($in, $type, $_FILES, $sid);
    [$fields, $errors] = home_validate_section($type, $in);
    $errors += $upload_errors;

    if (!$errors && $type === 'video') {
        $fields['thumb'] = (($old['video'] ?? null) === $fields['video'] && !empty($old['thumb']))
            ? $old['thumb'] : home_fetch_video_thumb($fields['video'], $sid);
    }
    if (!$errors) {
        $section = ['id' => $sid, 'type' => $type, 'visible' => $idx !== null ? !empty($doc['sections'][$idx]['visible']) : true, 'fields' => $fields];
        $saved   = home_save(home_upsert($doc, $section), $post_rev);
        if ($saved['ok']) {
            $name = home_section_name($section);
            flash_set('success', $idx !== null ? "„{$name}“ е запазена." : "„{$name}“ е добавена най-долу на страницата и вече се вижда.");
            header('Location: /admin/home-sections.php?focus=' . rawurlencode('edit:' . $sid)); exit;
        }
        $errors['_form'] = home_save_error_message($saved['error']);
    }
    $form = ['id' => $idx !== null ? $post_id : '', 'type' => $type, 'fields' => $fields];
    $rev  = $post_rev;   // keep the revision the admin started from
} elseif (isset($_GET['edit'])) {
    $idx = home_find($doc, (string) $_GET['edit']);
    if ($idx === null || !isset($types[$doc['sections'][$idx]['type']])) {
        flash_set('error', 'Секцията не е намерена.');
        header('Location: /admin/home-sections.php'); exit;
    }
    $form = $doc['sections'][$idx];
} elseif (isset($_GET['add']) && $_GET['add'] !== '') {
    $type = (string) $_GET['add'];
    if (!isset($types[$type]) || home_is_builtin($type)) { header('Location: /admin/home-sections.php?add='); exit; }
    $form = ['id' => '', 'type' => $type, 'fields' => home_validate_section($type, [])[0]];
}
$picker = $form === null && isset($_GET['add']);
$flash  = flash_get();

$active_nav       = 'pages';
$page_title_admin = 'Начална страница';
if ($form !== null) {
    $_tinymce_key    = setting_get('tinymce_api_key', 'no-api-key');
    $page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';
}
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>
<div id="hsLive" role="status" aria-live="polite" style="<?= HS_SR ?>"></div>
<?php foreach ($flash as $msg): $ok = $msg['type'] === 'success'; ?>
  <div <?= $ok ? 'id="hsFlash"' : 'role="alert"' ?> style="border:2px solid <?= $ok ? '#15803d' : '#b91c1c' ?>;background:<?= $ok ? '#f0fdf4' : '#fef2f2' ?>;color:<?= $ok ? '#14532d' : '#7f1d1d' ?>;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;font-weight:600;">
    <span aria-hidden="true"><?= $ok ? '✓' : '⚠' ?> </span><?= h($msg['message']) ?>
  </div>
<?php endforeach; ?>

<?php if ($form !== null): $t = $types[$form['type']]; $is_new = $form['id'] === ''; ?>
  <!-- ══ EDIT / NEW ══ -->
  <a href="/admin/home-sections.php" style="display:inline-block;margin-bottom:.5rem;">← Назад към всички секции</a>
  <h1 style="margin:0 0 1rem;"><span aria-hidden="true"><?= $t['icon'] ?></span> <?= h(($is_new ? 'Нова секция: ' : 'Редактиране: ') . $t['label']) ?></h1>
  <?= hs_error_summary($form['type'], $errors) ?>
  <?php if (!empty($t['note'])): ?><p style="background:#f8fafc;border-left:4px solid var(--border);padding:.75rem 1rem;"><?= h($t['note']) ?></p><?php endif; ?>

  <?php if ($form['type'] === 'campaign'): ?>
    <p>Текстът, снимката и линкът на кампанията се редактират на отделна страница.</p>
    <?php if (feature_enabled('campaign')): ?>
      <p><a class="btn btn--primary" href="/admin/pages.php?page=home_campaign" style="min-height:44px;">Редактирай кампанията</a></p>
    <?php else: ?>
      <p><strong>Модулът „Кампания“ е изключен</strong>, затова тази секция не се показва на сайта, дори да е видима тук.</p>
    <?php endif; ?>
  <?php else: ?>
  <form id="hsForm" method="POST" action="/admin/home-sections.php" enctype="multipart/form-data" class="admin-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="rev" value="<?= (int) $rev ?>">
    <?php if ($is_new): ?><input type="hidden" name="type" value="<?= h($form['type']) ?>">
    <?php else: ?><input type="hidden" name="id" value="<?= h($form['id']) ?>"><?php endif; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
      <button type="submit" class="btn btn--primary" style="min-height:44px;">Запази</button>
      <a href="/admin/home-sections.php" class="btn btn--outline" style="min-height:44px;">Отказ</a>
    </div>
    <?php if ($form['type'] === 'video' && !empty($form['fields']['thumb'])): ?>
      <p style="margin:0 0 .5rem;font-weight:600;">Картинка на видеото сега:</p>
      <img src="<?= h($form['fields']['thumb']) ?>" alt="" style="max-width:240px;border-radius:4px;margin-bottom:1.5rem;display:block;">
    <?php endif; ?>
    <?php foreach ($t['fields'] as $key => $def): ?>
      <?= hs_field($key, $def, $form['fields'][$key] ?? null, $errors) ?>
    <?php endforeach; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary" style="min-height:44px;">Запази</button>
      <a href="/admin/home-sections.php" class="btn btn--outline" style="min-height:44px;">Отказ</a>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($picker): ?>
  <!-- ══ TYPE PICKER ══ -->
  <a href="/admin/home-sections.php" style="display:inline-block;margin-bottom:.5rem;">← Назад към всички секции</a>
  <h1 style="margin:0 0 .5rem;">Добави секция</h1>
  <p style="margin:0 0 1.5rem;">Изберете вид. Новата секция ще се появи най-долу на страницата — после можете да я преместите.</p>
  <ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
    <?php foreach ($types as $key => $t): if ($t['builtin']) continue; ?>
      <li><a href="/admin/home-sections.php?add=<?= h($key) ?>" style="display:block;min-height:44px;padding:1rem 1.25rem;border:2px solid var(--border);border-radius:8px;text-decoration:none;color:inherit;">
        <span aria-hidden="true" style="font-size:1.5rem;"><?= $t['icon'] ?></span>
        <strong style="display:block;font-size:1.05rem;margin:.25rem 0;"><?= h($t['label']) ?></strong>
        <span style="color:var(--text-muted);"><?= h($t['desc']) ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>

<?php else: ?>
  <!-- ══ LIST ══ -->
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
    <h1 id="hsListTitle" tabindex="-1" style="margin:0;">Начална страница</h1>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <a href="/" target="_blank" rel="noopener" class="btn btn--outline" style="min-height:44px;">Виж началната страница<span style="<?= HS_SR ?>"> (отваря се в нов раздел)</span></a>
      <a href="/admin/home-sections.php?add=" class="btn btn--primary" style="min-height:44px;">+ Добави секция</a>
    </div>
  </div>
  <p style="margin:0 0 1.5rem;">Секциите се показват на сайта в този ред, отгоре надолу, на български и на английски.</p>
  <?php if ($loaded['corrupt']): ?>
    <div role="alert" style="border:2px solid #b45309;background:#fffbeb;color:#78350f;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;">
      <strong><span aria-hidden="true">⚠ </span>Файлът с подредбата на началната страница е повреден.</strong>
      Сайтът показва стандартната подредба. Първата промяна, която запазите тук, ще я замени.
    </div>
  <?php endif; ?>
  <ol id="list" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem;">
    <?php $n = count($doc['sections']); foreach ($doc['sections'] as $i => $s):
      $t = $types[$s['type']] ?? null; if ($t === null) continue;
      $name = home_section_name($s); $vis = !empty($s['visible']); ?>
    <li id="row-<?= h($s['id']) ?>" style="border:1px solid var(--border);border-radius:8px;padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;background:<?= $vis ? '#fff' : '#f3f4f6' ?>;">
      <div style="flex:1;min-width:220px;">
        <h2 id="h-<?= h($s['id']) ?>" tabindex="-1" style="font-size:1.05rem;margin:0;"><span aria-hidden="true"><?= $t['icon'] ?> </span><?= h($t['label']) ?></h2>
        <p style="margin:.25rem 0 0;color:var(--text-muted);"><?= h(home_section_preview($s)) ?></p>
        <p style="margin:.35rem 0 0;font-weight:600;color:<?= $vis ? '#166534' : '#4b5563' ?>;"><?= $vis ? '● Видима на сайта' : '○ Скрита' ?></p>
        <?php if ($s['type'] === 'campaign' && !feature_enabled('campaign')): ?>
          <p style="margin:.35rem 0 0;color:#4b5563;">Модулът „Кампания“ е изключен — секцията не се показва.</p>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <?= hs_action_form('move_up', $s, $rev, '↑ Нагоре', "Премести „{$name}“ нагоре", $i === 0, 'вече е най-горе') ?>
        <?= hs_action_form('move_down', $s, $rev, '↓ Надолу', "Премести „{$name}“ надолу", $i === $n - 1, 'вече е най-долу') ?>
        <a id="btn-edit-<?= h($s['id']) ?>" href="/admin/home-sections.php?edit=<?= h(rawurlencode($s['id'])) ?>" class="btn btn--outline" style="min-height:44px;" aria-label="<?= h("Редактирай „{$name}“") ?>">Редактирай</a>
        <?= hs_action_form('toggle', $s, $rev, $vis ? 'Скрий' : 'Покажи', ($vis ? 'Скрий' : 'Покажи') . " „{$name}“") ?>
        <?php if (!$t['builtin']): ?>
          <?= hs_action_form('duplicate', $s, $rev, 'Дублирай', "Дублирай „{$name}“") ?>
          <?= hs_action_form('delete', $s, $rev, 'Изтрий', "Изтрий „{$name}“", false, '', true) ?>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>

<script>
(function () {
  var live = document.getElementById('hsLive');
  function announce(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 100); }

  // After a list action: say what happened, and put focus back where the admin was.
  var flash = document.getElementById('hsFlash');
  if (flash) announce(flash.textContent.trim());
  var q = new URLSearchParams(location.search).get('focus');
  if (q) {
    var p = q.split(':'), el = document.getElementById('btn-' + p[0] + '-' + p[1]);
    if (!el || el.disabled) el = document.getElementById('h-' + p[1]) || document.getElementById('hsListTitle');
    if (el) el.focus();
  }
  var summary = document.getElementById('hsErrSummary');
  if (summary) summary.focus();

  // Images: library picker and "remove".
  document.addEventListener('click', function (e) {
    var box = e.target.closest('[data-hs-image]');
    if (!box) return;
    var path = box.querySelector('[data-hs-path]'), img = box.querySelector('[data-hs-preview]'), file = box.querySelector('input[type=file]');
    if (e.target.closest('[data-hs-pick]')) {
      openMediaPicker(function (p) { path.value = p; img.src = p; img.style.display = 'block'; file.value = ''; announce('Снимката е избрана.'); });
    }
    if (e.target.closest('[data-hs-clear]')) {
      path.value = ''; img.removeAttribute('src'); img.style.display = 'none'; file.value = ''; announce('Снимката е премахната.');
    }
  });

  // Cards: add, remove, reorder (2 to 4).
  var list = document.querySelector('[data-hs-cards]');
  if (list) {
    var tpl = document.getElementById('hsCardTpl'), add = document.querySelector('[data-hs-card-add]'), next = 100;
    function rows() { return list.querySelectorAll('[data-hs-card]'); }
    function renumber() {
      var r = rows();
      r.forEach(function (row, i) {
        var n = i + 1;
        row.querySelector('[data-hs-card-legend]').textContent = 'Карта ' + n;
        var up = row.querySelector('[data-hs-card-up]'), down = row.querySelector('[data-hs-card-down]'), rm = row.querySelector('[data-hs-card-remove]');
        up.setAttribute('aria-label', 'Премести карта ' + n + ' нагоре');
        down.setAttribute('aria-label', 'Премести карта ' + n + ' надолу');
        rm.setAttribute('aria-label', 'Премахни карта ' + n);
        up.disabled = i === 0;
        down.disabled = i === r.length - 1;
        rm.disabled = r.length <= 2;
      });
      add.disabled = r.length >= 4;
    }
    list.addEventListener('click', function (e) {
      var row = e.target.closest('[data-hs-card]');
      if (!row) return;
      if (e.target.closest('[data-hs-card-up]') && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling); renumber();
        (row.querySelector('[data-hs-card-up]:not(:disabled)') || row.querySelector('[data-hs-card-down]')).focus();
        announce('Картата е преместена нагоре.');
      } else if (e.target.closest('[data-hs-card-down]') && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row); renumber();
        (row.querySelector('[data-hs-card-down]:not(:disabled)') || row.querySelector('[data-hs-card-up]')).focus();
        announce('Картата е преместена надолу.');
      } else if (e.target.closest('[data-hs-card-remove]') && rows().length > 2) {
        var to = row.nextElementSibling || row.previousElementSibling;
        row.remove(); renumber();
        to.querySelector('input[type=text]').focus();
        announce('Картата е премахната.');
      }
    });
    add.addEventListener('click', function () {
      if (rows().length >= 4) return;
      list.insertAdjacentHTML('beforeend', tpl.innerHTML.split('__I__').join(String(next++)));
      renumber();
      list.lastElementChild.querySelector('input[type=text]').focus();
      announce('Добавена е нова карта.');
    });
    renumber();
  }

  if (window.tinymce && document.querySelector('textarea.hs-rich')) {
    tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.hs-rich', min_height: 220 }));
  }
})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
```

- [ ] **Step 6: Point `admin/pages.php` at the new screen and keep the campaign form**

Read `admin/pages.php` fully first. Then:
1. Directly after `$page = $_GET['page'] ?? '';` add:
   ```php
   // The front page is built in admin/home-sections.php now.
   if ($page === 'home' && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/home-sections.php'); exit; }
   ```
2. Add `'home_campaign' => 'Начална страница — кампания',` to `$page_labels` and `'home_campaign' => '/',` to `$page_urls`.
3. Delete the `if ($section === 'home') { … }` POST branch (it wrote `pages.json['home']`, which the front page no longer reads). Turn the following `} elseif ($section === 'campaign') {` into `if ($section === 'campaign') {`.
4. In the campaign branch, change all three redirects from `page=home` to `page=home_campaign`.
5. Replace the view branch `<?php elseif ($page === 'home'): ?>` — from its `<!-- ══ HOME ══ -->` header down to (not including) the `<?php if (feature_enabled('campaign')): ?>` that opens the campaign block — with:
   ```php
   <?php elseif ($page === 'home_campaign'): ?>
   <!-- ══ HOME — CAMPAIGN BLOCK ══ -->
   <a href="/admin/home-sections.php" style="color:var(--text-muted);font-size:0.9rem;display:block;margin-bottom:.25rem;">← Назад към началната страница</a>
   <?php if (!feature_enabled('campaign')): ?>
     <p>Модулът „Кампания“ е изключен, затова блокът на кампанията не се показва на сайта.</p>
   <?php endif; ?>
   ```
   Remove the `<hr>` right after that `if`, and change the campaign form's `action` to `/admin/pages.php?page=home_campaign`.
6. Delete the now-unused `function _pickMissionImage(btn) { … }` JS.
7. In the page list rows, change the `home` row's link target to `/admin/home-sections.php` if the row builds its own URL (grep `?page=' . ` near the `$rows` table).
8. `grep -rn "pages.php?page=home\b" --include='*.php' admin includes templates` → must return only `home_campaign` matches.

- [ ] **Step 7: Run the tests**

Run: `php vendor/bin/phpunit && php vendor/bin/phpunit tests/FeatureFlagsTest.php tests/Admin/TranslateButtonCoverageTest.php tests/Security`
Expected: PASS. (`TranslateButtonCoverageTest` sees `admin/home-sections.php` and `admin/pages.php`; any `*_en` input it flags must get a `data-translate-from`.)

- [ ] **Step 8: Verify in the browser, keyboard only**

Log in as admin, open `/admin/home-sections.php` and do all of this with Tab / Enter / Space only:
- Move a section down, then up. Focus lands on the same button, and the green message is visible.
- Hide a section, then show it. Check the status text and the change on `/`.
- Add a text + image block with an image from the library. It appears last and on `/` and `/en/`.
- Add a cards block. Add 2 more cards, move one, remove one, and check the Add button disables at 4. Submit with an empty card title: the error summary gets focus, its link jumps to the field, and everything typed is still there.
- Add a video with a bad link, then with a real one. After saving, the thumbnail shows in the form, `/` has no request to YouTube (check `read_network_requests`), and clicking play loads the player.
- Delete the test blocks through the confirm dialog.
- Open the list in two tabs, move a section in tab 1, then act in tab 2: you get the "someone else changed it" error and nothing is overwritten.
- At 375px width the list, the forms and the front page have no sideways scroll.
- `read_console_messages` shows no errors. Take one screenshot of the list and one of an edit form.

- [ ] **Step 9: Commit**

```bash
git add includes/home.php includes/home_admin.php admin/home-sections.php admin/pages.php tests/Admin/HomeSectionsAdminTest.php
git commit -m "feat(admin): build the front page — reorder, hide, edit, add and delete sections

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: Browser test and final verification

**Files:**
- Create: `tests/Browser/home-sections.spec.js`

- [ ] **Step 1: Write the Playwright test**

```js
// @ts-check
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
const HOME_JSON = path.join(__dirname, '../../content/home.json');
let backup = null;

test.use({ storageState: AUTH_FILE });
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { backup = fs.existsSync(HOME_JSON) ? fs.readFileSync(HOME_JSON) : null; });
test.afterAll(() => {
  if (backup === null) { if (fs.existsSync(HOME_JSON)) fs.unlinkSync(HOME_JSON); }
  else fs.writeFileSync(HOME_JSON, backup);
});

test('add a text + image block, move it up, hide it', async ({ page }) => {
  const stamp = 'PW блок ' + Date.now();

  await page.goto('/admin/home-sections.php');
  await page.getByRole('link', { name: '+ Добави секция' }).click();
  await page.getByRole('link', { name: /Текст и снимка/ }).click();
  await page.fill('#f_heading_bg', stamp);
  await page.fill('#f_heading_en', stamp + ' EN');
  await page.getByRole('button', { name: 'Запази' }).first().click();
  await expect(page.locator('#hsFlash')).toContainText('добавена');

  const rows = page.locator('#list > li');
  const count = await rows.count();
  await expect(rows.nth(count - 1)).toContainText(stamp);

  await rows.nth(count - 1).getByRole('button', { name: /нагоре/ }).click();
  await expect(page.locator('#hsFlash')).toContainText('преместена нагоре');
  await expect(rows.nth(count - 2)).toContainText(stamp);
  await expect(page.locator(':focus')).toHaveAttribute('id', /^btn-move_up-s_/);

  await page.goto('/');
  await expect(page.getByRole('heading', { name: stamp, exact: true })).toBeVisible();
  await page.goto('/en/');
  await expect(page.getByRole('heading', { name: stamp + ' EN' })).toBeVisible();

  await page.goto('/admin/home-sections.php');
  await rows.filter({ hasText: stamp }).getByRole('button', { name: /^Скрий/ }).click();
  await expect(page.locator('#hsFlash')).toContainText('скрита');
  await page.goto('/');
  await expect(page.getByRole('heading', { name: stamp, exact: true })).toHaveCount(0);

  await page.goto('/admin/home-sections.php');
  await rows.filter({ hasText: stamp }).getByRole('button', { name: /^Изтрий/ }).click();
  await page.getByRole('button', { name: 'Да, изтрий' }).click();
  await expect(page.locator('#hsFlash')).toContainText('изтрита');
  await expect(rows.filter({ hasText: stamp })).toHaveCount(0);
});

test('a bad video link keeps the form and explains why', async ({ page }) => {
  await page.goto('/admin/home-sections.php?add=video');
  await page.fill('#f_video', 'https://example.org/not-a-video');
  await page.getByRole('button', { name: 'Запази' }).first().click();
  await expect(page.locator('#hsErrSummary')).toBeFocused();
  await expect(page.locator('#f_video_err')).toContainText('YouTube или Vimeo');
  await expect(page.locator('#f_video')).toHaveValue('https://example.org/not-a-video');
});
```

- [ ] **Step 2: Run it**

Run: `npx playwright test tests/Browser/home-sections.spec.js`
Expected: 2 passed. If the confirm dialog's button text differs, read `showConfirm` in `admin/includes/admin-footer.php` and match it; do not change the dialog.

- [ ] **Step 3: Full verification**

Run each and read the output:
```bash
php vendor/bin/phpunit
php vendor/bin/phpunit tests/FeatureFlagsTest.php tests/InlineSaveTest.php tests/Admin/TranslateButtonCoverageTest.php tests/Security tests/Shop/FeaturedProductsTest.php
npx playwright test
```
Expected: all green, apart from any failures you confirm also happen on `main` without this branch; list those separately in the report.

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/home-sections.spec.js
git commit -m "test(browser): add, move, hide and delete a front-page section

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Hand back** — report the branch `home-sections`, its commits (`git log --oneline main..home-sections`), test results, and screenshots. Do not merge or push; the user decides (CLAUDE.md: commit locally, wait for "push"/"deploy").

---

## Self-review notes

- Spec coverage: data model (T2–T3), built-ins and blocks (T2, T5), validation (T1–T2), seeding and rollback-safe `pages.json` (T3), persistence, conflicts, corrupt file (T3, T8), admin list/picker/edit, focus and announcements (T8), inline editing on both languages (T7), shared rendering and lazy data (T5), video privacy (T5–T6), accessibility (T5, T8, T9), tests (every task), `.gitignore` (T1), no migration.
- Names are used consistently across tasks: `home_load`/`home_save`/`home_find`/`home_upsert`/`home_apply_action`, `hf`, `home_cms_attrs`, `hs_field`, error-key ↔ id rule `f_` + key with `.`→`_`.
