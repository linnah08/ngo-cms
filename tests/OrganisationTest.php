<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * includes/organisation.php — admin-editable organisation identity
 * (admin/organisation.php) that overrides the site.config.php constants.
 *
 * Anything that define()s constants runs in a child PHP process so the test
 * process's own SITE_* constants are never touched.
 */
final class OrganisationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';
        $this->tmp = sys_get_temp_dir() . '/om-org-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    private function validInput(array $over = []): array
    {
        return array_merge([
            'site_name_bg'     => 'Фондация Тест',
            'site_name_en'     => 'Test Foundation',
            'site_email'       => 'info@example.org',
            'site_phone'       => '+359 88 123 4567',
            'site_iban'        => 'bg80 bnbg 9661 1020 3456 78',
            'site_bic'         => 'bnbgbgsd',
            'site_bank_name'   => 'БНБ',
            'brand_theme'      => 'modern',
            'brand_primary'    => '#2d3a8c',
            'brand_accent'     => '#5B6FD6',
            'social_facebook'  => 'https://www.facebook.com/test',
            'social_instagram' => '',
            'social_linkedin'  => '',
        ], $over);
    }

    // ── IBAN / BIC / colour ──────────────────────────────────────────────────────

    public function test_valid_ibans_pass_including_spaces_and_lowercase(): void
    {
        $this->assertTrue(org_iban_valid('BG80BNBG96611020345678'));
        $this->assertTrue(org_iban_valid('bg80 bnbg 9661 1020 3456 78'));
        $this->assertTrue(org_iban_valid('DE89 3704 0044 0532 0130 00'));
        $this->assertTrue(org_iban_valid('GB82-WEST-1234-5698-7654-32'));
    }

    public function test_single_mistyped_digit_fails_checksum(): void
    {
        $this->assertFalse(org_iban_valid('BG80BNBG96611020345679'));
        $this->assertFalse(org_iban_valid('BG81BNBG96611020345678'));
    }

    public function test_malformed_ibans_fail(): void
    {
        foreach (['', 'BG80', 'not an iban', '1280BNBG96611020345678', 'BG80BNBG9661102034567$'] as $bad) {
            $this->assertFalse(org_iban_valid($bad), $bad);
        }
    }

    public function test_bic(): void
    {
        $this->assertTrue(org_bic_valid('STSABGSF'));
        $this->assertTrue(org_bic_valid('stsabgsfxxx'));
        $this->assertFalse(org_bic_valid('STSABG'));
        $this->assertFalse(org_bic_valid('STSABGSFXX'));
        $this->assertFalse(org_bic_valid('1TSABGSF'));
    }

    public function test_colour(): void
    {
        $this->assertTrue(org_color_valid('#0387A5'));
        $this->assertTrue(org_color_valid('#abcdef'));
        $this->assertFalse(org_color_valid('0387A5'));
        $this->assertFalse(org_color_valid('#FFF'));
        $this->assertFalse(org_color_valid('red'));
        $this->assertFalse(org_color_valid('#0387A5;background:url(x)'));
    }

    // ── org_validate() ───────────────────────────────────────────────────────────

    public function test_validate_accepts_and_normalises_good_input(): void
    {
        $r = org_validate($this->validInput(), array_keys(brand_themes()));
        $this->assertSame([], $r['errors']);
        $this->assertSame('BG80BNBG96611020345678', $r['values']['site_iban']);
        $this->assertSame('BNBGBGSD', $r['values']['site_bic']);
        $this->assertSame('#2D3A8C', $r['values']['brand_primary']);
    }

    public function test_optional_fields_may_be_empty(): void
    {
        $r = org_validate($this->validInput([
            'site_phone' => '', 'site_iban' => '', 'site_bic' => '', 'site_bank_name' => '', 'social_facebook' => '',
        ]), array_keys(brand_themes()));
        $this->assertSame([], $r['errors']);
        $this->assertSame('', $r['values']['site_iban']);
    }

    public function test_validate_reports_each_bad_field(): void
    {
        $r = org_validate($this->validInput([
            'site_name_bg'    => '  ',
            'site_email'      => 'not-an-email',
            'site_phone'      => '<script>',
            'site_iban'       => 'BG80BNBG96611020345679',
            'site_bic'        => 'XX',
            'brand_theme'     => 'hacker',
            'brand_primary'   => 'red',
            'social_facebook' => 'javascript:alert(1)',
        ]), array_keys(brand_themes()));
        foreach (['site_name_bg', 'site_email', 'site_phone', 'site_iban', 'site_bic', 'brand_theme', 'brand_primary', 'social_facebook'] as $k) {
            $this->assertArrayHasKey($k, $r['errors'], $k);
        }
        $this->assertArrayNotHasKey('site_name_en', $r['errors']);
    }

    public function test_validate_ignores_non_string_and_unknown_input(): void
    {
        $r = org_validate($this->validInput(['site_name_en' => ['array'], 'evil' => 'x']), array_keys(brand_themes()));
        $this->assertArrayHasKey('site_name_en', $r['errors']);
        $this->assertArrayNotHasKey('evil', $r['values']);
    }

    // ── Save / load round trip ───────────────────────────────────────────────────

    public function test_save_then_load_round_trip_keeps_empty_values(): void
    {
        $file = $this->tmp . '/organisation.json';
        $vals = org_validate($this->validInput(['site_phone' => '']), array_keys(brand_themes()))['values'];
        $this->assertTrue(org_save_overrides($vals + ['evil' => 'x'], $file));

        $loaded = org_load_overrides($file);
        $this->assertSame('Фондация Тест', $loaded['SITE_NAME_BG']);
        $this->assertSame('BG80BNBG96611020345678', $loaded['SITE_IBAN']);
        // An explicitly cleared phone must override a phone in site.config.php.
        $this->assertArrayHasKey('SITE_PHONE', $loaded);
        $this->assertSame('', $loaded['SITE_PHONE']);
        $this->assertCount(count(org_fields()), $loaded);
        $this->assertStringNotContainsString('evil', (string) file_get_contents($file));
        $this->assertSame([$file], glob($this->tmp . '/*'), 'no temp files left behind');
    }

    public function test_load_tolerates_missing_or_broken_file(): void
    {
        $this->assertSame([], org_load_overrides($this->tmp . '/missing.json'));
        file_put_contents($this->tmp . '/broken.json', '{not json');
        $this->assertSame([], org_load_overrides($this->tmp . '/broken.json'));
        file_put_contents($this->tmp . '/types.json', json_encode(['site_iban' => ['x'], 'site_bic' => 5, 'site_email' => 'a@b.bg']));
        $this->assertSame(['SITE_EMAIL' => 'a@b.bg'], org_load_overrides($this->tmp . '/types.json'));
    }

    // ── Override beats site.config.php (child process) ───────────────────────────

    private function runPhp(string $code): array
    {
        $script = $this->tmp . '/run.php';
        file_put_contents($script, $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    public function test_saved_values_override_site_config_without_warnings(): void
    {
        file_put_contents($this->tmp . '/site.config.php', "<?php\n"
            . "define('SITE_IBAN', 'OLD-IBAN');\n"
            . "define('SITE_NAME_BG', 'Старо име');\n"
            . "define('SITE_URL', 'https://example.org');\n");
        file_put_contents($this->tmp . '/organisation.json', json_encode(['site_iban' => 'BG80BNBG96611020345678']));

        $inc = var_export($_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php', true);
        $dir = var_export($this->tmp, true);
        [$rc, $out] = $this->runPhp("<?php
            require {$inc};
            \$pre = org_define_overrides(org_load_overrides({$dir} . '/organisation.json'));
            org_require_config({$dir} . '/site.config.php', \$pre);
            echo SITE_IBAN, '|', SITE_NAME_BG, '|', SITE_URL;
        ");
        $this->assertSame(0, $rc, $out);
        $this->assertSame('BG80BNBG96611020345678|Старо име|https://example.org', $out, 'no warning output expected');
    }

    public function test_unrelated_warnings_still_reach_the_error_handler(): void
    {
        file_put_contents($this->tmp . '/site.config.php', "<?php\n"
            . "define('SITE_IBAN', 'OLD');\n"
            . "define('OTHER_CONST', 1);\n"
            . "define('OTHER_CONST', 2);\n");

        $inc = var_export($_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php', true);
        $dir = var_export($this->tmp, true);
        [$rc, $out] = $this->runPhp("<?php
            require {$inc};
            \$seen = [];
            set_error_handler(function (\$no, \$str) use (&\$seen) { \$seen[] = \$str; return true; });
            \$pre = org_define_overrides(['SITE_IBAN' => 'NEW']);
            org_require_config({$dir} . '/site.config.php', \$pre);
            echo SITE_IBAN, '|', json_encode(\$seen);
        ");
        $this->assertSame(0, $rc, $out);
        $this->assertSame('NEW|["Constant OTHER_CONST already defined"]', $out);
    }

    // ── Logo upload ──────────────────────────────────────────────────────────────

    private function upload(string $path): array
    {
        return ['name' => basename($path), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    private function makeImage(string $type, int $w = 200, int $h = 100): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 3, 135, 165));
        $path = $this->tmp . '/src-' . bin2hex(random_bytes(3));
        match ($type) {
            'png'  => imagepng($im, $path),
            'jpeg' => imagejpeg($im, $path),
            'webp' => imagewebp($im, $path),
            'gif'  => imagegif($im, $path),
        };
        imagedestroy($im);
        return $path;
    }

    public function test_logo_png_jpeg_webp_saved_as_png_with_favicon(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        foreach (['png', 'jpeg', 'webp'] as $type) {
            @unlink($this->tmp . '/logo.png');
            @unlink($this->tmp . '/favicon.png');
            $src = $this->makeImage($type);
            $this->assertNull(org_save_logo($this->upload($src), $this->tmp, false), $type);

            $logo = getimagesize($this->tmp . '/logo.png');
            $this->assertSame('image/png', $logo['mime'], $type);
            $this->assertSame([200, 100], [$logo[0], $logo[1]], $type);
            $fav = getimagesize($this->tmp . '/favicon.png');
            $this->assertSame([64, 64], [$fav[0], $fav[1]], $type);
        }
    }

    public function test_logo_rejects_non_images_and_wrong_types(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');

        $php = $this->tmp . '/evil.png';
        file_put_contents($php, "<?php echo 'pwned';");
        $this->assertNotNull(org_save_logo($this->upload($php), $this->tmp, false));

        // PNG header followed by PHP — getimagesize() can't parse it.
        $polyglot = $this->tmp . '/poly.png';
        file_put_contents($polyglot, "\x89PNG\r\n\x1a\n<?php echo 1; ?>");
        $this->assertNotNull(org_save_logo($this->upload($polyglot), $this->tmp, false));

        $gif = $this->makeImage('gif');
        $this->assertNotNull(org_save_logo($this->upload($gif), $this->tmp, false));

        $this->assertFileDoesNotExist($this->tmp . '/logo.png');
    }

    public function test_logo_rejects_oversize_and_upload_errors(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        $big = $this->makeImage('png', ORG_LOGO_MAX_SIDE + 1, 10);
        $this->assertStringContainsString('размери', (string) org_save_logo($this->upload($big), $this->tmp, false));

        $heavy = $this->tmp . '/heavy.png';
        file_put_contents($heavy, str_repeat('x', ORG_LOGO_MAX_BYTES + 1));
        $this->assertStringContainsString('2 MB', (string) org_save_logo($this->upload($heavy), $this->tmp, false));

        $this->assertStringContainsString('2 MB', (string) org_save_logo(['error' => UPLOAD_ERR_INI_SIZE], $this->tmp, false));
        $this->assertNotNull(org_save_logo(['error' => UPLOAD_ERR_PARTIAL], $this->tmp, false));

        // A real image that wasn't uploaded via HTTP is refused in production mode.
        $png = $this->makeImage('png');
        $this->assertNotNull(org_save_logo($this->upload($png), $this->tmp));
        $this->assertFileDoesNotExist($this->tmp . '/logo.png');
    }

    // ── Updater never overwrites the owner's logo ────────────────────────────────

    public function test_updater_keeps_live_logo_and_favicon(): void
    {
        $new  = ['assets/images/logo.png', 'assets/images/favicon.png', 'config.php'];
        $live = fn(string $p): ?string => 'hash-' . $p;
        $this->assertSame(['assets/images/logo.png', 'assets/images/favicon.png'], updater_owner_files_to_keep($new, $live));
    }

    public function test_updater_installs_logo_when_none_exists(): void
    {
        $new  = ['assets/images/logo.png', 'assets/images/favicon.png'];
        $none = fn(string $p): ?string => null;
        $this->assertSame([], updater_owner_files_to_keep($new, $none));
    }

    // ── Admin page guards (static) ───────────────────────────────────────────────

    public function test_admin_page_requires_admin_and_csrf(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/organisation.php');
        $auth = strpos($src, 'admin_require_admin();');
        $this->assertNotFalse($auth);
        $this->assertLessThan(strpos($src, "\$_SERVER['REQUEST_METHOD'] === 'POST'"), $auth, 'auth before POST handling');
        $this->assertStringContainsString('csrf_verify()', $src);
        $this->assertStringContainsString('csrf_field()', $src);
        $this->assertStringNotContainsString('window.confirm', $src);
    }
}
