<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own document kinds (includes/site_extensions.php). */
final class SiteDocumentTypesTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    private function site(string $php): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, $php);
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_a_site_names_its_own_documents(): void
    {
        $this->site('<?php return ["document_types" => [
            "partner_cert"  => ["label" => "Сертификат (партньор)", "plural" => "Сертификати за партньора", "digits" => 4],
            "invoice"       => ["label" => "Hijack"],
            "way_too_long_key_for_the_sequence_table" => ["label" => "X"],
        ]];');
        $this->assertSame(['partner_cert'], array_keys(site_document_types()));
        $this->assertSame(4, site_document_types()['partner_cert']['digits']);
        $this->assertSame('Сертификат (партньор)', document_type_label('partner_cert'));
        $this->assertSame('Фактура', document_type_label('invoice'), 'built-in names stay');
        $this->assertSame('Документ', document_type_label('unknown'));
    }

    public function test_numbering_creates_the_counter_and_counts_up(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo  = get_pdo();
        $type = 'phpunit_' . bin2hex(random_bytes(4));
        try {
            $pdo->beginTransaction();
            $this->assertSame(1, document_sequence_next($pdo, $type));
            $this->assertSame(2, document_sequence_next($pdo, $type));
            $pdo->commit();
        } finally {
            $pdo->prepare('DELETE FROM document_sequences WHERE type = ?')->execute([$type]);
        }
    }

    public function test_a_document_of_a_site_kind_can_be_stored(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $col = get_pdo()->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'type'")->fetchColumn();
        $this->assertSame('varchar', $col, 'migration 044 has run: any site kind fits');
    }
}
