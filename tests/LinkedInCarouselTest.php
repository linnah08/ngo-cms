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
