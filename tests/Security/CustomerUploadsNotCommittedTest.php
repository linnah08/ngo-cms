<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Files customers upload (print designs) must never end up in the repository. */
final class CustomerUploadsNotCommittedTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_print_designs_are_ignored_but_their_guard_ships(): void
    {
        $ignore = (string) file_get_contents(self::root() . '/.gitignore');
        $this->assertStringContainsString("/uploads/print-designs/*\n", $ignore);
        $this->assertStringContainsString("!/uploads/print-designs/.htaccess\n", $ignore);

        if (!is_dir(self::root() . '/.git') && !is_file(self::root() . '/.git')) $this->markTestSkipped('Not a git checkout.');
        $check = static function (string $path): bool {
            exec('git -C ' . escapeshellarg(self::root()) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null', $o, $rc);
            return $rc === 0;
        };
        $this->assertTrue($check('uploads/print-designs/abc123_def456.png'), 'a customer design is ignored');
        $this->assertFalse($check('uploads/print-designs/.htaccess'), 'the folder guard is still committed');
    }

    public function test_the_upload_folder_is_where_the_cart_stores_designs(): void
    {
        $this->assertStringContainsString("'/uploads/print-designs/'", (string) file_get_contents(self::root() . '/cart/add.php'));
    }
}
