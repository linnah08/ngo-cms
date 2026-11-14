<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class SocialPostTextTest extends TestCase
{
    private const URL = 'https://x/novini/a/';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    public function testArticleThatFitsIsTitleAndBodyWithoutLink(): void
    {
        $r = social_post_text('Заглавие', '<p>Едно.</p><p>Две.</p>', self::URL, 'fb');
        $this->assertSame("Заглавие\n\nЕдно.\n\nДве.", $r['text']);
        $this->assertFalse($r['cut']);
        $this->assertSame(social_text_length($r['text']), $r['length']);
    }

    public function testLinkedInUsesEnglishReadMoreWhenCut(): void
    {
        $r = social_post_text('Title', '<p>' . str_repeat('One more sentence here. ', 200) . '</p>', 'https://x/en/news/a/', 'linkedin');
        $this->assertTrue($r['cut']);
        $this->assertStringEndsWith("here. …\n\nRead more: https://x/en/news/a/", $r['text']);
        $this->assertSame(3000, $r['limit']);
        $short = social_post_text('Title', '<p>One.</p>', 'https://x/en/news/a/', 'linkedin');
        $this->assertStringNotContainsString('Read more', $short['text']);
    }

    public function testLongTextCutAtLastFullSentence(): void
    {
        $body = '';
        for ($i = 1; $i <= 300; $i++) $body .= "Изречение номер $i е тук. ";
        $r = social_post_text('Заглавие', '<p>' . $body . '</p>', self::URL, 'insta');
        $this->assertLessThanOrEqual(2200, $r['length']);
        $this->assertSame(social_text_length($r['text']), $r['length']);
        $this->assertTrue($r['cut']);
        $this->assertStringEndsWith("тук. …\n\nПрочетете повече: " . self::URL, $r['text']);
    }

    public function testNoSentenceEndCutsAtWord(): void
    {
        $body = trim(str_repeat('дума ', 600));
        $r = social_post_text('Заглавие', '<p>' . $body . '</p>', self::URL, 'insta');
        $this->assertTrue($r['cut']);
        $this->assertLessThanOrEqual(2200, $r['length']);
        $this->assertMatchesRegularExpression('/дума …\n\nПрочетете повече: /u', $r['text']);
        $this->assertDoesNotMatchRegularExpression('/дум …|дума[а-я]/u', $r['text']);
    }

    public function testCutDoesNotSplitAbbreviationMidWord(): void
    {
        $body = trim(str_repeat('Живеем на ул. Шипка и работим там. ', 80)) . ' ' . str_repeat('на ул. Шипка ', 100);
        for ($pad = 0; $pad < 12; $pad++) {
            $r = social_post_text('З' . str_repeat('я', $pad), '<p>' . $body . '</p>', self::URL, 'insta');
            $this->assertTrue($r['cut']);
            $this->assertDoesNotMatchRegularExpression('/ул\. …/u', $r['text']);
            $this->assertStringEndsWith("работим там. …\n\nПрочетете повече: " . self::URL, $r['text']);
        }
    }

    public function testBoldTextCountedAsTwoUnits(): void
    {
        $this->assertSame(2, social_text_length('𝗔'));
    }

    public function testFacebookNeverCuts(): void
    {
        $r = social_post_text('Заглавие', '<p>' . str_repeat('а', 20000) . '</p>', self::URL, 'fb');
        $this->assertFalse($r['cut']);
    }

    public function testEmptyArticleGivesEmptyText(): void
    {
        $r = social_post_text('', '', self::URL, 'fb');
        $this->assertSame('', $r['text']);
        $this->assertSame(0, $r['length']);
    }

    public function testHtmlToSocialTextKeepsParagraphsAndLists(): void
    {
        $this->assertSame("A\n\n• b", html_to_social_text('<p>A</p><ul><li>b</li></ul>'));
    }

    public function testPublicUrls(): void
    {
        $this->assertStringEndsWith('/novini/x/', article_public_url('x', 'bg'));
        $this->assertStringEndsWith('/en/news/y/', article_public_url('y', 'en'));
    }

    public function testCyrillicAddressStaysReadable(): void
    {
        $this->assertSame(SITE_URL . '/novini/новина-1/', article_public_url('новина-1', 'bg'));
        $this->assertStringNotContainsString('%', article_public_url('новина', 'bg'));
        $this->assertSame(SITE_URL . '/en/news/news-1/', article_public_url('news-1', 'en'));
    }

    /** A body whose longest fitting start ends exactly at $end, followed by $after. */
    private function cutAt(string $end, string $after): array
    {
        $tail   = " …\n\nПрочетете повече: " . self::URL;
        $budget = 2200 - social_text_length($tail);
        $head   = "Заглавие\n\nПърво изречение. ";
        $filler = 'Д' . str_repeat('д', $budget - mb_strlen($head) - mb_strlen(' ' . $end) - 1);
        $r = social_post_text('Заглавие', '<p>Първо изречение. ' . $filler . ' ' . $end . $after . str_repeat(' още', 100) . '</p>', self::URL, 'insta');
        $this->assertTrue($r['cut']);
        return $r;
    }

    public function testStopAtTheEndOfWhatFitsIsNoSentenceEndInsideAWord(): void
    {
        // "www." and "3." cut off from what follows them are not the end of a sentence.
        $r = $this->cutAt('www.', 'example.org и още текст.');
        $this->assertStringEndsWith("Първо изречение. …\n\nПрочетете повече: " . self::URL, $r['text']);
        $r = $this->cutAt('т. 3.', '5 от закона и още текст.');
        $this->assertStringEndsWith("Първо изречение. …\n\nПрочетете повече: " . self::URL, $r['text']);
    }

    public function testStopAtTheEndOfWhatFitsFollowedByASpaceEndsTheSentence(): void
    {
        $r = $this->cutAt('Край.', ' Още текст след края.');
        $this->assertStringEndsWith("Край. …\n\nПрочетете повече: " . self::URL, $r['text']);
    }

    public function testScheduleTextFromSavedArticle(): void
    {
        $bg = ['title' => 'Заглавие', 'content' => '<p>Едно.</p>', 'slug' => 'a'];
        $this->assertSame(
            social_post_text('Заглавие', '<p>Едно.</p>', SITE_URL . '/novini/a/', 'fb')['text'],
            social_schedule_text($bg, [], 'fb')
        );
        $en = ['title' => 'Title', 'content' => '<p>' . str_repeat('One more sentence here. ', 200) . '</p>'];
        $this->assertStringEndsWith('Read more: ' . SITE_URL . '/en/news/a-en/',
            social_schedule_text($bg + ['slug_en' => 'a-en'], $en, 'linkedin'));
    }

    public function testLinkedInScheduleRefusesWithoutEnBody(): void
    {
        $bg = ['title' => 'Заглавие', 'content' => '<p>Едно.</p>', 'slug' => 'a'];
        $this->assertSame('', social_schedule_text($bg, [], 'linkedin'));
        $this->assertSame('', social_schedule_text([], [], 'fb'));
        // An English title alone is no post — the same rule as the panel.
        $this->assertSame('', social_schedule_text($bg, ['title' => 'Title', 'content' => ''], 'linkedin'));
        $this->assertSame('', social_schedule_text($bg, ['title' => 'Title', 'content' => '<p>&nbsp;</p>'], 'linkedin'));
    }

    public function testTitleWithoutBodyIsNoPost(): void
    {
        foreach (['fb', 'insta'] as $ch) {
            $this->assertSame('', social_schedule_text(['title' => 'Заглавие', 'content' => '', 'slug' => 'a'], [], $ch), $ch);
            $this->assertSame('', social_schedule_text(['title' => 'Заглавие', 'content' => '<p><br></p>', 'slug' => 'a'], [], $ch), $ch);
        }
    }
}
