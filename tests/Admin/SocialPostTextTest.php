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

    public function testShortArticleIsTitleBodyAndLink(): void
    {
        $r = social_post_text('Заглавие', '<p>Едно.</p><p>Две.</p>', self::URL, 'fb');
        $this->assertSame("Заглавие\n\nЕдно.\n\nДве.\n\nПрочетете повече: https://x/novini/a/", $r['text']);
        $this->assertFalse($r['cut']);
    }

    public function testLinkedInUsesEnglishReadMore(): void
    {
        $r = social_post_text('Title', '<p>One.</p>', 'https://x/en/news/a/', 'linkedin');
        $this->assertStringEndsWith('Read more: https://x/en/news/a/', $r['text']);
        $this->assertSame(3000, $r['limit']);
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
}
