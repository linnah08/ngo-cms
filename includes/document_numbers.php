<?php
/**
 * Document numbering — one counter per document type in document_sequences
 * (invoice, receipt, donation_cert, distribution_protocol, a site's own kinds).
 * Every place that issues a document takes its number from here.
 *
 * Shared numbering: one legal entity that issues documents from two sites must
 * keep one sequence. Set DOC_SEQUENCE_DB in site.config.php to the database on
 * the same MySQL server that holds the shared counters — the other site's own
 * database — and give this site's DB user SELECT, INSERT and UPDATE on it.
 * This site's own counters are kept level with the shared ones: the first
 * number taken continues after the higher of the two, and switching the
 * setting off later never hands out a number twice. The site whose database
 * holds the counters sets DOC_SEQUENCE_SHARED to true instead, so its admin
 * says the numbering is shared too.
 *
 * When the shared database can't be reached the document is not issued — a
 * number from this site's own counter could repeat one the other site issued.
 */

/** The database named in DOC_SEQUENCE_DB, or '' when numbering is the site's own. */
function document_sequence_shared_db(): string
{
    $db = defined('DOC_SEQUENCE_DB') ? trim((string) DOC_SEQUENCE_DB) : '';
    if ($db !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
        throw new RuntimeException('DOC_SEQUENCE_DB in site.config.php is not a valid database name.');
    }
    return $db;
}

/** Whether this site's numbering is shared with another site (either side). */
function document_sequence_is_shared(): bool
{
    return document_sequence_shared_db() !== '' || (defined('DOC_SEQUENCE_SHARED') && DOC_SEQUENCE_SHARED);
}

/**
 * Claim the next number of $type atomically (creating its counter on first use)
 * and return it. Joins the caller's transaction when there is one.
 */
function document_sequence_next(PDO $pdo, string $type): int
{
    return document_sequence_next_in($pdo, $type, document_sequence_shared_db());
}

/** document_sequence_next() with the shared database given — '' for the site's own. */
function document_sequence_next_in(PDO $pdo, string $type, string $shared_db): int
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // Shared row first, the same order on every site, so two sites can't deadlock.
        $shared = $shared_db !== '' ? _docseq_lock($pdo, _docseq_table($shared_db), $type) : 0;
        $next   = max($shared, _docseq_lock($pdo, 'document_sequences', $type)) + 1;
        _docseq_write($pdo, $type, $next, $shared_db);
        if ($own) $pdo->commit();
        return $next;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw _docseq_error($e, $shared_db);
    }
}

/** The last number issued of $type (0 before the first). */
function document_sequence_last(PDO $pdo, string $type): int
{
    return document_sequence_last_all($pdo)[$type] ?? 0;
}

/** Every counter: type => last number issued, the shared one where it is higher. */
function document_sequence_last_all(PDO $pdo, ?string $shared_db = null): array
{
    $shared_db ??= document_sequence_shared_db();
    $read = fn (string $table) => array_map('intval', array_column(
        $pdo->query("SELECT type, last_number FROM $table")->fetchAll(PDO::FETCH_ASSOC), 'last_number', 'type'));
    $out = $read('document_sequences');
    if ($shared_db !== '') {
        try {
            foreach ($read(_docseq_table($shared_db)) as $type => $last) $out[$type] = max($out[$type] ?? 0, $last);
        } catch (Throwable $e) {
            throw _docseq_error($e, $shared_db);
        }
    }
    return $out;
}

/** Set the last number issued of $type — the next document gets $last + 1. */
function document_sequence_set_last(PDO $pdo, string $type, int $last, ?string $shared_db = null): void
{
    $shared_db ??= document_sequence_shared_db();
    try {
        _docseq_write($pdo, $type, max(0, $last), $shared_db);
    } catch (Throwable $e) {
        throw _docseq_error($e, $shared_db);
    }
}

function _docseq_table(string $db): string
{
    return '`' . $db . '`.document_sequences';
}

function _docseq_lock(PDO $pdo, string $table, string $type): int
{
    $pdo->prepare("INSERT IGNORE INTO $table (type, last_number) VALUES (?, 0)")->execute([$type]);
    $lock = $pdo->prepare("SELECT last_number FROM $table WHERE type = ? FOR UPDATE");
    $lock->execute([$type]);
    return (int) $lock->fetchColumn();
}

/** Write $last to this site's counter and, when shared, to the shared one. */
function _docseq_write(PDO $pdo, string $type, int $last, string $shared_db): void
{
    $tables = $shared_db !== '' ? [_docseq_table($shared_db), 'document_sequences'] : ['document_sequences'];
    foreach ($tables as $table) {
        $pdo->prepare("INSERT INTO $table (type, last_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)")
            ->execute([$type, $last]);
    }
}

/** A database error under shared numbering becomes a plain message; the detail goes to the log. */
function _docseq_error(Throwable $e, string $shared_db): Throwable
{
    if ($shared_db === '' || !$e instanceof PDOException) return $e;
    error_log("document numbering: shared database '$shared_db': " . $e->getMessage());
    return new RuntimeException(
        'Общата номерация с другия сайт не е достъпна, затова документът не е издаден — '
        . 'иначе номерът му може да повтори вече издаден. Опитайте отново след малко; '
        . 'ако не стане, свържете се с поддръжката на сайта.', 0, $e);
}
