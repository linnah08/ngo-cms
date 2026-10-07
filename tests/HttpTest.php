<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/Support/TestServer.php';
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Smoke-tests every public page by making a real HTTP request to a throwaway
 * server for this checkout (tests/Support/TestServer.php) and asserting a 200.
 */
#[Group('http')]
final class HttpTest extends TestCase
{
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') {
            self::markTestSkipped('Could not start a local test server — skipping HTTP smoke tests.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
    }

    public static function publicPages(): array
    {
        // The addresses the site really serves (BG and EN pairs). /campaign/ and
        // /tickets/ redirect home when the campaign module is off; followed, still 200.
        return [
            // ── Public BG pages ────────────────────────────────────────────
            ['/', 'Home BG'],
            ['/za-nas/', 'About BG'],
            ['/proekti/', 'Projects BG'],
            ['/kak-da-pomogna/', 'How to help BG'],
            ['/kontakti/', 'Contact BG'],
            ['/magazin/', 'Shop BG'],
            ['/donation/', 'Donation BG'],
            ['/campaign/', 'Campaign BG'],
            ['/tickets/', 'Tickets'],
            ['/novini/', 'News BG'],
            ['/finansovi-otcheti/', 'Financial reports BG'],
            // ── Public EN pages ────────────────────────────────────────────
            ['/en/', 'Home EN'],
            ['/en/about/', 'About EN'],
            ['/en/projects/', 'Projects EN'],
            ['/en/how-to-help/', 'How to help EN'],
            ['/en/contacts/', 'Contact EN'],
            ['/en/shop/', 'Shop EN'],
            ['/en/donation/', 'Donation EN'],
            ['/en/campaign/', 'Campaign EN'],
            ['/en/news/', 'News EN'],
            ['/en/financial-reports/', 'Financial reports EN'],
            // ── Legal ──────────────────────────────────────────────────────
            ['/politika-za-poveritelnost/', 'Privacy policy BG'],
            ['/en/privacy-policy/', 'Privacy policy EN'],
            ['/politika-za-biskvitki/', 'Cookie policy BG'],
            ['/en/cookie-policy/', 'Cookie policy EN'],
            ['/usloviya/', 'Terms BG'],
            ['/en/terms/', 'Terms EN'],
            ['/pravna-informaciya/', 'Legal information BG'],
            ['/en/legal/', 'Legal information EN'],
        ];
    }

    public static function errorPages(): array
    {
        return [
            ['/errors/404.php', 404],
            ['/errors/500.php', 500],
            ['/errors/403.php', 403],
            ['/errors/503.php', 503],
        ];
    }

    #[DataProvider('errorPages')]
    public function testErrorPageReturnsCorrectCode(string $path, int $expected): void
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame($expected, $code, "Error page {$path} returned HTTP $code instead of $expected");
    }

    #[DataProvider('publicPages')]
    public function testPageReturns200(string $path, string $label): void
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $code, "$label ({$path}) returned HTTP $code");
    }

    /** Guards the test above: an address the site doesn't have must not pass as a page. */
    public function testUnknownAddressIs404(): void
    {
        $ch = curl_init(self::$base . '/no-such-page-' . bin2hex(random_bytes(4)) . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(404, $code);
    }
}
