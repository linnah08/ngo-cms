<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Migrations run on MariaDB 10.5 in production and MySQL 9 locally, so their SQL
 * must work on both. `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` are
 * MariaDB-only — on MySQL they are a syntax error that stops migrate.php (it
 * happened with 026). Re-runs are already safe without them: the runner records
 * applied files and skips "Duplicate column" / "already exists" errors.
 */
final class MigrationPortabilityTest extends TestCase
{
    public function testNoMigrationUsesMariaDbOnlyColumnClauses(): void
    {
        $root = dirname(__DIR__);
        $hits = [];
        foreach (glob($root . '/migrations/*.sql') as $path) {
            $sql = (string) file_get_contents($path);
            $sql = preg_replace('#/\*.*?\*/#s', '', $sql);   // block comments
            $sql = preg_replace('#(--|\#)[^\n]*#', '', $sql); // line comments
            if (preg_match('/\b(ADD|DROP)\s+COLUMN\s+IF\s+(NOT\s+)?EXISTS\b/i', $sql)) {
                $hits[] = basename($path);
            }
        }
        $this->assertSame([], $hits, "Write plain ADD/DROP COLUMN (MySQL rejects IF [NOT] EXISTS here):\n" . implode("\n", $hits));
    }
}
