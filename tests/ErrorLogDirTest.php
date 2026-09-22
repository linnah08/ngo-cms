<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Deploys that skip logs/ (rsync --exclude) leave a site with nowhere to write
 * errors, so failures vanish without a trace. The logger creates the folder
 * itself — locked against web access, since it holds paths and messages.
 */
final class ErrorLogDirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/om-logdir-test-' . bin2hex(random_bytes(4)) . '/logs';
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.htaccess');
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
    }

    public function test_missing_folder_is_created_and_locked(): void
    {
        $this->assertTrue(_om_log_dir_ready($this->dir));
        $this->assertDirectoryExists($this->dir);
        $this->assertSame("Require all denied\n", file_get_contents($this->dir . '/.htaccess'));
    }

    public function test_existing_folder_without_lock_gets_one(): void
    {
        mkdir($this->dir, 0755, true);
        $this->assertTrue(_om_log_dir_ready($this->dir));
        $this->assertFileExists($this->dir . '/.htaccess');
    }

    public function test_existing_lock_is_left_alone(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/.htaccess', "# custom\nRequire all denied\n");
        $this->assertTrue(_om_log_dir_ready($this->dir));
        $this->assertSame("# custom\nRequire all denied\n", file_get_contents($this->dir . '/.htaccess'));
    }

    public function test_uncreatable_folder_reports_false(): void
    {
        // A path under a regular file can never become a directory.
        $file = tempnam(sys_get_temp_dir(), 'om-logdir-');
        try {
            $this->assertFalse(_om_log_dir_ready($file . '/logs'));
        } finally {
            unlink($file);
        }
    }
}
