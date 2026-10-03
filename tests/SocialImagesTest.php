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
