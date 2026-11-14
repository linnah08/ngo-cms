<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Public pages load the site's own CSS and JS through versioned_asset(), which
 * adds ?v=<file time>. The address then changes whenever the file does, so a
 * browser or the server's cache can never keep serving an old main.js or
 * inline-cms.css after an update. (Admin pages: tests/Admin/AdminAssetVersionTest.php.)
 */
final class PublicAssetVersionTest extends TestCase
{
    private const SKIP = ['admin', 'vendor', 'node_modules', 'tests', '.git', 'backups', 'documents', 'scripts'];

    public function testNoPublicPageLoadsALocalStylesheetOrScriptWithoutAVersion(): void
    {
        $root = dirname(__DIR__);
        $skip = self::SKIP;
        $dirs = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $f, string $key, RecursiveDirectoryIterator $it): bool =>
                !($it->hasChildren() && $it->getSubPath() === '' && in_array($f->getFilename(), $skip, true))
        );
        $bare = [];
        foreach (new RecursiveIteratorIterator($dirs) as $file) {
            if ($file->getExtension() !== 'php' || $file->isLink()) continue;
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all('#(?:src|href)="(?:<\?= ?SITE_URL ?\?>)?/[^"?<]+\.(?:css|js)"#', $src, $m)) {
                foreach ($m[0] as $hit) $bare[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $hit;
            }
        }
        $this->assertSame([], $bare, "Load these through versioned_asset():\n" . implode("\n", $bare));
    }
}
