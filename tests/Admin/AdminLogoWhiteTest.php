<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Admin → Организация → „Покажи логото в бяло в тъмното меню“ (ADMIN_LOGO_WHITE):
 * the side menu's logo gets a white rendering, as an inline style.
 */
final class AdminLogoWhiteTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function style(?string $value): string
    {
        $def  = $value === null ? '' : 'define("ADMIN_LOGO_WHITE", ' . var_export($value, true) . ");\n";
        $file = tempnam(sys_get_temp_dir(), 'om_logo_white_');
        file_put_contents($file, "<?php\n{$def}require_once " . var_export(self::root() . '/includes/organisation.php', true) . ";\necho org_admin_logo_style();\n");
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_off_by_default_and_when_switched_off(): void
    {
        $this->assertSame('', $this->style(null));
        $this->assertSame('', $this->style('0'));
    }

    public function test_switched_on_the_logo_is_drawn_white(): void
    {
        $this->assertSame('filter:brightness(0) invert(1);', $this->style('1'));
    }

    public function test_the_form_saves_an_explicit_on_or_off(): void
    {
        require_once self::root() . '/includes/organisation.php';
        $this->assertSame('ADMIN_LOGO_WHITE', org_fields()['admin_logo_white']);
        $this->assertSame('1', org_validate(['admin_logo_white' => '1'], ['classic'])['values']['admin_logo_white']);
        $this->assertSame('0', org_validate(['admin_logo_white' => 'yes'], ['classic'])['values']['admin_logo_white']);
        $this->assertSame('0', org_validate([], ['classic'])['values']['admin_logo_white']);
    }

    public function test_the_side_menu_uses_it_inline_and_the_form_offers_it_next_to_the_logo(): void
    {
        $header = (string) file_get_contents(self::root() . '/admin/includes/admin-header.php');
        $this->assertStringContainsString('org_admin_logo_style()', $header);
        $this->assertStringContainsString("' style=\"' . h(\$_logo_style) . '\"'", $header, 'inline, not in admin.css');
        $form = (string) file_get_contents(self::root() . '/admin/organisation.php');
        $logo = strpos($form, 'name="logo"');
        $tick = strpos($form, 'name="admin_logo_white"');
        $this->assertNotFalse($tick);
        $this->assertGreaterThan($logo, $tick, 'right after the logo upload');
        $this->assertStringContainsString('Покажи логото в бяло в тъмното меню', $form);
    }
}
