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
