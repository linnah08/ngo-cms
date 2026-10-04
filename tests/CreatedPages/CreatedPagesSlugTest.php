<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/** Addresses: built from the title, unique, and never one the site itself uses. */
final class CreatedPagesSlugTest extends CreatedPagesTestCase
{
    public static function titles(): array
    {
        return [
            'bulgarian'          => ['Нашата история', 'nashata-istoriya'],
            'щ ж ц ю я'          => ['Щастливо жълто цвете юли я', 'shtastlivo-zhalto-tsvete-yuli-ya'],
            'punctuation'        => ['  „Кои сме ние?“ — 2025!  ', 'koi-sme-nie-2025'],
            'english'            => ['Our Story', 'our-story'],
            'accents'            => ['Café Crème', 'cafe-creme'],
            'nothing usable'     => ['!!! ???', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function testSlugify(string $title, string $slug): void
    {
        $this->assertSame($slug, cpage_slugify($title));
    }

    public function testLongTitlesAreCutAtAWord(): void
    {
        $s = cpage_slugify(str_repeat('дълга дума ', 20));
        $this->assertLessThanOrEqual(60, strlen($s));
        $this->assertMatchesRegularExpression(CPAGE_SLUG_RE, $s);
        $this->assertStringEndsNotWith('-', $s);
    }

    public static function builtIn(): array
    {
        return [
            ['bg', 'za-nas'], ['bg', 'novini'], ['bg', 'magazin'], ['bg', 'kontakti'], ['bg', 'admin'],
            ['bg', 'assets'], ['bg', 'page'], ['bg', 'sitemap'], ['bg', 'en'], ['bg', 'about'], ['bg', 'home'],
            ['bg', 'donation'], ['bg', 'content'],
            ['en', 'news'], ['en', 'about'], ['en', 'shop'], ['en', 'contacts'], ['en', 'privacy-policy'],
            ['en', 'programs'], ['en', 'home'], ['en', 'admin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('builtIn')]
    public function testBuiltInAddressesAreNeverTaken(string $lang, string $slug): void
    {
        $this->assertNotNull(cpage_slug_error($lang, $slug));
        $this->assertNotSame($slug, cpage_unique_slug($lang, $slug));
    }

    public function testInvalidAddressesAreRefusedInPlainWords(): void
    {
        $this->assertSame('Попълнете адреса.', cpage_slug_error('bg', ''));
        foreach (['Golyama', 'ima space', 'двойно--', '-start', 'end-', 'a/b', '../x', 'кирилица'] as $bad) {
            $this->assertNotNull(cpage_slug_error('bg', $bad), $bad);
        }
        $this->assertNotNull(cpage_slug_error('bg', str_repeat('a', CPAGE_SLUG_MAX + 1)));
        $this->assertNull(cpage_slug_error('bg', 'nashata-istoriya-2'));
    }

    public function testCollisionsGetANumber(): void
    {
        $this->storyPage();
        $this->assertNotNull(cpage_slug_error('bg', 'nashata-istoriya'));
        $this->assertNull(cpage_slug_error('bg', 'nashata-istoriya', 'p_abcd1234'), 'a page may keep its own address');
        $this->assertSame('nashata-istoriya-2', cpage_unique_slug('bg', 'Нашата история'));
        $this->assertSame('our-story-2', cpage_unique_slug('en', 'Our story'));
        $this->assertSame('novini-2', cpage_unique_slug('bg', 'Новини'));
    }

    public function testAnEmptyTitleStillGetsAnAddress(): void
    {
        $this->assertSame('stranitsa', cpage_unique_slug('bg', '???'));
        $this->assertSame('page-2', cpage_unique_slug('en', '???'), '"page" itself is reserved');
    }

    public function testAddressSharedAcrossLanguagesIsFine(): void
    {
        // The same word in /x/ and /en/x/ is two different addresses.
        $this->storyPage();
        $this->assertNull(cpage_slug_error('en', 'nashata-istoriya'));
        $this->assertNull(cpage_slug_error('bg', 'our-story'));
    }
}
