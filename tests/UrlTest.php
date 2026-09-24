<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/url.php';

/**
 * These exist because PHP's own URL tools quietly mishandle the addresses this
 * product's users actually have.
 *
 * FILTER_VALIDATE_URL refuses a Cyrillic address everywhere, so that is
 * asserted directly: if a future PHP fixes it, it shows up here.
 *
 * parse_url() is not a PHP-version bug but a locale one: it swaps every byte
 * iscntrl() calls a control character for '_', and whether 0x80 (the second
 * byte of "р") counts depends on LC_CTYPE and the C library. On macOS under
 * a UTF-8 locale it does; under "C" it does not. PHP takes LC_CTYPE from the
 * environment, so the same code worked or broke depending on the shell's LANG.
 * Asserting "parse_url() corrupts" is therefore not a stable canary; what
 * is asserted instead is the thing that matters — url_split() and url_host()
 * give back the typed host under either locale.
 */
final class UrlTest extends TestCase
{
    public function test_filter_var_still_refuses_a_cyrillic_address(): void
    {
        $this->assertFalse(
            filter_var('https://пример.бг/път?x=1', FILTER_VALIDATE_URL),
            'FILTER_VALIDATE_URL is expected to refuse a valid Cyrillic address'
        );
    }

    public function test_host_survives_a_utf8_locale(): void
    {
        $saved = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C', 'C.UTF-8', 'en_US.UTF-8', 'bg_BG.UTF-8'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                $this->assertSame('пример.бг', url_split('https://пример.бг/път?x=1')['host'], $locale);
                $this->assertSame('пример.бг', url_host('https://пример.бг/път?x=1'), $locale);
                $this->assertSame('/път', url_request_path('/път?x=1'), $locale);
            }
        } finally {
            setlocale(LC_CTYPE, $saved);
        }
    }

    // ── url_split ─────────────────────────────────────────────────────────────

    public function test_split_keeps_a_cyrillic_host_byte_for_byte(): void
    {
        $parts = url_split('https://пример.бг/път?x=1');

        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('пример.бг', $parts['host']);
        $this->assertSame('/път?x=1', $parts['rest']);
        $this->assertSame('', $parts['port']);
        $this->assertSame('', $parts['userinfo']);
    }

    public function test_split_separates_port_userinfo_and_ipv6(): void
    {
        $this->assertSame('8080', ltrim(url_split('http://example.org:8080/a')['port'], ':'));
        $this->assertSame('example.org', url_split('http://user:pw@example.org/a')['host']);
        $this->assertSame('user:pw@', url_split('http://user:pw@example.org/a')['userinfo']);

        // A bracketed IPv6 literal has no port to strip.
        $this->assertSame('[::1]', url_split('http://[::1]/a')['host']);
        $this->assertSame('', url_split('http://[::1]/a')['port']);
        $this->assertSame('[::1]', url_split('http://[::1]:9000/a')['host']);
        $this->assertSame(':9000', url_split('http://[::1]:9000/a')['port']);
    }

    public function test_split_rejects_what_is_not_a_url(): void
    {
        $this->assertNull(url_split('not a url'));
        $this->assertNull(url_split('/just/a/path'));
        $this->assertNull(url_split('example.org/no-scheme'));
    }

    // ── url_host ──────────────────────────────────────────────────────────────

    public function test_host_comes_back_as_typed(): void
    {
        $this->assertSame('пример.бг', url_host('https://пример.бг/път'));
        $this->assertSame('example.org', url_host('http://example.org'));
        $this->assertSame('example.org', url_host('http://example.org:8080/a?b=c'));
        $this->assertNull(url_host('https:///no-host'));
        $this->assertNull(url_host('nonsense'));
    }

    // ── url_ascii ─────────────────────────────────────────────────────────────

    public function test_ascii_punycodes_the_host_and_encodes_the_rest(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension not installed');
        }

        $this->assertSame(
            'https://xn--e1afmkfd.xn--90ae/%D0%BF%D1%8A%D1%82?x=1',
            url_ascii('https://пример.бг/път?x=1')
        );
        // An ASCII host with a Cyrillic path: only the path needs encoding.
        $this->assertSame(
            'https://bg.wikipedia.org/wiki/%D0%91%D1%8A%D0%BB%D0%B3%D0%B0%D1%80%D0%B8%D1%8F',
            url_ascii('https://bg.wikipedia.org/wiki/България')
        );
        // Already ASCII: unchanged.
        $this->assertSame('https://example.org/a?b=c', url_ascii('https://example.org/a?b=c'));
    }

    // ── url_is_web ────────────────────────────────────────────────────────────

    public function test_is_web_accepts_cyrillic_addresses(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension not installed');
        }

        $this->assertTrue(url_is_web('https://пример.бг/път?x=1'));
        $this->assertTrue(url_is_web('https://bg.wikipedia.org/wiki/България'));
        $this->assertTrue(url_is_web('http://example.org'));
    }

    // ── url_request_path ──────────────────────────────────────────────────────

    public function test_request_path_strips_query_and_fragment_bytes_intact(): void
    {
        $this->assertSame('/novini/', url_request_path('/novini/?page=2'));
        $this->assertSame('/novini/', url_request_path('/novini/#top'));
        $this->assertSame('/novini/', url_request_path('/novini/'));
        $this->assertSame('/', url_request_path(''));
        // A raw Cyrillic path survives; parse_url() would have altered it.
        $this->assertSame('/новини/статия', url_request_path('/новини/статия?x=1'));
    }

    public function test_is_web_refuses_what_it_should(): void
    {
        $this->assertFalse(url_is_web('javascript://пример.бг'), 'only http(s) may pass');
        $this->assertFalse(url_is_web('javascript:alert(1)'));
        $this->assertFalse(url_is_web('ftp://example.org'));
        $this->assertFalse(url_is_web('https://-.бг'), 'a malformed IDN label is not a host');
        $this->assertFalse(url_is_web('/relative/path'));
        $this->assertFalse(url_is_web('https:///nothing'));
        $this->assertFalse(url_is_web(''));
    }
}
