<?php
declare(strict_types=1);

require_once __DIR__ . '/CreatedPagesTestCase.php';

/**
 * page.php: a published page for everyone, a draft only for an Admin, and the
 * site's 404 page for anything else. Each request runs in its own PHP process.
 */
final class CreatedPagesRoutingTest extends CreatedPagesTestCase
{
    public function testPublishedPageInBulgarian(): void
    {
        $this->storyPage();
        [$html, $code] = $this->request('page.php', '/nashata-istoriya/');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('<html lang="bg">', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>Нашата история</h1>#u', $html);
        $this->assertStringContainsString('Тяло на страницата', $html);
        $this->assertStringNotContainsString('Body of the page', $html);
        $this->assertStringContainsString('</html>', $html, 'rendered with the site header and footer');
        $this->assertStringNotContainsString('Чернова', $html);
        $this->assertStringContainsString('hreflang="en" href="https://', $html);
        $this->assertStringContainsString('/en/our-story/"', $html);
    }

    public function testPublishedPageInEnglish(): void
    {
        $this->storyPage();
        [$html, $code] = $this->request('page.php', '/en/our-story/');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>Our story</h1>#', $html);
        $this->assertStringContainsString('Body of the page', $html);
    }

    public function testTheOtherLanguagesAddressIsNotThisPage(): void
    {
        $this->storyPage();
        $this->assertSame(404, $this->request('page.php', '/en/nashata-istoriya/')[1]);
        $this->assertSame(404, $this->request('page.php', '/our-story/')[1]);
    }

    public function testAddressWithoutTrailingSlashRedirects(): void
    {
        $this->storyPage();
        [$html, $code] = $this->request('page.php', '/nashata-istoriya');
        $this->assertSame(301, $code);
        $this->assertSame('', trim($html));
    }

    public function testDraftIsTheNotFoundPageForVisitors(): void
    {
        $this->storyPage('draft');
        foreach (['/nashata-istoriya/', '/en/our-story/'] as $uri) {
            [$html, $code] = $this->request('page.php', $uri);
            $this->assertSame(404, $code, $uri);
            $this->assertStringNotContainsString('Тяло на страницата', $html);
            $this->assertStringNotContainsString('Body of the page', $html);
        }
    }

    public function testDraftIsHiddenFromAuthorsToo(): void
    {
        $this->storyPage('draft');
        $this->assertSame(404, $this->request('page.php', '/nashata-istoriya/', 'author')[1]);
    }

    public function testAdminSeesTheDraftWithABarAndPublishButton(): void
    {
        $this->storyPage('draft');
        [$html, $code] = $this->request('page.php', '/nashata-istoriya/', 'admin');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Чернова — не се вижда от посетителите', $html);
        $this->assertStringContainsString('Тяло на страницата', $html);
        $this->assertMatchesRegularExpression('#<form method="POST" action="/admin/created-pages.php".*?name="csrf_token".*?value="publish".*?value="p_abcd1234"#s', $html);
        $this->assertStringNotContainsString('rel="alternate" hreflang', $html, 'a draft is not announced to search engines');
    }

    public function testUnknownAddressIsTheNotFoundPage(): void
    {
        foreach (['/nyama-takava/', '/en/no-such-page/', '/../config/'] as $uri) {
            $this->assertSame(404, $this->request('page.php', $uri)[1], $uri);
        }
    }

    public function testBulgarianAddressStartingWithEnStaysBulgarian(): void
    {
        $this->putPage(['id' => 'p_energy00', 'status' => 'published', 'title_bg' => 'Енергия', 'title_en' => 'Energy',
                        'slug_bg' => 'energiya', 'slug_en' => 'energy']);
        [$html, $code] = $this->request('page.php', '/energiya/');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('<html lang="bg">', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>Енергия</h1>#u', $html);
    }

    public function testTitlesAreEscaped(): void
    {
        $this->putPage(['id' => 'p_xss00000', 'status' => 'published', 'title_bg' => '<img src=x onerror=alert(1)>',
                        'title_en' => 'x', 'slug_bg' => 'xss', 'slug_en' => 'xss']);
        [$html] = $this->request('page.php', '/xss/');
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testFrontPageAndBuiltInsAreStillServedByTheirOwnFiles(): void
    {
        // The PHP dev server hands unknown addresses to index.php: it passes them on…
        $this->storyPage();
        [$html, $code] = $this->request('index.php', '/nashata-istoriya/');
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('#<h1[^>]*>Нашата история</h1>#u', $html);
        // …but the front page itself is untouched.
        [$html, $code] = $this->request('index.php', '/');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('Нашата история', $html);
    }

    public function testHtaccessSendsOnlyUnknownAddressesToPageController(): void
    {
        $ht = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        foreach (['^en/([a-z0-9-]+)/?$', '^([a-z0-9-]+)/?$'] as $rule) {
            $this->assertMatchesRegularExpression(
                '#RewriteCond %\{REQUEST_FILENAME\} !-f\s+RewriteCond %\{REQUEST_FILENAME\} !-d\s+RewriteRule '
                . preg_quote($rule, '#') . ' /page\.php \[L,QSA\]#',
                $ht, "built-in files and folders must win over $rule");
        }
        // After every other site rule (redirects of old addresses, news, shop).
        $this->assertGreaterThan(strpos($ht, '^en/shop/([^/]+)/?$'), strpos($ht, '/page.php'));
    }
}
