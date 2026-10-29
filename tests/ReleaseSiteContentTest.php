<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A release never replaces a site's own content: the centres, numbers and
 * partners are not shipped, and the front-page photo (like the logo) is kept
 * when the site has one. Composer's vendor/ is never served to the web.
 */
final class ReleaseSiteContentTest extends TestCase
{
    private const NOT_SHIPPED = ['content/centres.json', 'content/impact.json', 'content/partners.json'];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';
    }

    public function test_site_content_files_are_owner_files(): void
    {
        foreach (array_merge(self::NOT_SHIPPED, ['assets/images/hero.webp', 'assets/images/logo.png', 'assets/images/favicon.png']) as $f) {
            $this->assertContains($f, updater_owner_files());
        }
        $new  = ['assets/images/hero.webp', 'content/partners.json', 'index.php'];
        $this->assertSame(['assets/images/hero.webp', 'content/partners.json'],
            updater_owner_files_to_keep($new, fn(string $p): ?string => 'live'), 'a live copy is kept');
        $this->assertSame([], updater_owner_files_to_keep($new, fn(string $p): ?string => null), 'a fresh site gets the placeholder photo');
    }

    public function test_checksums_and_zip_exclusions_agree(): void
    {
        $build = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/tests/release-gate/build-zip.sh');
        foreach (self::NOT_SHIPPED as $f) {
            $this->assertSame(2, substr_count($build, '"' . $f . '"'), "$f must be excluded from both checksums.json and the zip");
        }
    }

    public function test_the_site_copy_is_gitignored_and_a_seed_ships_instead(): void
    {
        $root   = $_SERVER['DOCUMENT_ROOT'];
        $ignore = (string) file_get_contents($root . '/.gitignore');
        foreach (self::NOT_SHIPPED as $f) {
            $this->assertMatchesRegularExpression('#^/' . preg_quote($f, '#') . '$#m', $ignore, "$f is the site's, never committed");
            $seed = preg_replace('/\.json$/', '.seed.json', $f);
            $this->assertFileExists($root . '/' . $seed);
            $this->assertIsArray(json_decode((string) file_get_contents($root . '/' . $seed), true));
        }
        $tmp = sys_get_temp_dir() . '/om-seed-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/centres.seed.json', '[{"name_bg":"А"}]');
            $this->assertSame([['name_bg' => 'А']], load_json($tmp . '/centres.json'), 'no site copy yet: the seed');
            save_json($tmp . '/centres.json', [['name_bg' => 'Б']]);
            $this->assertSame([['name_bg' => 'Б']], load_json($tmp . '/centres.json'), 'once saved, the site copy');
            $this->assertSame('[{"name_bg":"А"}]', file_get_contents($tmp . '/centres.seed.json'), 'the seed is never written');
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    public function test_a_missing_content_file_reads_as_an_empty_list(): void
    {
        $this->assertSame([], load_json(sys_get_temp_dir() . '/om-missing-' . bin2hex(random_bytes(4)) . '/centres.json'));
    }

    public function test_the_built_zip_leaves_them_out_but_keeps_the_placeholder_photo(): void
    {
        if (trim((string) shell_exec('command -v zip')) === '') $this->markTestSkipped('zip is not installed');
        $root = dirname(__DIR__);
        $tree = sys_get_temp_dir() . '/om-relzip-' . bin2hex(random_bytes(4));
        mkdir($tree . '/content', 0755, true);
        mkdir($tree . '/assets/images', 0755, true);
        file_put_contents($tree . '/index.php', '<?php');
        file_put_contents($tree . '/assets/images/hero.webp', 'x');
        foreach (self::NOT_SHIPPED as $f) file_put_contents("$tree/$f", '[]');
        try {
            $zip = $tree . '/out.zip';
            exec('cd ' . escapeshellarg($tree) . ' && bash ' . escapeshellarg($root . '/tests/release-gate/build-zip.sh')
                . ' ' . escapeshellarg($zip) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $z = new ZipArchive();
            $this->assertTrue($z->open($zip) === true);
            $names = [];
            for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
            $z->close();
            $sums = json_decode((string) file_get_contents($tree . '/checksums.json'), true);
            $this->assertContains('assets/images/hero.webp', $names);
            foreach (self::NOT_SHIPPED as $f) {
                $this->assertNotContains($f, $names);
                $this->assertArrayNotHasKey($f, $sums);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    }

    public function test_vendor_is_blocked_from_the_web(): void
    {
        $ht = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/.htaccess');
        $this->assertStringContainsString('RewriteRule ^vendor(/|$) - [F,L]', $ht);
        $this->assertLessThan(strpos($ht, '# Force HTTPS'), strpos($ht, '^vendor(/|$)'), 'with the other blocks, before any routing');
    }
}
