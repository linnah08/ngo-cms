<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/** content/pages/<id>.json: create, read, list, change, delete — and nothing outside that folder. */
final class CreatedPagesStoreTest extends CreatedPagesTestCase
{
    public function testCreateMakesADraftWithAddressesFromTheTitles(): void
    {
        $r = cpage_create('Нашата история', 'Our story');
        $this->assertTrue($r['ok']);
        $p = $r['page'];
        $this->assertMatchesRegularExpression(CPAGE_ID_RE, $p['id']);
        $this->assertSame('draft', $p['status']);
        $this->assertSame('nashata-istoriya', $p['slug_bg']);
        $this->assertSame('our-story', $p['slug_en']);
        $this->assertSame([], $p['sections']);
        $this->assertFileExists($this->dir . '/' . $p['id'] . '.json');
        $this->assertSame($p['id'], cpage_get($p['id'])['id']);
        // Drafts are unreadable over the web even where content/ is not closed.
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->dir . '/.htaccess'));
    }

    public function testCreateNeedsATitleAndStripsMarkup(): void
    {
        $this->assertFalse(cpage_create('   ')['ok']);
        $p = cpage_create('<b>Проекти</b>  <script>x</script>за деца')['page'];
        $this->assertSame('Проекти xза деца', $p['title_bg']);
    }

    public function testCreateWithoutEnglishTitleBuildsTheEnglishAddressFromTheBulgarianOne(): void
    {
        $p = cpage_create('Доброволци')['page'];
        $this->assertSame('dobrovoltsi', $p['slug_bg']);
        $this->assertSame('dobrovoltsi', $p['slug_en']);
    }

    public function testAWantedAddressIsUsedOnlyWhenFree(): void
    {
        $this->assertSame('istoriya', cpage_create('Нещо', 'Thing', 'istoriya')['page']['slug_bg']);
        $this->assertSame('neshto-drugo', cpage_create('Нещо друго', 'Other', 'istoriya')['page']['slug_bg']);
        $this->assertSame('vtoro', cpage_create('Второ', 'Second', 'za-nas')['page']['slug_bg'], 'a built-in address is never taken');
    }

    public function testBySlugFindsPagesPerLanguage(): void
    {
        $this->storyPage();
        $this->assertSame('p_abcd1234', cpage_by_slug('bg', 'nashata-istoriya')['id']);
        $this->assertSame('p_abcd1234', cpage_by_slug('en', 'our-story')['id']);
        $this->assertNull(cpage_by_slug('en', 'nashata-istoriya'));
        $this->assertNull(cpage_by_slug('bg', ''));
        $this->assertNull(cpage_by_slug('bg', '../p_abcd1234'));
    }

    public static function badIds(): array
    {
        return [['../home'], ['p_abcd123'], ['p_ABCD1234'], ['p_abcd1234/..'], ['p_abcd1234.json'], [''], ["p_abcd1234\0"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function testOnlyPageIdsReachAFile(string $id): void
    {
        $this->assertNull(cpage_file($id));
        $this->assertNull(cpage_get($id));
        $this->assertFalse(cpage_update($id, fn($p) => $p)['ok']);
        $this->assertFalse(cpage_delete($id));
    }

    public function testHomeTargetRefusesAnythingButAPageId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        home_target_page('../../config');
    }

    public function testListSkipsDamagedAndForeignFiles(): void
    {
        $this->storyPage();
        file_put_contents($this->dir . '/p_bad00000.json', '{not json');
        file_put_contents($this->dir . '/p_other000.json', json_encode(['id' => 'p_someone0']));   // id ≠ file name
        file_put_contents($this->dir . '/notes.json', json_encode(['id' => 'notes']));
        cpage_cache_reset();
        $this->assertSame(['p_abcd1234'], array_keys(cpage_all()));
    }

    public function testNormalizeDropsInvalidAddressesAndUnknownStatus(): void
    {
        $this->putPage(['id' => 'p_aaaa0000', 'status' => 'live', 'slug_bg' => '../etc', 'slug_en' => 'Our Story']);
        $p = cpage_get('p_aaaa0000');
        $this->assertSame('draft', $p['status']);
        $this->assertSame('', $p['slug_bg']);
        $this->assertSame('', $p['slug_en']);
    }

    public function testStatusChangesAndPublishingNeedsAddresses(): void
    {
        $p = cpage_create('Събития', 'Events')['page'];
        $r = cpage_set_status($p['id'], 'published');
        $this->assertTrue($r['ok']);
        $this->assertSame('published', cpage_get($p['id'])['status']);
        $this->assertSame(1, cpage_get($p['id'])['rev']);
        $this->assertSame('draft', cpage_set_status($p['id'], 'draft')['page']['status']);

        $this->putPage(['id' => 'p_noaddr00']);
        $this->assertFalse(cpage_set_status('p_noaddr00', 'published')['ok']);
    }

    public function testSettingsValidateTitleAndAddresses(): void
    {
        $a = cpage_create('Първа', 'First')['page'];
        $b = cpage_create('Втора', 'Second')['page'];

        $r = cpage_save_settings($b['id'], ['title_bg' => '', 'title_en' => 'X', 'slug_bg' => $a['slug_bg'], 'slug_en' => 'Bad Slug!']);
        $this->assertFalse($r['ok']);
        $this->assertSame(['title_bg', 'slug_bg', 'slug_en'], array_keys($r['errors']));

        $r = cpage_save_settings($b['id'], ['title_bg' => 'Втора', 'title_en' => 'Second', 'slug_bg' => '/Nova-Vtora/', 'slug_en' => 'second']);
        $this->assertTrue($r['ok']);
        $this->assertSame('nova-vtora', cpage_get($b['id'])['slug_bg']);
        // Its own address is not a clash with itself.
        $this->assertTrue(cpage_save_settings($b['id'], ['title_bg' => 'Втора', 'slug_bg' => 'nova-vtora', 'slug_en' => 'second'])['ok']);
    }

    public function testDeleteRemovesTheFile(): void
    {
        $p = cpage_create('За триене')['page'];
        $this->assertTrue(cpage_delete($p['id']));
        $this->assertNull(cpage_get($p['id']));
        $this->assertFileDoesNotExist($this->dir . '/' . $p['id'] . '.json');
    }

    public function testSectionEditorSavesIntoThePageAndKeepsItsSettings(): void
    {
        $this->storyPage('draft');
        home_target_page('p_abcd1234');
        $loaded = home_load();
        $this->assertTrue($loaded['exists']);
        $this->assertCount(1, $loaded['doc']['sections'], 'no front-page blocks are added to a created page');
        $doc = $loaded['doc'];
        $doc['sections'][0]['fields']['heading']['bg'] = 'Ново';
        $this->assertTrue(home_save($doc, (int) $doc['rev'])['ok']);

        $p = cpage_get('p_abcd1234');
        $this->assertSame('Ново', $p['sections'][0]['fields']['heading']['bg']);
        $this->assertSame('nashata-istoriya', $p['slug_bg']);
        $this->assertSame('draft', $p['status']);
    }

    public function testSectionEditorNeverWritesAnotherDocumentIntoAPage(): void
    {
        $this->storyPage();
        home_target_page('p_abcd1234');
        $this->assertFalse(home_save(['id' => 'p_other000', 'rev' => 0, 'sections' => []], 0)['ok']);
        $this->assertSame('nashata-istoriya', cpage_get('p_abcd1234')['slug_bg']);
    }

    public function testSectionEditorDoesNotBringBackADeletedPage(): void
    {
        home_target_page('p_gone0000');
        $this->assertSame('gone', home_save(['id' => 'p_gone0000', 'rev' => 0, 'sections' => []], 0)['error']);
        $this->assertFileDoesNotExist($this->dir . '/p_gone0000.json');
    }

    public function testOnPageEditingSavesIntoThePage(): void
    {
        $this->storyPage();
        home_target_page('p_abcd1234');
        $this->assertSame('page:p_abcd1234:s_t1', home_cms_section('s_t1'));
        $r = home_inline_save('s_t1', ['heading' => ['bg' => 'Редактирано на място']]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $p = cpage_get('p_abcd1234');
        $this->assertSame('Редактирано на място', $p['sections'][0]['fields']['heading']['bg']);
        $this->assertSame('How we began', $p['sections'][0]['fields']['heading']['en']);
        $this->assertSame('published', $p['status']);

        home_target_page('p_gone0000');
        $this->assertFalse(home_inline_save('s_t1', ['heading' => ['bg' => 'x']])['ok']);
        $this->assertFileDoesNotExist($this->dir . '/p_gone0000.json');
        home_target_reset();
        $this->assertSame('home:s_t1', home_cms_section('s_t1'));
    }

    public function testInlineSaveEndpointChecksThePageId(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/inline-save.php');
        $this->assertMatchesRegularExpression('/count\(\$parts\) !== 3 \|\| !cpage_valid_id\(\$parts\[1\]\) \|\| cpage_get\(\$parts\[1\]\) === null/', $src);
        // …after the login and CSRF checks every other section goes through.
        $this->assertLessThan(strpos($src, "'page:'"), strpos($src, 'csrf_matches'));
    }

    public function testCreatedPagesOfferOnlyTheFreeBlocks(): void
    {
        home_target_page('p_abcd1234');
        $types = home_doc_types();
        $this->assertArrayHasKey('richtext', $types);
        $this->assertArrayNotHasKey('hero', $types);
        $this->assertArrayNotHasKey('products', $types);
        home_target_reset();
        $this->assertArrayHasKey('hero', home_doc_types(), 'the front page keeps every block');
    }

    public function testFrontPageIsUntouchedByTheTarget(): void
    {
        home_target_reset();
        $this->assertSame(CONTENT_PATH . '/home.json', $GLOBALS['_om_home_file'] ?? home_file());
        $this->assertSame('/assets/images/pages/home', home_upload_dir());
        home_target_page('p_abcd1234');
        $this->assertSame($this->dir . '/p_abcd1234.json', home_file());
        $this->assertSame('/assets/images/pages/created/p_abcd1234', home_upload_dir());
    }
}
