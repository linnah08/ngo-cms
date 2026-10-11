<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Recipient and site filters on Админ → Поръчки (includes/order_view.php + site_extensions.php). */
final class OrderFiltersTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_view.php';
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

    private const TWO = '<?php return [
        "recipients" => [
            "foundation" => ["label_bg" => "Фондацията", "purpose_bg" => "П"],
            "partner"    => ["label_bg" => "Партньор", "purpose_bg" => "П"],
        ],
        "order_filters" => ["big" => ["label" => "Големи", "where" => "total_eur >= ?", "params" => [100]], "bad" => ["label" => "X", "where" => "1; DROP TABLE orders"]],
    ];';

    public function test_without_a_site_file_the_filters_are_as_before(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $f = orders_list_filter(['type' => 'donation', 'rec' => 'partner', 'x' => 'big']);
        $this->assertSame('', $f['rec']);
        $this->assertSame('', $f['x']);
        $this->assertSame('/admin/orders.php?type=donation', orders_list_url($f));
    }

    public function test_recipient_and_site_filters_narrow_the_list(): void
    {
        $this->site(self::TWO);
        $this->assertSame(['big'], array_keys(site_order_filters()), 'a condition with ";" is refused');
        $f = orders_list_filter(['rec' => 'partner', 'x' => 'big']);
        $this->assertSame('donation', $f['type'], 'a recipient implies donations');
        $this->assertSame('/admin/orders.php?type=donation&rec=partner&x=big', orders_list_url($f));
        $this->assertSame('/admin/orders.php?type=physical&x=big', orders_list_url($f, ['type' => 'physical']), 'leaving donations drops the recipient');
    }

    public function test_the_recipient_condition_counts_old_lines_as_the_default(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $this->site(self::TWO);
        $pdo = get_pdo();
        $pdo->exec('CREATE TEMPORARY TABLE t_rec (items JSON)');
        $ins = $pdo->prepare('INSERT INTO t_rec VALUES (?)');
        foreach ([
            [['type' => 'donation', 'amount_eur' => 5]],                                   // old: default
            [['type' => 'donation', 'amount_eur' => 5, 'recipient' => 'foundation']],
            [['type' => 'donation', 'amount_eur' => 5, 'recipient' => 'partner']],
            [['type' => 'physical'], ['type' => 'donation', 'recipient' => 'partner']],
        ] as $items) $ins->execute([json_encode($items)]);
        $count = function (string $rec) use ($pdo): int {
            [$cond, $p] = donation_recipient_sql($rec);
            $s = $pdo->prepare("SELECT COUNT(*) FROM t_rec WHERE $cond");
            $s->execute($p);
            return (int) $s->fetchColumn();
        };
        $this->assertSame(2, $count('foundation'));
        $this->assertSame(2, $count('partner'));
        $pdo->exec('DROP TEMPORARY TABLE t_rec');
    }
}
