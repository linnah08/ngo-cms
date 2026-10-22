<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A footer link column (Навигация, Как да помогнете) with no links in the page's
 * language is left out, heading and all — emptying it in Admin → Менюта is how
 * an admin removes it, so no heading is stuck on the site with nothing under it.
 */
final class FooterEmptyColumnsTest extends TestCase
{
    private string $tmp;
    private string $menus;
    private ?string $saved = null;

    protected function setUp(): void
    {
        $this->tmp   = sys_get_temp_dir() . '/footer-cols-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->menus = $_SERVER['DOCUMENT_ROOT'] . '/content/menus.json';
        if (is_file($this->menus)) $this->saved = (string) file_get_contents($this->menus);
    }

    protected function tearDown(): void
    {
        if ($this->saved !== null) file_put_contents($this->menus, $this->saved);
        elseif (is_file($this->menus)) unlink($this->menus);
        @unlink($this->tmp . '/run.php');
        @rmdir($this->tmp);
    }

    private function footer(array $menus, string $uri): string
    {
        file_put_contents($this->menus, json_encode($menus, JSON_UNESCAPED_UNICODE));
        $root   = $_SERVER['DOCUMENT_ROOT'];
        $script = $this->tmp . '/run.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = " . var_export($root, true) . ";
            \$_SERVER['REQUEST_URI']   = " . var_export($uri, true) . ";
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['HTTP_HOST']     = 'localhost';
            require " . var_export($root . '/config.php', true) . ";
            require " . var_export($root . '/templates/footer.php', true) . ";
        ");
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d error_log=/dev/null ' . escapeshellarg($script), $out, $rc);
        $html = implode("\n", $out);
        $this->assertSame(0, $rc, $html);
        $this->assertStringContainsString('</footer>', $html, 'footer rendered in full');
        return $html;
    }

    private static function link(string $url, string $label): array
    {
        return ['url' => $url, 'label' => $label];
    }

    public function test_empty_columns_are_left_out_heading_and_all(): void
    {
        $html = $this->footer(['footer_nav' => ['bg' => [], 'en' => []], 'footer_help' => ['bg' => [], 'en' => []]], '/');
        $this->assertStringNotContainsString('data-cms-field="footer.nav"', $html);
        $this->assertStringNotContainsString('data-cms-field="footer.help"', $html);
        $this->assertStringContainsString('data-cms-field="footer.contact"', $html, 'contact column stays');
    }

    public function test_a_column_with_links_keeps_its_heading(): void
    {
        $html = $this->footer([
            'footer_nav'  => ['bg' => [self::link('mailto:a@example.org', 'Пишете ни')], 'en' => []],
            'footer_help' => ['bg' => [], 'en' => []],
        ], '/');
        $this->assertStringContainsString('data-cms-field="footer.nav"', $html);
        $this->assertStringContainsString('Пишете ни', $html);
        $this->assertStringNotContainsString('data-cms-field="footer.help"', $html);
    }

    public function test_each_language_goes_by_its_own_links(): void
    {
        $menus = ['footer_help' => ['bg' => [self::link('mailto:a@example.org', 'Дарете')], 'en' => []]];
        $this->assertStringContainsString('data-cms-field="footer.help"', $this->footer($menus, '/'));
        $this->assertStringNotContainsString('data-cms-field="footer.help"', $this->footer($menus, '/en/'));
    }
}
