<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Delete and other "are you sure?" questions go through the site's own modal
 * (_adminConfirm in admin/includes/admin-footer.php), never the browser's
 * confirm(): Chrome can stop showing it for a page, and then the question is
 * silently answered "no" (or the action silently never happens).
 */
final class NoNativeConfirmTest extends TestCase
{
    private const DIRS = ['admin', 'assets/js', 'templates', 'includes', 'checkout', 'en', 'novini'];

    public function testNothingCallsTheBrowsersConfirm(): void
    {
        $root = dirname(__DIR__);
        $hits = [];
        foreach (self::DIRS as $dir) {
            if (!is_dir("$root/$dir")) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!in_array($file->getExtension(), ['php', 'js'], true)) continue;
                foreach (file($file->getPathname()) as $n => $line) {
                    $code = ltrim($line);
                    // Comments may name it (e.g. "avoid window.confirm()").
                    if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) continue;
                    $code = preg_replace('#\s//.*$#', '', $code);
                    if (preg_match('/(?<![\w$.])confirm\s*\(|window\.confirm\s*\(/', $code)) {
                        $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                    }
                }
            }
        }
        $this->assertSame([], $hits, "Use _adminConfirm() instead of the browser's confirm():\n" . implode("\n", $hits));
    }
}
