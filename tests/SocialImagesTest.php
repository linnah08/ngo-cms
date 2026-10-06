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

    /** LinkedIn's document pages stay square. */
    public function testSquareModeCropsWideToTheCentre(): void
    {
        $out = social_prepare_image($this->jpeg('wide.jpg', 1200, 800), 'square');
        [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . $out);
        $this->assertSame([800, 800], [$w, $h]);
        $this->assertMatchesRegularExpression('/-sq-[0-9a-f]{8}\.jpg$/', $out);
        $this->assertSame([200, 0, 800, 800], social_crop_box(1200, 800, 'square'));
    }

    public function testCarouselSlidesAre4x5Portrait(): void
    {
        foreach ([[1200, 800, 640, 800], [900, 2000, 900, 1125], [800, 1000, 800, 1000]] as [$w, $h, $ew, $eh]) {
            $p   = $this->jpeg("c{$w}x{$h}.jpg", $w, $h);
            $out = social_prepare_image($p, 'carousel');
            $this->assertSame([$ew, $eh], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . $out), 0, 2), "{$w}×{$h}");
        }
    }

    /** The bug: a 900×2000 phone photo lost the head (rows 370–540) to a centred square. */
    public function testAutomaticCropOfATallPhotoKeepsTheTop(): void
    {
        $this->assertSame([0, 175, 900, 1125], social_crop_box(900, 2000, 'carousel'));   // (2000 − 1125) × 0.2
        $this->assertSame([0, 80, 900, 1600], social_crop_box(900, 2000, 'story'));        // 400 spare → 80
        $this->assertSame([0, 120, 800, 1000], social_crop_box(800, 1600, 'insta'));
        // Horizontal cuts stay centred.
        $this->assertSame([547, 0, 506, 900], social_crop_box(1600, 900, 'story'));
    }

    public function testASavedCropIsUsed(): void
    {
        $rect = ['x' => 0.0, 'y' => 0.5, 'w' => 0.5, 'h' => 0.28125, 'ar' => 0.8];   // 450×562.5 on 900×2000
        $this->assertSame([0, 1000, 450, 563], social_crop_box(900, 2000, 'carousel', $rect));

        $p   = $this->jpeg('tall.jpg', 900, 2000);
        $out = social_prepare_image($p, 'carousel', $rect);
        $this->assertSame([450, 563], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . $out), 0, 2));
        $this->assertNotSame(social_prepare_image($p, 'carousel'), $out, 'a different crop is a different file');
    }

    public function testACropMadeForAnotherShapeIsIgnored(): void
    {
        $square = ['x' => 0.0, 'y' => 0.5, 'w' => 1.0, 'h' => 0.45, 'ar' => 1.0];
        $this->assertSame(social_crop_box(900, 2000, 'carousel'), social_crop_box(900, 2000, 'carousel', $square));
        // Right label, wrong rectangle (e.g. the file was swapped for another size).
        $bad = ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0, 'ar' => 0.8];
        $this->assertSame([0, 175, 900, 1125], social_crop_box(900, 2000, 'carousel', $bad));
        $this->assertSame([0, 175, 900, 1125], social_crop_box(900, 2000, 'carousel', ['x' => 'a']));
    }

    public function testCarouselUsesTheSavedPostCropAndStoryItsOwn(): void
    {
        $a = $this->jpeg('a.jpg', 900, 2000);
        $b = $this->jpeg('b.jpg', 800, 800);
        $article = ['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']],
            'insta_crops' => ['post' => [$a => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5625, 'ar' => 0.8]]]];
        $r = social_fb_insta_request($article, 'insta');
        $path = substr($r['urls'][0], strlen(SITE_URL));
        $this->assertSame([900, 1125], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . rawurldecode($path)), 0, 2));
        $this->assertSame(social_prepare_image($a, 'carousel', $article['insta_crops']['post'][$a]), rawurldecode($path));
        // The story has no saved crop → automatic.
        $s = social_fb_insta_request($article, 'insta', true);
        $this->assertSame(social_prepare_image($a, 'story'), rawurldecode(substr($s['urls'][0], strlen(SITE_URL))));
    }

    public function testCropValidation(): void
    {
        $a = $this->jpeg('a.jpg', 900, 2000);
        $article = ['image' => $a, 'photos' => [['src' => $a, 'caption' => '']]];
        $ok = social_crop_clean($article, ['kind' => 'post', 'src' => $a, 'rect' => ['x' => -0.2, 'y' => '0.1', 'w' => 1.5, 'h' => 0.5, 'ar' => 0.8]]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(['x' => 0.0, 'y' => 0.1, 'w' => 1.0, 'h' => 0.5, 'ar' => 0.8], $ok['rect']);

        $this->assertFalse(social_crop_clean($article, ['kind' => 'reel', 'src' => $a, 'rect' => $ok['rect']])['ok']);
        $this->assertFalse(social_crop_clean($article, ['kind' => 'post', 'src' => '/assets/images/other.jpg', 'rect' => $ok['rect']])['ok']);
        $this->assertFalse(social_crop_clean($article, ['kind' => 'post', 'src' => $a, 'rect' => ['x' => 0, 'y' => 0, 'w' => 'all', 'h' => 1, 'ar' => 0.8]])['ok']);
        $this->assertFalse(social_crop_clean($article, ['kind' => 'post', 'src' => $a, 'rect' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 1, 'ar' => 0.8]])['ok']);
        $this->assertFalse(social_crop_clean($article, ['kind' => 'post', 'src' => $a])['ok']);
    }

    public function testPreviewMatchesWhatIsSent(): void
    {
        $a = $this->jpeg('a.jpg', 900, 2000);
        $b = $this->jpeg('b.jpg', 1200, 800);
        $pv = social_insta_preview(['image' => $b, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]]);
        $this->assertSame([$b, $a], array_column($pv['post'], 'src'));
        $this->assertSame([0.8, 0.8], array_column($pv['post'], 'ar'));
        $this->assertEqualsWithDelta(['x' => 0.0, 'y' => 0.0875, 'w' => 1.0, 'h' => 0.5625], $pv['post'][1]['rect'], 1e-9);
        $this->assertCount(1, $pv['story']);
        $this->assertSame($b, $pv['story'][0]['src']);
        // One photo: its own shape, clamped.
        $one = social_insta_preview(['image' => $b]);
        $this->assertSame(1.5, $one['post'][0]['ar']);
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

    public function testInstagramCarouselForSeveralPhotos(): void
    {
        $a = $this->jpeg('a.jpg', 1200, 800);
        $b = $this->jpeg('b.jpg', 800, 800);
        $r = social_fb_insta_request(['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]], 'insta');
        $this->assertCount(2, $r['urls']);
        $this->assertMatchesRegularExpression('/a-4x5-[0-9a-f]{8}\.jpg$/', $r['urls'][0]);
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

    public function testStoryModeCropsTo9x16(): void
    {
        $out = social_prepare_image($this->jpeg('wide.jpg', 1600, 900), 'story');
        [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . $out);
        $this->assertSame([506, 900], [$w, $h]);   // 900 × 9/16
        $this->assertMatchesRegularExpression('/-story-[0-9a-f]{8}\.jpg$/', $out);
    }

    public function testInstagramStoryIsTheMainPhotoAloneAsAStory(): void
    {
        $a = $this->jpeg('a.jpg', 900, 1600);
        $b = $this->jpeg('b.jpg', 800, 800);
        $r = social_fb_insta_request(['image' => $b, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]], 'insta', true);
        $this->assertCount(1, $r['urls']);
        $this->assertMatchesRegularExpression('/b-story-[0-9a-f]{8}\.jpg$/', $r['urls'][0]);
        $this->assertSame('instagram: { type: story, shouldShareToFeed: false }', $r['metadata']);
    }

    public function testFacebookSendsEveryPhotoAsAPost(): void
    {
        $a = $this->jpeg('a.jpg', 100, 100);
        $b = $this->jpeg('b.jpg', 100, 100);
        $r = social_fb_insta_request(['image' => $b, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]], 'fb');
        $this->assertSame('facebook: { type: post }', $r['metadata']);
        $this->assertStringEndsWith('b.jpg', $r['urls'][0]);
    }

    /** Review I3: an upper-case extension must never make the crop overwrite the original. */
    public function testUppercaseExtensionNeverOverwritesTheOriginal(): void
    {
        $p = $this->jpeg('IMG_1.JPG', 1200, 800);
        $out = social_prepare_image($p, 'square');
        $this->assertNotSame($p, $out);
        $this->assertSame([1200, 800], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . $p), 0, 2), 'original untouched');
        $this->assertSame([800, 800], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . $out), 0, 2));
    }
}
