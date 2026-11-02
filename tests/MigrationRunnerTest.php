<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/migrations.php';

/**
 * How migrate.php applies a file (includes/migrations.php). A statement whose
 * change is already there must skip only itself — it used to skip the rest of
 * the file while the file was still recorded as applied, so later columns were
 * never added. Runs on a TEMPORARY table, so the shared database is untouched.
 */
final class MigrationRunnerTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $this->pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->pdo->exec('CREATE TEMPORARY TABLE mig_runner_test (id INT PRIMARY KEY, existing INT NULL)');
    }

    private function columns(): array
    {
        return array_column($this->pdo->query('SHOW COLUMNS FROM mig_runner_test')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }

    public function test_an_already_added_column_skips_only_its_own_statement(): void
    {
        $result = migration_apply_sql($this->pdo, "
            ALTER TABLE mig_runner_test ADD COLUMN existing INT NULL;
            ALTER TABLE mig_runner_test ADD COLUMN added_after INT NULL;
        ");
        $this->assertNull($result['error']);
        $this->assertSame(1, $result['skipped']);
        $this->assertContains('added_after', $this->columns(), 'the statement after the duplicate still ran');
    }

    public function test_a_real_error_stops_the_file_and_is_reported(): void
    {
        $result = migration_apply_sql($this->pdo, "
            ALTER TABLE mig_runner_test ADD COLUMN first INT NULL;
            ALTER TABLE mig_runner_test_missing ADD COLUMN x INT NULL;
            ALTER TABLE mig_runner_test ADD COLUMN never INT NULL;
        ");
        $this->assertNotNull($result['error']);
        $this->assertContains('first', $this->columns());
        $this->assertNotContains('never', $this->columns(), 'nothing runs after a real error');
    }

    public function test_a_semicolon_in_a_comment_does_not_split_a_statement(): void
    {
        $this->assertSame(
            ['ALTER TABLE t ADD COLUMN a INT', 'ALTER TABLE t ADD COLUMN b INT'],
            migration_statements("-- adds a; then b\nALTER TABLE t ADD COLUMN a INT;\n  -- note; here\nALTER TABLE t ADD COLUMN b INT;\n")
        );
    }

    public function test_only_already_applied_errors_are_skipped(): void
    {
        $this->assertTrue(migration_error_is_already_applied("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'x'"));
        $this->assertTrue(migration_error_is_already_applied("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 't' already exists"));
        $this->assertFalse(migration_error_is_already_applied("SQLSTATE[42S02]: Base table or view not found: 1146 Table 't' doesn't exist"));
    }

    public function test_migrate_php_uses_the_per_statement_runner(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/migrate.php');
        $this->assertStringContainsString('migration_apply_sql(', $src);
        $this->assertStringNotContainsString("explode(';'", $src, 'statement splitting lives in includes/migrations.php');
    }
}
