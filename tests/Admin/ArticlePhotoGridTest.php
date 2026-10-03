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
        $this->assertStringContainsString('name="photo_caption_bg[0]"', $html);
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
        $this->assertStringContainsString('name="photo_caption_en[<?= $i ?>]"', $src);
        $this->assertStringContainsString('data-translate-from="photo_caption_bg[<?= $i ?>]"', $src);
        $this->assertStringContainsString('Може да добавите до 10 снимки. Премахнете снимка, за да добавите нова.', $src);
        $this->assertStringNotContainsString('window.confirm', $src);
    }
}
