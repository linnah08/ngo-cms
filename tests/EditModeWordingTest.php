<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/ui_wording.php';
require_once dirname(__DIR__) . '/includes/legal_pages.php';

/**
 * What Edit mode reaches beyond the content blocks: page wording printed with te()
 * (saved by inline-save.php section "ui" into the ui_wording setting), the legal
 * pages' bodies (section "legal"), Как да помогна's title and intro (section
 * "how_to_help"), and cms_admin() — "changed in the admin" — for everything else.
 */
#[Group('admin')]
final class EditModeWordingTest extends TestCase
{
    private ?string $wordingBefore = null;
    private ?string $pagesBefore = null;

    protected function setUp(): void
    {
        $this->wordingBefore = setting_get(UI_WORDING_SETTING, "\0none");
        $p = CONTENT_PATH . '/pages.json';
        $this->pagesBefore = is_file($p) ? (string) file_get_contents($p) : null;
        $GLOBALS['_show_admin_bar'] = false;
    }

    protected function tearDown(): void
    {
        if ($this->wordingBefore === "\0none") {
            get_pdo()->prepare('DELETE FROM settings WHERE `key` = ?')->execute([UI_WORDING_SETTING]);
        } else {
            setting_set(UI_WORDING_SETTING, (string) $this->wordingBefore);
        }
        $p = CONTENT_PATH . '/pages.json';
        if ($this->pagesBefore === null) @unlink($p); else file_put_contents($p, $this->pagesBefore);
        unset($GLOBALS['_om_ui_wording'], $GLOBALS['_show_admin_bar']);
    }

    private function save(array $body, array $opts = []): array
    {
        $r = run_admin_page('admin/inline-save.php', [], $opts + ['json' => $body]);
        return json_decode(trim($r['body']), true) ?? [];
    }

    // ── te() and cms_admin() ─────────────────────────────────────────────────

    public function test_te_prints_the_text_escaped_in_an_editable_span(): void
    {
        $GLOBALS['_om_ui_wording'] = ['bg' => [], 'en' => []];
        $html = te('x.test_key', 'Дари & помогни', 'Donate & help', 'bg');
        $this->assertSame('<span data-cms-section="ui" data-cms-field="x.test_key" data-cms-type="text">Дари &amp; помогни</span>', $html);
    }

    public function test_te_carries_both_languages_only_for_an_admin(): void
    {
        $GLOBALS['_om_ui_wording'] = ['bg' => [], 'en' => []];
        $GLOBALS['_show_admin_bar'] = true;
        $html = te('x.test_key', 'Дари', 'Donate', 'en');
        $this->assertStringContainsString('data-cms-bg="Дари" data-cms-en="Donate">Donate</span>', $html);
    }

    public function test_te_with_a_number_shows_it_but_hands_the_editor_the_template(): void
    {
        $GLOBALS['_om_ui_wording'] = ['bg' => [], 'en' => []];
        $GLOBALS['_show_admin_bar'] = true;
        $html = te('x.left', 'Остават {n} места.', '{n} seats left.', 'bg', ['n' => 5]);
        $this->assertStringContainsString('data-cms-bg="Остават {n} места."', $html);
        $this->assertStringContainsString('data-cms-vars="{&quot;n&quot;:&quot;5&quot;}"', $html);
        $this->assertStringEndsWith('>Остават 5 места.</span>', $html);
    }

    public function test_cms_admin_names_the_admin_page(): void
    {
        $this->assertSame(' data-cms-admin="Организация" data-cms-admin-url="/admin/organisation.php"', cms_admin('organisation'));
        $this->assertSame(' data-cms-admin="Събития" data-cms-admin-url="/admin/event-edit.php?id=3"', cms_admin(['Събития', '/admin/event-edit.php?id=3']));
    }

    // ── The wording store ────────────────────────────────────────────────────

    public function test_saved_wording_wins_and_emptying_it_brings_back_the_default(): void
    {
        $this->assertSame(['ok' => true], ui_wording_save(['x.test_key' => ['bg' => 'Подкрепи <b>ни</b>&nbsp;днес', 'en' => 'Support us']]));
        $all = ui_wording_all();
        $this->assertSame('Подкрепи ни днес', $all['bg']['x.test_key'], 'plain text on one line, no markup');
        $this->assertSame('Support us', $all['en']['x.test_key']);

        // A language not sent keeps its text; one sent empty goes back to the default.
        $this->assertSame(['ok' => true], ui_wording_save(['x.test_key' => ['bg' => '']]));
        $all = ui_wording_all();
        $this->assertArrayNotHasKey('x.test_key', $all['bg']);
        $this->assertSame('Support us', $all['en']['x.test_key']);
    }

    public function test_odd_keys_and_long_texts_are_refused(): void
    {
        $this->assertFalse(ui_wording_save(['../etc' => ['bg' => 'x']])['ok']);
        $this->assertFalse(ui_wording_save(['nodot' => ['bg' => 'x']])['ok']);
        $this->assertFalse(ui_wording_save(['x.long' => ['bg' => str_repeat('а', UI_WORDING_MAX_LEN + 1)]])['ok']);
    }

    public function test_page_wording_is_layered_over_the_strings_files(): void
    {
        // lang_strings() caches per request: a fresh PHP process sees the layer from its first call.
        $root = dirname(__DIR__);
        $code = '$_SERVER["DOCUMENT_ROOT"] = ' . var_export($root, true) . ';'
              . '$GLOBALS["_om_ui_wording"] = ["bg" => ["footer.nav" => "От страницата"], "en" => []];'
              . 'require ' . var_export($root . '/config.php', true) . ';'
              . 'echo t("footer.nav"), "|", t_or("footer.nav", "", "", "en");';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        [$bg, $en] = explode('|', $out) + ['', ''];
        $this->assertSame('От страницата', $bg, 'what was typed on the page wins over strings.json');
        $this->assertNotSame('', $en);
        $this->assertNotSame('От страницата', $en, 'the other language keeps its own text');
    }

    // ── inline-save.php ──────────────────────────────────────────────────────

    public function test_ui_section_saves_through_the_endpoint_and_needs_an_admin(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'unauthorized'],
            $this->save(['section' => 'ui', 'fields' => ['x.test_key' => ['bg' => 'А']]], ['role' => null]));
        $this->assertSame(['ok' => true], $this->save(['section' => 'ui', 'fields' => ['x.test_key' => ['bg' => 'Ново', 'en' => 'New']]]));
        $this->assertSame('Ново', ui_wording_all()['bg']['x.test_key']);
    }

    public function test_legal_section_saves_the_body_cleaned_and_keeps_the_other_language(): void
    {
        save_json(CONTENT_PATH . '/pages.json', ['legal' => ['cookie_policy_bg' => '<p>Старо</p>', 'cookie_policy_en' => '<p>Old</p>']]);
        $this->assertSame(['ok' => true], $this->save(['section' => 'legal', 'fields' => [
            'cookies' => ['bg' => '<p onclick="x()">Ново</p><script>alert(1)</script><table><tr><td>PHPSESSID</td></tr></table>'],
        ]]));
        $legal = load_json(CONTENT_PATH . '/pages.json')['legal'];
        $this->assertStringContainsString('<p>Ново</p>', $legal['cookie_policy_bg']);
        $this->assertStringContainsString('<td>PHPSESSID</td>', $legal['cookie_policy_bg'], 'tables survive');
        $this->assertStringNotContainsString('script', $legal['cookie_policy_bg']);
        $this->assertStringNotContainsString('onclick', $legal['cookie_policy_bg']);
        $this->assertSame('<p>Old</p>', $legal['cookie_policy_en']);
        $this->assertFalse($this->save(['section' => 'legal', 'fields' => ['nope' => ['bg' => 'x']]])['ok']);
    }

    public function test_how_to_help_section_saves_title_and_intro_as_plain_text(): void
    {
        save_json(CONTENT_PATH . '/pages.json', ['how_to_help' => ['title' => 'Как да помогна?', 'title_en' => 'How?', 'ways' => [['title' => 'А']]]]);
        $this->assertSame(['ok' => true], $this->save(['section' => 'how_to_help', 'fields' => [
            'title' => ['bg' => 'Игри &amp; <i>терапии</i>'], 'ways' => ['bg' => 'x'],
        ]]));
        $help = load_json(CONTENT_PATH . '/pages.json')['how_to_help'];
        $this->assertSame('Игри & терапии', $help['title']);
        $this->assertSame('How?', $help['title_en']);
        $this->assertSame([['title' => 'А']], $help['ways'], 'only the title and intro can change here');
    }

    // ── The editor script ────────────────────────────────────────────────────

    public function test_editor_saves_each_listed_product_or_article_to_itself(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/inline-cms.js');
        // Before: one "product.name" entry for every card, saved to the first product on the page.
        $this->assertStringContainsString("return section + (id ? '#' + id : '') + '.' + field;", $js);
        $this->assertStringContainsString("if (f.section === 'product') bySection[gk]['id'] = { bg: f.id, en: f.id };", $js);
        // BG and EN articles are separate files: a card writes only its own page's language.
        $this->assertStringContainsString("{ bg: activeLang === 'bg' ? f.id : '', en: activeLang === 'en' ? f.id : '' }", $js);
    }

    public function test_article_pages_save_only_their_own_language(): void
    {
        $bg = (string) file_get_contents(dirname(__DIR__) . '/novini/index.php');
        $en = (string) file_get_contents(dirname(__DIR__) . '/en/news/index.php');
        $this->assertMatchesRegularExpression('/data-cms-article-slug-bg="<\?= h\(\$article\[\'slug\'\] \?\? \'\'\) \?>"\s+data-cms-article-slug-en="">/', $bg);
        $this->assertStringContainsString('<article data-cms-article-slug-bg="" data-cms-article-slug-en="<?= h($article[\'slug\'] ?? \'\') ?>">', $en);
        $this->assertStringContainsString('data-cms-section="article"', $en, 'the EN article is editable too');
    }
}
