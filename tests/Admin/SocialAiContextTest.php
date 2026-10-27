<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The AI that drafts Facebook/Instagram/LinkedIn posts is told who the
 * organisation is: its name and Организация → Кратко описание (org_ai_context()).
 * Constants cannot be undone, so each case runs in its own PHP process.
 */
final class SocialAiContextTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function context(array $defines): array
    {
        $defs = '';
        foreach ($defines as $c => $v) $defs .= 'define(' . var_export($c, true) . ', ' . var_export($v, true) . ");\n";
        $file = tempnam(sys_get_temp_dir(), 'om_ai_ctx_');
        file_put_contents($file, "<?php\n{$defs}require_once " . var_export(self::root() . '/includes/organisation.php', true) . ";\n"
            . "echo json_encode([org_ai_context('bg'), org_ai_context('en')], JSON_UNESCAPED_UNICODE);\n");
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        $this->assertSame(0, $rc, implode("\n", $out));
        return json_decode(implode("\n", $out), true);
    }

    public function test_name_and_description_in_the_posts_language(): void
    {
        [$bg, $en] = $this->context([
            'SITE_NAME_BG' => 'Фондация Пример', 'SITE_NAME_EN' => 'Example Foundation',
            'SITE_DESCRIPTION_BG' => 'Подкрепяме деца и семейства.', 'SITE_DESCRIPTION_EN' => 'We support children and families.',
        ]);
        $this->assertSame('ЗА ОРГАНИЗАЦИЯТА: Фондация Пример. Подкрепяме деца и семейства.', $bg);
        $this->assertSame('ABOUT THE ORGANISATION: Example Foundation. We support children and families.', $en);
    }

    public function test_a_missing_language_borrows_the_other_one(): void
    {
        [$bg, $en] = $this->context(['SITE_NAME_BG' => 'Фондация Пример', 'SITE_DESCRIPTION_BG' => 'Подкрепяме деца.']);
        $this->assertSame('ЗА ОРГАНИЗАЦИЯТА: Фондация Пример. Подкрепяме деца.', $bg);
        $this->assertSame('ABOUT THE ORGANISATION: Фондация Пример. Подкрепяме деца.', $en);
    }

    public function test_without_a_description_only_the_name_and_without_both_nothing(): void
    {
        [$bg] = $this->context(['SITE_NAME_BG' => 'Фондация Пример', 'SITE_DESCRIPTION_BG' => '  ']);
        $this->assertSame('ЗА ОРГАНИЗАЦИЯТА: Фондация Пример', $bg);
        $this->assertSame(['', ''], $this->context([]));
    }

    public function test_markup_and_line_breaks_are_flattened(): void
    {
        [$bg] = $this->context(['SITE_NAME_BG' => 'Пример', 'SITE_DESCRIPTION_BG' => "Ред <b>едно</b>\n\nред две"]);
        $this->assertSame('ЗА ОРГАНИЗАЦИЯТА: Пример. Ред едно ред две', $bg);
    }

    public function test_every_social_prompt_includes_it(): void
    {
        $fb = (string) file_get_contents(self::root() . '/admin/social-ajax.php');
        $li = (string) file_get_contents(self::root() . '/admin/linkedin-ajax.php');
        $this->assertStringContainsString("org_ai_context('bg')", $fb, 'Facebook/Instagram posts are written in Bulgarian');
        $this->assertStringContainsString("org_ai_context('en')", $li, 'LinkedIn posts are written in English');
        $this->assertSame(2, substr_count($fb, 'organisation.{$org_about}') + substr_count($fb, 'НПО.{$org_about}'));
        $this->assertSame(2, substr_count($li, 'organisation.{$org_about}'));
    }
}
