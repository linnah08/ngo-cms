<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ExifJpeg.php';

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
            'insta_crops' => ['post' => [$a => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5625, 'ar' => 0.8]]],
            'insta_shape' => 'portrait'];
        $r = social_fb_insta_request($article, 'insta');
        $path = substr($r['urls'][0], strlen(SITE_URL));
        $this->assertSame([900, 1125], array_slice(getimagesize($_SERVER['DOCUMENT_ROOT'] . rawurldecode($path)), 0, 2));
        $this->assertSame(social_prepare_image($a, 'carousel', $article['insta_crops']['post'][$a], 0.8), rawurldecode($path));
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
        // One wide, one very tall: wide cuts less in total, so both go at the wide photo's 3:2.
        $this->assertSame([1.5, 1.5], array_column($pv['post'], 'ar'));
        $this->assertEqualsWithDelta(['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0], $pv['post'][0]['rect'], 1e-9);
        $this->assertSame(['fill', 'whole'], array_column($pv['post'], 'fit'));
        // The tall photo is shown whole: a 3000×2000 white frame around the 900×2000 photo.
        $this->assertEqualsWithDelta(['x' => -1050 / 900, 'y' => 0.0, 'w' => 3000 / 900, 'h' => 1.0], $pv['post'][1]['rect'], 1e-9);
        $this->assertEqualsWithDelta(['x' => 0.0, 'y' => (2000 - 600) * 0.2 / 2000, 'w' => 1.0, 'h' => 0.3], $pv['post'][1]['fill_rect'], 1e-9);
        // Picking Portrait gives the old 4:5, the tall photo cut from 20% down.
        $pv = social_insta_preview(['image' => $b, 'insta_shape' => 'portrait', 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]]);
        $this->assertSame([0.8, 0.8], array_column($pv['post'], 'ar'));
        $this->assertEqualsWithDelta(['x' => 0.0, 'y' => 0.0875, 'w' => 1.0, 'h' => 0.5625], $pv['post'][1]['fill_rect'], 1e-9);
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
        // 3:2 and square cut the same in total; the main photo's 3:2 wins the tie and goes whole.
        $this->assertStringEndsWith('/a.jpg', $r['urls'][0]);
        $this->assertMatchesRegularExpression('/b-whole-[0-9a-f]{8}\.jpg$/', $r['urls'][1], 'the square photo is shown whole');
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
        $a = $this->jpeg('a.jpg', 800, 800);
        $b = $this->jpeg('b.jpg', 1000, 1600);   // 9:16 cuts 10% of it — fine
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

    /** A phone photo stored sideways with a rotate flag (see tests/Support/ExifJpeg.php). */
    private function phonePhoto(string $name, int $w, int $h, int $orientation): string
    {
        if (!function_exists('exif_read_data')) $this->markTestSkipped('No exif extension.');
        ExifJpeg::write($this->dir . '/' . $name, $w, $h, $orientation);
        return $this->rel . '/' . $name;
    }

    /** Already a fine shape once upright — but Instagram ignores the flag, so it's still re-saved upright. */
    public function testFlaggedPhotoIsSentUprightEvenWithoutACrop(): void
    {
        $p   = $this->phonePhoto('phone.jpg', 1000, 800, 6);   // upright: 800×1000 = 4:5
        $out = social_prepare_image($p, 'insta');
        $this->assertNotSame($p, $out);
        $im = imagecreatefromjpeg($_SERVER['DOCUMENT_ROOT'] . $out);
        $this->assertSame([800, 1000], [imagesx($im), imagesy($im)]);
        [$x, $y] = ExifJpeg::upright(ExifJpeg::MARK_X, ExifJpeg::MARK_Y, 1000, 800, 6);
        $this->assertTrue(ExifJpeg::isRed($im, $x, $y), 'turned the way the browser shows it');
        imagedestroy($im);
    }

    /** The author draws the crop on the upright photo in the browser; it must cut the same part. */
    public function testSavedCropOfAFlaggedPhotoCutsTheUprightPhoto(): void
    {
        $p    = $this->phonePhoto('phone.jpg', 2000, 900, 6);   // upright: 900×2000
        $rect = ['x' => 0.5, 'y' => 0.0, 'w' => 0.5, 'h' => 0.28125, 'ar' => 0.8];   // top-right 450×563
        $out  = social_prepare_image($p, 'carousel', $rect);
        $im   = imagecreatefromjpeg($_SERVER['DOCUMENT_ROOT'] . $out);
        $this->assertSame([450, 563], [imagesx($im), imagesy($im)]);
        // The red block sits at the upright photo's top-right corner, so it's inside this crop.
        [$x, $y] = ExifJpeg::upright(ExifJpeg::MARK_X, ExifJpeg::MARK_Y, 2000, 900, 6);
        $this->assertTrue(ExifJpeg::isRed($im, $x - 450, $y));
        imagedestroy($im);
    }

    public function testPreviewGivesTheUprightSize(): void
    {
        $p  = $this->phonePhoto('phone.jpg', 2000, 900, 6);
        $pv = social_insta_preview(['image' => $p]);
        $this->assertSame([900, 2000], [$pv['post'][0]['w'], $pv['post'][0]['h']]);
    }

    // ── The carousel's shape follows its photos, or the shape the author picks ──

    /** The bug: landscape sticker photos went to Instagram cut to 4:5 portrait. */
    public function testLandscapePhotosMakeALandscapeCarouselUncut(): void
    {
        $a = $this->jpeg('s1.jpg', 1500, 1000);
        $b = $this->jpeg('s2.jpg', 1500, 1000);
        $c = $this->jpeg('s3.jpg', 1500, 1000);
        $r = social_fb_insta_request(['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => ''], ['src' => $c, 'caption' => '']]], 'insta');
        $this->assertCount(3, $r['urls']);
        foreach ([$a, $b, $c] as $i => $p) $this->assertSame(social_image_url($p), $r['urls'][$i], 'sent whole, not cut');
    }

    /** As in Instagram's app: the main (first) photo sets the shape. */
    public function testCarouselShapeIsTheMainPhotos(): void
    {
        $this->assertSame(1.5, social_carousel_aspect([[1500, 1000], [1500, 1000]]));
        $this->assertSame(0.8, social_carousel_aspect([[800, 1000], [1500, 1000]]));
        $this->assertSame(0.8, social_carousel_aspect([[900, 2000], [1600, 900]]), 'taller than 4:5 is still 4:5');
        $this->assertSame(1.91, social_carousel_aspect([[3000, 1000], [800, 1000]]));
        // Lafetki "rrrrr": a 3:4 main photo and two banners — the post goes 4:5, not a wide
        // strip that keeps only the middle of the main photo.
        $this->assertSame(0.8, social_carousel_aspect([[1200, 1600], [419, 222], [419, 222]]));
        $this->assertSame(1.0, social_carousel_aspect([[1000, 1000], [1500, 1000], [1500, 1000]]));
    }

    public function testCropLoss(): void
    {
        $this->assertEqualsWithDelta(0.0, social_crop_loss(1500, 1000, 1.5), 1e-9);
        $this->assertEqualsWithDelta(1 - 0.8 / 1.5, social_crop_loss(1500, 1000, 0.8), 1e-9);
    }

    /** Never refused: whatever the shapes, every photo goes out at the main photo's shape. */
    public function testMixedShapesAreSentNotRefused(): void
    {
        $a = $this->jpeg('tall.jpg', 750, 1000);
        $b = $this->jpeg('ban1.jpg', 1910, 1000);
        $c = $this->jpeg('ban2.jpg', 1910, 1000);
        $article = ['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => ''], ['src' => $c, 'caption' => '']]];
        $r = social_fb_insta_request($article, 'insta');
        $this->assertCount(3, $r['urls']);
        foreach ($r['urls'] as $u) {
            [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . rawurldecode(substr($u, strlen(SITE_URL))));
            $this->assertEqualsWithDelta(0.8, $w / $h, 0.01, $u);
        }
        $this->assertSame([0.8, 0.8, 0.8], array_column(social_insta_preview($article)['post'], 'ar'));
    }

    public function testThePickedShapeWins(): void
    {
        $a = $this->jpeg('w1.jpg', 1500, 1000);
        $b = $this->jpeg('w2.jpg', 1500, 1000);
        $two = ['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']]];
        foreach (['landscape' => 1.91, 'square' => 1.0, 'portrait' => 0.8] as $shape => $ar) {
            $pv = social_insta_preview($two + ['insta_shape' => $shape]);
            $this->assertSame($shape, $pv['shape']);
            $this->assertSame([$ar, $ar], array_column($pv['post'], 'ar'), $shape);
            $r = social_fb_insta_request($two + ['insta_shape' => $shape], 'insta');
            [$w, $h] = getimagesize($_SERVER['DOCUMENT_ROOT'] . rawurldecode(substr($r['urls'][0], strlen(SITE_URL))));
            $this->assertEqualsWithDelta($ar, $w / $h, 0.01, $shape);
        }
        // One photo too: Square makes it square instead of its own shape.
        $this->assertSame(1.0, social_insta_preview(['image' => $a, 'insta_shape' => 'square'])['post'][0]['ar']);
        $this->assertSame(1.5, social_insta_preview(['image' => $a])['post'][0]['ar']);
        // A Story stays 9:16 whatever the post's shape; nonsense falls back to auto.
        $this->assertSame(0.5625, social_insta_preview($two + ['insta_shape' => 'square'])['story'][0]['ar']);
        $this->assertSame('auto', social_insta_preview($two + ['insta_shape' => 'huge'])['shape']);
        $this->assertSame([1.5, 1.5], array_column(social_insta_preview($two + ['insta_shape' => ['x']])['post'], 'ar'));
    }

    public function testACropMadeForTheOldShapeIsIgnoredAfterPickingANewOne(): void
    {
        $a = $this->jpeg('w1.jpg', 1500, 1000);
        $b = $this->jpeg('w2.jpg', 1500, 1000);
        $article = ['image' => $a, 'photos' => [['src' => $a, 'caption' => ''], ['src' => $b, 'caption' => '']],
            'insta_crops' => ['post' => [$a => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'ar' => 1.5]]], 'insta_shape' => 'square'];
        $this->assertNull(social_insta_slides($article, 'post')[0]['rect']);
        unset($article['insta_shape']);
        $this->assertNotNull(social_insta_slides($article, 'post')[0]['rect']);
    }

    // ── A photo that doesn't fit the shape is shown whole on white, or fills if the author says so ──

    private function sent(string $url): string
    {
        return $_SERVER['DOCUMENT_ROOT'] . rawurldecode(substr($url, strlen(SITE_URL)));
    }

    /** Lafetki "rrrrr": a 3:4 main photo and two banners nearly 1.91:1. */
    public function testTheMainPhotoSetsTheShapeAndBannersGoWholeOnWhite(): void
    {
        $m  = $this->jpeg('main.jpg', 1200, 1600);
        $b1 = $this->jpeg('b1.jpg', 419, 222);
        $b2 = $this->jpeg('b2.jpg', 419, 222);
        $article = ['image' => $m, 'photos' => [['src' => $m, 'caption' => ''], ['src' => $b1, 'caption' => ''], ['src' => $b2, 'caption' => '']]];
        $slides = social_insta_slides($article, 'post');
        $this->assertSame(0.8, $slides[0]['ar']);
        $this->assertSame(['fill', 'whole', 'whole'], array_column($slides, 'fit'), 'the main photo loses 6%; the banners go whole');

        $r = social_fb_insta_request($article, 'insta');
        $this->assertSame([1200, 1500], array_slice(getimagesize($this->sent($r['urls'][0])), 0, 2));
        $this->assertMatchesRegularExpression('/b1-whole-[0-9a-f]{8}\.jpg$/', $r['urls'][1]);
        $im = imagecreatefromjpeg($this->sent($r['urls'][1]));
        $this->assertSame([419, 524], [imagesx($im), imagesy($im)]);   // 419 ÷ 0.8
        $top = imagecolorsforindex($im, imagecolorat($im, 200, 5));
        $this->assertGreaterThan(245, $top['red'], 'white above the banner');
        $mid = imagecolorsforindex($im, imagecolorat($im, 200, 262));
        $this->assertLessThan(20, $mid['red'], 'the banner itself (black) in the middle');
    }

    public function testTheAuthorCanSwitchEachPhotoBetweenWholeAndFill(): void
    {
        $m  = $this->jpeg('main.jpg', 1200, 1600);
        $b1 = $this->jpeg('b1.jpg', 1910, 1000);
        $article = ['image' => $m, 'photos' => [['src' => $m, 'caption' => ''], ['src' => $b1, 'caption' => '']],
                    'insta_shape' => 'landscape',
                    'insta_fit' => ['post' => [$m => 'fill', $b1 => 'whole']]];
        $this->assertSame(['fill', 'whole'], array_column(social_insta_slides($article, 'post'), 'fit'));
        $r = social_fb_insta_request($article, 'insta');
        $this->assertMatchesRegularExpression('/main-car-[0-9a-f]{8}\.jpg$/', $r['urls'][0]);
        [$w, $h] = getimagesize($this->sent($r['urls'][0]));
        $this->assertEqualsWithDelta(1.91, $w / $h, 0.01);
        // Nonsense values fall back to the default.
        $article['insta_fit']['post'][$m] = 'stretch';
        $this->assertSame('whole', social_insta_slides($article, 'post')[0]['fit']);
    }

    public function testAChosenCropMeansFillAndStoriesAlwaysFill(): void
    {
        $m = $this->jpeg('main.jpg', 1200, 1600);
        $b = $this->jpeg('b.jpg', 1910, 1000);
        $article = ['image' => $m, 'photos' => [['src' => $m, 'caption' => ''], ['src' => $b, 'caption' => '']],
            'insta_crops' => ['post' => [$m => ['x' => 0, 'y' => 0.2, 'w' => 1, 'h' => 1200 / 1.91 / 1600, 'ar' => 1.91]]]];
        $this->assertSame('fill', social_insta_slides($article, 'post')[0]['fit']);
        $this->assertSame('fill', social_insta_slides($article, 'story')[0]['fit']);
    }

    public function testAPhotoAloneKeepsItsShapeAndFills(): void
    {
        $m = $this->jpeg('main.jpg', 1200, 1600);
        $this->assertSame('fill', social_insta_slides(['image' => $m], 'post')[0]['fit']);
        $this->assertSame('whole', social_insta_slides(['image' => $m, 'insta_shape' => 'landscape'], 'post')[0]['fit']);
    }

    public function testFitValidation(): void
    {
        $m = $this->jpeg('main.jpg', 1200, 1600);
        $article = ['image' => $m, 'photos' => [['src' => $m, 'caption' => '']]];
        $this->assertSame(['ok' => true, 'src' => $m, 'fit' => 'whole'], social_fit_clean($article, ['src' => $m, 'fit' => 'whole']));
        $this->assertFalse(social_fit_clean($article, ['src' => $m, 'fit' => 'stretch'])['ok']);
        $this->assertFalse(social_fit_clean($article, ['src' => '/assets/images/other.jpg', 'fit' => 'fill'])['ok']);
        $this->assertFalse(social_fit_clean($article, ['src' => ['x'], 'fit' => 'fill'])['ok']);
    }
}
