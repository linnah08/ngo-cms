<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** site_phone_public() and load_json()'s seed fallback (both came from the lafetki fork). */
final class ConfigHelpersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfg-helpers-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_load_json_uses_the_seed_until_the_real_file_exists(): void
    {
        file_put_contents($this->dir . '/pages.seed.json', '{"from":"seed"}');
        $this->assertSame(['from' => 'seed'], load_json($this->dir . '/pages.json'));

        save_json($this->dir . '/pages.json', ['from' => 'admin']);
        $this->assertSame(['from' => 'admin'], load_json($this->dir . '/pages.json'));
        $this->assertSame('{"from":"seed"}', file_get_contents($this->dir . '/pages.seed.json'), 'a save never touches the seed');
    }

    public function test_load_json_without_file_or_seed_is_empty(): void
    {
        $this->assertSame([], load_json($this->dir . '/missing.json'));
    }

    public function test_site_phone_public_follows_site_phone_unless_switched_off(): void
    {
        // SITE_PHONE_PUBLIC is not defined in the test config: the phone is shown.
        $this->assertFalse(defined('SITE_PHONE_PUBLIC'));
        $this->assertSame(defined('SITE_PHONE') ? (string) SITE_PHONE : '', site_phone_public());
    }
}
