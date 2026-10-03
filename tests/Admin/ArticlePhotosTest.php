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

    /** Review I2: a photo already on the post stays when its file is missing on this machine. */
    public function testStoredPhotoWhoseFileIsMissingIsKeptOnSave(): void
    {
        $none = static fn(string $p): bool => false;
        $r = article_photos_from_post([
            'photos_present' => '1',
            'photo_src'      => ['/assets/images/articles/old.jpg', '/assets/images/articles/new-but-missing.jpg'],
            'photo_main'     => '0',
        ], $none, ['/assets/images/articles/old.jpg']);
        $this->assertSame(['/assets/images/articles/old.jpg'], array_column($r['bg'], 'src'));
        $this->assertSame('/assets/images/articles/old.jpg', $r['image']);
    }

    /** Review I2: the editor lists stored photos even when the file is missing, so nothing drops silently. */
    public function testEditorListIncludesMissingFilesButNotBadPaths(): void
    {
        $a = ['image' => '/assets/images/articles/gone.jpg', 'photos' => [
            ['src' => '/assets/images/articles/gone.jpg', 'caption' => 'x'],
            ['src' => '/etc/passwd', 'caption' => ''],
        ]];
        $this->assertSame(['/assets/images/articles/gone.jpg'], array_column(article_photos_for_editor($a), 'src'));
    }

    /** Review I4: "📷 Replace" must actually write the post file and swap the main photo. */
    public function testInlineSaveWritesTheFileAndSwapsTheMainPhoto(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'art') . '.json';
        file_put_contents($file, json_encode(['title' => 'T', 'image' => '/assets/images/articles/a.jpg', 'photos' => [
            ['src' => '/assets/images/articles/a.jpg', 'caption' => 'A'], ['src' => '/assets/images/articles/b.jpg', 'caption' => 'B']]]));
        $ok = article_inline_save($file, 'bg', ['title' => ['bg' => ' Ново '], 'image' => ['bg' => '/assets/images/articles/n.jpg']], self::all());
        $saved = json_decode((string) file_get_contents($file), true);
        unlink($file);
        $this->assertTrue($ok);
        $this->assertSame('Ново', $saved['title']);
        $this->assertSame('/assets/images/articles/n.jpg', $saved['image']);
        $this->assertSame(['/assets/images/articles/n.jpg', '/assets/images/articles/b.jpg'], array_column($saved['photos'], 'src'));
        $this->assertSame('A', $saved['photos'][0]['caption']);
    }

    public function testInlineSaveRefusesABadImagePathAndReportsAMissingFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'art') . '.json';
        file_put_contents($file, json_encode(['image' => '/assets/images/articles/a.jpg']));
        article_inline_save($file, 'bg', ['image' => ['bg' => '/assets/images/../../config.php']], self::all());
        $saved = json_decode((string) file_get_contents($file), true);
        unlink($file);
        $this->assertSame('/assets/images/articles/a.jpg', $saved['image']);
        $this->assertFalse(article_inline_save('/nonexistent/dir/x.json', 'bg', ['title' => ['bg' => 'x']], self::all()));
    }
}
