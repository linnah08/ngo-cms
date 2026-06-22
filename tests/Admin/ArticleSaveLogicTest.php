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
            ['title', 'slug', 'date', 'author', 'status', 'excerpt', 'image', 'tags', 'content'],
            array_keys($data)
        );
        $this->assertSame('EN T', $data['title']);
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
}
