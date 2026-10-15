<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SESSION_SHARE_SUBDOMAINS: the session cookie shared with the site's own
 * subdomains (session_cookie_domain() in config.php). Off = host-only, as before.
 */
final class SessionCookieDomainTest extends TestCase
{
    public function test_off_means_host_only(): void
    {
        $this->assertSame('', session_cookie_domain('https://example.org', 'example.org', false));
        $this->assertSame('', session_cookie_domain('https://example.org', 'kampaniya.example.org', false));
    }

    public function test_on_shares_with_the_sites_own_subdomains(): void
    {
        $this->assertSame('example.org', session_cookie_domain('https://example.org', 'example.org', true));
        $this->assertSame('example.org', session_cookie_domain('https://example.org', 'kampaniya.example.org', true));
        $this->assertSame('example.org', session_cookie_domain('https://example.org', 'Kampaniya.Example.org:443', true));
        $this->assertSame('example.org', session_cookie_domain('https://www.example.org', 'kampaniya.example.org', true), 'www. is dropped so its siblings share it');
    }

    public function test_never_above_the_sites_own_host_so_public_suffix_hosts_keep_working(): void
    {
        $this->assertSame('ngo.cpanel.site', session_cookie_domain('https://ngo.cpanel.site', 'ngo.cpanel.site', true), 'not cpanel.site, which the browser would refuse');
        $this->assertSame('ngo.github.io', session_cookie_domain('https://ngo.github.io', 'sub.ngo.github.io', true));
        $this->assertSame('example.co.uk', session_cookie_domain('https://www.example.co.uk', 'example.co.uk', true));
    }

    public function test_host_only_whenever_the_browser_would_refuse_the_domain(): void
    {
        $this->assertSame('', session_cookie_domain('http://localhost:8000', 'localhost:8000', true));
        $this->assertSame('', session_cookie_domain('http://127.0.0.1', '127.0.0.1', true));
        $this->assertSame('', session_cookie_domain('https://example.org', 'preview.hosting.net', true), 'a request on another host');
        $this->assertSame('', session_cookie_domain('https://example.org', 'evilexample.org', true), 'not a subdomain, just similar');
        $this->assertSame('', session_cookie_domain('', 'example.org', true));
    }

    public function test_config_uses_it_for_the_session_and_the_admin_bar_only_when_switched_on(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/config.php');
        $this->assertStringContainsString("defined('SESSION_SHARE_SUBDOMAINS') && SESSION_SHARE_SUBDOMAINS === true", $src);
        $this->assertStringContainsString("ini_set('session.cookie_domain', \$_om_cookie_domain)", $src);
        $this->assertMatchesRegularExpression('/function _admin_bar_domain\(\): string \{[^}]*site_session_cookie_domain\(\)/s', $src);
        $this->assertStringContainsString("define('SESSION_SHARE_SUBDOMAINS'", (string) file_get_contents(dirname(__DIR__, 2) . '/site.config.example.php'), 'documented for site owners');
        if (!defined('SESSION_SHARE_SUBDOMAINS')) $this->assertSame('', site_session_cookie_domain(), 'off unless the site says so');
    }
}
