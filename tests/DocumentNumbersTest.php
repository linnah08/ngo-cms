<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Document numbering (includes/document_numbers.php), on the site's own counter
 * and shared with another site's database through DOC_SEQUENCE_DB.
 */
final class DocumentNumbersTest extends TestCase
{
    private const SHARED_DB = 'phpunit_docseq_shared';

    private PDO $pdo;
    private string $type;

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $this->pdo  = get_pdo();
        $this->type = 'phpunit_' . bin2hex(random_bytes(4));
        try {
            $this->pdo->exec('CREATE DATABASE IF NOT EXISTS ' . self::SHARED_DB);
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::SHARED_DB . '.document_sequences LIKE document_sequences');
        } catch (PDOException $e) {
            $this->markTestSkipped('Cannot create the second database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->pdo->prepare('DELETE FROM document_sequences WHERE type = ?')->execute([$this->type]);
        $this->pdo->exec('DROP DATABASE IF EXISTS ' . self::SHARED_DB);
    }

    private function last(string $table): int
    {
        $s = $this->pdo->prepare("SELECT last_number FROM $table WHERE type = ?");
        $s->execute([$this->type]);
        return (int) $s->fetchColumn();
    }

    public function test_own_numbering_creates_the_counter_and_counts_up(): void
    {
        $this->assertSame(1, document_sequence_next_in($this->pdo, $this->type, ''));
        $this->assertSame(2, document_sequence_next_in($this->pdo, $this->type, ''));
        $this->assertSame(2, $this->last('document_sequences'));
        $this->assertSame(0, $this->last(self::SHARED_DB . '.document_sequences'), 'the other database is left alone');
    }

    public function test_it_joins_the_callers_transaction(): void
    {
        $this->pdo->beginTransaction();
        $this->assertSame(1, document_sequence_next_in($this->pdo, $this->type, self::SHARED_DB));
        $this->assertTrue($this->pdo->inTransaction(), 'the caller still owns the transaction');
        $this->pdo->rollBack();
        $this->assertSame(0, $this->last('document_sequences'));
        $this->assertSame(0, $this->last(self::SHARED_DB . '.document_sequences'));
    }

    public function test_shared_numbering_continues_after_the_higher_counter_and_keeps_both_level(): void
    {
        document_sequence_set_last($this->pdo, $this->type, 41, '');   // this site issued up to 41
        $this->pdo->prepare('INSERT INTO ' . self::SHARED_DB . '.document_sequences (type, last_number) VALUES (?, 57)')
            ->execute([$this->type]);                                    // the other site, up to 57

        $this->assertSame(58, document_sequence_next_in($this->pdo, $this->type, self::SHARED_DB));
        $this->assertSame(58, $this->last(self::SHARED_DB . '.document_sequences'));
        $this->assertSame(58, $this->last('document_sequences'), 'own counter kept level, so switching off repeats nothing');

        // The other site issues its own next one from the shared counter.
        $this->pdo->prepare('UPDATE ' . self::SHARED_DB . '.document_sequences SET last_number = 59 WHERE type = ?')
            ->execute([$this->type]);
        $this->assertSame(60, document_sequence_next_in($this->pdo, $this->type, self::SHARED_DB));
    }

    public function test_a_higher_own_counter_wins_too(): void
    {
        document_sequence_set_last($this->pdo, $this->type, 90, '');
        $this->assertSame(91, document_sequence_next_in($this->pdo, $this->type, self::SHARED_DB));
        $this->assertSame(91, $this->last(self::SHARED_DB . '.document_sequences'));
    }

    public function test_reading_and_setting_go_to_both_counters(): void
    {
        document_sequence_set_last($this->pdo, $this->type, 12, self::SHARED_DB);
        $this->assertSame(12, $this->last('document_sequences'));
        $this->assertSame(12, $this->last(self::SHARED_DB . '.document_sequences'));

        $this->pdo->prepare('UPDATE ' . self::SHARED_DB . '.document_sequences SET last_number = 20 WHERE type = ?')
            ->execute([$this->type]);
        $this->assertSame(20, document_sequence_last_all($this->pdo, self::SHARED_DB)[$this->type]);
        $this->assertSame(12, document_sequence_last_all($this->pdo, '')[$this->type]);
    }

    public function test_a_site_that_numbers_on_its_own_is_not_shared(): void
    {
        if (defined('DOC_SEQUENCE_DB') || defined('DOC_SEQUENCE_SHARED')) $this->markTestSkipped('This checkout shares its numbering.');
        $this->assertSame('', document_sequence_shared_db());
        $this->assertFalse(document_sequence_is_shared());
    }

    public function test_an_unreachable_shared_database_issues_nothing_and_says_so_plainly(): void
    {
        try {
            document_sequence_next_in($this->pdo, $this->type, 'phpunit_docseq_missing');
            $this->fail('a number was issued without the shared counter');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Общата номерация', $e->getMessage());
            $this->assertStringNotContainsString('phpunit_docseq_missing', $e->getMessage(), 'no database names in the browser');
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->last('document_sequences'), 'own counter untouched');

        $this->expectException(RuntimeException::class);
        document_sequence_last_all($this->pdo, 'phpunit_docseq_missing');
    }
}
