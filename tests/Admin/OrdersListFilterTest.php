<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The admin order list's filters and search box — orders_list_filter() and
 * orders_list_url() in includes/order_view.php, used by admin/orders.php.
 */
#[Group('admin')]
final class OrdersListFilterTest extends TestCase
{
    private static ?PDO $pdo = null;
    /** @var int[] */
    private static array $order_ids = [];
    private static string $tag;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_view.php';
        self::$tag = 'S' . substr(uniqid(), -8);
        if (!test_db_available()) return;
        require_once dirname(__DIR__, 2) . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo || !self::$order_ids) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
    }

    // ── reading the query string ─────────────────────────────────────────────

    public function testNoFiltersMeansEverything(): void
    {
        $f = orders_list_filter([]);
        $this->assertSame(['all', 'all', ''], [$f['type'], $f['status'], $f['q']]);
        $this->assertSame('1=1', $f['where']);
        $this->assertSame([], $f['params']);
    }

    public function testUnknownOrMalformedFiltersFallBackToAll(): void
    {
        $f = orders_list_filter(['type' => "physical' OR 1=1 --", 'status' => ['new'], 'q' => ['x']]);
        $this->assertSame(['all', 'all', ''], [$f['type'], $f['status'], $f['q']]);
        $this->assertSame('1=1', $f['where']);
    }

    public function testSearchTextNeverEntersTheSql(): void
    {
        $evil = "x' OR '1'='1";
        $f = orders_list_filter(['q' => $evil, 'type' => 'physical', 'status' => 'new']);
        $this->assertStringNotContainsString($evil, $f['where']);
        $this->assertStringNotContainsString("'1'='1", $f['where']);
        $this->assertSame(['physical', 'new', "%$evil%", "%$evil%", "%$evil%"], $f['params']);
    }

    public function testSearchIsTrimmedAndLikeWildcardsAreLiteral(): void
    {
        $f = orders_list_filter(['q' => "  50%   off_x!  "]);
        $this->assertSame('50% off_x!', $f['q']);
        $this->assertSame('%50!% off!_x!!%', $f['params'][0]);
    }

    public function testPhoneLikeSearchAlsoMatchesDigitsWithoutLeadingZero(): void
    {
        $f = orders_list_filter(['q' => '0888 12-34-56']);
        $this->assertCount(4, $f['params']);
        $this->assertSame('%888123456%', $f['params'][3]);
        $this->assertStringContainsString('customer_phone', $f['where']);

        // A name or email search doesn't look at phones; nor do too few digits.
        $this->assertStringNotContainsString('customer_phone', orders_list_filter(['q' => 'maria'])['where']);
        $this->assertStringNotContainsString('customer_phone', orders_list_filter(['q' => '01'])['where']);
    }

    public function testUrlKeepsOtherFiltersAndDropsDefaults(): void
    {
        $f = orders_list_filter(['type' => 'donation', 'status' => 'all', 'q' => 'Мария Иванова']);
        $this->assertSame('/admin/orders.php?type=donation&status=new&q=' . urlencode('Мария Иванова'),
            orders_list_url($f, ['status' => 'new']));
        $this->assertSame('/admin/orders.php?type=donation', orders_list_url($f, ['q' => '']));
        $this->assertSame('/admin/orders.php', orders_list_url(orders_list_filter([])));
    }

    // ── against the database ─────────────────────────────────────────────────

    private function insertOrder(array $o): string
    {
        $number = self::$tag . '-' . count(self::$order_ids);
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,lang,customer_name,customer_email,customer_phone,items,
                                 subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $number, $o['type'] ?? 'physical', $o['status'] ?? 'new', 'bg', $o['name'], $o['email'], $o['phone'] ?? null,
            '[]', 10.00, 0, 10.00, 'cod', 'pending',
        ]);
        self::$order_ids[] = (int)self::$pdo->lastInsertId();
        return $number;
    }

    /** Order numbers of this test's orders that the filter finds. */
    private function find(array $query): array
    {
        $f = orders_list_filter($query);
        $stmt = self::$pdo->prepare('SELECT order_number FROM orders WHERE ' . $f['where'] . ' AND order_number LIKE ? ORDER BY id');
        $stmt->execute([...$f['params'], self::$tag . '-%']);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testSearchFindsByNumberNameEmailAndPhoneTogetherWithFilters(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');

        $maria  = $this->insertOrder(['name' => 'Мария Петрова ' . self::$tag, 'email' => 'maria.' . self::$tag . '@example.test', 'phone' => '+359 888 123 456']);
        $ivan   = $this->insertOrder(['name' => 'Иван ' . self::$tag, 'email' => 'ivan.' . self::$tag . '@example.test', 'phone' => '0877/654-321', 'status' => 'shipped']);
        $don    = $this->insertOrder(['name' => 'Мария Дарителка ' . self::$tag, 'email' => 'd.' . self::$tag . '@example.test', 'type' => 'donation']);
        $pct    = $this->insertOrder(['name' => '100% ' . self::$tag, 'email' => 'p.' . self::$tag . '@example.test']);

        $this->assertSame([$ivan], $this->find(['q' => $ivan]), 'by order number');
        $this->assertSame([$maria, $don], $this->find(['q' => 'мария']), 'by name, case-insensitive');
        $this->assertSame([$ivan], $this->find(['q' => 'ivan.' . self::$tag]), 'by email');
        $this->assertSame([$maria], $this->find(['q' => '0888 123 456']), 'by phone, written differently');
        $this->assertSame([$ivan], $this->find(['q' => '0877654321']), 'by phone with separators stored');

        $this->assertSame([$don], $this->find(['q' => 'мария', 'type' => 'donation']), 'search + type');
        $this->assertSame([$maria, $don], $this->find(['q' => 'мария', 'status' => 'new']), 'search + status');
        $this->assertSame([], $this->find(['q' => 'мария', 'status' => 'shipped']));

        $this->assertSame([$pct], $this->find(['q' => '100%']), '% is searched for literally');
        $this->assertSame([], $this->find(['q' => '_']), 'a lone _ does not match everything');
    }
}
