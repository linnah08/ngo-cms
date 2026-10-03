# Post Photo Carousel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A news post can have up to 10 photos, one marked main; the article page shows them as a carousel, and Buffer shares them as an Instagram carousel, a Facebook multi-photo post and a LinkedIn PDF carousel.

**Architecture:** A `photos` list joins the post's JSON (`content/articles/{bg,en}/{slug}.json`); `image` stays and always equals the main photo, so every existing reader of `image` keeps working. Pure helpers in `includes/articles.php` own reading, cleaning and building the list; the editor uploads each photo through the existing `admin/inline-upload.php` and posts only paths; a shared template renders the carousel on both article pages; image preparation for Buffer moves out of the two AJAX files into `includes/social_images.php` so it works on a list.

**Tech Stack:** PHP 8.4 (no framework), JSON flat files, GD, mPDF (already in Composer), vanilla JS, PHPUnit 13, Buffer GraphQL API.

**Spec:** `docs/superpowers/specs/2026-10-03-post-photo-carousel-design.md`

## Global Constraints

- Up to **10** photos per post (`ARTICLE_PHOTOS_MAX = 10`).
- `image` always equals the main photo's `src`, or `""` when there are no photos.
- `src` values and order are identical in the BG and EN files; `caption` is per language.
- A post with `image` and no `photos` is read as `[{src: image, caption: ""}]` — no migration.
- Photo paths must match `#^/assets/images/[a-zA-Z0-9_\-][a-zA-Z0-9/_.\-]*$#`, contain no `..`, and exist on disk.
- Caption fallback alt: BG `"Снимка {n} от {total} — {title}"`, EN `"Photo {n} of {total} — {title}"`.
- Limit message (persistent, not a flash): `"Може да добавите до 10 снимки. Премахнете снимка, за да добавите нова."`
- Instagram carousel slides: centred **1:1** crop. LinkedIn: one square photo per PDF page with the EN caption underneath (BG if EN empty).
- Carousel: no autoplay; no slide animation under `prefers-reduced-motion`; buttons are real `<button>`s with `aria-label`; counter is `aria-live="polite"`; tap targets ≥ 44×44.
- Layout-critical styles inline (CLAUDE.md: main.css / admin.css may be stale-cached).
- Every EN caption input has `data-translate-from` (`tests/Admin/TranslateButtonCoverageTest.php`).
- BG/EN parity: every change to `novini/index.php` has its twin in `en/news/index.php`.
- Buffer returns HTTP 200 for errors — always read the response body.
- Work in the worktree `../oddminds-oss-wt/carousel-spec` (branch `carousel-spec`); commit per task; never push without the user saying "push".
- Run the suite as `php vendor/bin/phpunit > /tmp/pu.txt 2>&1; echo rc=$?; tail -3 /tmp/pu.txt` — never pipe phpunit into `tail` inside an `&&` chain (the exit code gets lost).

## Review Focus

1. **An old post saved without touching the photo grid** — someone fixes a typo in a 2025 post that has only `image`. Expected: after saving, `photos` = `[{src: image, caption: ""}]` and `image` is unchanged. (Test in Task 2.)
2. **A post with no EN version, translated later with the ✦ button on the articles list** (`admin/translate-article-ajax.php`). Expected: the new EN file gets the same `photos` order with translated captions, and `image` matches. (Task 3.)
3. **Captions containing quotes, `<`, `&` or emoji** — they end up in `alt`, visible text and the LinkedIn PDF. Expected: shown literally, never as markup. (Tests in Tasks 5 and 8.)
4. **A photo file deleted from disk** (or the main photo's file). Expected: the carousel skips it and opens on the first remaining photo; Buffer gets only the files that exist; nothing errors. (Tests in Tasks 1, 5 and 6.)
5. **A hostile path in the POST** — `/assets/images/../../config.php`, `/etc/passwd`, `https://evil/x.jpg`. Expected: dropped, never stored. (Test in Task 1.)

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/articles.php` (modify) | `article_photo_path_ok()`, `article_photos()`, `article_main_photo_index()`, `article_photos_from_post()`; builders accept `photos` |
| `admin/article-edit.php` (modify) | Saving from the grid; the photo grid UI |
| `admin/translate-article-ajax.php` (modify) | Copy photos + translate captions into the EN file |
| `admin/inline-save-article.php` (modify) | "📷 Replace" swaps the main photo inside `photos` |
| `templates/admin/article-photo-row.php` (create) | One card of the editor's photo grid |
| `templates/article-carousel.php` (create) | Hero: single image or carousel; shared by both article pages |
| `novini/index.php`, `en/news/index.php` (modify) | Use the template |
| `includes/social_images.php` (create) | `social_image_url()`, `social_prepare_image()`, `social_buffer_assets_gql()` |
| `admin/social-ajax.php` (modify) | FB / IG: all photos; IG square slides when > 1 (Buffer makes the carousel from several assets; `type` stays `post`) |
| `includes/documents/LinkedInCarouselGenerator.php` (create) | The square-page PDF |
| `admin/linkedin-ajax.php` (modify) | LinkedIn: one `document` asset when > 1 photo |
| `tests/Admin/ArticlePhotosTest.php`, `tests/Admin/ArticleCarouselTemplateTest.php`, `tests/SocialImagesTest.php`, `tests/LinkedInCarouselTest.php` (create) | Tests |

---

### Task 0: Verify Buffer accepts what we will send (gate — no code)

Per CLAUDE.md, unfamiliar API behaviour is verified with real requests before production code. **Creating posts in Buffer is outward-facing: ask the user before each request**, and use `saveToDraft: true` so nothing is ever published.

**Files:** none (findings go into the spec's "Must be verified" section).

- [ ] **Step 1: Ask the user** for permission to create draft posts on the Buffer Instagram, Facebook and LinkedIn channels, and which LinkedIn channel (profile or page) the site uses. Get the channel ids from Buffer (`list_channels`) or from the site's settings `buffer_instagram_channel_id`, `buffer_facebook_channel_id` and the LinkedIn equivalent.

- [ ] **Step 2: Instagram carousel draft.** Host three square JPEGs at public URLs on the test site (`https://working-teal-duck.fabulous-emerald-wombat.89-252-247-41.cpanel.site/assets/images/articles/…`), then:

```graphql
mutation { createPost(input: {
  channelId: "<insta id>", schedulingType: automatic, mode: customScheduled,
  dueAt: "2027-01-01T09:00:00.000Z", saveToDraft: true, text: "carousel test",
  assets: [{ image: { url: "<url1>" } }, { image: { url: "<url2>" } }, { image: { url: "<url3>" } }],
  metadata: { instagram: { type: carousel, shouldShareToFeed: true } }
}) { ... on PostActionSuccess { post { id assets { source } } } ... on MutationError { message } } }
```

Expected: `post.id` and three assets. Record any error message verbatim.

- [ ] **Step 3: Facebook multi-photo draft.** Same three URLs, `metadata: { facebook: { type: post } }`. Expected: `post.id`, three assets.

- [ ] **Step 4: LinkedIn document draft.** Upload a 3-page PDF (any) to the test site, then:

```graphql
mutation { createPost(input: {
  channelId: "<linkedin id>", schedulingType: automatic, mode: customScheduled,
  dueAt: "2027-01-01T09:00:00.000Z", saveToDraft: true, text: "document test",
  assets: [{ document: { url: "<pdf url>", title: "Test carousel", thumbnailUrl: "<url1>" } }]
}) { ... on PostActionSuccess { post { id assets { type source } } } ... on MutationError { message } } }
```

Expected: `post.id`, one asset of type `document`.

- [ ] **Step 5: Delete the three drafts** (`deletePost`) — with the user's OK.

- [ ] **Step 6: Record the findings** in the spec, under "Must be verified before any code is written": accepted or not, error texts, max counts if reported. **If LinkedIn refused the document, stop and ask the user** whether LinkedIn falls back to a multi-photo post or the main photo only, then adjust Tasks 8–9 before continuing. Commit the spec change:

```bash
git add docs/superpowers/specs/2026-10-03-post-photo-carousel-design.md
git commit -m "docs(spec): Buffer carousel and document posts verified against the API"
```

---

### Task 1: Read and clean a post's photo list

**Files:**
- Modify: `includes/articles.php` (append after `article_build_en_data()`)
- Test: `tests/Admin/ArticlePhotosTest.php` (create)

**Interfaces:**
- Produces:
  - `const ARTICLE_PHOTOS_MAX = 10;`
  - `article_photo_path_ok(string $src, ?callable $exists = null): bool` — `$exists` defaults to `fn($p) => is_file($_SERVER['DOCUMENT_ROOT'] . $p)`
  - `article_photos(array $article, ?callable $exists = null): array` — list of `['src' => string, 'caption' => string]`, at most 10, only valid existing paths
  - `article_main_photo_index(array $article, array $photos): int` — index in `$photos` of `$article['image']`, else 0

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A post's photos: up to 10, one main. `image` stays the main photo so every page that
 * shows a single picture keeps working; `photos` is the full list for the carousel.
 */
#[Group('admin')]
final class ArticlePhotosTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    private static function all(): callable { return static fn(string $p): bool => true; }

    public function testOldPostWithOnlyAnImageIsOnePhoto(): void
    {
        $photos = article_photos(['image' => '/assets/images/articles/a.jpg'], self::all());
        $this->assertSame([['src' => '/assets/images/articles/a.jpg', 'caption' => '']], $photos);
    }

    public function testPostWithNoImageHasNoPhotos(): void
    {
        $this->assertSame([], article_photos(['image' => ''], self::all()));
        $this->assertSame([], article_photos([], self::all()));
    }

    public function testPhotosKeepOrderAndCaptions(): void
    {
        $a = ['image' => '/assets/images/articles/b.jpg', 'photos' => [
            ['src' => '/assets/images/articles/a.jpg', 'caption' => 'Първа'],
            ['src' => '/assets/images/articles/b.jpg', 'caption' => ''],
        ]];
        $this->assertSame($a['photos'], article_photos($a, self::all()));
    }

    public function testOnlyTheFirstTenAreUsed(): void
    {
        $list = [];
        for ($i = 1; $i <= 12; $i++) $list[] = ['src' => "/assets/images/articles/$i.jpg", 'caption' => ''];
        $this->assertCount(10, article_photos(['photos' => $list], self::all()));
    }

    public function testMissingFilesAreSkipped(): void
    {
        $exists = static fn(string $p): bool => $p !== '/assets/images/articles/gone.jpg';
        $a = ['photos' => [
            ['src' => '/assets/images/articles/gone.jpg', 'caption' => 'x'],
            ['src' => '/assets/images/articles/here.jpg', 'caption' => 'y'],
        ]];
        $this->assertSame([['src' => '/assets/images/articles/here.jpg', 'caption' => 'y']], article_photos($a, $exists));
    }

    /** Review Focus 5: nothing outside /assets/images/ is ever stored or shown. */
    public function testHostilePathsAreRejected(): void
    {
        foreach ([
            '/assets/images/../../config.php',
            '/assets/images/articles/../../../db.config.php',
            '/etc/passwd',
            'https://evil.example/x.jpg',
            '//evil.example/x.jpg',
            '/assets/images/',
            "/assets/images/a.jpg\0.php",
            '',
        ] as $bad) {
            $this->assertFalse(article_photo_path_ok($bad, self::all()), $bad);
        }
        $this->assertTrue(article_photo_path_ok('/assets/images/articles/camp-1.jpg', self::all()));
    }

    public function testMainIndexFollowsImageWhereverItSits(): void
    {
        $photos = [['src' => '/assets/images/articles/a.jpg', 'caption' => ''],
                   ['src' => '/assets/images/articles/b.jpg', 'caption' => '']];
        $this->assertSame(1, article_main_photo_index(['image' => '/assets/images/articles/b.jpg'], $photos));
        $this->assertSame(0, article_main_photo_index(['image' => '/assets/images/articles/zzz.jpg'], $photos));
        $this->assertSame(0, article_main_photo_index([], []));
    }

    public function testNonStringCaptionsBecomeEmpty(): void
    {
        $a = ['photos' => [['src' => '/assets/images/articles/a.jpg', 'caption' => ['x']]]];
        $this->assertSame('', article_photos($a, self::all())[0]['caption']);
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Admin/ArticlePhotosTest.php`
Expected: errors, `Call to undefined function article_photos()`.

- [ ] **Step 3: Implement** (append to `includes/articles.php`)

```php
/** Most photos a post can have — Instagram's carousel limit, so nothing is dropped on the way there. */
const ARTICLE_PHOTOS_MAX = 10;

/**
 * Is $src a site image a post may use? Under /assets/images/, no "..", no scheme,
 * and present on disk ($exists is injectable for tests).
 */
function article_photo_path_ok(string $src, ?callable $exists = null): bool {
    if (!preg_match('#^/assets/images/[a-zA-Z0-9_\-][a-zA-Z0-9/_.\-]*$#', $src)) return false;
    if (str_contains($src, '..')) return false;
    $exists ??= static fn(string $p): bool => is_file($_SERVER['DOCUMENT_ROOT'] . $p);
    return $exists($src);
}

/**
 * The post's photos in display order: [['src' => ..., 'caption' => ...], ...].
 * A post saved before photos existed has only `image` — it reads as one photo.
 * Invalid or missing files are skipped; at most ARTICLE_PHOTOS_MAX are returned.
 */
function article_photos(array $article, ?callable $exists = null): array {
    $raw = $article['photos'] ?? null;
    if (!is_array($raw)) {
        $img = is_string($article['image'] ?? null) ? $article['image'] : '';
        $raw = $img !== '' ? [['src' => $img, 'caption' => '']] : [];
    }
    $out = [];
    foreach ($raw as $p) {
        if (!is_array($p) || !is_string($p['src'] ?? null)) continue;
        if (!article_photo_path_ok($p['src'], $exists)) continue;
        $out[] = ['src' => $p['src'], 'caption' => is_string($p['caption'] ?? null) ? $p['caption'] : ''];
        if (count($out) === ARTICLE_PHOTOS_MAX) break;
    }
    return $out;
}

/** Index in $photos of the post's main photo (its `image`), or 0 when it isn't there. */
function article_main_photo_index(array $article, array $photos): int {
    $main = $article['image'] ?? '';
    foreach ($photos as $i => $p) {
        if ($p['src'] === $main) return $i;
    }
    return 0;
}
```

- [ ] **Step 4: Run the tests**

Run: `php vendor/bin/phpunit tests/Admin/ArticlePhotosTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/articles.php tests/Admin/ArticlePhotosTest.php
git commit -m "feat(articles): a post can list up to 10 photos, read safely"
```

---

### Task 2: Save the photo list from the editor

**Files:**
- Modify: `includes/articles.php` — add `article_photos_from_post()`; `article_build_bg_data()` and `article_build_en_data()` copy `$f['photos']`
- Modify: `admin/article-edit.php:56-96` — replace the single-image block; lines ~111-128 pass `photos`
- Test: `tests/Admin/ArticlePhotosTest.php` (extend), `tests/Admin/ArticleSaveLogicTest.php` (extend)

**Interfaces:**
- Consumes: `article_photo_path_ok()`, `article_photos()` (Task 1)
- Produces: `article_photos_from_post(array $post, ?callable $exists = null): array{bg: array, en: array, image: string}`.
  POST fields: `photo_src[]` (string paths, grid order), `photo_caption_bg[]`, `photo_caption_en[]` (same indexes), `photo_main` (index into `photo_src`), and `photos_present = "1"` (marks that the grid was on the page).
  Return: `bg`/`en` are photo lists with that language's captions; `image` is the main `src` or `''`.
  When `photos_present` is missing the caller keeps the stored list (protects old forms / API callers).

- [ ] **Step 1: Write the failing tests** (add to `ArticlePhotosTest`)

```php
    public function testPostedGridBuildsBothLanguagesAndTheMainImage(): void
    {
        $r = article_photos_from_post([
            'photos_present'   => '1',
            'photo_src'        => ['/assets/images/articles/a.jpg', '/assets/images/articles/b.jpg'],
            'photo_caption_bg' => ['Лагер', ''],
            'photo_caption_en' => ['Camp', ''],
            'photo_main'       => '1',
        ], self::all());

        $this->assertSame('/assets/images/articles/b.jpg', $r['image']);
        $this->assertSame(array_column($r['bg'], 'src'), array_column($r['en'], 'src'));
        $this->assertSame('Лагер', $r['bg'][0]['caption']);
        $this->assertSame('Camp', $r['en'][0]['caption']);
    }

    public function testRemovingTheMainPhotoPromotesTheFirstRemaining(): void
    {
        // The main photo's row was removed in the browser, so photo_main points past the end.
        $r = article_photos_from_post([
            'photos_present' => '1',
            'photo_src'      => ['/assets/images/articles/a.jpg'],
            'photo_main'     => '3',
        ], self::all());
        $this->assertSame('/assets/images/articles/a.jpg', $r['image']);
    }

    public function testEmptyGridClearsTheImage(): void
    {
        $r = article_photos_from_post(['photos_present' => '1'], self::all());
        $this->assertSame(['bg' => [], 'en' => [], 'image' => ''], $r);
    }

    public function testDroppedPathKeepsCaptionsAlignedWithTheirPhotos(): void
    {
        $r = article_photos_from_post([
            'photos_present'   => '1',
            'photo_src'        => ['/etc/passwd', '/assets/images/articles/b.jpg'],
            'photo_caption_bg' => ['лошо', 'добро'],
            'photo_main'       => '1',
        ], self::all());
        $this->assertSame([['src' => '/assets/images/articles/b.jpg', 'caption' => 'добро']], $r['bg']);
        $this->assertSame('/assets/images/articles/b.jpg', $r['image']);
    }

    public function testMoreThanTenPostedPhotosAreCapped(): void
    {
        $src = [];
        for ($i = 1; $i <= 11; $i++) $src[] = "/assets/images/articles/$i.jpg";
        $r = article_photos_from_post(['photos_present' => '1', 'photo_src' => $src], self::all());
        $this->assertCount(10, $r['bg']);
    }

    public function testCaptionsAreTrimmedAndLimited(): void
    {
        $r = article_photos_from_post([
            'photos_present'   => '1',
            'photo_src'        => ['/assets/images/articles/a.jpg'],
            'photo_caption_bg' => ['  ' . str_repeat('я', 400) . '  '],
        ], self::all());
        $this->assertSame(300, mb_strlen($r['bg'][0]['caption']));
    }
```

And in `ArticleSaveLogicTest` (Review Focus 1 — the builders carry the list):

```php
    public function testBuildersCarryThePhotoList(): void
    {
        $photos = [['src' => '/assets/images/articles/a.jpg', 'caption' => 'Лагер']];
        $f = ['title' => 'T', 'slug' => 't', 'slug_en' => 't', 'date' => '2026-10-03', 'author' => '',
              'status' => 'draft', 'excerpt' => '', 'image' => '/assets/images/articles/a.jpg',
              'tags' => [], 'content' => '', 'scheduled' => false, 'photos' => $photos];
        $this->assertSame($photos, article_build_bg_data($f)['photos']);
        $this->assertSame($photos, article_build_en_data($f)['photos']);
    }

    public function testOldPostSavedWithoutTheGridKeepsItsImageAsOnePhoto(): void
    {
        // Review Focus 1: a typo fix on a pre-carousel post must not lose its picture.
        $stored = ['image' => '/assets/images/articles/old.jpg'];
        $photos = article_photos($stored, static fn() => true);
        $this->assertSame([['src' => '/assets/images/articles/old.jpg', 'caption' => '']], $photos);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `php vendor/bin/phpunit tests/Admin/ArticlePhotosTest.php tests/Admin/ArticleSaveLogicTest.php`
Expected: `Call to undefined function article_photos_from_post()`, and `Undefined array key "photos"`.

- [ ] **Step 3: Implement the helper** (append to `includes/articles.php`)

```php
/** Longest caption kept — enough for a sentence, short enough for a slide. */
const ARTICLE_PHOTO_CAPTION_MAX = 300;

/**
 * Rebuild a post's photos from the editor's grid. Paths are re-checked here — the
 * browser only ever sends paths, never trusted ones. A dropped path drops its captions
 * with it, so captions never slide onto the wrong photo.
 *
 * @return array{bg: array, en: array, image: string}
 */
function article_photos_from_post(array $post, ?callable $exists = null): array {
    $src  = is_array($post['photo_src'] ?? null) ? array_values($post['photo_src']) : [];
    $capB = is_array($post['photo_caption_bg'] ?? null) ? array_values($post['photo_caption_bg']) : [];
    $capE = is_array($post['photo_caption_en'] ?? null) ? array_values($post['photo_caption_en']) : [];
    $main = filter_var($post['photo_main'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $clean = static fn($c): string => is_string($c) ? mb_substr(trim($c), 0, ARTICLE_PHOTO_CAPTION_MAX) : '';

    $bg = $en = [];
    $image = '';
    foreach ($src as $i => $s) {
        if (!is_string($s) || !article_photo_path_ok($s, $exists)) continue;
        if (count($bg) === ARTICLE_PHOTOS_MAX) break;
        $bg[] = ['src' => $s, 'caption' => $clean($capB[$i] ?? '')];
        $en[] = ['src' => $s, 'caption' => $clean($capE[$i] ?? '')];
        if ($main === $i) $image = $s;
    }
    if ($image === '' && $bg) $image = $bg[0]['src'];
    return ['bg' => $bg, 'en' => $en, 'image' => $image];
}
```

In `article_build_bg_data()` and `article_build_en_data()` add after `'image' => $f['image'],`:

```php
        'photos'  => $f['photos'] ?? [],
```

- [ ] **Step 4: Wire it into `admin/article-edit.php`.** Keep the existing single-upload branch working for now (the grid replaces the field in Task 4); after the `$image_error` handling (just before `// If editing and BG slug changed`) add:

```php
        // The photo grid (Task 4) posts paths only — each file was already uploaded
        // through /admin/inline-upload.php. Without the grid, keep what is stored.
        if (($_POST['photos_present'] ?? '') === '1') {
            $grid      = article_photos_from_post($_POST);
            $photos_bg = $grid['bg'];
            $photos_en = $grid['en'];
            $image     = $grid['image'];
        } else {
            $photos_bg = article_photos(array_merge($article, ['image' => $image]));
            $photos_en = $article_en ? article_photos(array_merge($article_en, ['image' => $image])) : $photos_bg;
            // A photo replaced through the old single field becomes the main photo.
            if ($image !== '' && !in_array($image, array_column($photos_bg, 'src'), true)) {
                array_unshift($photos_bg, ['src' => $image, 'caption' => '']);
                array_unshift($photos_en, ['src' => $image, 'caption' => '']);
                $photos_bg = array_slice($photos_bg, 0, ARTICLE_PHOTOS_MAX);
                $photos_en = array_slice($photos_en, 0, ARTICLE_PHOTOS_MAX);
            }
            if ($image === '') { $photos_bg = []; $photos_en = []; }
        }
```

Pass `'photos' => $photos_bg` into the `article_build_bg_data([...])` array and `'photos' => $photos_en` into `article_build_en_data([...])`.

- [ ] **Step 5: Run the tests**

Run: `php vendor/bin/phpunit tests/Admin/ArticlePhotosTest.php tests/Admin/ArticleSaveLogicTest.php`
Expected: all pass. Then the full suite: `php vendor/bin/phpunit > /tmp/pu.txt 2>&1; echo rc=$?; tail -3 /tmp/pu.txt` → `rc=0`.

- [ ] **Step 6: Commit**

```bash
git add includes/articles.php admin/article-edit.php tests/Admin/ArticlePhotosTest.php tests/Admin/ArticleSaveLogicTest.php
git commit -m "feat(articles): save a post's photo list to both languages, image = main photo"
```

---

### Task 3: Other code that writes a post keeps the photos in step

Two other places write post files: the ✦ translate-to-English button on the articles list (`admin/translate-article-ajax.php`) and the public page's "📷 Replace" (`admin/inline-save-article.php`, which sets `image` only). Both must keep `photos` consistent with `image`.

**Files:**
- Modify: `admin/translate-article-ajax.php:48-66`
- Modify: `admin/inline-save-article.php:31-35`
- Test: `tests/Admin/ArticlePhotosTest.php` (extend)

**Interfaces:**
- Consumes: `article_photos()`, `article_main_photo_index()`, `article_photo_path_ok()` (Task 1)
- Produces (in `includes/articles.php`):
  - `article_photos_translated(array $photos, callable $translate): array` — same `src`s, each non-empty caption passed through `$translate(string): ?string` (null/'' → keep the BG caption).
  - `article_with_main_photo(array $article, string $new_src, ?callable $exists = null): array` — returns `$article` with `image = $new_src` and, in `photos`, the old main photo's entry replaced by `$new_src` (caption kept); when `$new_src` is `''` the main entry is removed and `image` becomes the next photo or `''`.

- [ ] **Step 1: Write the failing test** (Review Focus 2)

```php
    public function testTranslatedCopyKeepsPhotosAndTranslatesCaptions(): void
    {
        $bg = [['src' => '/assets/images/articles/a.jpg', 'caption' => 'Лагер'],
               ['src' => '/assets/images/articles/b.jpg', 'caption' => '']];
        $calls = 0;
        $en = article_photos_translated($bg, function (string $t) use (&$calls): ?string {
            $calls++;
            return $t === 'Лагер' ? 'Camp' : null;
        });
        $this->assertSame([['src' => '/assets/images/articles/a.jpg', 'caption' => 'Camp'],
                           ['src' => '/assets/images/articles/b.jpg', 'caption' => '']], $en);
        $this->assertSame(1, $calls, 'empty captions are not sent to DeepL');
    }

    public function testFailedCaptionTranslationKeepsTheBulgarian(): void
    {
        $en = article_photos_translated([['src' => '/assets/images/articles/a.jpg', 'caption' => 'Лагер']],
                                        static fn(string $t): ?string => null);
        $this->assertSame('Лагер', $en[0]['caption']);
    }

    public function testReplacingTheMainPhotoInPlaceKeepsTheRest(): void
    {
        $a = ['image' => '/assets/images/articles/b.jpg', 'photos' => [
            ['src' => '/assets/images/articles/a.jpg', 'caption' => 'A'],
            ['src' => '/assets/images/articles/b.jpg', 'caption' => 'Б'],
        ]];
        $r = article_with_main_photo($a, '/assets/images/articles/new.jpg', self::all());
        $this->assertSame('/assets/images/articles/new.jpg', $r['image']);
        $this->assertSame(['/assets/images/articles/a.jpg', '/assets/images/articles/new.jpg'], array_column($r['photos'], 'src'));
        $this->assertSame('Б', $r['photos'][1]['caption']);
    }

    public function testClearingTheMainPhotoPromotesTheNext(): void
    {
        $a = ['image' => '/assets/images/articles/a.jpg', 'photos' => [
            ['src' => '/assets/images/articles/a.jpg', 'caption' => ''],
            ['src' => '/assets/images/articles/b.jpg', 'caption' => ''],
        ]];
        $r = article_with_main_photo($a, '', self::all());
        $this->assertSame('/assets/images/articles/b.jpg', $r['image']);
        $this->assertCount(1, $r['photos']);
    }

    public function testReplacingOnAnOldPostCreatesTheList(): void
    {
        $r = article_with_main_photo(['image' => '/assets/images/articles/old.jpg'], '/assets/images/articles/new.jpg', self::all());
        $this->assertSame([['src' => '/assets/images/articles/new.jpg', 'caption' => '']], $r['photos']);
    }
```

- [ ] **Step 2: Run to see them fail** — `php vendor/bin/phpunit tests/Admin/ArticlePhotosTest.php` → undefined functions.

- [ ] **Step 3: Implement** (append to `includes/articles.php`)

```php
/** The EN copy of a photo list: same photos, captions run through $translate (BG kept on failure). */
function article_photos_translated(array $photos, callable $translate): array {
    $out = [];
    foreach ($photos as $p) {
        $cap = $p['caption'];
        if ($cap !== '') {
            $t = $translate($cap);
            if (is_string($t) && $t !== '') $cap = $t;
        }
        $out[] = ['src' => $p['src'], 'caption' => $cap];
    }
    return $out;
}
```

```php
/**
 * $article with its main photo swapped for $new_src (the public page's "📷 Replace").
 * The replaced photo keeps its place and caption; '' removes it and promotes the next.
 */
function article_with_main_photo(array $article, string $new_src, ?callable $exists = null): array {
    $photos = article_photos($article, $exists);
    $i = article_main_photo_index($article, $photos);
    if ($new_src === '') {
        if ($photos) array_splice($photos, $i, 1);
    } elseif ($photos) {
        $photos[$i]['src'] = $new_src;
    } else {
        $photos = [['src' => $new_src, 'caption' => '']];
    }
    $article['photos'] = $photos;
    $article['image']  = $new_src !== '' ? $new_src : ($photos[0]['src'] ?? '');
    return $article;
}
```

In `admin/inline-save-article.php`, add `require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';` at the top and change the loop body so `image` goes through the helper:

```php
    foreach ($allowed as $f) {
        if (!isset($fields[$f])) continue;
        $val = $fields[$f][$lang] ?? '';
        if ($f === 'image') {
            $val = trim(strip_tags($val));
            if ($val === '' || article_photo_path_ok($val)) $article = article_with_main_photo($article, $val);
            continue;
        }
        $article[$f] = ($f === 'content') ? $val : trim(strip_tags($val));
    }
```

(This also closes a gap: the inline save used to accept any string as `image`.)

In `admin/translate-article-ajax.php`, after the content translation and before `$en_data = [`:

```php
$photos_en = article_photos_translated(article_photos($article), static function (string $t): ?string {
    $e = null;
    $r = deepl_translate($t, 'EN-GB', false, $e);
    return $e ? null : $r;   // a failed caption keeps its Bulgarian text; the post still translates
});
```

and add `'photos' => $photos_en,` after `'image' => ...` in `$en_data`.

- [ ] **Step 4: Run** the filter, then the full suite (`rc=0`).

- [ ] **Step 5: Commit**

```bash
git add includes/articles.php admin/translate-article-ajax.php admin/inline-save-article.php tests/Admin/ArticlePhotosTest.php
git commit -m "feat(articles): translating a post and replacing its picture keep the photo list in step"
```

---

### Task 4: The photo grid in the editor

**Files:**
- Modify: `admin/article-edit.php` — the "Изображение" form group (~line 252-270), the EN section (captions), the JS at the bottom (`_pickArticleImage` ~line 580 and the `imageFile` change handler ~line 593)
- Create: `templates/admin/article-photo-row.php` (one grid card; rendered by PHP for stored photos and cloned by JS from a `<template>`)
- Test: `tests/Admin/ArticlePhotoGridTest.php` (create); `tests/Admin/TranslateButtonCoverageTest.php` must still pass

**Interfaces:**
- Consumes: `article_photos()`, `article_main_photo_index()` (Task 1); POST contract of `article_photos_from_post()` (Task 2); `POST /admin/inline-upload.php` with `image`, `section=article`, `csrf_token` → `{ok: true, path}`; `window.openMediaPicker(cb)`; `OMCrop.open(file) → Promise<File|null>`.
- Produces: form fields `photos_present`, `photo_src[]`, `photo_caption_bg[]`, `photo_caption_en[]`, `photo_main`.

Layout: the BG section holds the grid (thumbnail, ★ Основна, ↑, ↓, Изрежи, Премахни, BG caption). The EN section holds a mirrored list of EN caption inputs, one per photo, in the same order, each labelled "Caption under photo N" — the JS keeps both lists in step when photos are added, removed or moved.

- [ ] **Step 1: Write the failing test** — renders the row template and checks the contract and accessibility:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/** One card of the post editor's photo grid. */
#[Group('admin')]
final class ArticlePhotoGridTest extends TestCase
{
    private function row(array $photo, int $i, int $total, bool $main): string
    {
        ob_start();
        (static function (array $photo, int $i, int $total, bool $main): void {
            require dirname(__DIR__, 2) . '/templates/admin/article-photo-row.php';
        })($photo, $i, $total, $main);
        return (string) ob_get_clean();
    }

    public function testRowPostsItsPathAndCaption(): void
    {
        $html = $this->row(['src' => '/assets/images/articles/a.jpg', 'caption' => 'Лагер'], 0, 2, true);
        $this->assertStringContainsString('name="photo_src[]" value="/assets/images/articles/a.jpg"', $html);
        $this->assertStringContainsString('name="photo_caption_bg[]"', $html);
        $this->assertStringContainsString('value="Лагер"', $html);
    }

    public function testMainButtonIsAPressedToggle(): void
    {
        $main  = $this->row(['src' => '/assets/images/articles/a.jpg', 'caption' => ''], 0, 2, true);
        $other = $this->row(['src' => '/assets/images/articles/b.jpg', 'caption' => ''], 1, 2, false);
        $this->assertStringContainsString('aria-pressed="true"', $main);
        $this->assertStringContainsString('aria-pressed="false"', $other);
    }

    public function testEveryControlIsLabelledAndBigEnough(): void
    {
        $html = $this->row(['src' => '/assets/images/articles/a.jpg', 'caption' => ''], 1, 3, false);
        foreach (['Направи снимка 2 основна', 'Премести снимка 2 нагоре', 'Премести снимка 2 надолу',
                  'Изрежи снимка 2', 'Премахни снимка 2'] as $label) {
            $this->assertStringContainsString('aria-label="' . $label . '"', $html);
        }
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'min-height:44px'));
        $this->assertMatchesRegularExpression('#<label for="photo_caption_bg2_[0-9a-f]{6}">Надпис под снимка 2</label>#u', $html);
    }

    public function testCaptionIsEscaped(): void
    {
        $html = $this->row(['src' => '/assets/images/articles/a.jpg', 'caption' => '"><script>x</script>'], 0, 1, true);
        $this->assertStringNotContainsString('<script>x', $html);
    }

    public function testEditorPostsTheGridMarker(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/article-edit.php');
        $this->assertStringContainsString('name="photos_present" value="1"', $src);
        $this->assertStringContainsString('name="photo_main"', $src);
        $this->assertStringContainsString('name="photo_caption_en[]"', $src);
        $this->assertStringContainsString('data-translate-from="photo_caption_bg', $src);
        $this->assertStringContainsString('Може да добавите до 10 снимки. Премахнете снимка, за да добавите нова.', $src);
        $this->assertStringNotContainsString('window.confirm', $src);
    }
}
```

- [ ] **Step 2: Run to see it fail** — `php vendor/bin/phpunit tests/Admin/ArticlePhotoGridTest.php` → file not found.

- [ ] **Step 3: Create `templates/admin/article-photo-row.php`**

```php
<?php
/**
 * One photo card in the post editor's grid. Rendered by PHP for stored photos and,
 * with placeholder values, inside a <template> that the editor's JS clones for new ones.
 * Vars: $photo ['src','caption'], $i (0-based), $total, $main (bool).
 * Inline styles only — admin.css may be stale-cached.
 */
$n   = $i + 1;
$uid = 'photo_caption_bg' . $n . '_' . substr(md5($photo['src']), 0, 6);  // id prefix = the BG field's name, so the translate coverage test can resolve it
$b   = 'min-height:44px;min-width:44px;';
?>
<li class="ap-photo" data-src="<?= h($photo['src']) ?>"
    style="list-style:none;border:1px solid var(--border);border-radius:8px;padding:.75rem;background:#fff;display:flex;flex-direction:column;gap:.5rem;">
  <input type="hidden" name="photo_src[]" value="<?= h($photo['src']) ?>">
  <img src="<?= h($photo['src']) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;display:block;">
  <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
    <button type="button" class="btn btn--outline ap-main" aria-pressed="<?= $main ? 'true' : 'false' ?>"
            aria-label="Направи снимка <?= $n ?> основна" style="<?= $b ?>"><span aria-hidden="true">★</span> Основна</button>
    <button type="button" class="btn btn--outline ap-up" aria-label="Премести снимка <?= $n ?> нагоре" style="<?= $b ?>">↑</button>
    <button type="button" class="btn btn--outline ap-down" aria-label="Премести снимка <?= $n ?> надолу" style="<?= $b ?>">↓</button>
    <button type="button" class="btn btn--outline ap-crop" aria-label="Изрежи снимка <?= $n ?>" style="<?= $b ?>">Изрежи</button>
    <button type="button" class="btn btn--outline ap-remove" aria-label="Премахни снимка <?= $n ?>"
            style="<?= $b ?>color:#b91c1c;border-color:#b91c1c;">Премахни</button>
  </div>
  <label for="<?= $uid ?>">Надпис под снимка <?= $n ?></label>
  <input type="text" id="<?= $uid ?>" name="photo_caption_bg[]" maxlength="300" value="<?= h($photo['caption']) ?>"
         style="width:100%;box-sizing:border-box;min-height:44px;">
</li>
```

- [ ] **Step 4: Replace the "Изображение" form group in `admin/article-edit.php`** with the grid. Before the form, compute the stored lists:

```php
$grid_bg   = article_photos($article);
$grid_en   = $article_en ? article_photos(array_merge($article_en, ['image' => $article['image'] ?? ''])) : [];
$grid_main = article_main_photo_index($article, $grid_bg);
$en_caps   = array_column($grid_en, 'caption', 'src');
```

The form group:

```php
    <div class="form-group">
      <h3 id="apTitle" style="font-size:1rem;margin:0 0 .25rem;">Снимки</h3>
      <p style="margin:0 0 .75rem;color:var(--text-muted);">До 10 снимки. Основната се показва в списъка с новини и при споделяне.</p>
      <input type="hidden" name="photos_present" value="1">
      <input type="hidden" name="photo_main" id="apMain" value="<?= (int) $grid_main ?>">
      <ul id="apGrid" aria-labelledby="apTitle"
          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;padding:0;margin:0 0 .75rem;">
        <?php foreach ($grid_bg as $i => $photo): $total = count($grid_bg); $main = $i === $grid_main; ?>
          <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/admin/article-photo-row.php'; ?>
        <?php endforeach; ?>
      </ul>
      <template id="apRowTpl"><?php
        $photo = ['src' => '__SRC__', 'caption' => '']; $i = 0; $total = 1; $main = false;
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/admin/article-photo-row.php'; ?></template>
      <p id="apLimit" role="alert" hidden
         style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:.75rem 1rem;font-weight:600;">
        Може да добавите до 10 снимки. Премахнете снимка, за да добавите нова.</p>
      <p id="apError" role="alert" hidden
         style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:.75rem 1rem;font-weight:600;"></p>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <label class="btn btn--primary" for="apFiles" style="min-height:44px;cursor:pointer;">+ Добави снимки</label>
        <input type="file" id="apFiles" accept="image/jpeg,image/png,image/webp" multiple
               style="position:absolute;width:1px;height:1px;opacity:0;">
        <button type="button" class="btn btn--outline" id="apLibrary" style="min-height:44px;">Избери от библиотека</button>
      </div>
      <div id="apLive" role="status" aria-live="polite" style="position:absolute;left:-9999px;"></div>
    </div>
```

Remove the old `remove_image`, `imageFile`, `image_from_library` and `imagePreview` elements and their JS; Task 2's server code then always takes the `photos_present` branch from this page (the `else` branch stays for posts saved by other code paths).

- [ ] **Step 5: Add the EN captions** to the EN section of the form (after the EN excerpt):

```php
    <div class="form-group">
      <h3 style="font-size:1rem;margin:0 0 .5rem;">Photo captions (EN)</h3>
      <ol id="apEnCaps" style="padding-left:1.25rem;margin:0;">
        <?php foreach ($grid_bg as $i => $photo): $n = $i + 1; ?>
        <li style="margin-bottom:.5rem;">
          <label for="photoCapEn<?= $n ?>">Caption under photo <?= $n ?></label>
          <input type="text" id="photoCapEn<?= $n ?>" name="photo_caption_en[]" maxlength="300"
                 data-translate-from="photo_caption_bg<?= $n ?>_<?= substr(md5($photo['src']), 0, 6) ?>"
                 value="<?= h($en_caps[$photo['src']] ?? '') ?>" style="width:100%;box-sizing:border-box;min-height:44px;">
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
```

- [ ] **Step 6: The grid script** — add at the bottom of the page's existing `<script>` block (replacing `_pickArticleImage` and the `imageFile` change handler):

```js
// ── Photo grid ───────────────────────────────────────────────────────────────
(function () {
  var MAX = 10;
  var grid = document.getElementById('apGrid'), tpl = document.getElementById('apRowTpl');
  var enList = document.getElementById('apEnCaps'), mainInput = document.getElementById('apMain');
  var live = document.getElementById('apLive'), limit = document.getElementById('apLimit'), err = document.getElementById('apError');
  var csrf = document.querySelector('input[name="csrf_token"]').value;

  function rows() { return Array.prototype.slice.call(grid.querySelectorAll('.ap-photo')); }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 50); }
  function showError(msg) { err.textContent = msg; err.hidden = !msg; }

  // Renumber labels, keep EN captions in the same order, and record the main photo's index.
  function sync() {
    var rs = rows(), mainIdx = 0, oldEn = {};
    enList.querySelectorAll('li').forEach(function (li) { oldEn[li.dataset.src] = li.querySelector('input').value; });
    enList.innerHTML = '';
    rs.forEach(function (r, i) {
      var n = i + 1, id = 'photo_caption_bg' + n + '_' + Math.random().toString(16).slice(2, 8);
      var cap = r.querySelector('input[name="photo_caption_bg[]"]'), lab = r.querySelector('label');
      cap.id = id; lab.htmlFor = id; lab.textContent = 'Надпис под снимка ' + n;
      r.querySelector('.ap-main').setAttribute('aria-label', 'Направи снимка ' + n + ' основна');
      r.querySelector('.ap-up').setAttribute('aria-label', 'Премести снимка ' + n + ' нагоре');
      r.querySelector('.ap-down').setAttribute('aria-label', 'Премести снимка ' + n + ' надолу');
      r.querySelector('.ap-crop').setAttribute('aria-label', 'Изрежи снимка ' + n);
      r.querySelector('.ap-remove').setAttribute('aria-label', 'Премахни снимка ' + n);
      if (r.querySelector('.ap-main').getAttribute('aria-pressed') === 'true') mainIdx = i;
      var li = document.createElement('li');
      li.dataset.src = r.dataset.src; li.style.marginBottom = '.5rem';
      li.innerHTML = '<label for="photoCapEn' + n + '">Caption under photo ' + n + '</label>'
        + '<input type="text" id="photoCapEn' + n + '" name="photo_caption_en[]" maxlength="300"'
        + ' data-translate-from="' + id + '" style="width:100%;box-sizing:border-box;min-height:44px;">';
      li.querySelector('input').value = oldEn[r.dataset.src] || '';
      enList.appendChild(li);
    });
    if (rs.length && !rs.some(function (r) { return r.querySelector('.ap-main').getAttribute('aria-pressed') === 'true'; })) {
      rs[0].querySelector('.ap-main').setAttribute('aria-pressed', 'true');
      mainIdx = 0;
    }
    mainInput.value = mainIdx;
    limit.hidden = rs.length < MAX;
    if (window.omInitTranslateButtons) window.omInitTranslateButtons(enList);
  }

  function addRow(path) {
    if (rows().length >= MAX) { limit.hidden = false; return false; }
    var holder = document.createElement('div');
    holder.innerHTML = tpl.innerHTML.split('__SRC__').join(path);
    var row = holder.querySelector('.ap-photo');
    row.querySelector('.ap-main').setAttribute('aria-pressed', 'false');
    grid.appendChild(row);
    return true;
  }

  function upload(file) {
    var fd = new FormData();
    fd.append('image', file); fd.append('section', 'article'); fd.append('csrf_token', csrf);
    return fetch('/admin/inline-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.error || 'upload'); return d.path; });
  }

  document.getElementById('apFiles').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files), room = MAX - rows().length;
    this.value = '';
    if (files.length > room) limit.hidden = false;
    files = files.slice(0, Math.max(0, room));
    showError('');
    var failed = 0;
    files.reduce(function (p, f) {
      return p.then(function () {
        return upload(f).then(function (path) { addRow(path); }, function () { failed++; });
      });
    }, Promise.resolve()).then(function () {
      sync();
      say(files.length - failed + ' снимки са добавени');
      if (failed) showError(failed + ' снимки не можаха да се качат. Позволени са JPEG, PNG и WebP.');
    });
  });

  document.getElementById('apLibrary').addEventListener('click', function () {
    if (rows().length >= MAX) { limit.hidden = false; return; }
    openMediaPicker(function (p) { if (addRow(p)) { sync(); say('Снимката е добавена'); } });
  });

  grid.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var row = b.closest('.ap-photo'), rs = rows(), i = rs.indexOf(row);
    if (b.classList.contains('ap-main')) {
      rs.forEach(function (r) { r.querySelector('.ap-main').setAttribute('aria-pressed', r === row ? 'true' : 'false'); });
      sync(); say('Снимка ' + (i + 1) + ' е основна');
    } else if (b.classList.contains('ap-up') && i > 0) {
      grid.insertBefore(row, rs[i - 1]); sync(); b.focus(); say('Снимка е преместена на място ' + i);
    } else if (b.classList.contains('ap-down') && i < rs.length - 1) {
      grid.insertBefore(rs[i + 1], row); sync(); b.focus(); say('Снимка е преместена на място ' + (i + 2));
    } else if (b.classList.contains('ap-remove')) {
      var next = rs[i + 1] || rs[i - 1];
      row.remove(); sync(); say('Снимка ' + (i + 1) + ' е премахната');
      (next ? next.querySelector('.ap-remove') : document.getElementById('apLibrary')).focus();
    } else if (b.classList.contains('ap-crop')) {
      fetch(row.dataset.src).then(function (r) { return r.blob(); })
        .then(function (blob) { return OMCrop.open(new File([blob], 'photo.' + (blob.type.split('/')[1] || 'jpg'), { type: blob.type })); })
        .then(function (cropped) {
          if (!cropped) return;
          return upload(cropped).then(function (path) {
            row.dataset.src = path;
            row.querySelector('input[name="photo_src[]"]').value = path;
            row.querySelector('img').src = path;
            sync(); say('Снимка ' + (i + 1) + ' е изрязана');
          });
        })
        .catch(function () { showError('Снимката не можа да се изреже. Опитайте отново.'); });
    }
  });

  // Drag to reorder (mouse); ↑/↓ above are the keyboard way.
  var dragged = null;
  grid.addEventListener('dragstart', function (e) { dragged = e.target.closest('.ap-photo'); });
  grid.addEventListener('dragover', function (e) { if (dragged) e.preventDefault(); });
  grid.addEventListener('drop', function (e) {
    var over = e.target.closest('.ap-photo');
    if (dragged && over && over !== dragged) {
      var rs = rows();
      grid.insertBefore(dragged, rs.indexOf(dragged) < rs.indexOf(over) ? over.nextSibling : over);
      sync(); say('Редът на снимките е променен');
    }
    dragged = null;
  });
  rows().forEach(function (r) { r.draggable = true; });
  new MutationObserver(function () { rows().forEach(function (r) { r.draggable = true; }); }).observe(grid, { childList: true });
})();
```

Check before writing: the name of the shared translate-button initialiser in `admin/includes/admin-footer.php` (grep `data-translate-from`). If it observes the DOM itself, drop the `omInitTranslateButtons` line; if it exposes a different function, call that one. Also confirm the CSRF input's name in `csrf_field()` (`grep -n "function csrf_field" -A3 config.php`).

- [ ] **Step 7: Run the tests** — `php vendor/bin/phpunit tests/Admin/ArticlePhotoGridTest.php tests/Admin/TranslateButtonCoverageTest.php`, then the full suite (`rc=0`).

- [ ] **Step 8: Check it in the browser** (start the `ngo-cms` preview against this worktree; sign in with the local seed admin from `tests/seed-admin.php`): add 3 photos at once, crop one, mark the 2nd as main, move it with ↑, remove one, type BG captions, press ✦ Translate on an EN caption, save, reopen — order, main and both captions persist; Tab reaches every control with a visible outline; try to add an 11th → the limit message shows and stays.

- [ ] **Step 9: Commit**

```bash
git add admin/article-edit.php templates/admin/article-photo-row.php tests/Admin/ArticlePhotoGridTest.php
git commit -m "feat(articles): a photo grid in the post editor — add many, crop, reorder, mark main, caption"
```

---

### Task 5: The carousel on the article page (BG and EN)

**Files:**
- Create: `templates/article-carousel.php`
- Modify: `novini/index.php:74-80` (keep the CMS `om-img-wrap` around the single-image case), `en/news/index.php:76-80`
- Test: `tests/Admin/ArticleCarouselTemplateTest.php` (create)

**Interfaces:**
- Consumes: `article_photos()`, `article_main_photo_index()` (Task 1)
- Produces: template vars `$article`, `$lang` ('bg'|'en'), optional `$photos_exist` (callable, tests only).

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The image area at the top of a post: one picture, or a carousel for two or more. */
final class ArticleCarouselTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    private function render(array $article, string $lang = 'bg', ?callable $exists = null): string
    {
        $photos_exist = $exists ?? static fn(): bool => true;
        ob_start();
        require dirname(__DIR__, 2) . '/templates/article-carousel.php';
        return (string) ob_get_clean();
    }

    private function post(int $n, int $main = 0, array $caps = []): array
    {
        $photos = [];
        for ($i = 0; $i < $n; $i++) $photos[] = ['src' => "/assets/images/articles/p$i.jpg", 'caption' => $caps[$i] ?? ''];
        return ['title' => 'Лятен лагер', 'image' => $photos[$main]['src'] ?? '', 'photos' => $photos];
    }

    public function testOnePhotoIsAPlainImage(): void
    {
        $html = $this->render($this->post(1));
        $this->assertStringContainsString('/assets/images/articles/p0.jpg', $html);
        $this->assertStringNotContainsString('data-carousel', $html);
    }

    public function testNoPhotosRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(['title' => 'x', 'image' => ''])));
    }

    public function testTwoOrMoreBecomeACarouselOpeningOnTheMainPhoto(): void
    {
        $html = $this->render($this->post(3, 2));
        $this->assertStringContainsString('data-carousel', $html);
        $this->assertStringContainsString('data-start="2"', $html);
        $this->assertStringContainsString('3 / 3', $html);
        $this->assertStringContainsString('aria-label="Предишна снимка"', $html);
        $this->assertStringContainsString('aria-label="Следваща снимка"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringNotContainsString('autoplay', $html);
    }

    public function testCaptionIsShownAndUsedAsAlt(): void
    {
        $html = $this->render($this->post(2, 0, ['Децата на лагера', '']));
        $this->assertStringContainsString('alt="Децата на лагера"', $html);
        $this->assertStringContainsString('<figcaption', $html);
        $this->assertStringContainsString('alt="Снимка 2 от 2 — Лятен лагер"', $html);
    }

    public function testEnglishLabelsAndFallbackAlt(): void
    {
        $html = $this->render($this->post(2), 'en');
        $this->assertStringContainsString('aria-label="Previous photo"', $html);
        $this->assertStringContainsString('alt="Photo 1 of 2 — Лятен лагер"', $html);
    }

    /** Review Focus 3 */
    public function testCaptionMarkupIsShownLiterally(): void
    {
        $html = $this->render($this->post(2, 0, ['"><img src=x onerror=alert(1)> & 😀', '']));
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x', $html);
    }

    /** Review Focus 4 */
    public function testMissingMainPhotoOpensOnTheFirstRemaining(): void
    {
        $exists = static fn(string $p): bool => $p !== '/assets/images/articles/p1.jpg';
        $html = $this->render($this->post(3, 1), 'bg', $exists);
        $this->assertStringNotContainsString('p1.jpg', $html);
        $this->assertStringContainsString('data-start="0"', $html);
        $this->assertStringContainsString('1 / 2', $html);
    }

    public function testOnlyTheFirstShownPhotoLoadsEagerly(): void
    {
        $html = $this->render($this->post(4, 0));
        $this->assertSame(3, substr_count($html, 'loading="lazy"'));
    }

    public function testBothArticlePagesUseTheTemplate(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['/novini/index.php', '/en/news/index.php'] as $page) {
            $this->assertStringContainsString('/templates/article-carousel.php', (string) file_get_contents($root . $page), $page);
        }
    }
}
```

- [ ] **Step 2: Run to see them fail** — template missing.

- [ ] **Step 3: Create `templates/article-carousel.php`**

```php
<?php
/**
 * The image area at the top of a post. One photo: a plain image (as before). Two or more:
 * a carousel that opens on the main photo, with previous/next buttons, a counter, swipe
 * and ←/→. Never moves on its own; no slide animation under prefers-reduced-motion.
 * Captions show under the photo and are its alt text.
 *
 * Vars: $article, $lang ('bg'|'en'); $photos_exist (callable) only in tests.
 * Inline styles only — main.css may be stale-cached.
 */
$_ac_photos = article_photos($article, $photos_exist ?? null);
if (!$_ac_photos) return;
$_ac_title = (string) ($article['title'] ?? '');
$_ac_total = count($_ac_photos);
$_ac_start = article_main_photo_index($article, $_ac_photos);
$_ac_alt = static function (array $p, int $n) use ($lang, $_ac_total, $_ac_title): string {
    if ($p['caption'] !== '') return $p['caption'];
    return $lang === 'en' ? "Photo $n of $_ac_total — $_ac_title" : "Снимка $n от $_ac_total — $_ac_title";
};
$_ac_img = 'width:100%;height:auto;border-radius:var(--radius-lg);display:block;';
?>
<?php if ($_ac_total === 1): $p = $_ac_photos[0]; ?>
  <figure style="margin:0 0 2rem;">
    <img src="<?= asset_url($p['src']) ?>" alt="<?= h($p['caption'] !== '' ? $p['caption'] : $_ac_title) ?>" style="<?= $_ac_img ?>">
    <?php if ($p['caption'] !== ''): ?>
      <figcaption style="margin-top:.5rem;font-size:.9rem;color:var(--text-muted);"><?= h($p['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>
<?php else: ?>
  <section data-carousel data-start="<?= $_ac_start ?>" tabindex="0"
           aria-roledescription="<?= $lang === 'en' ? 'carousel' : 'въртележка' ?>"
           aria-label="<?= h($lang === 'en' ? "Photos: $_ac_title" : "Снимки: $_ac_title") ?>"
           style="margin:0 0 2rem;outline-offset:4px;">
    <?php foreach ($_ac_photos as $i => $p): ?>
      <figure data-slide <?= $i === $_ac_start ? '' : 'hidden' ?> style="margin:0;">
        <img src="<?= asset_url($p['src']) ?>" alt="<?= h($_ac_alt($p, $i + 1)) ?>"
             <?= $i === $_ac_start ? '' : 'loading="lazy"' ?>
             style="<?= $_ac_img ?>aspect-ratio:3/2;object-fit:cover;">
        <?php if ($p['caption'] !== ''): ?>
          <figcaption style="margin-top:.5rem;font-size:.9rem;color:var(--text-muted);"><?= h($p['caption']) ?></figcaption>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:.75rem;">
      <button type="button" data-prev class="btn btn--outline" aria-label="<?= $lang === 'en' ? 'Previous photo' : 'Предишна снимка' ?>"
              style="min-width:44px;min-height:44px;padding:0;justify-content:center;"><span aria-hidden="true">‹</span></button>
      <span data-count aria-live="polite" style="min-width:4rem;text-align:center;font-weight:600;"><?= $_ac_start + 1 ?> / <?= $_ac_total ?></span>
      <button type="button" data-next class="btn btn--outline" aria-label="<?= $lang === 'en' ? 'Next photo' : 'Следваща снимка' ?>"
              style="min-width:44px;min-height:44px;padding:0;justify-content:center;"><span aria-hidden="true">›</span></button>
    </div>
  </section>
  <script>
  (function () {
    var c = document.currentScript.previousElementSibling;
    var slides = c.querySelectorAll('[data-slide]'), count = c.querySelector('[data-count]');
    var i = parseInt(c.dataset.start, 10) || 0, n = slides.length, x0 = null;
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function show(k) {
      slides[i].hidden = true;
      i = (k + n) % n;
      slides[i].hidden = false;
      if (!still) { slides[i].style.opacity = 0; slides[i].style.transition = 'opacity .25s'; requestAnimationFrame(function () { slides[i].style.opacity = 1; }); }
      count.textContent = (i + 1) + ' / ' + n;
    }
    c.querySelector('[data-prev]').addEventListener('click', function () { show(i - 1); });
    c.querySelector('[data-next]').addEventListener('click', function () { show(i + 1); });
    c.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(i - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); show(i + 1); }
    });
    c.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    c.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1));
    }, { passive: true });
  })();
  </script>
<?php endif; ?>
```

Note: `testOnlyTheFirstShownPhotoLoadsEagerly` and the "no autoplay" check also guard against `transition` creeping into a setInterval — keep the script free of timers.

- [ ] **Step 4: Use it on both pages.** `novini/index.php` — replace the `<?php if (!empty($article['image'])): ?> … <?php endif; ?>` block with:

```php
      <?php $lang = 'bg'; ?>
      <?php if (count(article_photos($article)) <= 1 && !empty($article['image'])): ?>
        <span class="om-img-wrap" data-cms-field="image" data-cms-section="article">
          <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-carousel.php'; ?>
          <?php if ($_show_admin_bar): ?><span class="om-img-overlay">📷 Replace</span><?php endif; ?>
        </span>
      <?php else: ?>
        <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-carousel.php'; ?>
      <?php endif; ?>
```

(The inline "📷 Replace" only makes sense for a single image; posts with several photos are edited in the photo grid.) Add `require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';` near the top if `config.php` doesn't already load it (grep first).

`en/news/index.php` — replace its image block with:

```php
      <?php $lang = 'en'; require $_SERVER['DOCUMENT_ROOT'] . '/templates/article-carousel.php'; ?>
```

- [ ] **Step 5: Run the tests**, then the full suite (`rc=0`).

- [ ] **Step 6: Check it in the browser** — a post with 3 photos (one captioned) on `/novini/<slug>/` and `/en/news/<slug_en>/`: opens on the main photo; ‹ › and ←/→ move; swipe at 375px width; counter updates; with "reduce motion" emulated there's no fade; a one-photo post looks as before.

- [ ] **Step 7: Commit**

```bash
git add templates/article-carousel.php novini/index.php en/news/index.php tests/Admin/ArticleCarouselTemplateTest.php
git commit -m "feat(news): a post with several photos shows them as a carousel (BG and EN)"
```

---

### Task 6: Prepare a list of photos for Buffer

**Files:**
- Create: `includes/social_images.php`
- Test: `tests/SocialImagesTest.php` (create)

**Interfaces:**
- Consumes: `article_photos()` (Task 1)
- Produces:
  - `social_prepare_image(string $path, string $mode): ?string` — `$mode` is `'fit'` (≤ 4800px wide, as today), `'insta'` (4:5–1.91:1 range, as today — single Instagram photo) or `'square'` (centred 1:1, Instagram carousel). Writes a sibling file (`-ig` / `-sq` suffix) only when it has to change the image; returns the **site path** of the file to send, or `null` if the file is missing/unreadable.
  - `social_image_url(string $path): string` — `SITE_URL . '/' .` the rawurlencoded path.
  - `social_photo_paths(array $article): array` — main photo first, then the rest in order, only existing files.
  - `social_buffer_assets_gql(array $urls): string` — `'assets: [{ image: { url: "…" } }, …],'` or `''`.

- [ ] **Step 1: Write the failing tests** — generate real images with GD in a temp dir under `DOCUMENT_ROOT`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Getting a post's photos ready for Buffer: sizes Instagram accepts, main photo first. */
final class SocialImagesTest extends TestCase
{
    private string $dir;
    private string $rel;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/articles.php';
        require_once dirname(__DIR__) . '/includes/social_images.php';
    }

    protected function setUp(): void
    {
        $this->rel = '/assets/images/articles/_test-social-' . bin2hex(random_bytes(3));
        $this->dir = $_SERVER['DOCUMENT_ROOT'] . $this->rel;
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function jpeg(string $name, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagejpeg($im, $this->dir . '/' . $name);
        imagedestroy($im);
        return $this->rel . '/' . $name;
    }

    public function testSquareModeCropsToTheCentre(): void
    {
        $out = social_prepare_image($this->jpeg('wide.jpg', 1200, 800), 'square');
        [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . $out);
        $this->assertSame([800, 800], [$w, $h]);
        $this->assertStringEndsWith('-sq.jpg', $out);
    }

    public function testAlreadySquareIsSentAsIs(): void
    {
        $p = $this->jpeg('sq.jpg', 600, 600);
        $this->assertSame($p, social_prepare_image($p, 'square'));
    }

    public function testInstaModeKeepsAnAcceptableShape(): void
    {
        $p = $this->jpeg('ok.jpg', 1000, 800);   // 1.25 — within 0.8 … 1.91
        $this->assertSame($p, social_prepare_image($p, 'insta'));
        $tall = social_prepare_image($this->jpeg('tall.jpg', 800, 2000), 'insta');
        [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . $tall);
        $this->assertSame(1000, $h);  // 800 × 5/4
    }

    public function testMissingFileGivesNull(): void
    {
        $this->assertNull(social_prepare_image($this->rel . '/nope.jpg', 'fit'));
    }

    /** Review Focus 4 */
    public function testMainPhotoGoesFirstAndMissingFilesAreLeftOut(): void
    {
        $a = $this->jpeg('a.jpg', 10, 10);
        $b = $this->jpeg('b.jpg', 10, 10);
        $article = ['image' => $b, 'photos' => [
            ['src' => $a, 'caption' => ''], ['src' => $this->rel . '/gone.jpg', 'caption' => ''], ['src' => $b, 'caption' => ''],
        ]];
        $this->assertSame([$b, $a], social_photo_paths($article));
    }

    public function testAssetsListForBuffer(): void
    {
        $this->assertSame('', social_buffer_assets_gql([]));
        $gql = social_buffer_assets_gql(['https://x.org/a.jpg', 'https://x.org/b "q".jpg']);
        $this->assertSame(
            'assets: [{ image: { url: "https://x.org/a.jpg" } }, { image: { url: "https://x.org/b \"q\".jpg" } }],',
            $gql
        );
    }
}
```

- [ ] **Step 2: Run to see them fail** — `php vendor/bin/phpunit tests/SocialImagesTest.php` → file missing.

- [ ] **Step 3: Create `includes/social_images.php`.** Move the GD code from `admin/social-ajax.php:250-334` into it, generalised:

```php
<?php
/**
 * Getting a post's photos ready for Buffer. Lifted out of admin/social-ajax.php and
 * admin/linkedin-ajax.php so both share it and it works on a list of photos.
 */
require_once __DIR__ . '/articles.php';

function social_image_url(string $path): string {
    return SITE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/** Main photo first, then the others in the author's order; only files that exist. */
function social_photo_paths(array $article): array {
    $photos = article_photos($article);
    if (!$photos) return [];
    $main = article_main_photo_index($article, $photos);
    $paths = array_column($photos, 'src');
    $first = $paths[$main];
    unset($paths[$main]);
    return array_merge([$first], array_values($paths));
}

function social_buffer_assets_gql(array $urls): string {
    if (!$urls) return '';
    $items = array_map(static fn(string $u): string => '{ image: { url: ' . json_encode($u, JSON_UNESCAPED_SLASHES) . ' } }', $urls);
    return 'assets: [' . implode(', ', $items) . '],';
}

/** @return \GdImage|false */
function _social_gd_open(string $abs, string $ext) {
    return match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($abs),
        'png'         => @imagecreatefrompng($abs),
        'webp'        => @imagecreatefromwebp($abs),
        default       => false,
    };
}

function _social_gd_save(\GdImage $im, string $abs, string $ext): bool {
    return match ($ext) {
        'jpg', 'jpeg' => imagejpeg($im, $abs, 90),
        'png'         => imagepng($im, $abs),
        'webp'        => imagewebp($im, $abs, 90),
        default       => false,
    };
}

/**
 * The file to send for one photo. 'fit': at most 4800px wide (Buffer's limit is 5000).
 * 'insta': also between 4:5 and 1.91:1. 'square': also a centred 1:1 crop (every slide of
 * an Instagram carousel shares one shape). Returns a site path, or null if unreadable.
 */
function social_prepare_image(string $path, string $mode): ?string {
    $abs = $_SERVER['DOCUMENT_ROOT'] . $path;
    $size = @getimagesize($abs);
    if (!$size) return null;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    [$w, $h] = $size;

    $scale = $w > 4800 ? 4800 / $w : 1.0;
    $cw = $w; $ch = $h; $cx = 0; $cy = 0;
    if ($mode === 'square' && $w !== $h) {
        $cw = $ch = min($w, $h);
        $cx = intdiv($w - $cw, 2); $cy = intdiv($h - $ch, 2);
    } elseif ($mode === 'insta') {
        $r = $w / $h;
        if ($r < 0.8)      { $ch = (int) round($w * 5 / 4); $cy = intdiv($h - $ch, 2); }
        elseif ($r > 1.91) { $cw = (int) round($h * 1.91);  $cx = intdiv($w - $cw, 2); }
    }
    if ($scale === 1.0 && $cw === $w && $ch === $h) return $path;

    $src = _social_gd_open($abs, $ext);
    if (!$src) return $path;   // can't read it with GD — send the original, as before
    $dw = (int) round($cw * $scale); $dh = (int) round($ch * $scale);
    $dst = imagecreatetruecolor($dw, $dh);
    if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);
    $suffix  = $mode === 'square' ? '-sq' : '-ig';
    $outPath = preg_replace('/\.' . preg_quote($ext, '/') . '$/', $suffix . '.' . $ext, $path);
    $ok = _social_gd_save($dst, $_SERVER['DOCUMENT_ROOT'] . $outPath, $ext);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? $outPath : $path;
}
```

- [ ] **Step 4: Run the tests** — all pass; full suite `rc=0`.

- [ ] **Step 5: Commit**

```bash
git add includes/social_images.php tests/SocialImagesTest.php
git commit -m "refactor(social): one helper prepares any post photo for Buffer, incl. square slides"
```

---

### Task 7: Facebook and Instagram get all the photos

**Files:**
- Modify: `admin/social-ajax.php:235-334` (image block) and `:395-401` (`$metadata`, `$assets_gql`)
- Test: `tests/SocialImagesTest.php` (extend) — the request-building helper

**Interfaces:**
- Consumes: `social_photo_paths()`, `social_prepare_image()`, `social_image_url()`, `social_buffer_assets_gql()` (Task 6)
- Produces: `social_fb_insta_request(array $article, string $channel): array{urls: string[], metadata: string}` in `includes/social_images.php`.

- [ ] **Step 1: Write the failing tests**

```php
    public function testInstagramCarouselForSeveralPhotos(): void
    {
        $a = $this->jpeg('a.jpg', 1200, 800);
        $b = $this->jpeg('b.jpg', 800, 800);
        $r = social_fb_insta_request(['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]], 'insta');
        $this->assertCount(2, $r['urls']);
        $this->assertStringEndsWith('a-sq.jpg', $r['urls'][0]);
        // Buffer refuses type: carousel (verified 03.10.2026) — several assets on a post make the carousel.
        $this->assertSame('instagram: { type: post, shouldShareToFeed: true }', $r['metadata']);
    }

    public function testInstagramSinglePhotoStaysAPost(): void
    {
        $a = $this->jpeg('a.jpg', 1000, 800);
        $r = social_fb_insta_request(['image' => $a], 'insta');
        $this->assertSame('instagram: { type: post, shouldShareToFeed: true }', $r['metadata']);
        $this->assertCount(1, $r['urls']);
    }

    public function testFacebookSendsEveryPhotoAsAPost(): void
    {
        $a = $this->jpeg('a.jpg', 100, 100);
        $b = $this->jpeg('b.jpg', 100, 100);
        $r = social_fb_insta_request(['image' => $b, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]], 'fb');
        $this->assertSame('facebook: { type: post }', $r['metadata']);
        $this->assertStringEndsWith('b.jpg', $r['urls'][0]);
    }
```

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement** (append to `includes/social_images.php`)

```php
/**
 * What social-ajax.php sends Buffer for Facebook or Instagram: the photo URLs (main
 * first) and the metadata. Instagram with several photos is a carousel of square slides.
 */
function social_fb_insta_request(array $article, string $channel): array {
    $paths    = social_photo_paths($article);
    $carousel = $channel === 'insta' && count($paths) > 1;
    $mode     = $channel === 'insta' ? ($carousel ? 'square' : 'insta') : 'fit';
    $urls = [];
    foreach ($paths as $p) {
        $ready = social_prepare_image($p, $mode);
        if ($ready !== null) $urls[] = social_image_url($ready);
    }
    $metadata = $channel === 'fb'
        ? 'facebook: { type: post }'
        : 'instagram: { type: post, shouldShareToFeed: true }';   // several assets make the carousel; Buffer refuses type: carousel
    return ['urls' => $urls, 'metadata' => $metadata];
}
```

In `admin/social-ajax.php`: `require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/social_images.php';` at the top; replace lines 235-334 (from `// Build absolute image URL` to the end of the resize/crop `if`) with:

```php
    $req = social_fb_insta_request($data, $channel);
    _om_log('INFO', "social-ajax schedule/{$channel}: " . count($req['urls']) . ' photo(s)');

    // Instagram requires an image
    if ($channel === 'insta' && !$req['urls']) {
        echo json_encode(['ok' => false, 'error' => 'Instagram изисква снимка. Добавете снимка към статията преди да планирате.']);
        exit;
    }
```

and replace the `$metadata = …` / `$assets_gql = …` lines before `createPost` with:

```php
    $metadata   = $req['metadata'];
    $assets_gql = social_buffer_assets_gql($req['urls']);
```

Leave the `updatePost` block as it is — Trello #20 owns it.

- [ ] **Step 4: Run** the tests and the full suite (`rc=0`).

- [ ] **Step 5: Verify against Buffer once** — with the user's OK, schedule a Facebook and an Instagram post for a test article with 3 photos on the test site, `dueAt` a week ahead; confirm in Buffer both show all photos (Instagram as a carousel), then delete them in Buffer.

- [ ] **Step 6: Commit**

```bash
git add includes/social_images.php admin/social-ajax.php tests/SocialImagesTest.php
git commit -m "feat(social): Facebook gets every photo, Instagram a square-slide carousel"
```

---

### Task 8: The LinkedIn carousel PDF

**Files:**
- Create: `includes/documents/LinkedInCarouselGenerator.php`
- Test: `tests/LinkedInCarouselTest.php` (create)

**Interfaces:**
- Consumes: `social_photo_paths()`, `social_prepare_image()` (Task 6); `article_photos()` (Task 1)
- Produces: `LinkedInCarouselGenerator::build(array $article_bg, array $article_en): string` (PDF bytes) and `LinkedInCarouselGenerator::save(array $article_bg, array $article_en): ?string` (site path of the saved PDF, `/assets/images/articles/linkedin-<slug>-<hash>.pdf`; the hash of the photo list + captions, so a changed list gets a new file and Buffer never sees a stale cached PDF; `null` if fewer than 2 photos).

Note: this generator does not extend `DocumentGenerator` (that class is for order documents with an `$order`/`$items` signature); it copies its `createMpdf()` settings with a square page format.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The PDF LinkedIn shows as a swipeable carousel: one square page per photo, caption under it. */
final class LinkedInCarouselTest extends TestCase
{
    private string $dir;
    private string $rel;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        require_once dirname(__DIR__) . '/includes/social_images.php';
        require_once dirname(__DIR__) . '/includes/documents/LinkedInCarouselGenerator.php';
    }

    protected function setUp(): void
    {
        $this->rel = '/assets/images/articles/_test-li-' . bin2hex(random_bytes(3));
        $this->dir = $_SERVER['DOCUMENT_ROOT'] . $this->rel;
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function photo(string $name): string
    {
        $im = imagecreatetruecolor(400, 300);
        imagejpeg($im, $this->dir . '/' . $name);
        return $this->rel . '/' . $name;
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    public function testOnePagePerPhotoMainFirst(): void
    {
        $a = $this->photo('a.jpg'); $b = $this->photo('b.jpg'); $c = $this->photo('c.jpg');
        $bg = ['slug' => 't', 'title' => 'Лагер', 'image' => $b, 'photos' => [
            ['src' => $a, 'caption' => 'А'], ['src' => $b, 'caption' => 'Б'], ['src' => $c, 'caption' => 'В']]];
        $pdf = LinkedInCarouselGenerator::build($bg, []);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(3, $this->pages($pdf));
    }

    public function testEnglishCaptionPreferredBulgarianAsFallback(): void
    {
        $a = $this->photo('a.jpg'); $b = $this->photo('b.jpg');
        $bg = ['slug' => 't', 'title' => 'Т', 'image' => $a, 'photos' => [['src' => $a, 'caption' => 'Лагер'], ['src' => $b, 'caption' => 'Море']]];
        $en = ['title' => 'T', 'image' => $a, 'photos' => [['src' => $a, 'caption' => 'Camp'], ['src' => $b, 'caption' => '']]];
        $html = LinkedInCarouselGenerator::html($bg, $en);
        $this->assertStringContainsString('Camp', $html);
        $this->assertStringContainsString('Море', $html);
        $this->assertStringNotContainsString('Лагер', $html);
    }

    /** Review Focus 3 */
    public function testCaptionsAreEscaped(): void
    {
        $a = $this->photo('a.jpg'); $b = $this->photo('b.jpg');
        $bg = ['slug' => 't', 'title' => 'Т', 'image' => $a, 'photos' => [['src' => $a, 'caption' => '<b>x</b> & "y"'], ['src' => $b, 'caption' => '']]];
        $html = LinkedInCarouselGenerator::html($bg, []);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;', $html);
    }

    public function testSingleOrNoPhotoMakesNoPdf(): void
    {
        $a = $this->photo('a.jpg');
        $this->assertNull(LinkedInCarouselGenerator::save(['slug' => 't', 'image' => $a], []));
    }

    public function testChangedPhotosGetANewFile(): void
    {
        $a = $this->photo('a.jpg'); $b = $this->photo('b.jpg');
        $one = ['slug' => 'tst', 'title' => 'Т', 'image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]];
        $two = $one; $two['photos'][1]['caption'] = 'нов';
        $p1 = LinkedInCarouselGenerator::save($one, []);
        $p2 = LinkedInCarouselGenerator::save($two, []);
        $this->assertNotSame($p1, $p2);
        foreach ([$p1, $p2] as $p) { $this->assertFileExists($_SERVER['DOCUMENT_ROOT'] . $p); unlink($_SERVER['DOCUMENT_ROOT'] . $p); }
    }
}
```

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Create `includes/documents/LinkedInCarouselGenerator.php`**

```php
<?php
/**
 * The PDF LinkedIn shows as a swipeable "document" carousel: one square page per photo,
 * main photo first, its English caption underneath (Bulgarian when there is no English).
 */
require_once __DIR__ . '/../social_images.php';

final class LinkedInCarouselGenerator
{
    /** Square page, in mm. 1080px at 96 dpi ≈ 285.75mm. */
    private const SIDE = 285.75;

    public static function html(array $bg, array $en): string
    {
        $en_caps = array_column(article_photos(array_merge($en, ['image' => $bg['image'] ?? ''])), 'caption', 'src');
        $bg_caps = array_column(article_photos($bg), 'caption', 'src');
        $html = '<style>body{font-family:dejavusans;margin:0}.p{text-align:center}'
              . '.p img{width:240mm;height:240mm;object-fit:cover}.c{font-size:16pt;color:#1a1a2e;margin-top:6mm}</style>';
        $first = true;
        foreach (social_photo_paths($bg) as $path) {
            $ready = social_prepare_image($path, 'square') ?? $path;
            $cap   = ($en_caps[$path] ?? '') !== '' ? $en_caps[$path] : ($bg_caps[$path] ?? '');
            $html .= ($first ? '' : '<pagebreak />')
                   . '<div class="p"><img src="' . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] . $ready, ENT_QUOTES, 'UTF-8') . '">'
                   . ($cap !== '' ? '<div class="c">' . htmlspecialchars($cap, ENT_QUOTES, 'UTF-8') . '</div>' : '')
                   . '</div>';
            $first = false;
        }
        return $html;
    }

    public static function build(array $bg, array $en): string
    {
        $tmp = sys_get_temp_dir() . '/mpdf_' . substr(md5(uniqid('', true)), 0, 8);
        if (!is_dir($tmp) && !mkdir($tmp, 0755, true)) {
            throw new \RuntimeException("Cannot create mPDF temp directory: {$tmp}");
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => [self::SIDE, self::SIDE],
            'margin_top' => 18, 'margin_bottom' => 12, 'margin_left' => 22, 'margin_right' => 22,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetTitle((string) ($en['title'] ?? $bg['title'] ?? ''));
        $mpdf->WriteHTML(self::html($bg, $en));
        return $mpdf->Output('', 'S');
    }

    /** Write the PDF next to the post's images; null when there are fewer than two photos. */
    public static function save(array $bg, array $en): ?string
    {
        if (count(article_photos($bg)) < 2) return null;
        $hash = substr(md5(json_encode([$bg['photos'] ?? [], $en['photos'] ?? [], $bg['image'] ?? ''])), 0, 10);
        $path = '/assets/images/articles/linkedin-' . ascii_slug((string) ($bg['slug'] ?? 'post')) . '-' . $hash . '.pdf';
        $abs  = $_SERVER['DOCUMENT_ROOT'] . $path;
        if (!is_file($abs) && file_put_contents($abs, self::build($bg, $en)) === false) return null;
        return $path;
    }
}
```

If mPDF ignores `object-fit` (it does not support it), the photos are already square from `social_prepare_image(..., 'square')`, so `width`/`height` alone keep them undistorted — that's why the prepare step comes first.

- [ ] **Step 4: Run** the tests and the full suite (`rc=0`). Open one generated PDF locally and look at it (3 square pages, captions legible).

- [ ] **Step 5: Commit**

```bash
git add includes/documents/LinkedInCarouselGenerator.php tests/LinkedInCarouselTest.php
git commit -m "feat(social): build the LinkedIn carousel PDF from a post's photos"
```

---

### Task 9: LinkedIn sends the PDF carousel

**Files:**
- Modify: `admin/linkedin-ajax.php:317-362` (image block) and `:363-365` (`$assets_gql`)
- Test: `tests/LinkedInCarouselTest.php` (extend)

**Interfaces:**
- Consumes: `LinkedInCarouselGenerator::save()` (Task 8); `social_photo_paths()`, `social_prepare_image()`, `social_image_url()`, `social_buffer_assets_gql()` (Task 6)
- Produces: `social_linkedin_assets_gql(array $bg, array $en): string` in `includes/social_images.php`.

- [ ] **Step 1: Write the failing tests**

```php
    public function testSeveralPhotosGoAsOneDocument(): void
    {
        $a = $this->photo('a.jpg'); $b = $this->photo('b.jpg');
        $bg = ['slug' => 'tst', 'title' => 'Т', 'image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]];
        $gql = social_linkedin_assets_gql($bg, ['title' => 'Camp "2026"']);
        $this->assertMatchesRegularExpression('#^assets: \[\{ document: \{ url: "[^"]+\.pdf", title: "Camp \\\\"2026\\\\"", thumbnailUrl: "[^"]+" \} \}\],$#', $gql);
        foreach (glob($_SERVER['DOCUMENT_ROOT'] . '/assets/images/articles/linkedin-tst-*.pdf') as $f) unlink($f);
    }

    public function testOnePhotoStaysAnImagePost(): void
    {
        $a = $this->photo('a.jpg');
        $gql = social_linkedin_assets_gql(['slug' => 't', 'image' => $a], []);
        $this->assertStringStartsWith('assets: [{ image: { url: ', $gql);
    }
```

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement** (append to `includes/social_images.php`)

```php
/**
 * LinkedIn: several photos go as one PDF "document" — LinkedIn shows it as a swipeable
 * carousel; one photo stays an ordinary image post.
 */
function social_linkedin_assets_gql(array $bg, array $en): string {
    $paths = social_photo_paths($bg);
    if (count($paths) > 1) {
        require_once __DIR__ . '/documents/LinkedInCarouselGenerator.php';
        $pdf = LinkedInCarouselGenerator::save($bg, $en);
        if ($pdf !== null) {
            $thumb = social_prepare_image($paths[0], 'square') ?? $paths[0];
            return 'assets: [{ document: { url: ' . json_encode(social_image_url($pdf), JSON_UNESCAPED_SLASHES)
                 . ', title: ' . json_encode((string) ($en['title'] ?? $bg['title'] ?? ''), JSON_UNESCAPED_UNICODE)
                 . ', thumbnailUrl: ' . json_encode(social_image_url($thumb), JSON_UNESCAPED_SLASHES) . ' } }],';
        }
    }
    if (!$paths) return '';
    $one = social_prepare_image($paths[0], 'fit');
    return $one === null ? '' : social_buffer_assets_gql([social_image_url($one)]);
}
```

In `admin/linkedin-ajax.php`: require `includes/social_images.php`; load the EN record next to `$article_data` (`$en_data = load_json(ARTICLES_PATH . '/en/' . ($article_data['slug_en'] ?? $slug) . '.json') ?: [];` — check the variable names in the schedule branch first); replace lines 317-362 and the `$assets_gql = …` line with:

```php
    $assets_gql = social_linkedin_assets_gql($article_data, $en_data);
    _om_log('INFO', 'linkedin-ajax schedule: ' . (str_contains($assets_gql, 'document:') ? 'PDF carousel' : ($assets_gql ? 'one image' : 'no image')));
```

Leave the `updatePost` block — Trello #20.

- [ ] **Step 4: Run** the tests and the full suite (`rc=0`).

- [ ] **Step 5: Verify against Buffer once** — with the user's OK, schedule the test article (3 photos) to LinkedIn a week ahead; confirm in Buffer it shows as a document with 3 pages; delete it.

- [ ] **Step 6: Commit**

```bash
git add includes/social_images.php admin/linkedin-ajax.php tests/LinkedInCarouselTest.php
git commit -m "feat(social): LinkedIn gets a post's photos as a swipeable PDF carousel"
```

---

### Task 10: Finish

- [ ] **Step 1: Full suite** — `php vendor/bin/phpunit > /tmp/pu.txt 2>&1; echo rc=$?; tail -3 /tmp/pu.txt` → `rc=0`.
- [ ] **Step 2: Whole-flow browser check** on the preview (BG and EN): new post with 4 photos → save → article page carousel → news list and home card show the main photo → page source `og:image` is the main photo.
- [ ] **Step 3: Trello** — comment on #17 with what shipped and that oddminds needs the port; leave it in its list until the user has tested it on the test site.
- [ ] **Step 4: Stop and report** — list the commits; wait for "push". When pushing: `git fetch && git rebase origin/main`, full suite, `git log --oneline origin/main..HEAD` shows only this branch's commits, `git push origin HEAD:main`, then remove the worktree and branch.
