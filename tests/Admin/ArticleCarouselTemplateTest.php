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
