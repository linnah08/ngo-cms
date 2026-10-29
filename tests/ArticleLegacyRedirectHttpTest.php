<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/Support/TestServer.php';

/**
 * An article's old address (renamed in the editor, or a Cyrillic address replaced
 * by a Latin one) answers with a 301 to its current address on both news pages —
 * whether or not Latin addresses are switched on.
 */
#[Group('http')]
final class ArticleLegacyRedirectHttpTest extends TestCase
{
    private static string $base = '';
    private static string $tag  = '';
    /** @var string[] */
    private static array $files = [];

    public static function setUpBeforeClass(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') {
            self::markTestSkipped('Could not start a local test server — skipping.');
        }
        self::$tag = 'zzlegacy' . bin2hex(random_bytes(3));
        $article = ['title' => 'T', 'date' => '2026-01-01', 'status' => 'published', 'content' => '<p>x</p>', 'excerpt' => '', 'image' => '', 'tags' => []];
        self::put('bg', self::$tag . '-novo', $article + ['slug_en' => self::$tag . '-new', 'slug_legacy' => 'стара-' . self::$tag]);
        self::put('en', self::$tag . '-new', $article + ['slug_legacy' => [self::$tag . '-old', 'стара-' . self::$tag]]);
        // A BG article with an English version whose English file knows nothing of the
        // old Cyrillic address (content migrated from oddminds): English links that use
        // the BG address, old or current, land on the English article.
        self::put('bg', self::$tag . '-dvoyno', $article + ['slug_en' => self::$tag . '-double', 'slug_legacy' => 'двойно-' . self::$tag]);
        self::put('en', self::$tag . '-double', $article);
        // A BG-only article renamed once: its old address on /en/news/ goes to the BG page.
        self::put('bg', self::$tag . '-samo-bg', $article + ['slug_legacy' => self::$tag . '-bg-old']);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $f) @unlink($f);
        TestServer::stop();
    }

    private static function put(string $lang, string $slug, array $data): void
    {
        $dir = ARTICLES_PATH . '/' . $lang;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/' . $slug . '.json';
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        self::$files[] = $file;
    }

    /** @return array{0:int,1:string} status and Location */
    private function head(string $path): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', $raw, $m);
        return [$code, $m[1] ?? ''];
    }

    public function testOldCyrillicBgAddressMovesPermanently(): void
    {
        [$code, $loc] = $this->head('/novini/' . rawurlencode('стара-' . self::$tag) . '/');
        $this->assertSame(301, $code);
        $this->assertSame('/novini/' . self::$tag . '-novo/', $loc);
    }

    public function testCurrentBgAddressStillServesThePage(): void
    {
        [$code] = $this->head('/novini/' . self::$tag . '-novo/');
        $this->assertSame(200, $code);
    }

    public function testOldEnAddressMovesPermanently(): void
    {
        foreach ([self::$tag . '-old', 'стара-' . self::$tag] as $old) {
            [$code, $loc] = $this->head('/en/news/' . rawurlencode($old) . '/');
            $this->assertSame(301, $code, $old);
            $this->assertSame('/en/news/' . self::$tag . '-new/', $loc, $old);
        }
    }

    public function testOldAddressOfABgOnlyArticleOnTheEnglishPageGoesToTheBgPage(): void
    {
        [$code, $loc] = $this->head('/en/news/' . self::$tag . '-bg-old/');
        $this->assertSame(301, $code);
        $this->assertSame('/novini/' . self::$tag . '-samo-bg/', $loc);
    }

    public function testOldCyrillicAddressOnTheEnglishPageGoesToTheEnglishVersion(): void
    {
        [$code, $loc] = $this->head('/en/news/' . rawurlencode('двойно-' . self::$tag) . '/');
        $this->assertSame(301, $code);
        $this->assertSame('/en/news/' . self::$tag . '-double/', $loc);
        // …and on the Bulgarian page to the Bulgarian article.
        [$code, $loc] = $this->head('/novini/' . rawurlencode('двойно-' . self::$tag) . '/');
        $this->assertSame(301, $code);
        $this->assertSame('/novini/' . self::$tag . '-dvoyno/', $loc);
    }

    public function testCurrentBgAddressOnTheEnglishPageGoesToTheEnglishVersion(): void
    {
        [$code, $loc] = $this->head('/en/news/' . self::$tag . '-dvoyno/');
        $this->assertSame(301, $code);
        $this->assertSame('/en/news/' . self::$tag . '-double/', $loc);
    }

    public function testCurrentAddressOfABgOnlyArticleOnTheEnglishPageStillGoesToTheBgPage(): void
    {
        [$code, $loc] = $this->head('/en/news/' . self::$tag . '-samo-bg/');
        $this->assertSame(302, $code);
        $this->assertSame('/novini/' . self::$tag . '-samo-bg/', $loc);
    }

    public function testUnknownAddressIsStillNotFound(): void
    {
        $this->assertSame(404, $this->head('/novini/' . self::$tag . '-nikoga/')[0]);
        $this->assertSame(404, $this->head('/en/news/' . self::$tag . '-never/')[0]);
    }
}
