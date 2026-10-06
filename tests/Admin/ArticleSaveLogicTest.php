<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the article-save logic extracted out of admin/article-edit.php
 * into includes/articles.php — slug derivation and the save-record assembly that
 * must preserve social metadata. Previously fused into the page and untestable.
 *
 * Note: this (generic) build keeps the source language in BG slugs (slug() allows
 * Cyrillic), unlike the oddminds build which transliterates BG slugs to ASCII.
 */
#[Group('admin')]
final class ArticleSaveLogicTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
        require_once dirname(__DIR__, 2) . '/includes/ai_excerpt.php';
    }

    // ── article_compute_slugs ────────────────────────────────────────────────

    public function testBgSlugKeepsSourceLanguage(): void
    {
        // slug() preserves Cyrillic (lower-cased); only path-unsafe chars are stripped.
        $s = article_compute_slugs('Парти Парти', '', 'irrelevant', '');
        $this->assertSame('парти-парти', $s['bg']);
    }

    public function testBgSlugFallsBackToTitleWhenSlugEmpty(): void
    {
        $s = article_compute_slugs('', '', 'Hello World', '');
        $this->assertSame('hello-world', $s['bg']);
    }

    public function testBgSlugFallsBackToTimestampWhenSlugAndTitleEmpty(): void
    {
        $s = article_compute_slugs('', '', '', '', '20260622120000');
        $this->assertSame('article-20260622120000', $s['bg']);
    }

    public function testPathTraversalAndNullBytesAreStrippedFromSlug(): void
    {
        $s = article_compute_slugs("../etc\0/passwd", '', 'fallback', '');
        $this->assertDoesNotMatchRegularExpression('#[/.\x00]#', $s['bg']);
    }

    public function testEnSlugExplicitOverrideWins(): void
    {
        $s = article_compute_slugs('bg-slug', 'My EN Title', 'BG Title', 'Different EN Title');
        $this->assertSame('my-en-title', $s['en']);
    }

    public function testEnSlugDerivedFromEnTitleWhenNoOverride(): void
    {
        $s = article_compute_slugs('bg-slug', '', 'BG Title', 'The English Title');
        $this->assertSame('the-english-title', $s['en']);
    }

    public function testEnSlugFallsBackToAsciiBgTitleWhenNoEnTitle(): void
    {
        // EN always transliterates the BG title to ASCII as its last-resort source.
        $s = article_compute_slugs('', '', 'Парти Парти', '');
        $this->assertSame('parti-parti', $s['en']);
    }

    public function testEnSlugFallsBackToBgSlugWhenEverythingElseEmpty(): void
    {
        $s = article_compute_slugs('my-bg', '', '', '', '20260622120000');
        $this->assertSame($s['bg'], $s['en']);
    }

    // ── article_build_bg_data ────────────────────────────────────────────────

    public function testBgDataPreservesSocialMetadataFromExisting(): void
    {
        $existing = [
            'linkedin_url'       => 'https://linkedin.com/x',
            'fb_text'            => 'hello fb',
            'insta_scheduled_at' => '2026-06-22 11:00',
        ];
        $data = article_build_bg_data([
            'title' => 'T', 'slug' => 'parti', 'slug_en' => 'party',
            'date' => '2026-06-22', 'author' => 'A', 'status' => 'published',
            'excerpt' => 'E', 'image' => '/img.jpg', 'tags' => ['a'], 'content' => '<p>x</p>',
        ], $existing);

        $this->assertSame('https://linkedin.com/x', $data['linkedin_url']);
        $this->assertSame('hello fb', $data['fb_text']);
        $this->assertSame('2026-06-22 11:00', $data['insta_scheduled_at']);
        $this->assertSame('parti', $data['slug']);
        $this->assertSame('published', $data['status']);
    }

    public function testBgDataKeepsInstagramCropsForPhotosStillOnThePost(): void
    {
        $rect = ['x' => 0, 'y' => 0.1, 'w' => 1, 'h' => 0.5, 'ar' => 0.8];
        $existing = ['insta_crops' => [
            'post'  => ['/assets/images/a.jpg' => $rect, '/assets/images/gone.jpg' => $rect],
            'story' => ['/assets/images/a.jpg' => $rect],
        ]];
        $data = article_build_bg_data([
            'title' => 'T', 'slug' => 's', 'slug_en' => 'se', 'date' => '2026-10-06', 'author' => 'A',
            'status' => 'published', 'excerpt' => '', 'image' => '/assets/images/a.jpg', 'tags' => [], 'content' => '',
            'photos' => [['src' => '/assets/images/a.jpg', 'caption' => ''], ['src' => '/assets/images/b.jpg', 'caption' => '']],
        ], $existing);
        $this->assertSame([
            'post'  => ['/assets/images/a.jpg' => $rect],
            'story' => ['/assets/images/a.jpg' => $rect],
        ], $data['insta_crops'], 'a removed photo loses its crop; the others are kept');

        $none = article_build_bg_data([
            'title' => 'T', 'slug' => 's', 'slug_en' => 'se', 'date' => '2026-10-06', 'author' => 'A',
            'status' => 'published', 'excerpt' => '', 'image' => '', 'tags' => [], 'content' => '',
        ], []);
        $this->assertArrayNotHasKey('insta_crops', $none);
    }

    public function testBgDataDefaultsMissingMetadataToEmptyStringForNewArticle(): void
    {
        $data = article_build_bg_data([
            'title' => 'T', 'slug' => 's', 'slug_en' => 'se',
            'date' => '2026-06-22', 'author' => 'A', 'status' => 'draft',
            'excerpt' => '', 'image' => '', 'tags' => [], 'content' => '',
        ], []); // no existing record

        $this->assertSame('', $data['linkedin_url']);
        $this->assertSame('', $data['buffer_post_id']);
        $this->assertSame('', $data['insta_due_at']);
    }

    // ── article_build_en_data ────────────────────────────────────────────────

    public function testEnDataMapsOnlyTheSharedAndEnglishFields(): void
    {
        $data = article_build_en_data([
            'title' => 'EN T', 'slug' => 'en-t', 'date' => '2026-06-22',
            'author' => 'A', 'status' => 'published', 'excerpt' => 'ex',
            'image' => '/i.jpg', 'tags' => ['t'], 'content' => '<p>en</p>',
        ]);
        $this->assertSame(
            ['title', 'slug', 'date', 'author', 'status', 'excerpt', 'image', 'photos', 'tags', 'content', 'scheduled'],
            array_keys($data)
        );
        $this->assertSame('EN T', $data['title']);
    }

    // ── article_excerpt_from_content ─────────────────────────────────────────

    public function testExcerptFromContentStripsHtmlAndCollapsesWhitespace(): void
    {
        $out = article_excerpt_from_content("<p>Hello   <strong>world</strong></p>\n<p>Bye</p>");
        $this->assertSame('Hello world Bye', $out);
    }

    public function testExcerptFromContentReturnsShortTextUnchanged(): void
    {
        $this->assertSame('Short text.', article_excerpt_from_content('<p>Short text.</p>'));
    }

    public function testExcerptFromContentTruncatesAtWordBoundaryWithEllipsis(): void
    {
        $text = str_repeat('word ', 40); // way over 160 chars
        $out  = article_excerpt_from_content($text, 160);
        $this->assertLessThanOrEqual(161, mb_strlen($out)); // 160 + ellipsis char
        $this->assertStringEndsWith('…', $out);
        $this->assertStringNotContainsString('  ', $out);
    }

    public function testExcerptFromContentReturnsEmptyForEmptyOrTagOnlyContent(): void
    {
        $this->assertSame('', article_excerpt_from_content(''));
        $this->assertSame('', article_excerpt_from_content('<p><br></p>'));
    }

    // ── article_auto_excerpt ──────────────────────────────────────────────────
    // Only the content-emptiness short-circuit is pure/deterministic; the
    // Claude-vs-fallback branch depends on live settings (claude_api_key) and
    // isn't unit-tested here, matching claude_suggest_keywords() in ai_keywords.php.

    public function testAutoExcerptReturnsEmptyForEmptyContent(): void
    {
        $this->assertSame('', article_auto_excerpt('Title', '', 'bg'));
        $this->assertSame('', article_auto_excerpt('Title', '<p></p>', 'en'));
    }

    public function testEditorKeepsTheStoredExcerptAndFillsAMissingOne(): void
    {
        // The editor form has no excerpt field: reading one from $_POST blanked the
        // excerpt (set inline on the listing) on every save.
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/article-edit.php');
        $this->assertStringNotContainsString("\$_POST['excerpt", $src);
        $this->assertStringContainsString("\$excerpt = trim(\$article['excerpt'] ?? '');", $src);
        $this->assertStringContainsString("\$excerpt_en = trim(\$article_en['excerpt'] ?? '');", $src);
        $this->assertStringContainsString("article_auto_excerpt(\$title, \$content, 'bg')", $src);
        $this->assertStringContainsString("article_auto_excerpt(\$title_en ?: \$title, \$content_en, 'en')", $src);
    }

    public function testExcerptBackfillRunsOnlyFromTheCommandLine(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/cron/backfill-article-excerpts.php');
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')", $src);
        $this->assertStringContainsString("in_array('--apply', \$argv, true)", $src, 'writes only with --apply');
    }

    // ── html_to_social_text ──────────────────────────────────────────────────

    public function testHtmlToSocialTextConvertsBoldToUnicodeAndStripsTags(): void
    {
        $out = html_to_social_text('<p>Hi <strong>there</strong></p>');
        $this->assertStringStartsWith('Hi ', $out);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('there', $out);
        $this->assertMatchesRegularExpression('/[\x{1D5D4}-\x{1D621}]/u', $out);
    }

    public function testHtmlToSocialTextDecodesEntities(): void
    {
        $this->assertSame('Tom & Jerry', html_to_social_text('Tom &amp; Jerry'));
    }

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
}
