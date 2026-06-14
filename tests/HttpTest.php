<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Smoke-tests every public page by making a real HTTP request to oddminds.test
 * and asserting a 200 response. Tests are skipped automatically when the local
 * dev host is unreachable (e.g. in CI).
 */
#[Group('http')]
final class HttpTest extends TestCase
{
    private static string $base = 'http://oddminds.test';

    public static function setUpBeforeClass(): void
    {
        // Skip the entire class if the local dev server isn't up.
        $ch = curl_init(self::$base . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            self::markTestSkipped('oddminds.test is not reachable — skipping HTTP smoke tests.');
        }
    }

    public static function publicPages(): array
    {
        return [
            // ── Public BG pages ────────────────────────────────────────────
            ['/', 'Home BG'],
            ['/about/', 'About BG'],
            ['/projects/', 'Projects BG'],
            ['/how-to-help/', 'How to help BG'],
            ['/contact/', 'Contact BG'],
            ['/shop/', 'Shop BG'],
            ['/campaign/', 'Campaign BG'],
            ['/tickets/', 'Tickets'],
            ['/articles/', 'Articles BG'],
            // ── Public EN pages ────────────────────────────────────────────
            ['/en/', 'Home EN'],
            ['/en/about/', 'About EN'],
            ['/en/projects/', 'Projects EN'],
            ['/en/how-to-help/', 'How to help EN'],
            ['/en/contact/', 'Contact EN'],
            ['/en/shop/', 'Shop EN'],
            ['/en/campaign/', 'Campaign EN'],
            ['/en/articles/', 'Articles EN'],
            // ── Legal ──────────────────────────────────────────────────────
            ['/legal/privacy/', 'Privacy policy'],
            ['/legal/cookies/', 'Cookie policy'],
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
}
