<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Text kept only in the browser's backup must be findable from the list pages, and
 * an open editor must keep the session alive — otherwise a long article was lost
 * when the host threw away a session idle for 24 minutes.
 */
#[Group('admin')]
final class UnsavedDraftsTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function testListPagesShowUnsavedDrafts(): void
    {
        foreach (['admin/articles.php' => "prefix: 'article:'", 'admin/products.php' => "prefix: 'product:'"] as $file => $prefix) {
            $src = self::read($file);
            $this->assertStringContainsString('/admin/js/unsaved-drafts.js', $src, $file);
            $this->assertStringContainsString($prefix, $src, $file);
            $this->assertStringContainsString('role="status"', $src, "$file needs a live region for deletions");
        }
    }

    public function testListPrefixesMatchTheEditorsAutosaveKeys(): void
    {
        $this->assertStringContainsString("json_encode('article:' .", self::read('admin/article-edit.php'));
        $this->assertStringContainsString("json_encode('product:' .", self::read('admin/product-edit.php'));
    }

    public function testDraftDeleteUsesTheAdminConfirmModal(): void
    {
        $js = self::read('admin/js/unsaved-drafts.js');
        $this->assertStringContainsString('_adminConfirm', $js);
        $this->assertStringNotContainsString('window.confirm(', $js);
    }

    public function testEditorRestoresStraightAwayFromTheList(): void
    {
        $this->assertStringContainsString("get('restore') === '1'", self::read('admin/js/autosave.js'));
    }

    public function testOpenPagesKeepTheSessionAlive(): void
    {
        $js = self::read('admin/js/session-guard.js');
        $this->assertMatchesRegularExpression('/setInterval\(beat,/', $js);
        $this->assertStringContainsString("'om:activity'", $js);
        $this->assertStringContainsString("'om:activity'", self::read('admin/js/autosave.js'));
        $this->assertStringContainsString('login.php?next=', $js);
    }

    public function testSessionFilesOutliveTheAdminLimit(): void
    {
        $this->assertStringContainsString("ini_set('session.gc_maxlifetime', (string) (8 * 3600))", self::read('config.php'));
        $this->assertStringContainsString("define('ADMIN_SESSION_HOURS', 8)", self::read('config.php'));
    }
}
