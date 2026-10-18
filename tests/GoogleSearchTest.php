<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Google, from Admin → Организация → Google: the Search Console code, the
 * analytics / ads IDs, the "hide from search engines" switch — and robots.txt,
 * which names the sitemap by its full address.
 *
 * Pages and robots.php run in a child PHP process with the constants defined
 * first, so the test process's own constants are never touched.
 */
final class GoogleSearchTest extends TestCase
{
    private const CODE = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7dE9f';

    private string $tmp;

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
        $this->tmp = sys_get_temp_dir() . '/om-google-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    /** Run $file (path from the site root) with $consts defined first; returns its output. */
    private function runPage(array $consts, string $file, string $uri = '/'): string
    {
        $root    = $_SERVER['DOCUMENT_ROOT'];
        $defines = '';
        foreach ($consts as $name => $value) {
            $defines .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $script = $this->tmp . '/run.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = " . var_export($root, true) . ";
            \$_SERVER['REQUEST_URI']   = " . var_export($uri, true) . ";
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['HTTP_HOST']     = 'localhost';
            {$defines}
            require " . var_export($root . '/config.php', true) . ";
            \$page_title = 'Test';
            require " . var_export($root . '/' . $file, true) . ";
        ");
        // A constant the site's own config also defines warns "already defined" —
        // expected here, so warnings stay out of the captured output.
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d error_log=/dev/null ' . escapeshellarg($script), $out, $rc);
        $text = implode("\n", $out);
        $this->assertSame(0, $rc, $text);
        return $text;
    }

    // ── Search Console code ──────────────────────────────────────────────────

    public function test_verification_code_is_taken_from_whatever_google_shows(): void
    {
        $this->assertSame(self::CODE, org_google_verification_code(self::CODE));
        $this->assertSame(self::CODE, org_google_verification_code('  ' . self::CODE . "\n"));
        $this->assertSame(self::CODE, org_google_verification_code('<meta name="google-site-verification" content="' . self::CODE . '" />'));
        $this->assertSame(self::CODE, org_google_verification_code("<meta name='google-site-verification' content='" . self::CODE . "'>"));
        $this->assertSame(self::CODE, org_google_verification_code('google-site-verification=' . self::CODE), 'the DNS record form');
    }

    public function test_verification_code_rejects_anything_else(): void
    {
        foreach (['', 'short', 'google1234567890abcdef.html', self::CODE . '"><script>alert(1)</script>',
                  '<meta content="abc">', 'код на кирилица който е достатъчно дълъг'] as $bad) {
            $this->assertNull(org_google_verification_code($bad), $bad);
        }
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_ids_are_cleaned_and_checked(): void
    {
        $v = ['google_site_verification' => '<meta name="google-site-verification" content="' . self::CODE . '" />',
              'ga4_id' => ' g-ab12cd34ef ', 'gtm_id' => 'gtm-ab12cd3', 'google_ads_id' => 'aw-123456789',
              'google_ads_purchase_label' => 'AbC-12_x'];
        $this->assertSame([], org_google_validate($v));
        $this->assertSame(self::CODE, $v['google_site_verification']);
        $this->assertSame('G-AB12CD34EF', $v['ga4_id']);
        $this->assertSame('GTM-AB12CD3', $v['gtm_id']);
        $this->assertSame('AW-123456789', $v['google_ads_id']);
    }

    public function test_all_google_fields_may_be_empty(): void
    {
        $v = [];
        $this->assertSame([], org_google_validate($v));
        $this->assertSame('', $v['ga4_id']);
    }

    public function test_bad_ids_get_a_plain_message_each(): void
    {
        $v = ['google_site_verification' => 'not a code', 'ga4_id' => 'UA-1234567-1', 'gtm_id' => 'GTM-<x>',
              'google_ads_id' => 'AW-abc', 'google_ads_purchase_label' => "x');alert(1);//"];
        $e = org_google_validate($v);
        $this->assertSame(['google_site_verification', 'ga4_id', 'gtm_id', 'google_ads_id', 'google_ads_purchase_label'], array_keys($e));
        $this->assertStringContainsString('UA-', $e['ga4_id'], 'an old Universal Analytics ID is explained, not just refused');

        $v = ['ga4_id' => 'G-12;alert(1)'];
        $this->assertArrayHasKey('ga4_id', org_google_validate($v));
    }

    public function test_the_organisation_form_saves_the_google_fields_and_the_hide_switch(): void
    {
        $in = ['site_name_bg' => 'Фондация', 'site_name_en' => 'Foundation', 'site_email' => 'info@example.org',
               'brand_theme' => 'classic', 'brand_primary' => '#112233', 'brand_accent' => '#445566',
               'ga4_id' => 'g-ab12cd34ef', 'google_site_verification' => self::CODE, 'site_noindex' => '1'];
        $r = org_validate($in, ['classic']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('G-AB12CD34EF', $r['values']['ga4_id']);
        $this->assertSame(self::CODE, $r['values']['google_site_verification']);
        $this->assertSame('1', $r['values']['site_noindex']);

        unset($in['site_noindex']);   // an unticked box is not posted
        $this->assertSame('0', org_validate($in, ['classic'])['values']['site_noindex'], 'stored as "0" so it overrides site.config.php');

        $in['gtm_id'] = 'nope';
        $this->assertArrayHasKey('gtm_id', org_validate($in, ['classic'])['errors']);
    }

    public function test_saved_values_override_the_site_config_constants(): void
    {
        $file = $this->tmp . '/organisation.json';
        file_put_contents($file, json_encode(['site_noindex' => '0', 'ga4_id' => 'G-AB12CD34EF', 'google_site_verification' => self::CODE]));
        $o = org_load_overrides($file);
        $this->assertSame('0', $o['SITE_NOINDEX']);
        $this->assertSame('G-AB12CD34EF', $o['GA4_ID']);
        $this->assertSame(self::CODE, $o['GOOGLE_SITE_VERIFICATION']);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function test_header_carries_the_verification_code_even_while_hidden(): void
    {
        $tag  = '<meta name="google-site-verification" content="' . self::CODE . '">';
        $html = $this->runPage(['GOOGLE_SITE_VERIFICATION' => self::CODE, 'SITE_NOINDEX' => '1'], 'templates/header.php');
        $this->assertStringContainsString($tag, $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);

        $this->assertStringNotContainsString('google-site-verification', $this->runPage(['GOOGLE_SITE_VERIFICATION' => ''], 'templates/header.php'));
    }

    public function test_unticking_hide_from_search_engines_lets_the_site_be_indexed(): void
    {
        // The admin saves '0', which must win over a site.config.php true.
        $html = $this->runPage(['SITE_NOINDEX' => '0'], 'templates/header.php');
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_robots_txt_names_the_sitemap_by_its_full_address(): void
    {
        $txt = $this->runPage(['SITE_URL' => 'https://ngo.example/'], 'robots.php', '/robots.txt');
        $this->assertStringContainsString("Sitemap: https://ngo.example/sitemap.xml\n", $txt . "\n");
        $this->assertStringContainsString("User-agent: *\nDisallow: /admin/", $txt);
        $this->assertStringNotContainsString("Disallow: /\n", $txt . "\n", 'crawling stays allowed, also while hidden: a crawler must read the noindex tag');
    }

    public function test_robots_txt_is_served_by_robots_php(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $this->assertStringContainsString('RewriteRule ^robots\.txt$ /robots.php [L]', (string) file_get_contents($root . '/.htaccess'));
        $this->assertFileDoesNotExist($root . '/robots.txt', 'a static robots.txt would carry a relative, ignored Sitemap line');
    }
}
