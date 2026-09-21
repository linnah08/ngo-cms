<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

#[Group('admin')]
final class HomeSectionsAdminTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/home-sections.php');
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

    public function test_new_sections_cannot_be_builtins_or_unknown_types(): void
    {
        $this->assertStringContainsString('home_is_builtin($type)', $this->src());
        $this->assertStringContainsString('http_response_code(400)', $this->src());
    }

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
