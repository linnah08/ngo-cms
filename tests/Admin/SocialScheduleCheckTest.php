<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * social_schedule_check(): every check a post must pass before it goes to Buffer.
 * The endpoints' dry run (`check`) and `schedule` both run it, so they can't drift —
 * and a post the dry run refuses never publishes a draft.
 */
#[Group('admin')]
final class SocialScheduleCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private static string $dir;
    private static string $src;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/social_images.php';
        $slug      = '_test-check-' . bin2hex(random_bytes(3));
        self::$dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/articles/' . $slug;
        self::$src = '/assets/images/articles/' . $slug . '/p.jpg';
        @mkdir(self::$dir, 0755, true);
        $im = imagecreatetruecolor(20, 20);
        imagejpeg($im, self::$dir . '/p.jpg');
        imagedestroy($im);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/p.jpg');
        @rmdir(self::$dir);
    }

    private function bg(array $over = []): array
    {
        return $over + ['title' => 'Заглавие', 'content' => '<p>Текст.</p>', 'slug' => 'a',
                        'photos' => [['src' => self::$src, 'caption' => '']]];
    }

    private function future(): string
    {
        return (new DateTimeImmutable('@' . (self::NOW + 3600)))->setTimezone(new DateTimeZone('Europe/Sofia'))->format('Y-m-d\TH:i');
    }

    private function check(string $channel, array $over = [], string $at = '', bool $story = false,
                           string $cid = 'C1', string $key = 'K1', array $en = []): array
    {
        return social_schedule_check($channel, $story, $at === '' ? $this->future() : $at, $cid, $key,
            $this->bg($over), $en, self::NOW);
    }

    public function testAGoodPostPasses(): void
    {
        $r = $this->check('fb');
        $this->assertTrue($r['ok']);
        $this->assertSame(self::NOW + 3600, $r['ts']);
        $this->assertTrue($this->check('insta')['ok']);
        $now = $this->check('fb', [], 'now');
        $this->assertTrue($now['ok']);
        $this->assertSame(self::NOW + 120, $now['ts']);
    }

    public function testTimeMustBeGivenAndInTheFuture(): void
    {
        $r = social_schedule_check('fb', false, '', 'C1', 'K1', $this->bg(), [], self::NOW);
        $this->assertSame('Изберете дата и час.', $r['error']);
        $past = (new DateTimeImmutable('@' . (self::NOW - 60)))->setTimezone(new DateTimeZone('Europe/Sofia'))->format('Y-m-d\TH:i');
        $this->assertSame('Датата трябва да е в бъдещето.', $this->check('fb', [], $past)['error']);
        $this->assertSame('Датата трябва да е в бъдещето.', $this->check('fb', [], 'not a date')['error']);
    }

    public function testChannelMustBeKnown(): void
    {
        $this->assertSame('Невалиден канал.', $this->check('')['error']);
        $this->assertSame('Невалиден канал.', $this->check('twitter')['error']);
    }

    public function testBufferMustBeSetUp(): void
    {
        $this->assertStringContainsString('Buffer Facebook Channel ID не е конфигуриран', $this->check('fb', [], '', false, '')['error']);
        $this->assertStringContainsString('Buffer Instagram Channel ID', $this->check('insta', [], '', false, '')['error']);
        $this->assertStringContainsString('Buffer API ключ не е конфигуриран', $this->check('fb', [], '', false, 'C1', '')['error']);
    }

    public function testTextIsNeededExceptForAStory(): void
    {
        $noText = 'Статията няма текст за публикуване. Напишете текста ѝ и опитайте отново.';
        $this->assertSame($noText, $this->check('fb', ['content' => ''])['error']);
        $this->assertSame($noText, $this->check('insta', ['content' => '<p>&nbsp;</p>'])['error'], 'a title alone is no post');
        $this->assertSame($noText, $this->check('fb', ['title' => '', 'content' => ''])['error']);
        $this->assertTrue($this->check('insta', ['content' => ''], '', true)['ok'], 'a Story has no caption');
    }

    public function testInstagramNeedsAPhoto(): void
    {
        $err = 'Instagram изисква снимка. Добавете снимка към статията преди да планирате.';
        $this->assertSame($err, $this->check('insta', ['photos' => []])['error']);
        $this->assertSame($err, $this->check('insta', ['photos' => [['src' => '/assets/images/nope/missing.jpg', 'caption' => '']]])['error']);
        $this->assertSame($err, $this->check('insta', ['photos' => []], '', true)['error'], 'a Story too');
        $this->assertTrue($this->check('fb', ['photos' => []])['ok'], 'Facebook posts without a photo');
    }

    public function testLinkedInNeedsTheEnglishBody(): void
    {
        $noEn = 'Няма английски текст — преведете статията.';
        $this->assertSame($noEn, $this->check('linkedin')['error']);
        $this->assertSame($noEn, $this->check('linkedin', [], '', false, 'C1', 'K1', ['title' => 'Title', 'content' => ''])['error']);
        $this->assertTrue($this->check('linkedin', [], '', false, 'C1', 'K1', ['title' => 'Title', 'content' => '<p>Body.</p>'])['ok']);
        $this->assertStringContainsString('Buffer LinkedIn Channel ID', $this->check('linkedin', [], '', false, '')['error']);
    }

    /** Both endpoints run the one check, in the dry run and before calling Buffer. */
    public function testBothEndpointsShareTheCheck(): void
    {
        foreach (['social-ajax', 'linkedin-ajax'] as $name) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/' . $name . '.php');
            $this->assertSame(2, substr_count($src, 'social_schedule_check('), $name . ': check and schedule');
            $this->assertStringContainsString("if (\$action === 'check')", $src, $name);
            $this->assertStringNotContainsString('Датата трябва да е в бъдещето', $src, $name . ': no second copy of the rules');
            $this->assertStringNotContainsString('Изберете дата и час', $src, $name);
            $start = strpos($src, "if (\$action === 'check')");
            $dry   = substr($src, $start, strpos($src, "\n}\n", $start) - $start);
            $this->assertStringContainsString('social_schedule_check(', $dry, $name);
            $this->assertStringNotContainsString('curl_', $dry, $name . ': the dry run calls nothing');
            $this->assertStringNotContainsString('save_json', $dry, $name . ': the dry run writes nothing');
            $this->assertStringNotContainsString('social_fb_insta_request', $dry, $name . ': no photos are made');
            $this->assertLessThan(strpos($src, "if (!\$slug)"), $start, $name . ': a new article needs no address for the dry run');
        }
    }

    public function testStorySendsNoText(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/social-ajax.php');
        $this->assertStringContainsString("\$post_text = \$is_story ? '' : social_schedule_text(\$data, [], \$channel);", $src);
    }
}
