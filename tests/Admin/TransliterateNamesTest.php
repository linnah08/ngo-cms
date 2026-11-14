<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * People's names are written in Latin letters, never translated: DeepL turned
 * „Детелина“ into "Clover". Name fields get „✦ На латиница“, which runs
 * admin/js/bg-translit.js in the page (no DeepL, works with AI helpers off).
 */
#[Group('admin')]
final class TransliterateNamesTest extends TestCase
{
    private const JS = __DIR__ . '/../../admin/js/bg-translit.js';

    public static function names(): array
    {
        return [
            'plain'              => ['Детелина Василева', 'Detelina Vasileva'],
            '-ия at word end'    => ['Мария', 'Maria'],
            '-ия mid-word stays' => ['Мариян', 'Mariyan'],
            'щ ж ь'              => ['Жельо Щерев', 'Zhelyo Shterev'],
            'ю ц'                => ['Юлия Цветкова', 'Yulia Tsvetkova'],
            'х ъ'                => ['Христо Ъглов', 'Hristo Aglov'],
            'all capitals'       => ['ЖЕЛЕВ', 'ZHELEV'],
            'Latin untouched'    => ['Anna-Мария Lee', 'Anna-Maria Lee'],
        ];
    }

    #[DataProvider('names')]
    public function testNamesFollowTheOfficialTransliteration(string $bg, string $latin): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') $this->markTestSkipped('node is not installed.');
        $out = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg(
            'process.stdout.write(require(process.argv[1])(process.argv[2]))') . ' ' . escapeshellarg(realpath(self::JS)) . ' ' . escapeshellarg($bg));
        $this->assertSame($latin, $out);
    }

    /** Each person-name field, by the data-translate-from attribute it carries in its page's source. */
    public static function nameFields(): array
    {
        return [
            ['admin/account.php',      'data-translate-from="first_name"'],
            ['admin/account.php',      'data-translate-from="last_name"'],
            ['admin/users.php',        'data-translate-from="\' . h($p . \'-first_name\') . \'"'],
            ['admin/users.php',        'data-translate-from="\' . h($p . \'-last_name\') . \'"'],
            ['admin/article-edit.php', 'data-translate-from="author"'],
            ['admin/organisation.php', 'data-translate-from="site_mol"'],
        ];
    }

    #[DataProvider('nameFields')]
    public function testEveryPersonNameFieldIsTransliterated(string $file, string $hook): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertStringContainsString($hook . ' data-translate-mode="transliterate"', $src,
            "$file: $hook is a person's name — it must be written in Latin letters, not translated");
    }

    public function testTheLatinButtonDoesNotDependOnTheAiHelpers(): void
    {
        $src    = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/includes/admin-footer.php');
        $gate   = strpos($src, "<?php if (!module_enabled_with_needs('ai_helpers')): ?>");
        $endif  = strpos($src, '<?php endif; ?>', (int) $gate);
        $script = strpos($src, '/admin/js/bg-translit.js');
        $attach = strpos($src, "document.querySelectorAll('[data-translate-from]').forEach(attach)");
        $this->assertNotFalse($gate);
        $this->assertGreaterThan($endif, $script, 'bg-translit.js loads whatever the AI helpers setting');
        $this->assertGreaterThan($endif, $attach, 'the buttons are set up whatever the AI helpers setting');
        $this->assertStringContainsString("if (!translit && !aiOn) return;", $src, '✦ Translate still needs the AI helpers');
    }

    public function testAccountPageLoadsTheTransliteration(): void
    {
        $r = run_admin_page('admin/account.php', [], ['method' => 'GET', 'role' => 'author']);
        $this->assertStringContainsString('/admin/js/bg-translit.js', $r['body']);
        $this->assertMatchesRegularExpression('/id="first_name_en"[^>]*data-translate-mode="transliterate"/', $r['body']);
    }
}
