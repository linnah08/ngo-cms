<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own permanent redirects (includes/site_extensions.php 'redirects'). */
final class SiteRedirectsTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    private function site(array $redirects): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, '<?php return ' . var_export(['redirects' => $redirects], true) . ';');
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_no_redirects_without_a_site_file(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $this->assertSame([], site_redirects());
        $this->assertNull(site_redirect_target('/anything/'));
    }

    public function test_only_paths_on_this_site_are_accepted(): void
    {
        $this->site([
            '/en/donate/'   => '/en/donation/',
            '/blog/*'       => '/novini/*',
            '/shop-old/*'   => '/magazin/',
            '/campaign/'    => 'https://other.example/',
            '/x/'           => '//evil.example/',
            '/y/'           => '/\\evil.example/',
            '/z/'           => 'javascript:alert(1)',
            'relative/'     => '/a/',
            '/exact/'       => '/new/*',
            '/same/'        => '/same/',
            '/mid*dle/'     => '/a/',
            '/num/'         => 5,
        ]);
        $this->assertSame(['/en/donate/', '/blog/*', '/shop-old/*'], array_keys(site_redirects()));
    }

    public function test_exact_and_prefix_matching(): void
    {
        $this->site([
            '/en/donate/'       => '/en/donation/',
            '/blog/*'           => '/novini/*',
            '/blog/archive/*'   => '/novini/',
            '/shop-old/*'       => '/magazin/',
            '/статия-стара/'    => '/novini/statiya/',
            '/to-cyrillic/'     => '/novini/нова/',
        ]);
        $this->assertSame('/en/donation/', site_redirect_target('/en/donate/'));
        $this->assertSame('/en/donation/', site_redirect_target('/en/donate'), 'with or without the trailing slash');
        $this->assertSame('/en/donation/?utm=x', site_redirect_target('/en/donate/?utm=x'), 'the query string is kept');
        $this->assertNull(site_redirect_target('/en/donate/more/'), 'an exact key matches only itself');
        $this->assertSame('/novini/some-post/', site_redirect_target('/blog/some-post/'));
        $this->assertSame('/novini/', site_redirect_target('/blog/archive/2019/'), 'the longest prefix wins');
        $this->assertSame('/magazin/', site_redirect_target('/shop-old/product/x/'));
        $this->assertSame('/novini/statiya/', site_redirect_target('/' . rawurlencode('статия-стара') . '/'), 'Cyrillic keys match the encoded address');
        $this->assertSame('/novini/' . rawurlencode('нова') . '/', site_redirect_target('/to-cyrillic/'), 'the Location is encoded');
        $this->assertNull(site_redirect_target('/novini/'));
    }

    public function test_a_kept_rest_can_never_lead_to_another_host(): void
    {
        $this->site(['/go/*' => '/*', '/out/*' => '/x/*']);
        $this->assertNull(site_redirect_target('/go//evil.example/'), '"/" + "/evil.example/" would be another host');
        $this->assertNull(site_redirect_target('/go/%2Fevil.example/'));
        $this->assertNull(site_redirect_target('/go/\\evil.example/'));
        $this->assertNull(site_redirect_target('/out/a%0D%0ASet-Cookie:x'), 'no header injection');
        $this->assertSame('/fine/', site_redirect_target('/go/fine/'));
    }

    public function test_only_page_views_outside_the_admin_are_redirected(): void
    {
        $this->site(['/*' => '/elsewhere/']);
        // Each of these returns instead of sending a redirect (which would exit).
        site_redirects_apply('/page/', 'GET', true);       // command line
        site_redirects_apply('/page/', 'POST', false);     // a form post keeps its data
        site_redirects_apply('/admin/', 'GET', false);
        site_redirects_apply('/admin/orders.php', 'GET', false);
        site_redirects_apply('/api/x.php', 'GET', false);
        site_redirects_apply('/install/', 'GET', false);
        $this->assertSame('/elsewhere/', site_redirect_target('/page/'));
    }

    public function test_config_and_the_404_page_apply_them(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['config.php', 'errors/404.php'] as $f) {
            $src = (string) file_get_contents("$root/$f");
            $this->assertStringContainsString('includes/site_extensions.php', $src, "$f loads the helpers itself");
            $this->assertStringContainsString('site_redirects_apply()', $src, $f);
        }
        $nf = (string) file_get_contents("$root/errors/404.php");
        $this->assertLessThan(strpos($nf, 'http_response_code(404)'), strpos($nf, 'site_redirects_apply()'), 'before the 404 is sent');
    }
}
