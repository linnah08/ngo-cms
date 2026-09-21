<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

#[Group('admin')]
final class HomeSectionsAdminTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/home-sections-admin-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/home-sections.php');
    }

    /** @param array $extra extra sections appended after hero + cta */
    private function doc(int $rev, array $extra = []): array
    {
        return ['version' => 1, 'rev' => $rev, 'sections' => array_merge([
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => ['title' => ['bg' => 'Здравейте', 'en' => '']]],
            ['id' => 's_ab12', 'type' => 'cta', 'visible' => false, 'fields' => $this->validCtaFields()],
        ], $extra)];
    }

    private function seedFile(array $doc): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function validCtaFields(): array
    {
        return [
            'heading'    => ['bg' => 'Помогнете', 'en' => ''],
            'text'       => ['bg' => '', 'en' => ''],
            'btn1_label' => ['bg' => '', 'en' => ''],
            'btn1_url'   => ['bg' => '', 'en' => ''],
            'btn2_label' => ['bg' => '', 'en' => ''],
            'btn2_url'   => ['bg' => '', 'en' => ''],
            'background' => 'teal',
        ];
    }

    public function test_requires_an_admin_before_anything_else(): void
    {
        $src   = $this->src();
        $login = strpos($src, 'admin_require_login();');
        $admin = strpos($src, 'admin_require_admin();');
        $this->assertNotFalse($login);
        $this->assertNotFalse($admin);
        foreach (['home_load()', "\$_SERVER['REQUEST_METHOD']", 'echo', '?>'] as $later) {
            $pos = strpos($src, $later);
            if ($pos !== false) $this->assertLessThan($pos, $admin, "admin check must precede $later");
        }
    }

    public function test_post_handler_verifies_csrf_first(): void
    {
        $src  = $this->src();
        $post = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        $csrf = strpos($src, 'csrf_verify()', (int) $post);
        $this->assertNotFalse($csrf);
        $this->assertLessThan(strpos($src, '$_POST[', (int) $post), $csrf);
    }

    // ── home_admin_save(): the real save flow ──────────────────────────────────

    public function test_save_refuses_a_new_builtin_section(): void
    {
        $doc = $this->doc(0);
        $this->seedFile($doc);
        $before = file_get_contents($GLOBALS['_om_home_file']);

        $r = home_admin_save($doc, ['id' => '', 'type' => 'hero', 'rev' => 0, 'f' => []], []);

        $this->assertSame('bad_type', $r['status']);
        $this->assertSame($before, file_get_contents($GLOBALS['_om_home_file']));
    }

    public function test_save_refuses_a_new_unknown_type(): void
    {
        $doc = $this->doc(0);
        $this->seedFile($doc);
        $before = file_get_contents($GLOBALS['_om_home_file']);

        $r = home_admin_save($doc, ['id' => '', 'type' => 'not_a_real_type', 'rev' => 0, 'f' => []], []);

        $this->assertSame('bad_type', $r['status']);
        $this->assertSame($before, file_get_contents($GLOBALS['_om_home_file']));
    }

    public function test_save_creates_a_new_visible_section_appended_last(): void
    {
        $doc = $this->doc(3);
        $this->seedFile($doc);

        $r = home_admin_save($doc, ['id' => '', 'type' => 'cta', 'rev' => 3, 'f' => $this->validCtaFields()], []);

        $this->assertSame('saved', $r['status']);
        $this->assertMatchesRegularExpression('/^s_[a-z0-9_]{1,24}$/', $r['sid']);
        $this->assertNotSame('s_hero', $r['sid']);
        $this->assertNotSame('s_ab12', $r['sid']);

        $sections = $r['doc']['sections'];
        $last = $sections[count($sections) - 1];
        $this->assertSame($r['sid'], $last['id']);
        $this->assertSame('cta', $last['type']);
        $this->assertTrue($last['visible']);

        $onDisk = json_decode((string) file_get_contents($GLOBALS['_om_home_file']), true);
        $this->assertSame(4, $onDisk['rev']);
        $this->assertSame($r['sid'], $onDisk['sections'][count($onDisk['sections']) - 1]['id']);
    }

    public function test_save_reports_a_conflict_when_the_revision_is_stale_and_writes_nothing(): void
    {
        $doc = $this->doc(5);
        $this->seedFile($doc);

        $r = home_admin_save($doc, ['id' => 's_ab12', 'type' => 'cta', 'rev' => 4, 'f' => $this->validCtaFields()], []);

        $this->assertSame('invalid', $r['status']);
        $this->assertSame(home_save_error_message('conflict'), $r['errors']['_form']);

        $onDisk = json_decode((string) file_get_contents($GLOBALS['_om_home_file']), true);
        $this->assertSame(5, $onDisk['rev']);
        $this->assertSame(['s_hero', 's_ab12'], array_column($onDisk['sections'], 'id'));
    }

    public function test_save_keeps_typed_values_on_an_invalid_link_and_writes_nothing(): void
    {
        $doc = $this->doc(0);
        $fields = $this->validCtaFields();
        $fields['heading']['bg']  = 'Нова CTA';
        $fields['text']['bg']     = 'Описание';
        $fields['btn1_url']['bg'] = 'javascript:evil()';

        $r = home_admin_save($doc, ['id' => '', 'type' => 'cta', 'rev' => 0, 'f' => $fields], []);

        $this->assertSame('invalid', $r['status']);
        $this->assertNotSame([], $r['errors']);
        $this->assertSame('Нова CTA', $r['form']['fields']['heading']['bg']);
        $this->assertSame('Описание', $r['form']['fields']['text']['bg']);
        $this->assertSame('javascript:evil()', $r['form']['fields']['btn1_url']['bg']);
        $this->assertFileDoesNotExist($GLOBALS['_om_home_file']);
    }

    public function test_save_reports_not_found_for_an_unknown_id(): void
    {
        $doc = $this->doc(0);

        $r = home_admin_save($doc, ['id' => 's_zzzz', 'type' => 'cta', 'rev' => 0, 'f' => []], []);

        $this->assertSame('not_found', $r['status']);
        $this->assertStringContainsString('не е намерена', $r['message']);
    }

    public function test_save_keeps_the_stored_type_and_visibility_even_if_the_post_disagrees(): void
    {
        $doc = $this->doc(2);   // s_ab12 is type 'cta', visible = false
        $this->seedFile($doc);

        $r = home_admin_save($doc, ['id' => 's_ab12', 'type' => 'hero', 'rev' => 2, 'f' => $this->validCtaFields()], []);

        $this->assertSame('saved', $r['status']);
        $saved = $r['doc']['sections'][array_search('s_ab12', array_column($r['doc']['sections'], 'id'), true)];
        $this->assertSame('cta', $saved['type']);
        $this->assertFalse($saved['visible']);
    }

    public function test_save_fetches_a_thumbnail_once_for_a_new_video(): void
    {
        $doc = $this->doc(0);
        $this->seedFile($doc);
        $calls = 0;
        $fetch = function (array $v, string $sid) use (&$calls): string { $calls++; return '/assets/images/pages/home/new-thumb.jpg'; };

        $post = ['id' => '', 'type' => 'video', 'rev' => 0, 'f' => [
            'heading' => ['bg' => '', 'en' => ''],
            'video'   => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'caption' => ['bg' => '', 'en' => ''],
            'background' => 'white',
        ]];
        $r = home_admin_save($doc, $post, [], $fetch);

        $this->assertSame('saved', $r['status']);
        $this->assertSame(1, $calls);
        $last = $r['doc']['sections'][count($r['doc']['sections']) - 1];
        $this->assertSame('/assets/images/pages/home/new-thumb.jpg', $last['fields']['thumb']);
    }

    public function test_save_keeps_the_old_thumbnail_when_the_video_is_unchanged(): void
    {
        $doc = $this->doc(0, [[
            'id' => 's_vid1', 'type' => 'video', 'visible' => true, 'fields' => [
                'heading' => ['bg' => '', 'en' => ''],
                'video'   => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
                'caption' => ['bg' => '', 'en' => ''],
                'thumb'   => '/assets/images/pages/home/existing-thumb.jpg',
                'background' => 'white',
            ],
        ]]);
        $this->seedFile($doc);
        $calls = 0;
        $fetch = function (array $v, string $sid) use (&$calls): string { $calls++; return '/assets/images/pages/home/should-not-be-used.jpg'; };

        $post = ['id' => 's_vid1', 'type' => 'video', 'rev' => 0, 'f' => [
            'heading' => ['bg' => '', 'en' => ''],
            'video'   => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'caption' => ['bg' => '', 'en' => ''],
            'background' => 'white',
        ]];
        $r = home_admin_save($doc, $post, [], $fetch);

        $this->assertSame('saved', $r['status']);
        $this->assertSame(0, $calls);
        $saved = $r['doc']['sections'][array_search('s_vid1', array_column($r['doc']['sections'], 'id'), true)];
        $this->assertSame('/assets/images/pages/home/existing-thumb.jpg', $saved['fields']['thumb']);
    }

    // ── home_apply_uploads(): a non-image at a non-zero card position ──────────

    public function test_apply_uploads_reports_a_non_image_card_upload_at_its_position(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'not an image, just text');
        $in = ['cards' => [
            3 => ['title' => ['bg' => 'Първа', 'en' => '']],
            7 => ['title' => ['bg' => 'Втора', 'en' => '']],
        ]];
        $files = ['up_card' => ['tmp_name' => [7 => $tmp], 'error' => [7 => UPLOAD_ERR_OK], 'name' => [7 => 'x.txt']]];

        $errors = home_apply_uploads($in, 'cards', $files, 's_ab12');
        unlink($tmp);

        $this->assertSame('Снимката трябва да е JPEG, PNG или WebP.', $errors['cards.1.image'] ?? null);
    }

    // ── Form-rendering helpers (unchanged behaviour) ────────────────────────────

    public function test_english_text_fields_have_a_translate_hook_but_links_do_not(): void
    {
        $types = home_types();
        $html  = hs_field('heading', $types['cta']['fields']['heading'], ['bg' => 'А', 'en' => ''], []);
        $this->assertStringContainsString('name="f[heading][en]"', $html);
        $this->assertStringContainsString('data-translate-from="f[heading][bg]"', $html);
        $html = hs_field('btn1_url', $types['cta']['fields']['btn1_url'], ['bg' => '/', 'en' => ''], []);
        $this->assertStringNotContainsString('data-translate-from', $html);
    }

    public function test_every_input_has_a_label(): void
    {
        foreach (home_types() as $type => $def) {
            foreach ($def['fields'] as $key => $fdef) {
                $html = $fdef['kind'] === 'cards' ? hs_cards([], []) : hs_field($key, $fdef, null, []);
                preg_match_all('/<(?:input|textarea|select)\b(?![^>]*type="hidden")[^>]*\bid="([^"]+)"/', $html, $m);
                foreach ($m[1] as $id) {
                    $this->assertStringContainsString('for="' . $id . '"', $html, "$type.$key: #$id has no label");
                }
            }
        }
    }

    public function test_errors_are_announced_next_to_the_field(): void
    {
        $html = hs_field('heading', home_types()['cta']['fields']['heading'], ['bg' => '', 'en' => ''], ['heading.bg' => 'Твърде дълго.']);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="f_heading_bg_err"', $html);
        $this->assertStringContainsString('id="f_heading_bg_err"', $html);
        $this->assertStringContainsString('⚠', $html);
    }

    public function test_error_summary_links_to_fields_with_their_labels(): void
    {
        $html = hs_error_summary('cards', ['cards.1.title.bg' => 'Това поле е задължително.', '_form' => 'Опитайте пак.']);
        $this->assertStringContainsString('href="#f_cards_1_title_bg"', $html);
        $this->assertStringContainsString('Карта 2 — Заглавие (BG)', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_action_buttons_have_names_and_delete_asks_first(): void
    {
        $s = ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['heading' => ['bg' => 'Помогнете', 'en' => '']]];
        $up = hs_action_form('move_up', $s, 4, '↑ Нагоре', 'Премести „Помогнете“ нагоре', true, 'вече е най-горе');
        $this->assertStringContainsString('aria-label="Премести „Помогнете“ нагоре (вече е най-горе)"', $up);
        $this->assertStringContainsString(' disabled', $up);
        $this->assertStringContainsString('name="rev" value="4"', $up);
        $this->assertStringContainsString('name="csrf_token"', $up);
        $del = hs_action_form('delete', $s, 4, 'Изтрий', 'Изтрий „Помогнете“');
        $this->assertStringContainsString('data-confirm="Да изтрия ли „Помогнете“? Това не може да се върне."', $del);
    }

    public function test_upload_rejects_non_images(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, '<?php echo 1;');
        $r = home_store_upload(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'x.jpg'], 's_ab12', 'image');
        unlink($tmp);
        $this->assertNull($r['path']);
        $this->assertSame('Снимката трябва да е JPEG, PNG или WebP.', $r['error']);
        $this->assertSame(['path' => null, 'error' => null],
            home_store_upload(['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], 's_ab12', 'image'));
    }

    public function test_files_entry_reads_nested_upload_arrays(): void
    {
        $files = ['up_card' => ['tmp_name' => [3 => '/tmp/x'], 'error' => [3 => 0], 'name' => [3 => 'a.jpg']]];
        $this->assertSame(['tmp_name' => '/tmp/x', 'error' => 0, 'name' => 'a.jpg'], home_files_entry($files, 'up_card', 3));
        $this->assertNull(home_files_entry($files, 'up', 'image'));
    }
}
