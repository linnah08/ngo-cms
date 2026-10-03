<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bulgaria adopted the euro on 1 Jan 2026 and the dual EUR/BGN display period
 * ended on 1 July 2026, so nothing the site renders — pages, emails, PDFs,
 * admin — may show a лв/BGN amount any more.
 */
final class EuroOnlyTest extends TestCase
{
    /** Files that may mention BGN, and why. */
    private const ALLOWED = [
        'includes/couriers/SpeedyCourier.php' => 'converts prices the Speedy API may still return in BGN',
    ];

    public function testNoCodeRendersBgnAmounts(): void
    {
        $root = ROOT_PATH;
        exec('git -C ' . escapeshellarg($root) . ' ls-files -- "*.php" "*.js" "*.css" 2>/dev/null', $files, $code);
        if ($code !== 0 || !$files) $this->markTestSkipped('git ls-files unavailable');

        $hits = [];
        foreach ($files as $rel) {
            if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'docs/') || isset(self::ALLOWED[$rel])) continue;
            $src = @file_get_contents("$root/$rel");
            if ($src === false) continue;
            foreach (preg_split('/\R/', $src) as $n => $line) {
                if (preg_match('/лв\b|лв\.|(?<![A-Za-z])BGN(?![A-Za-z])|EUR_BGN|1\.95583/u', $line)) {
                    $hits[] = "$rel:" . ($n + 1) . ': ' . trim(mb_substr($line, 0, 120));
                }
            }
        }
        $this->assertSame([], $hits, "BGN amounts are still rendered:\n" . implode("\n", $hits));
    }

    public function testAllowListPointsAtRealFiles(): void
    {
        foreach (array_keys(self::ALLOWED) as $rel) $this->assertFileExists(ROOT_PATH . "/$rel");
    }
}
