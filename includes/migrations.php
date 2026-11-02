<?php
/**
 * How migrate.php applies one migration file. Kept apart from migrate.php so
 * tests can load it without running the migrations.
 */

/** The statements of a migration file, in order, with full-line `--` comments removed. */
function migration_statements(string $sql): array
{
    // Strip comments first: a semicolon inside one would otherwise split a
    // statement and send the comment's tail to the database as SQL.
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

/**
 * Whether a failed statement already took effect on an earlier run: the column
 * or table it adds is already there. Live databases hit this when a migration
 * reaches them after the same change arrived another way.
 */
function migration_error_is_already_applied(string $message): bool
{
    return str_contains($message, 'Duplicate column') || str_contains($message, 'already exists');
}

/**
 * Run every statement of one migration file. A statement whose change is
 * already there is skipped and the rest still run — catching per file used to
 * stop at the first one and silently skip everything after it, while the file
 * was still recorded as applied. Any other error stops the file.
 *
 * @return array{error: ?string, skipped: int} error is null when the file may be recorded as applied
 */
function migration_apply_sql(PDO $pdo, string $sql): array
{
    $skipped = 0;
    foreach (migration_statements($sql) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (!migration_error_is_already_applied($e->getMessage())) {
                return ['error' => $e->getMessage(), 'skipped' => $skipped];
            }
            $skipped++;
        }
    }
    return ['error' => null, 'skipped' => $skipped];
}
