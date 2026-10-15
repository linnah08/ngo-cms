<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin pages load the site's own CSS and JS with a ?v=<file time> stamp, so a
 * browser (or the server's cache) can never keep serving the old admin.css or
 * autosave.js after an update — the address changes whenever the file does.
 */
#[Group('admin')]
final class AdminAssetVersionTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testNoAdminPageLoadsALocalStylesheetOrScriptWithoutAVersion(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root . '/admin', FilesystemIterator::SKIP_DOTS));
        $bare = [];
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all('#(?:src|href)="/[^"?<]+\.(?:css|js)"#', $src, $m)) {
                foreach ($m[0] as $hit) $bare[] = substr($file->getPathname(), strlen(self::$root) + 1) . ': ' . $hit;
            }
        }
        $this->assertSame([], $bare, "Load these through versioned_asset():\n" . implode("\n", $bare));
    }

    public function testTheAdminHeaderAndFooterUseVersionedAssets(): void
    {
        $header = (string) file_get_contents(self::$root . '/admin/includes/admin-header.php');
        $footer = (string) file_get_contents(self::$root . '/admin/includes/admin-footer.php');
        foreach (['/admin/assets/admin.css', '/assets/css/main.css', '/admin/js/autosave.js', '/assets/js/image-cropper.js'] as $asset) {
            $this->assertStringContainsString("versioned_asset('{$asset}')", $header, $asset);
        }
        $this->assertStringContainsString("versioned_asset('/admin/js/session-guard.js')", $footer);
    }

    public function testVersionedAssetStampsAdminFilesWithTheirModificationTime(): void
    {
        $prev = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $_SERVER['DOCUMENT_ROOT'] = self::$root;
        try {
            $mtime = filemtime(self::$root . '/admin/assets/admin.css');
            $this->assertSame('/admin/assets/admin.css?v=' . $mtime, versioned_asset('/admin/assets/admin.css'));
            $this->assertSame('/admin/js/autosave.js?v=' . filemtime(self::$root . '/admin/js/autosave.js'), versioned_asset('admin/js/autosave.js'));
            // A missing file still gets a working address, just without a stamp.
            $this->assertSame('/admin/assets/missing.css', versioned_asset('/admin/assets/missing.css'));
        } finally {
            $_SERVER['DOCUMENT_ROOT'] = $prev;
        }
    }
}
