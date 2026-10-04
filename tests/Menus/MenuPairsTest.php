<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once dirname(__DIR__, 2) . '/includes/menus.php';

/**
 * Admin → Менюта edits one list of BG/EN pairs; the EN menu always mirrors
 * the BG one. These cover the shared address map, pairing an existing
 * menus.json into rows, and the shape that is saved.
 */
final class MenuPairsTest extends TestCase
{
    public static function urls(): array
    {
        return [
            'static page'          => ['/kontakti/',              '/en/contacts/'],
            'no trailing slash'    => ['/za-nas',                 '/en/about'],
            'news article slug'    => ['/novini/nashata-istoriya/', '/en/news/nashata-istoriya/'],
            'shop'                 => ['/magazin/',               '/en/shop/'],
            'home'                 => ['/',                       '/en/'],
            'anchor kept'          => ['/kak-da-pomogna/#dari',   '/en/how-to-help/#dari'],
            'query kept'           => ['/novini/?page=2',         '/en/news/?page=2'],
            'unknown site page'    => ['/nyakakva-stranitsa/',    '/en/nyakakva-stranitsa/'],
            'unknown, no slash'    => ['/story',                  '/en/story'],
            'already english'      => ['/en/about/',              '/en/about/'],
            'external link'        => ['https://example.org/x',   'https://example.org/x'],
            'protocol-relative'    => ['//example.org/x',         '//example.org/x'],
            'mailto'               => ['mailto:info@example.org', 'mailto:info@example.org'],
            'empty'                => ['',                        ''],
        ];
    }

    #[DataProvider('urls')]
    public function testEnAddressComesFromTheSharedMap(string $bg, string $en): void
    {
        $this->assertSame($en, menu_en_url($bg));
    }

    public function testUnknownPagesFollowTheLanguageSwitcher(): void
    {
        foreach (['/story', '/principles/', '/some/deep/page'] as $p) {
            $this->assertSame(path_bg_to_en($p), menu_en_url($p), $p);
        }
    }

    public function testAnEnAddressCopiedUnchangedByV022IsCorrectedOnOpen(): void
    {
        $rows = menu_rows_from_section([
            'bg' => [['url' => '/story', 'label' => 'История'], ['url' => '/donation', 'label' => 'Дари'],
                     ['url' => 'https://example.org', 'label' => 'Външен']],
            'en' => [['url' => '/story', 'label' => 'Our story'], ['url' => '/en/donation', 'label' => 'Donate'],
                     ['url' => 'https://example.org', 'label' => 'External']],
        ]);
        $this->assertSame('/en/story', $rows[0]['url_en'], 'the stale copy is replaced');
        $this->assertSame('Our story', $rows[0]['label_en'], 'the EN text is kept');
        $this->assertSame('/en/donation', $rows[1]['url_en']);
        $this->assertSame('https://example.org', $rows[2]['url_en'], 'external links are untouched');
    }

    public function testLanguageSwitcherUsesTheSameMap(): void
    {
        $this->assertSame('/en/news/abc/', path_bg_to_en('/novini/abc/'));
        $this->assertSame('/novini/abc/', path_en_to_bg('/en/news/abc/'));
        $this->assertSame('/', path_en_to_bg('/en/'));
        $this->assertSame('/en/nyakakva/', path_bg_to_en('/nyakakva/'), 'the switcher still prefixes unknown pages');
    }

    public function testKnownPageNamesComeFromTheSiteStrings(): void
    {
        $this->assertSame(t_or('nav.contacts', '', '', 'en'), menu_known_label_en('Контакти'));
        $this->assertSame(t_or('nav.shop', '', '', 'en'), menu_known_label_en(' магазин '));
        $this->assertNull(menu_known_label_en('Нещо съвсем непознато'));
    }

    public function testExistingMenuPairsByAddressThenPosition(): void
    {
        $rows = menu_rows_from_section([
            'bg' => [
                ['url' => '/za-nas/',   'label' => 'За нас'],
                ['url' => '/kontakti/', 'label' => 'Контакти'],
                ['url' => '/blog/',     'label' => 'Блог'],
            ],
            'en' => [
                ['url' => '/en/contacts/', 'label' => 'Contact us'], // address match, different position
                ['url' => '/en/about',     'label' => 'About'],      // matches without the trailing slash
                ['url' => '/en/diary/',    'label' => 'Diary'],      // no address match → by position
            ],
        ]);

        $this->assertSame([
            ['label_bg' => 'За нас',   'url_bg' => '/za-nas/',   'label_en' => 'About',      'url_en' => '/en/about'],
            ['label_bg' => 'Контакти', 'url_bg' => '/kontakti/', 'label_en' => 'Contact us', 'url_en' => '/en/contacts/'],
            ['label_bg' => 'Блог',     'url_bg' => '/blog/',     'label_en' => 'Diary',      'url_en' => '/en/diary/'],
        ], $rows);
    }

    public function testUnpairableEnglishItemsAreKeptAsRowsWithAnEmptyBulgarianSide(): void
    {
        $rows = menu_rows_from_section([
            'bg' => [['url' => '/kontakti/', 'label' => 'Контакти']],
            'en' => [
                ['url' => '/en/team/',     'label' => 'Our team'],
                ['url' => '/en/contacts/', 'label' => 'Contacts'],
                ['url' => '/en/press/',    'label' => 'Press'],
            ],
        ]);

        $this->assertCount(3, $rows, 'no English item is lost');
        $this->assertSame(['label_bg' => '', 'url_bg' => '', 'label_en' => 'Our team', 'url_en' => '/en/team/'], $rows[0]);
        $this->assertSame('Контакти', $rows[1]['label_bg']);
        $this->assertSame('Contacts', $rows[1]['label_en']);
        $this->assertSame(['label_bg' => '', 'url_bg' => '', 'label_en' => 'Press', 'url_en' => '/en/press/'], $rows[2]);

        // Saving those rows unchanged is refused — never silently dropped.
        $saved = menu_section_from_rows($rows);
        $this->assertArrayHasKey('label_bg', $saved['errors'][0]);
        $this->assertArrayHasKey('label_bg', $saved['errors'][2]);
        $this->assertArrayNotHasKey(1, $saved['errors']);
        $this->assertCount(3, $saved['rows'], 'the rows come back to the form with their English text');
    }

    public function testBulgarianItemWithoutEnglishTwinGetsItsEnglishSideFilled(): void
    {
        $rows = menu_rows_from_section([
            'bg' => [
                ['url' => '/kontakti/', 'label' => 'Контакти'],
                ['url' => '/blog/',     'label' => 'Блог'],
            ],
            'en' => [],
        ]);
        $this->assertSame(menu_known_label_en('Контакти'), $rows[0]['label_en']);
        $this->assertSame('/en/contacts/', $rows[0]['url_en']);
        $this->assertSame('Блог', $rows[1]['label_en'], 'unknown name: the BG label stands in');
        $this->assertTrue(menu_label_untranslated($rows[1]['label_bg'], $rows[1]['label_en']), 'and the row says it is not translated');
        $this->assertFalse(menu_label_untranslated('Контакти', 'Contacts'));
    }

    public function testSaveWritesEqualBgAndEnListsInTheSameOrder(): void
    {
        $r = menu_section_from_rows([
            ['label_bg' => ' Новини ', 'url_bg' => '/novini/', 'label_en' => 'News', 'url_en' => '/en/news/'],
            ['label_bg' => '', 'url_bg' => '', 'label_en' => '', 'url_en' => ''], // empty row: ignored
            ['label_bg' => 'Блог', 'url_bg' => '/blog/', 'label_en' => '', 'url_en' => ''],
            ['label_bg' => 'Партньор', 'url_bg' => 'https://example.org', 'label_en' => 'Partner <b>x</b>', 'url_en' => ''],
        ]);

        $this->assertSame([], $r['errors']);
        $this->assertSame([
            'bg' => [
                ['url' => '/novini/', 'label' => 'Новини'],
                ['url' => '/blog/', 'label' => 'Блог'],
                ['url' => 'https://example.org', 'label' => 'Партньор'],
            ],
            'en' => [
                ['url' => '/en/news/', 'label' => 'News'],
                ['url' => '/en/blog/', 'label' => 'Блог'],
                ['url' => 'https://example.org', 'label' => 'Partner x'],
            ],
        ], $r['section']);
    }

    public function testBadAddressesAreRefusedWithAFieldError(): void
    {
        $r = menu_section_from_rows([
            ['label_bg' => 'X', 'url_bg' => 'javascript:alert(1)', 'label_en' => 'X', 'url_en' => ''],
            ['label_bg' => 'Y', 'url_bg' => '', 'label_en' => 'Y', 'url_en' => '/en/y/'],
            ['label_bg' => 'Z', 'url_bg' => '/z/', 'label_en' => 'Z', 'url_en' => 'javascript:x'],
        ]);
        $this->assertArrayHasKey('url_bg', $r['errors'][0]);
        $this->assertArrayHasKey('url_bg', $r['errors'][1]);
        $this->assertArrayHasKey('url_en', $r['errors'][2]);
    }

    #[RunInSeparateProcess]
    public function testEverySavedItemShowsInTheEnglishHeader(): void
    {
        $file   = CONTENT_PATH . '/menus.json';
        $backup = is_file($file) ? (string) file_get_contents($file) : null;
        try {
            $menus = load_json($file);
            $menus['header'] = menu_section_from_rows([
                ['label_bg' => 'Пробна връзка', 'url_bg' => '/kontakti/', 'label_en' => '', 'url_en' => ''],
                ['label_bg' => 'Втора', 'url_bg' => '/za-nas/', 'label_en' => 'Second', 'url_en' => ''],
            ])['section'];
            save_json($file, $menus);

            $_SERVER['REQUEST_URI'] = '/en/';
            $page_title = 'x';
            ob_start();
            require dirname(__DIR__, 2) . '/templates/header.php';
            $html = (string) ob_get_clean();

            $nav = substr($html, (int) strpos($html, 'id="siteNav"'));
            $this->assertStringContainsString('href="/en/contacts/"', $nav, 'the item with no EN text still appears in English');
            $this->assertStringContainsString('href="/en/about/"', $nav);
            $this->assertStringContainsString('Second', $nav);
        } finally {
            if ($backup !== null) file_put_contents($file, $backup); else @unlink($file);
        }
    }
}
