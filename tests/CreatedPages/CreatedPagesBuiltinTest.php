<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/**
 * A built-in page built from blocks (За нас, CPAGE_ABOUT_ID) is stored like a
 * created page, but it is part of the site: it can't be deleted, hidden, or
 * moved to another address. Every other page keeps all of that.
 */
final class CreatedPagesBuiltinTest extends CreatedPagesTestCase
{
    private function aboutPage(): array
    {
        return $this->putPage([
            'id' => CPAGE_ABOUT_ID, 'status' => 'published',
            'title_bg' => 'Кои сме ние?', 'title_en' => 'Who are we?',
            'slug_bg' => 'za-nas', 'slug_en' => 'about',
        ]);
    }

    public function test_about_is_builtin_and_others_are_not(): void
    {
        $this->assertSame('p_000about', CPAGE_ABOUT_ID);
        $this->assertTrue(cpage_valid_id(CPAGE_ABOUT_ID));
        $this->assertTrue(cpage_is_builtin(CPAGE_ABOUT_ID));
        $this->assertFalse(cpage_is_builtin('p_abcd1234'));
    }

    public function test_about_cannot_be_deleted(): void
    {
        $this->aboutPage();
        $this->assertFalse(cpage_delete(CPAGE_ABOUT_ID));
        $this->assertFileExists($this->dir . '/' . CPAGE_ABOUT_ID . '.json');
        $this->assertNotNull(cpage_get(CPAGE_ABOUT_ID));
        $this->assertSame([], cpage_deleted(), 'its addresses are not remembered as deleted');
    }

    public function test_about_cannot_be_unpublished(): void
    {
        $this->aboutPage();
        $r = cpage_set_status(CPAGE_ABOUT_ID, 'draft');
        $this->assertFalse($r['ok']);
        $this->assertSame('Тази страница е част от сайта и винаги се вижда.', $r['error']);
        $this->assertSame('published', cpage_get(CPAGE_ABOUT_ID)['status']);
        $this->assertTrue(cpage_set_status(CPAGE_ABOUT_ID, 'published')['ok'], 'publishing it again is harmless');
    }

    public function test_about_slugs_cannot_change(): void
    {
        $this->aboutPage();
        $r = cpage_save_settings(CPAGE_ABOUT_ID, ['title_bg' => 'За нас', 'title_en' => 'About',
                                                  'slug_bg' => 'drug-adres', 'slug_en' => 'other']);
        $this->assertTrue($r['ok'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        $p = cpage_get(CPAGE_ABOUT_ID);
        $this->assertSame(['За нас', 'About', 'za-nas', 'about'], [$p['title_bg'], $p['title_en'], $p['slug_bg'], $p['slug_en']]);

        // No slugs posted at all (the form shows them read-only) is fine too.
        $this->assertTrue(cpage_save_settings(CPAGE_ABOUT_ID, ['title_bg' => 'Ние', 'title_en' => 'Us'])['ok']);
        $this->assertSame('za-nas', cpage_get(CPAGE_ABOUT_ID)['slug_bg']);

        $bad = cpage_save_settings(CPAGE_ABOUT_ID, ['title_bg' => '', 'title_en' => 'Us']);
        $this->assertFalse($bad['ok'], 'the title is still required');
        $this->assertArrayHasKey('title_bg', $bad['errors']);
    }

    public function test_other_pages_unaffected(): void
    {
        $this->storyPage();
        $this->assertTrue(cpage_set_status('p_abcd1234', 'draft')['ok']);
        $r = cpage_save_settings('p_abcd1234', ['title_bg' => 'История', 'title_en' => 'Story', 'slug_bg' => 'istoriya', 'slug_en' => 'story']);
        $this->assertTrue($r['ok']);
        $this->assertSame('istoriya', cpage_get('p_abcd1234')['slug_bg']);
        $this->assertTrue(cpage_delete('p_abcd1234'));
        $this->assertNull(cpage_get('p_abcd1234'));
    }

    public function test_about_is_not_offered_twice_in_the_menu_picker_or_sitemap(): void
    {
        $this->aboutPage();
        $this->storyPage();
        $opts = menu_pick_options();
        $this->assertArrayHasKey('/za-nas/', $opts['builtin']);
        $this->assertArrayNotHasKey('/za-nas/', $opts['created']);
        $this->assertArrayHasKey('/nashata-istoriya/', $opts['created']);

        [$xml] = $this->request('sitemap.php', '/sitemap.xml');
        $this->assertSame(1, substr_count($xml, '/za-nas/</loc>'), $xml);
        $this->assertSame(1, substr_count($xml, '/en/about/</loc>'), $xml);
        $this->assertStringContainsString('/nashata-istoriya/</loc>', $xml);
    }

    // ── Admin screens ────────────────────────────────────────────────────────

    private function adminGet(string $rel, array $get = []): string
    {
        $r = run_admin_page($rel, [], ['method' => 'GET', 'get' => $get, 'globals' => ['_om_pages_dir' => $this->dir]]);
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['body'];
    }

    public function test_editor_shows_fixed_addresses_and_no_hide_or_delete(): void
    {
        $this->aboutPage();
        $html = $this->adminGet('admin/page-edit.php', ['id' => CPAGE_ABOUT_ID]);
        $this->assertStringContainsString('Адресът на тази страница не може да се променя.', $html);
        $this->assertStringContainsString('/za-nas/', $html);
        $this->assertStringContainsString('/en/about/', $html);
        $this->assertStringNotContainsString('name="slug_bg"', $html);
        $this->assertStringNotContainsString('Скрий от сайта', $html);
        $this->assertStringNotContainsString('action="/admin/created-pages.php"', $html, 'no publish/hide/delete form for the page itself');
        $this->assertStringContainsString('name="title_en"', $html, 'the title can still be changed');
    }

    public function test_editor_seeds_the_page_when_nobody_visited_it_yet(): void
    {
        $html = $this->adminGet('admin/page-edit.php', ['id' => CPAGE_ABOUT_ID]);
        $this->assertFileExists($this->dir . '/' . CPAGE_ABOUT_ID . '.json');
        $this->assertStringContainsString('Адресът на тази страница не може да се променя.', $html);
    }

    public function test_pages_list_links_to_the_new_editor_and_hides_about_from_created_pages(): void
    {
        $this->aboutPage();
        $this->storyPage();
        $html = $this->adminGet('admin/pages.php');
        $this->assertStringContainsString('href="/admin/page-edit.php?id=p_000about"', $html);
        $this->assertStringNotContainsString('id="cp-p_000about"', $html);
        $this->assertStringContainsString('id="cp-p_abcd1234"', $html);
        $this->assertStringNotContainsString('href="/admin/pages.php?page=about"', $html);

        $old = $this->adminGet('admin/pages.php', ['page' => 'about']);
        $this->assertStringContainsString('href="/admin/page-edit.php?id=p_000about"', $old);
        $this->assertStringNotContainsString('id="aboutForm"', $old);
    }

    public function test_delete_and_hide_requests_are_refused(): void
    {
        $this->aboutPage();
        foreach (['delete', 'unpublish'] as $action) {
            $r = run_admin_page('admin/created-pages.php', ['action' => $action, 'id' => CPAGE_ABOUT_ID, 'format' => 'json'],
                ['globals' => ['_om_pages_dir' => $this->dir]]);
            $this->assertFalse(json_decode(trim($r['body']), true)['ok'] ?? true, $action . ': ' . $r['body']);
        }
        cpage_cache_reset();
        $this->assertSame('published', cpage_get(CPAGE_ABOUT_ID)['status']);
    }
}
