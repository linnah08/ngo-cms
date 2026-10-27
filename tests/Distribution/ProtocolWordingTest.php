<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The hand-over protocol's price column and item line can be reworded through
 * strings.site.json: distribution.protocol.price_label and
 * distribution.protocol.item_name ({product}). No database needed.
 */
final class ProtocolWordingTest extends TestCase
{
    private const FIELDS = [
        'distributor_name' => 'Книжарница <А>', 'distributor_eik' => '', 'distributor_contact' => '', 'distributor_address' => '',
        'item_name' => 'Комплект (Син)', 'allocated_on' => '2026-10-01', 'quantity' => 2, 'unit_price_eur' => 5.0,
    ];

    /** The protocol HTML in a fresh PHP process, with an optional strings.site.json for BG. */
    private function html(array $site_bg = []): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = sys_get_temp_dir() . '/om-protocol-' . bin2hex(random_bytes(4));
        mkdir($dir . '/bg', 0777, true);
        if ($site_bg) file_put_contents($dir . '/bg/strings.site.json', json_encode($site_bg, JSON_UNESCAPED_UNICODE));
        $file = tempnam(sys_get_temp_dir(), 'om_protocol_');
        file_put_contents($file, "<?php\n\$GLOBALS['_om_site_strings_dir'] = " . var_export($dir, true) . ";\n"
            . 'require ' . var_export($root . '/tests/bootstrap.php', true) . ";\n"
            . 'require_once ROOT_PATH . "/includes/documents/DistributionProtocolGenerator.php";' . "\n"
            . '$g = new class extends DistributionProtocolGenerator { public function html(array $o): string { return $this->buildHtml($o, [], ["formatted_number" => "00007"]); } };' . "\n"
            . 'echo $g->html(' . var_export(self::FIELDS, true) . ");\n");
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        } finally {
            @unlink($file);
            @unlink($dir . '/bg/strings.site.json'); @rmdir($dir . '/bg'); @rmdir($dir);
        }
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_default_wording_is_unchanged(): void
    {
        $html = $this->html();
        $this->assertStringContainsString('>Цена за брой</th>', $html);
        $this->assertStringContainsString('<td>Комплект (Син)</td>', $html);
        $this->assertStringContainsString('10.00 €', $html);
    }

    public function test_a_site_can_reword_the_price_column_and_the_item_line(): void
    {
        $html = $this->html([
            'distribution.protocol.price_label' => 'Препоръчителна цена',
            'distribution.protocol.item_name'   => 'Продукт: {product} <б>',
        ]);
        $this->assertStringContainsString('>Препоръчителна цена</th>', $html);
        $this->assertStringNotContainsString('Цена за брой', $html);
        $this->assertStringContainsString('<td>Продукт: Комплект (Син) &lt;б&gt;</td>', $html, 'reworded and still escaped');
    }

    public function test_both_keys_are_in_the_shipped_strings(): void
    {
        foreach (['bg', 'en'] as $lang) {
            $s = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/content/{$lang}/strings.json"), true);
            $this->assertArrayHasKey('distribution.protocol.price_label', $s, $lang);
            $this->assertSame('{product}', $s['distribution.protocol.item_name'] ?? null, $lang);
        }
    }
}
