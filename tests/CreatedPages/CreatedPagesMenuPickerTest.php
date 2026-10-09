<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/** Admin → Менюта: the "Води към" page picker of each row. */
final class CreatedPagesMenuPickerTest extends CreatedPagesTestCase
{
    public function testPickerListsBuiltInPagesByNameAndCreatedPagesWithTheirStatus(): void
    {
        $this->storyPage('draft');
        $this->putPage(['id' => 'p_live0000', 'status' => 'published', 'title_bg' => 'Събития', 'slug_bg' => 'nashi-sabitiya', 'slug_en' => 'our-events']);
        $this->putPage(['id' => 'p_noaddr00', 'title_bg' => 'Без адрес']);   // cannot be linked yet
        $o = menu_pick_options();
        $this->assertSame('За нас', $o['builtin']['/za-nas/']);
        $this->assertSame('Начална страница', $o['builtin']['/']);
        $this->assertSame(['/nashata-istoriya/', '/nashi-sabitiya/'], array_keys($o['created']));
        $this->assertSame('draft', $o['created']['/nashata-istoriya/']['status']);
    }

    public function testARowOpensOnItsPageOrOnExternalLink(): void
    {
        $this->storyPage();
        $o = menu_pick_options();
        $this->assertSame('/za-nas/', menu_pick_value('/za-nas', $o));
        $this->assertSame('/', menu_pick_value('/', $o));
        $this->assertSame('/nashata-istoriya/', menu_pick_value('/nashata-istoriya', $o));
        $this->assertSame('__custom__', menu_pick_value('https://example.org/', $o));
        $this->assertSame('__custom__', menu_pick_value('/za-nas/#ekip', $o), 'an anchor stays as typed');
        $this->assertSame('__custom__', menu_pick_value('/novini/statiya/', $o));
        $this->assertSame('', menu_pick_value('', $o));
    }

    public function testThePickedPageWinsOverTheTypedAddressButOnlyASitePathCanBePicked(): void
    {
        $this->assertSame('/za-nas/', menu_resolve_pick('/za-nas/', 'https://old.example/'));
        $this->assertSame('mailto:a@b.bg', menu_resolve_pick('__custom__', 'mailto:a@b.bg'));
        $this->assertSame('/typed/', menu_resolve_pick('', '/typed/'));
        foreach (['javascript:alert(1)', '//evil.example/', '/a b', '/"x', ['/za-nas/']] as $bad) {
            $this->assertSame('/typed/', menu_resolve_pick($bad, '/typed/'), var_export($bad, true));
        }
    }

    public function testTheEditorWarnsAboutAddressesThatLeadNowhere(): void
    {
        $this->assertSame('missing', menu_link_state('/nyakakva-stranitsa/')['state']);
        $this->assertSame('ok', menu_link_state('/kontakti/')['state']);
        $this->assertSame('ok', menu_link_state('mailto:a@b.bg')['state']);
        $this->assertSame('ok', menu_link_state('/novini/statiya/')['state'], 'only one-segment addresses are judged');
    }

    public function testMenuEditorPostsThePickAndCreatesPagesWithCsrf(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/menus.php');
        $this->assertStringContainsString("menu_resolve_pick(\$picks[\$i] ?? '', \$urls_bg[\$i] ?? '')", $src);
        $this->assertStringContainsString('name="pick_bg[]"', $src);
        $this->assertStringContainsString('<label for="mp-bg-<?= $uid ?>" style="<?= $lab ?>">Води към (BG)</label>', $src);
        $this->assertStringContainsString('<select id="mp-bg-<?= $uid ?>"', $src);
        $this->assertStringContainsString("csrf_token: window._csrfToken, action: 'create', format: 'json'", $src);
        $this->assertStringContainsString('Тази страница не съществува.', $src);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="mn-', $src);
        // admin_require_admin() before anything is read or written.
        $this->assertLessThan(strpos($src, '$_POST'), strpos($src, 'admin_require_admin();'));
    }

    public function testCreatedPagesEndpointIsAdminOnlyAndCsrfChecked(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/created-pages.php');
        $guard = strpos($src, 'admin_require_admin();');
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($src, 'csrf_verify()'), $guard);
        $this->assertLessThan(strpos($src, "switch (\$action)"), strpos($src, 'csrf_verify()'));
    }
}
