<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/**
 * Where created pages meet the rest of the site: the language switcher, the
 * menus, the sitemap, and the release ZIP (which must never carry them).
 */
final class CreatedPagesSiteTest extends CreatedPagesTestCase
{
    // ── Language switcher ────────────────────────────────────────────────────

    public function testLanguageSwitcherPairsACreatedPagesAddresses(): void
    {
        $this->storyPage();
        $this->assertSame('/en/our-story/', path_bg_to_en('/nashata-istoriya/'));
        $this->assertSame('/nashata-istoriya/', path_en_to_bg('/en/our-story/'));
        $this->assertSame('/en/our-story', path_bg_to_en('/nashata-istoriya'));
    }

    public function testDraftsArePairedTooSoAdminsCanSwitchLanguage(): void
    {
        $this->storyPage('draft');
        $this->assertSame('/en/our-story/', path_bg_to_en('/nashata-istoriya/'));
    }

    public function testBuiltInPairsAreUnchanged(): void
    {
        $this->storyPage();
        $this->assertSame('/en/about/', path_bg_to_en('/za-nas/'));
        $this->assertSame('/en/news/x/', path_bg_to_en('/novini/x/'));
        $this->assertSame('/kontakti/', path_en_to_bg('/en/contacts/'));
        $this->assertSame('/en/unknown/', path_bg_to_en('/unknown/'));
        // A page file claiming a built-in address never overrides the built-in pair.
        $this->putPage(['id' => 'p_clash000', 'status' => 'published', 'slug_bg' => 'za-nas', 'slug_en' => 'clash']);
        $this->assertSame('/en/about/', path_bg_to_en('/za-nas/'));
    }

    // ── Menus ────────────────────────────────────────────────────────────────

    public function testMenusHideLinksToDraftsAndMissingPages(): void
    {
        $this->storyPage('draft');
        $this->putPage(['id' => 'p_live0000', 'status' => 'published', 'slug_bg' => 'sabitiya', 'slug_en' => 'events']);
        $items = [
            0 => ['label' => 'Draft',      'url' => '/nashata-istoriya/'],
            1 => ['label' => 'Draft EN',   'url' => '/en/our-story'],
            2 => ['label' => 'Published',  'url' => '/sabitiya/'],
            3 => ['label' => 'Deleted',    'url' => '/iztrita-stranitsa/'],
            4 => ['label' => 'Built-in',   'url' => '/za-nas/'],
            5 => ['label' => 'Shop',       'url' => '/magazin/'],
            6 => ['label' => 'Article',    'url' => '/novini/nyakakva-statiya/'],
            7 => ['label' => 'External',   'url' => 'https://example.org/x/'],
            8 => ['label' => 'Anchor',     'url' => '#donate'],
            9 => ['label' => 'Home',       'url' => '/'],
            10 => ['label' => 'EN home',   'url' => '/en/'],
            11 => ['label' => 'Query',     'url' => '/sabitiya/?a=1#b'],
            12 => ['label' => 'Redirected', 'url' => '/about/'],
            13 => 'not an item',
        ];
        $shown = menu_public_items($items);
        $this->assertSame([2, 4, 5, 6, 7, 8, 9, 10, 11, 12], array_keys($shown), 'keys are kept for the on-page editor');
    }

    public function testPublishingAPageShowsItsMenuLink(): void
    {
        $this->storyPage('draft');
        $items = [['label' => 'Story', 'url' => '/nashata-istoriya/']];
        $this->assertSame([], menu_public_items($items));
        cpage_set_status('p_abcd1234', 'published');
        $this->assertCount(1, menu_public_items($items));
    }

    public function testHeaderAndFooterFilterTheirMenus(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("menu_public_items(is_array(\$_menus['header'][\$lang]", (string) file_get_contents($root . '/templates/header.php'));
        $footer = (string) file_get_contents($root . '/templates/footer.php');
        $this->assertStringContainsString("menu_public_items(is_array(\$_fmenus['footer_nav'][\$lang]", $footer);
        $this->assertStringContainsString("menu_public_items(is_array(\$_fmenus['footer_help'][\$lang]", $footer);
    }

    public function testMenuLinksFollowAnAddressChange(): void
    {
        // cpage_menus_follow() edits content/menus.json — keep the site's own copy intact.
        $file   = CONTENT_PATH . '/menus.json';
        $backup = is_file($file) ? file_get_contents($file) : null;
        try {
            file_put_contents($file, json_encode(['header' => [
                'bg' => [['label' => 'И', 'url' => '/stara/#x'], ['label' => 'Д', 'url' => '/drugo/']],
                'en' => [['label' => 'S', 'url' => '/en/old']],
            ]]));
            $old = ['slug_bg' => 'stara', 'slug_en' => 'old'];
            $this->assertTrue(cpage_menus_follow($old, ['slug_bg' => 'nova', 'slug_en' => 'new']));
            $m = json_decode((string) file_get_contents($file), true);
            $this->assertSame('/nova/#x', $m['header']['bg'][0]['url']);
            $this->assertSame('/drugo/', $m['header']['bg'][1]['url']);
            $this->assertSame('/en/new', $m['header']['en'][0]['url']);
        } finally {
            $backup !== null ? file_put_contents($file, $backup) : @unlink($file);
        }
    }

    // ── Sitemap ──────────────────────────────────────────────────────────────

    public function testSitemapListsPublishedPagesOnly(): void
    {
        $this->storyPage();
        $this->putPage(['id' => 'p_draft000', 'status' => 'draft', 'slug_bg' => 'chernova', 'slug_en' => 'draft-page']);
        [$xml, $code] = $this->request('sitemap.php', '/sitemap.xml');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('/nashata-istoriya/</loc>', $xml);
        $this->assertStringContainsString('/en/our-story/</loc>', $xml);
        $this->assertStringNotContainsString('chernova', $xml);
        $this->assertStringNotContainsString('draft-page', $xml);
    }

    // ── Release ZIP ──────────────────────────────────────────────────────────

    public function testReleaseZipNeverCarriesCreatedPages(): void
    {
        if (trim((string) shell_exec('command -v zip')) === '') $this->markTestSkipped('zip is not installed');
        $root = dirname(__DIR__, 2);
        $tree = sys_get_temp_dir() . '/om-cpzip-' . bin2hex(random_bytes(4));
        mkdir($tree . '/content/pages', 0755, true);
        mkdir($tree . '/assets/images/pages/created/p_abcd1234', 0755, true);
        mkdir($tree . '/assets/images/pages/home', 0755, true);
        file_put_contents($tree . '/index.php', '<?php');
        file_put_contents($tree . '/content/pages/p_abcd1234.json', '{}');
        file_put_contents($tree . '/assets/images/pages/created/p_abcd1234/a.jpg', 'x');
        file_put_contents($tree . '/assets/images/pages/home/b.jpg', 'x');
        try {
            $zip = $tree . '/out.zip';
            exec('cd ' . escapeshellarg($tree) . ' && bash ' . escapeshellarg($root . '/tests/release-gate/build-zip.sh')
                . ' ' . escapeshellarg($zip) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $z = new ZipArchive();
            $this->assertTrue($z->open($zip) === true);
            $names = [];
            for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
            $z->close();
            $this->assertContains('index.php', $names);
            $this->assertContains('assets/images/pages/home/b.jpg', $names);
            foreach ($names as $n) {
                $this->assertStringStartsNotWith('content/pages/', $n);
                $this->assertStringStartsNotWith('assets/images/pages/created/', $n);
            }
            $sums = json_decode((string) file_get_contents($tree . '/checksums.json'), true);
            $this->assertArrayHasKey('index.php', $sums);
            $this->assertArrayNotHasKey('content/pages/p_abcd1234.json', $sums);
            $this->assertArrayNotHasKey('assets/images/pages/created/p_abcd1234/a.jpg', $sums);
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    }

    public function testCreatedPagesAreNotInGit(): void
    {
        $ignore = (string) file_get_contents(dirname(__DIR__, 2) . '/.gitignore');
        $this->assertStringContainsString("\n/content/pages/\n", $ignore);
        $this->assertStringContainsString("\n/assets/images/pages/created/\n", $ignore);
    }
}
