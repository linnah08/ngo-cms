<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ExifJpeg.php';

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

    public function test_order_prefix_is_optional_uppercased_and_checked(): void
    {
        $themes = array_keys(brand_themes());
        $this->assertSame('', org_validate($this->validInput(['order_prefix' => '']), $themes)['values']['order_prefix']);

        $r = org_validate($this->validInput(['order_prefix' => ' lf ']), $themes);
        $this->assertSame([], $r['errors']);
        $this->assertSame('LF', $r['values']['order_prefix']);

        foreach (['CP', 'L', 'ЛФ', 'AB-1', 'ABCDEF'] as $bad) {
            $this->assertArrayHasKey('order_prefix', org_validate($this->validInput(['order_prefix' => $bad]), $themes)['errors'], $bad);
        }
    }

    public function test_legal_name_is_optional_but_needs_both_languages(): void
    {
        $themes = array_keys(brand_themes());

        $none = org_validate($this->validInput(['site_legal_name_bg' => '', 'site_legal_name_en' => '']), $themes);
        $this->assertSame([], $none['errors']);

        $both = org_validate($this->validInput([
            'site_legal_name_bg' => '  Фондация Различни умове ', 'site_legal_name_en' => 'Odd Minds Foundation',
        ]), $themes);
        $this->assertSame([], $both['errors']);
        $this->assertSame('Фондация Различни умове', $both['values']['site_legal_name_bg']);

        $bgOnly = org_validate($this->validInput(['site_legal_name_bg' => 'Фондация Различни умове']), $themes);
        $this->assertArrayHasKey('site_legal_name_en', $bgOnly['errors']);
        $this->assertArrayNotHasKey('site_legal_name_bg', $bgOnly['errors']);

        $enOnly = org_validate($this->validInput(['site_legal_name_en' => 'Odd Minds Foundation']), $themes);
        $this->assertArrayHasKey('site_legal_name_bg', $enOnly['errors']);

        $long = org_validate($this->validInput([
            'site_legal_name_bg' => str_repeat('я', 151), 'site_legal_name_en' => 'X Foundation',
        ]), $themes);
        $this->assertArrayHasKey('site_legal_name_bg', $long['errors']);
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
        // [] = the list of admins who may sign (admin/organisation.php passes theirs).
        $vals = org_validate($this->validInput(['site_phone' => '']), array_keys(brand_themes()), [])['values'];
        $this->assertTrue(org_save_overrides($vals + ['evil' => 'x'], $file));

        $loaded = org_load_overrides($file);
        $this->assertSame('Фондация Тест', $loaded['SITE_NAME_BG']);
        $this->assertSame('BG80BNBG96611020345678', $loaded['SITE_IBAN']);
        // An explicitly cleared phone must override a phone in site.config.php.
        $this->assertArrayHasKey('SITE_PHONE', $loaded);
        $this->assertSame('', $loaded['SITE_PHONE']);
        // Every field of the Организация form; the module switches (Admin → Модули) and the
        // launch state (Пусни сайта) are saved elsewhere, and hidden sections keep their values.
        $this->assertCount(count(org_fields()) - count(org_system_fields()) - count(org_hidden_fields()), $loaded);
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

    /** A logo photographed on a phone is saved the way the browser showed it, not sideways. */
    public function test_logo_photo_with_a_rotate_flag_is_saved_upright(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('exif_read_data')) {
            $this->markTestSkipped('GD or exif not available');
        }
        $src = $this->tmp . '/phone-logo.jpg';
        ExifJpeg::write($src, 200, 100, 6);
        $this->assertNull(org_save_logo($this->upload($src), $this->tmp, false));

        $im = imagecreatefrompng($this->tmp . '/logo.png');
        $this->assertSame([100, 200], [imagesx($im), imagesy($im)]);
        [$x, $y] = ExifJpeg::upright(ExifJpeg::MARK_X, ExifJpeg::MARK_Y, 200, 100, 6);
        $this->assertTrue(ExifJpeg::isRed($im, $x, $y));
        imagedestroy($im);
    }

    /**
     * Some of these organisations are on a .бг domain. filter_var() refuses
     * such an address outright, so the website field rejected their own site.
     */
    public function test_website_accepts_a_cyrillic_address(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension not installed');
        }

        $this->assertTrue(org_url_valid('https://фондация.бг'));
        $this->assertTrue(org_url_valid('https://пример.бг/за-нас'));
        $this->assertTrue(org_url_valid('https://example.org'));

        $this->assertFalse(org_url_valid('javascript:alert(1)'));
        $this->assertFalse(org_url_valid('ftp://example.org'));
        $this->assertFalse(org_url_valid('not a url'));
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

    // ── Sharing: description and link-preview picture ───────────────────────────

    public function test_description_is_one_line_and_has_a_length_limit(): void
    {
        $r = org_validate($this->validInput(['site_description_bg' => "  Подкрепяме\n деца   и семейства. "]), ['classic']);
        $this->assertSame('Подкрепяме деца и семейства.', $r['values']['site_description_bg']);
        $this->assertArrayNotHasKey('site_description_bg', $r['errors']);

        $r = org_validate($this->validInput(['site_description_en' => str_repeat('a', ORG_DESCRIPTION_MAX + 1)]), ['classic']);
        $this->assertStringContainsString('твърде дълго', $r['errors']['site_description_en']);
    }

    public function test_description_falls_back_to_the_site_name_in_the_same_language(): void
    {
        $inc = var_export($_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php', true);
        [$rc, $out] = $this->runPhp("<?php
            require {$inc};
            define('SITE_NAME_BG', 'Фондация'); define('SITE_NAME_EN', 'Foundation');
            define('SITE_DESCRIPTION_BG', 'Описание');
            echo org_site_description('bg'), '|', org_site_description('en');
        ");
        $this->assertSame(0, $rc, $out);
        $this->assertSame('Описание|Foundation', $out);
    }

    public function test_share_image_is_made_from_the_logo_with_bands_in_the_main_colour(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        $logo = $this->makeImage('png', 300, 100);   // a teal block
        $this->assertTrue(org_make_share_image($logo, $this->tmp . '/share.png', '#FF0000'));

        $im = imagecreatefrompng($this->tmp . '/share.png');
        $this->assertSame([ORG_SHARE_W, ORG_SHARE_H], [imagesx($im), imagesy($im)]);
        $rgb = static fn(int $x, int $y): array => array_values(array_slice(imagecolorsforindex($im, imagecolorat($im, $x, $y)), 0, 3));
        $this->assertSame([255, 0, 0], $rgb(600, 3), 'top band');
        $this->assertSame([255, 0, 0], $rgb(600, ORG_SHARE_H - 3), 'bottom band');
        $this->assertSame([255, 255, 255], $rgb(20, 315), 'white card');
        $this->assertSame([3, 135, 165], $rgb(600, 315), 'logo in the middle');
        imagedestroy($im);

        $this->assertFalse(org_make_share_image($this->tmp . '/missing.png', $this->tmp . '/x.png'));
    }

    public function test_uploaded_share_image_is_cropped_to_1200_by_630(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        foreach (['png', 'jpeg', 'webp'] as $type) {
            $this->assertNull(org_save_share_image($this->upload($this->makeImage($type, 2000, 2000)), $this->tmp, false), $type);
            $info = getimagesize($this->tmp . '/share-upload.jpg');
            $this->assertSame(['image/jpeg', ORG_SHARE_W, ORG_SHARE_H], [$info['mime'], $info[0], $info[1]], $type);
        }
    }

    public function test_uploaded_share_image_rejects_non_images(): void
    {
        $php = $this->tmp . '/x.png';
        file_put_contents($php, '<?php echo 1;');
        $this->assertStringContainsString('Картинката за споделяне', (string) org_save_share_image($this->upload($php), $this->tmp, false));
        $this->assertStringContainsString('2 MB', (string) org_save_share_image(['error' => UPLOAD_ERR_INI_SIZE], $this->tmp, false));
        $this->assertNotNull(org_save_share_image($this->upload($this->makeImage('png')), $this->tmp));   // not a real upload
        $this->assertFileDoesNotExist($this->tmp . '/share-upload.jpg');
    }

    public function test_share_image_path_prefers_upload_then_logo_card_then_logo(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        $root = $this->tmp . '/site';
        $dir  = $root . '/assets/images';
        mkdir($dir, 0777, true);
        try {
            $this->assertSame('/assets/images/logo.png', org_share_image_path($root), 'nothing at all');

            copy($this->makeImage('png'), $dir . '/logo.png');
            $this->assertSame('/assets/images/share.png', org_share_image_path($root), 'made from the logo on demand');
            $this->assertFileExists($dir . '/share.png');

            touch($dir . '/share-upload.jpg');
            $this->assertSame('/assets/images/share-upload.jpg', org_share_image_path($root));
            $this->assertMatchesRegularExpression('#^/assets/images/share-upload\.jpg\?v=\d+$#', org_share_image_url($root));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    // ── The site's own logo and favicon live at a path no release ships ─────────

    /** A site root with assets/images/, optionally holding the shipped defaults. */
    private function brandRoot(bool $withDefaults = true): string
    {
        $root = $this->tmp . '/brand-' . bin2hex(random_bytes(3));
        mkdir($root . '/assets/images', 0777, true);
        if ($withDefaults) {
            foreach (['logo.png', 'favicon.png'] as $n) {
                copy($_SERVER['DOCUMENT_ROOT'] . '/assets/images/' . $n, "$root/assets/images/$n");
            }
        }
        return $root;
    }

    public function test_the_shipped_defaults_are_known_as_defaults(): void
    {
        foreach (['logo.png', 'favicon.png'] as $n) {
            $this->assertContains(hash_file('sha256', $_SERVER['DOCUMENT_ROOT'] . '/assets/images/' . $n), ORG_BRAND_DEFAULT_HASHES[$n],
                "assets/images/$n changed: add its hash to ORG_BRAND_DEFAULT_HASHES, or every site would take the new default for its own logo");
        }
        $this->assertMatchesRegularExpression('#^/' . preg_quote(ORG_BRAND_DIR, '#') . '/$#m',
            (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/.gitignore'), 'the site\'s own logo is never committed');
        $this->assertStringNotContainsString(ORG_BRAND_DIR, implode("\n", updater_owner_files()), 'nothing to keep: no release ships it');
    }

    public function test_a_fresh_site_shows_the_shipped_default(): void
    {
        $root = $this->brandRoot();
        try {
            $this->assertSame('assets/images/logo.png', org_brand_image('logo.png', $root));
            $this->assertSame('assets/images/favicon.png', org_brand_image('favicon.png', $root));
            $this->assertDirectoryDoesNotExist($root . '/' . ORG_BRAND_DIR, 'the default is never copied');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_an_uploaded_logo_lands_in_the_sites_own_folder_and_wins(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        $root = $this->brandRoot();
        try {
            $this->assertNull(org_save_logo($this->upload($this->makeImage('png')), org_brand_dir($root), false));
            $this->assertSame(ORG_BRAND_DIR . '/logo.png', org_brand_image('logo.png', $root));
            $this->assertSame(ORG_BRAND_DIR . '/favicon.png', org_brand_image('favicon.png', $root));
            $this->assertSame(hash_file('sha256', $_SERVER['DOCUMENT_ROOT'] . '/assets/images/logo.png'),
                hash_file('sha256', $root . '/assets/images/logo.png'), 'the shipped default is left as it is');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * A site that had its own logo before the new folder existed — uploaded in
     * Организация, or committed in a fork (lafetki) — keeps it: the updater
     * keeps the live copy at the old path, and the first read moves a copy to
     * the site's own folder, so later releases and deploys can't touch it.
     */
    public function test_a_sites_own_logo_at_the_old_path_is_carried_over(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not available');
        $root = $this->brandRoot();
        try {
            $mine = $this->makeImage('png', 300, 120);
            copy($mine, $root . '/assets/images/logo.png');
            $this->assertSame(['assets/images/logo.png'],
                updater_owner_files_to_keep(['assets/images/logo.png', 'index.php'], fn(string $p): ?string => is_file("$root/$p") ? 'live' : null),
                'an update keeps it where it is');

            $this->assertSame(ORG_BRAND_DIR . '/logo.png', org_brand_image('logo.png', $root));
            $this->assertFileEquals($mine, $root . '/' . ORG_BRAND_DIR . '/logo.png');
            $this->assertFileEquals($mine, $root . '/assets/images/logo.png', 'the old copy stays');
            $this->assertSame('assets/images/favicon.png', org_brand_image('favicon.png', $root), 'a default favicon is not carried over');

            // A later upload replaces the carried-over copy, not the old path.
            $this->assertNull(org_save_logo($this->upload($this->makeImage('png', 50, 50)), org_brand_dir($root), false));
            $this->assertSame([50, 50], array_slice(getimagesize(org_brand_image_file('logo.png', $root)), 0, 2));
            $this->assertFileEquals($mine, $root . '/assets/images/logo.png');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_the_old_path_is_used_as_it_is_when_it_cannot_be_copied(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) $this->markTestSkipped('root can write anywhere');
        $root = $this->brandRoot();
        try {
            file_put_contents($root . '/assets/images/favicon.png', 'a site\'s own favicon');
            chmod($root . '/assets/images', 0555);
            $this->assertSame('assets/images/favicon.png', org_brand_image('favicon.png', $root));
            $this->assertDirectoryDoesNotExist($root . '/' . ORG_BRAND_DIR);
        } finally {
            chmod($root . '/assets/images', 0777);
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_every_reader_asks_for_the_sites_own_logo(): void
    {
        // Only these may name the old path: the helper itself, the updater's
        // keep list, and fallbacks for code that may run without organisation.php.
        $allowed = ['includes/organisation.php', 'includes/updater.php', 'includes/mailer.php',
                    'includes/seo.php', 'includes/launch.php', 'errors/_layout.php', 'site.config.example.php'];
        $root = $_SERVER['DOCUMENT_ROOT'];
        $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $bad  = [];
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (!str_ends_with($rel, '.php') || preg_match('#^(vendor|node_modules|tests|docs|\.git)/#', $rel)) continue;
            if (in_array($rel, $allowed, true) || preg_match('#^[a-z]+\.config\.php$#', $rel)) continue;   // a site's own config
            if (preg_match('#assets/images/(logo|favicon)\.png#', (string) file_get_contents($f->getPathname()))) $bad[] = $rel;
        }
        $this->assertSame([], $bad, 'use logo_url() / favicon_url() / org_brand_image() instead');
    }

    // ── English address, representative and bank name ───────────────────────────

    public function test_english_address_representative_and_bank_fall_back_to_bulgarian(): void
    {
        $root = var_export($_SERVER['DOCUMENT_ROOT'], true);
        $run  = fn(string $defs) => $this->runPhp("<?php
            {$defs}
            if (!function_exists('h')) { function h(\$s) { return htmlspecialchars((string) \$s, ENT_QUOTES, 'UTF-8'); } }
            require {$root} . '/includes/organisation.php';
            require {$root} . '/includes/legal_pages.php';
            require {$root} . '/includes/legal_templates.php';
            echo json_encode([
                'bg'    => [org_text('SITE_ADDRESS'), org_text('SITE_MOL', 'bg'), org_text('SITE_BANK_NAME', 'bg')],
                'en'    => [org_text('SITE_ADDRESS', 'en'), org_text('SITE_MOL', 'en'), org_text('SITE_BANK_NAME', 'en')],
                'lines' => legal_en_identity_lines(true),
                'factsEn' => [legal_facts('en')['address'], legal_facts('en')['mol']],
                'factsBg' => [legal_facts('bg')['address'], legal_facts('bg')['mol']],
            ], JSON_UNESCAPED_UNICODE);
        ");
        $bg = "define('SITE_ADDRESS', 'гр. София, ул. Тест 1'); define('SITE_MOL', 'Иван Иванов'); define('SITE_BANK_NAME', 'Банка ДСК');";

        [$rc, $out] = $run($bg . "define('SITE_ADDRESS_EN', '1 Test St, Sofia'); define('SITE_MOL_EN', 'Ivan Ivanov'); define('SITE_BANK_NAME_EN', ' DSK Bank ');");
        $this->assertSame(0, $rc, $out);
        $r = json_decode($out, true);
        $this->assertSame(['гр. София, ул. Тест 1', 'Иван Иванов', 'Банка ДСК'], $r['bg']);
        $this->assertSame(['1 Test St, Sofia', 'Ivan Ivanov', 'DSK Bank'], $r['en']);
        $this->assertStringContainsString('Address: 1 Test St, Sofia', $r['lines']);
        $this->assertStringContainsString('Representative: Ivan Ivanov', $r['lines']);
        $this->assertSame(['1 Test St, Sofia', 'Ivan Ivanov'], $r['factsEn']);
        $this->assertSame(['гр. София, ул. Тест 1', 'Иван Иванов'], $r['factsBg']);

        // English left empty (or never set): the Bulgarian is printed, as before.
        [$rc, $out] = $run($bg . "define('SITE_ADDRESS_EN', '  ');");
        $this->assertSame(0, $rc, $out);
        $r = json_decode($out, true);
        $this->assertSame($r['bg'], $r['en']);
        $this->assertStringContainsString('Address: гр. София, ул. Тест 1', $r['lines']);
    }

    public function test_english_versions_are_saved_checked_and_translatable(): void
    {
        foreach (['site_address_en' => 'SITE_ADDRESS_EN', 'site_mol_en' => 'SITE_MOL_EN', 'site_bank_name_en' => 'SITE_BANK_NAME_EN'] as $k => $c) {
            $this->assertSame($c, org_fields()[$k] ?? null);
        }
        $r = org_validate($this->validInput(['site_address_en' => ' 1 Test St ', 'site_mol_en' => 'Ivan', 'site_bank_name_en' => 'DSK Bank']), ['teal']);
        $this->assertSame('1 Test St', $r['values']['site_address_en']);
        $this->assertSame('DSK Bank', $r['values']['site_bank_name_en']);
        $this->assertArrayNotHasKey('site_address_en', $r['errors']);

        $r = org_validate($this->validInput(['site_address_en' => str_repeat('a', 251), 'site_mol_en' => str_repeat('a', 151), 'site_bank_name_en' => str_repeat('a', 151)]), ['teal']);
        foreach (['site_address_en', 'site_mol_en', 'site_bank_name_en'] as $k) {
            $this->assertArrayHasKey($k, $r['errors']);
        }

        $form = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/organisation.php');
        foreach (['site_address_en' => 'site_address', 'site_mol_en' => 'site_mol', 'site_bank_name_en' => 'site_bank_name'] as $en => $bg) {
            $this->assertMatchesRegularExpression('/name="' . $en . '" data-translate-from="' . $bg . '"/', $form);
        }
        // English documents and the English legal page read the English versions.
        $this->assertStringContainsString("org_text('SITE_ADDRESS', \$lang)", (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php'));
        $this->assertStringContainsString("legal_const_en('SITE_BANK_NAME')", (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/en/legal/index.php'));
    }
}
