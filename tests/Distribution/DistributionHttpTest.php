<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * admin/distribution.php and admin/download-distribution-protocol.php over real
 * HTTP: access control, CSRF, a full add-batch → hand-over → protocol round
 * trip, and the bulk "mark paid" action. Boots its own `php -S` like the other
 * admin HTTP tests.
 */
#[Group('http')]
#[Group('admin')]
#[Group('distribution')]
final class DistributionHttpTest extends TestCase
{
    private static string $orgFile = '';
    private static ?string $orgBackup = null;
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $ready = false;
    private static array $logs = [];
    private static string $jar = '';

    private static string $email    = 'test.distribution.admin@example.test';
    private static string $password = 'DistributionTest_Admin_42!';
    private static ?int $uid        = null;
    private static ?int $productId  = null;
    private static ?int $seqBefore  = null;
    private static array $files     = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        // Distribution is new (off until switched on) and needs the shop: switch both on
        // for the test server, keeping the rest of organisation.json; restored afterwards.
        self::$orgFile   = self::$root . '/content/organisation.json';
        self::$orgBackup = is_file(self::$orgFile) ? (string) file_get_contents(self::$orgFile) : null;
        $org = self::$orgBackup !== null ? (json_decode(self::$orgBackup, true) ?: []) : [];
        $org['feature_shop'] = '1';
        $org['feature_distribution'] = '1';
        if (!is_dir(dirname(self::$orgFile))) mkdir(dirname(self::$orgFile), 0755, true);
        file_put_contents(self::$orgFile, json_encode($org, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";

        $out = tempnam(sys_get_temp_dir(), 'om_dist_srv_out_');
        $err = tempnam(sys_get_temp_dir(), 'om_dist_srv_err_');
        self::$logs = [$out, $err];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root],
            [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, self::$root);
        if (is_resource($proc)) self::$serverProc = $proc;

        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) { self::$ready = true; break; }
            usleep(100_000);
        }
        if (!self::$ready || !test_db_available()) return;

        $pdo = get_pdo();
        try {
            $pdo->query('SELECT 1 FROM distribution_batches LIMIT 1');
        } catch (PDOException $e) {
            self::$ready = false;
            return;
        }
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (PDOException $e) {
        }
        $seq = $pdo->query("SELECT last_number FROM document_sequences WHERE type = 'distribution_protocol'")->fetchColumn();
        self::$seqBefore = $seq === false ? null : (int) $seq;

        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Distribution Test', self::$email, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['test-dist-http-' . bin2hex(random_bytes(4)), 'Календар тест', 'Calendar test', 9.00, 0, 0]);
        self::$productId = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$orgBackup !== null) file_put_contents(self::$orgFile, self::$orgBackup);
        elseif (self::$orgFile !== '') @unlink(self::$orgFile);
        if (test_db_available() && self::$productId) {
            $pdo = get_pdo();
            $dist = $pdo->prepare('SELECT DISTINCT distributor_id FROM distribution_allocations WHERE product_id = ? AND distributor_id IS NOT NULL');
            $dist->execute([self::$productId]);
            $ids = $dist->fetchAll(PDO::FETCH_COLUMN);
            $files = $pdo->prepare('SELECT protocol_file_path FROM distribution_allocations WHERE product_id = ? AND protocol_file_path IS NOT NULL');
            $files->execute([self::$productId]);
            foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $f) {
                @unlink(self::$root . '/' . $f);
                @rmdir(dirname(self::$root . '/' . $f)); // only goes if now empty
            }
            foreach (['distribution_sales', 'distribution_allocations', 'distribution_batches'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE product_id = ?")->execute([self::$productId]);
            }
            $pdo->prepare("DELETE FROM distribution_distributors WHERE name LIKE 'HTTP тест %'")->execute();
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([self::$productId]);
            if (self::$seqBefore !== null) {
                $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'distribution_protocol'")->execute([self::$seqBefore]);
            }
        }
        if (test_db_available() && self::$uid) {
            get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
        }
        if (is_resource(self::$serverProc)) {
            $status = proc_get_status(self::$serverProc);
            proc_terminate(self::$serverProc, 9);
            if (!empty($status['pid'])) @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
        }
        foreach (self::$logs as $l) @unlink($l);
        if (self::$jar) @unlink(self::$jar);
    }

    protected function setUp(): void
    {
        if (!self::$ready || !self::$productId) $this->markTestSkipped('Needs the local test server and a DB with migration 041.');
    }

    /** @return array{0:int,1:string,2:string} code, body, redirect location */
    private function req(string $path, ?array $post = null, bool $auth = true): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true]);
        if ($auth) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, self::$jar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, self::$jar);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return [$code, substr($raw, $hs), $m[1] ?? ''];
    }

    private function login(): string
    {
        if (!self::$jar) {
            self::$jar = (string) tempnam(sys_get_temp_dir(), 'om_dist_jar_');
            $this->req('/admin/login.php', ['email' => self::$email, 'password' => self::$password]);
        }
        [, $body] = $this->req('/admin/distribution.php?product=' . self::$productId);
        $this->assertMatchesRegularExpression('/name="csrf_token" value="([a-f0-9]+)"/', $body);
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m);
        return $m[1];
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        [$code, , $loc] = $this->req('/admin/distribution.php', null, false);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('/admin/login.php', $loc);

        [$code, , $loc] = $this->req('/admin/download-distribution-protocol.php?id=1', null, false);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('/admin/login.php', $loc);

        [$code, , $loc] = $this->req('/admin/distribution.php', ['action' => 'add_batch', 'item' => self::$productId . '_0', 'quantity' => 5], false);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('/admin/login.php', $loc);
    }

    public function testPostWithoutCsrfIsRefused(): void
    {
        skip_unless_modules_on('distribution');
        $this->login();
        [$code] = $this->req('/admin/distribution.php', ['action' => 'add_batch', 'item' => self::$productId . '_0', 'quantity' => 5, 'produced_on' => '2026-01-01']);
        $this->assertSame(400, $code);
        $n = get_pdo()->prepare('SELECT COUNT(*) FROM distribution_batches WHERE product_id = ?');
        $n->execute([self::$productId]);
        $this->assertSame(0, (int) $n->fetchColumn());
    }

    public function testEachTabHasItsOwnProductDropdown(): void
    {
        skip_unless_modules_on('distribution');
        $this->login();
        [$code, $body] = $this->req('/admin/distribution.php');
        $this->assertSame(200, $code);
        // No page-wide product picker: each tab's form has its own „Продукт“ dropdown,
        // and each tab's list its own product filter.
        $this->assertStringNotContainsString('id="distProduct"', $body);
        foreach (['batchItem', 'stockItem', 'allocLine1Item', 'saleLine1Item'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $body, $id);
        }
        foreach (['fBatchP', 'fAllocP', 'fSaleProd', 'fDistP'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $body, $id);
        }
        $this->assertMatchesRegularExpression('/<option value="' . self::$productId . '_0">Календар тест( \(неактивен\))?<\/option>/u', $body);
        $this->assertGreaterThan(strpos($body, 'id="distTabs"'), strpos($body, 'id="batchItem"'), 'the dropdown is inside the tab');
        foreach (['Партиди', 'Разпределения', 'Продажби', 'Дистрибутори'] as $tab) {
            $this->assertStringContainsString($tab, $body);
        }
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Warning:', $body);
    }

    public function testBatchHandOverProtocolAndBulkPaidRoundTrip(): void
    {
        skip_unless_modules_on('distribution');
        $pdo   = get_pdo();
        $token = $this->login();
        $pid   = self::$productId;
        $base  = ['csrf_token' => $token, 'product_id' => $pid];

        [$code, , $loc] = $this->req('/admin/distribution.php', $base + ['action' => 'add_batch', 'item' => $pid . '_0', 'quantity' => 40, 'produced_on' => '2026-05-01']);
        $this->assertSame(302, $code);
        $this->assertStringEndsWith('#batches', $loc);

        $this->req('/admin/distribution.php', $base + ['action' => 'add_distributor', 'name' => 'HTTP тест книжарница', 'company_number' => '987654321']);
        $did = (int) $pdo->query("SELECT id FROM distribution_distributors WHERE name = 'HTTP тест книжарница'")->fetchColumn();
        $this->assertGreaterThan(0, $did);

        // Missing EIK is refused with a visible message.
        $this->req('/admin/distribution.php', $base + ['action' => 'add_distributor', 'name' => 'HTTP тест без ЕИК', 'company_number' => '']);
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('ЕИК / Булстат — той се отпечатва', $body);

        [, , $loc] = $this->req('/admin/distribution.php', $base + [
            'action' => 'allocate', 'recipient' => (string) $did, 'allocated_on' => '2026-05-02',
            'line_item' => [$pid . '_0'], 'line_qty' => ['15'], 'line_price' => ['9,50'],
        ]);
        $this->assertStringEndsWith('#allocations', $loc);
        $a = $pdo->prepare('SELECT * FROM distribution_allocations WHERE product_id = ? AND destination = ?');
        $a->execute([$pid, 'distributor']);
        $alloc = $a->fetch();
        $this->assertSame(15, (int) $alloc['quantity']);
        $this->assertSame(9.5, (float) $alloc['unit_price_eur'], 'a comma decimal is accepted');
        $this->assertNotEmpty($alloc['protocol_file_path']);

        [$code, $pdf] = $this->req('/admin/download-distribution-protocol.php?id=' . (int) $alloc['id']);
        $this->assertSame(200, $code);
        $this->assertStringStartsWith('%PDF', $pdf);

        [$code] = $this->req('/admin/download-distribution-protocol.php?id=999999999');
        $this->assertSame(404, $code);

        // Over-allocating is refused and nothing is written.
        $this->req('/admin/distribution.php', $base + ['action' => 'allocate', 'recipient' => 'personal', 'allocated_on' => '2026-05-02', 'line_item' => [$pid . '_0'], 'line_qty' => ['26'], 'line_price' => ['9']]);
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('Няма толкова неразпределени бройки', $body);

        foreach ([3, 4] as $q) {
            $this->req('/admin/distribution.php', $base + ['action' => 'record_sale', 'distributor_id' => $did, 'sale_date' => '2026-05-10', 'line_item' => [$pid . '_0'], 'line_qty' => [(string) $q]]);
        }
        $ids = $pdo->query("SELECT id FROM distribution_sales WHERE distributor_id = $did ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $ids);

        // Filtered view narrows the list.
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid . '&sp=paid');
        $this->assertStringContainsString('Няма продажби, които отговарят на филтъра', $body);

        [, , $loc] = $this->req('/admin/distribution.php', $base + ['action' => 'sales_paid', 'paid' => '1', 'ids' => $ids, 'sp' => 'unpaid']);
        $this->assertStringContainsString('sp=unpaid', $loc, 'filters survive the bulk action');
        $paid = (int) $pdo->query("SELECT SUM(payment_received) FROM distribution_sales WHERE distributor_id = $did")->fetchColumn();
        $this->assertSame(2, $paid);

        // Delete: the page offers it with a confirm, a sale goes, then the hand-over and its protocol.
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('value="delete_sale"', $body);
        $this->assertStringContainsString('Записът за полученото плащане също ще бъде изтрит', $body, 'paid sales warn about the payment');
        $this->assertStringContainsString('value="delete_allocation"', $body);
        $this->assertStringContainsString('value="delete_batch"', $body);

        [, , $loc] = $this->req('/admin/distribution.php', $base + ['action' => 'delete_allocation', 'id' => (int) $alloc['id']]);
        $this->assertStringEndsWith('#allocations', $loc);
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('Първо изтрийте или намалете продажбите', $body, '7 sold blocks deleting the hand-over');

        foreach ($ids as $sid) {
            [, , $loc] = $this->req('/admin/distribution.php', $base + ['action' => 'delete_sale', 'id' => (int) $sid]);
            $this->assertStringEndsWith('#sales', $loc);
        }
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM distribution_sales WHERE distributor_id = $did")->fetchColumn());

        $this->req('/admin/distribution.php', $base + ['action' => 'delete_allocation', 'id' => (int) $alloc['id']]);
        $this->assertFalse($pdo->query('SELECT id FROM distribution_allocations WHERE id = ' . (int) $alloc['id'])->fetchColumn());
        $this->assertFileDoesNotExist(self::$root . '/' . $alloc['protocol_file_path'], 'the only row on it: the PDF goes');
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('Записът и протокол № ' . $alloc['protocol_formatted_number'] . ' са изтрити', $body);

        // Unauthenticated: no delete.
        $sid = (int) $pdo->query("SELECT id FROM distribution_batches WHERE product_id = $pid LIMIT 1")->fetchColumn();
        [$code] = $this->req('/admin/distribution.php', ['action' => 'delete_batch', 'id' => $sid, 'product_id' => $pid], false);
        $this->assertNotSame(200, $code);
        $this->assertSame($sid, (int) $pdo->query("SELECT id FROM distribution_batches WHERE id = $sid")->fetchColumn());

        $this->req('/admin/distribution.php', $base + ['action' => 'distributors_active', 'active' => '0', 'ids' => [$did]]);
        $this->assertSame(0, (int) $pdo->query("SELECT active FROM distribution_distributors WHERE id = $did")->fetchColumn());
    }

    public function testAllProductsTableHasTotalRow(): void
    {
        skip_unless_modules_on('distribution');
        require_once self::$root . '/includes/distribution.php';
        $pdo   = get_pdo();
        $token = $this->login();
        $pid   = self::$productId;
        $this->req('/admin/distribution.php', ['csrf_token' => $token, 'product_id' => $pid,
            'action' => 'add_batch', 'item' => $pid . '_0', 'quantity' => 7, 'produced_on' => '2026-06-01']);

        $overview = distribution_overview($pdo);
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        if (count($overview) < 2) {
            $this->assertStringNotContainsString('<tfoot>', $body, 'One product: a total would just repeat its row');
            return;
        }
        $this->assertMatchesRegularExpression('~<tfoot>.*Общо~s', $body);
        $produced = array_sum(array_map(fn($o) => (int) $o['total']['produced'], $overview));
        preg_match('~<tfoot>.*?Общо</th>\s*<td[^>]*>([^<]+)</td>~s', $body, $m);
        $this->assertSame(number_format($produced, 0, ',', ' '), trim($m[1] ?? ''), 'Total produced is the sum of the product rows');
    }

    public function testSamplesForSeveralProductsRoundTrip(): void
    {
        skip_unless_modules_on('distribution');
        $pdo   = get_pdo();
        $token = $this->login();
        $pid   = self::$productId;
        $base  = ['csrf_token' => $token, 'product_id' => $pid];
        $this->req('/admin/distribution.php', $base + ['action' => 'add_batch', 'item' => $pid . '_0', 'quantity' => 10, 'produced_on' => '2026-06-01']);

        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('Наличност — всички продукти', $body);
        $this->assertStringContainsString('name="qty[' . $pid . '_0]"', $body);

        $count = fn(string $dest) => (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM distribution_allocations WHERE product_id = $pid AND destination = '$dest'")->fetchColumn();
        $samples = $count('sample');

        // No recipient: refused, visibly, nothing written.
        [$code, , $loc] = $this->req('/admin/distribution.php', $base + [
            'action' => 'give_out_many', 'kind' => 'sample', 'allocated_on' => '2026-06-02', 'qty' => [$pid . '_0' => '1'],
        ]);
        $this->assertSame(302, $code);
        $this->assertStringEndsWith('#stock-all', $loc);
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('на кого са дадени', $body);
        $this->assertSame($samples, $count('sample'));

        $this->req('/admin/distribution.php', $base + [
            'action' => 'give_out_many', 'kind' => 'sample', 'sample_recipient' => 'Партньор тест',
            'allocated_on' => '2026-06-02', 'qty' => [$pid . '_0' => '2', '999999999_0' => ''],
        ]);
        $this->assertSame($samples + 2, $count('sample'));

        $this->req('/admin/distribution.php', $base + [
            'action' => 'give_out_many', 'kind' => 'writeoff', 'allocated_on' => '2026-06-02', 'qty' => [$pid . '_0' => '1'],
        ]);
        $this->assertSame(1, $count('writeoff'));
        [, $body] = $this->req('/admin/distribution.php?product=' . $pid);
        $this->assertStringContainsString('Отписани: 1 бр.', $body);
        $this->assertStringNotContainsString('Warning:', $body);

        // Junk quantities are refused.
        $this->req('/admin/distribution.php', $base + [
            'action' => 'give_out_many', 'kind' => 'writeoff', 'allocated_on' => '2026-06-02', 'qty' => [$pid . '_0' => 'две'],
        ]);
        $this->assertSame(1, $count('writeoff'));
    }

    public function testSeveralProductsToOneDistributorAndTheDistributorsFilter(): void
    {
        skip_unless_modules_on('distribution');
        $pdo   = get_pdo();
        $token = $this->login();

        // Two separate books, and a series with two variants.
        $pids = [];
        foreach (['Книга едно тест', 'Книга две тест', 'Серия тест'] as $i => $name) {
            $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active, `type`) VALUES (?, ?, ?, ?, 0, 0, ?)')
                ->execute(['test-dist-http-many-' . bin2hex(random_bytes(4)), $name, $name, 6.00 + $i, $i === 2 ? 'variant' : 'standard']);
            $pids[] = (int) $pdo->lastInsertId();
        }
        $vids = [];
        foreach (['Първа', 'Втора'] as $i => $label) {
            $pdo->prepare('INSERT INTO product_variants (product_id, label_bg, label_en, attributes, stock, active, sort_order) VALUES (?, ?, ?, ?, 0, 1, ?)')
                ->execute([$pids[2], $label, $label, '{}', $i]);
            $vids[] = (int) $pdo->lastInsertId();
        }
        $keys = [$pids[0] . '_0', $pids[1] . '_0', $pids[2] . '_' . $vids[0], $pids[2] . '_' . $vids[1]];
        $in   = implode(',', $pids);
        try {
            $base = ['csrf_token' => $token];
            foreach ($keys as $k) {
                [, , $loc] = $this->req('/admin/distribution.php', $base + ['action' => 'add_batch', 'item' => $k, 'quantity' => 10, 'produced_on' => '2026-07-01']);
                $this->assertStringEndsWith('#batches', $loc);
            }
            $this->assertSame(40, (int) $pdo->query("SELECT SUM(quantity) FROM distribution_batches WHERE product_id IN ($in)")->fetchColumn());
            $this->req('/admin/distribution.php', $base + ['action' => 'add_distributor', 'name' => 'HTTP тест много', 'company_number' => '111222333']);
            $did = (int) $pdo->query("SELECT id FROM distribution_distributors WHERE name = 'HTTP тест много'")->fetchColumn();

            // Every product and variant is in the forms' dropdowns.
            [, $body] = $this->req('/admin/distribution.php');
            foreach ($keys as $k) $this->assertStringContainsString('<option value="' . $k . '">', $body);

            // All four to one distributor in one save: one protocol for the lot.
            [, , $loc] = $this->req('/admin/distribution.php', $base + [
                'action' => 'allocate', 'recipient' => (string) $did, 'allocated_on' => '2026-07-02',
                'line_item' => [$keys[0], $keys[1], $keys[2], $keys[3], ''], 'line_qty' => ['4', '5', '6', '3', ''], 'line_price' => ['6', '7', '8', '8', ''],
            ]);
            $this->assertStringEndsWith('#allocations', $loc);
            $rows = $pdo->query("SELECT product_id, quantity, unit_price_eur, protocol_number FROM distribution_allocations WHERE distributor_id = $did ORDER BY product_id, variant_id")->fetchAll();
            $this->assertSame([4, 5, 6, 3], array_map(fn($r) => (int) $r['quantity'], $rows));
            $this->assertSame([6.0, 7.0, 8.0, 8.0], array_map(fn($r) => (float) $r['unit_price_eur'], $rows), 'each line keeps its own price');
            $this->assertCount(1, array_unique(array_column($rows, 'protocol_number')), 'one protocol for the whole hand-over');
            $this->assertNotNull($rows[0]['protocol_number']);

            // A quantity on a line with no product is refused, not silently dropped.
            $this->req('/admin/distribution.php', $base + [
                'action' => 'allocate', 'recipient' => 'personal', 'allocated_on' => '2026-07-02',
                'line_item' => [''], 'line_qty' => ['2'], 'line_price' => ['6'],
            ]);
            [, $body] = $this->req('/admin/distribution.php');
            $this->assertStringContainsString('не е избран продукт', $body);

            // The sale form knows what the distributor holds, and sells several products at once.
            $this->assertStringContainsString('"' . $keys[0] . '":4', $body, 'holdings for the sale form');
            $this->req('/admin/distribution.php', $base + [
                'action' => 'record_sale', 'distributor_id' => $did, 'sale_date' => '2026-07-10',
                'line_item' => [$keys[0], $keys[1], $keys[3]], 'line_qty' => ['4', '', '2'],
            ]);
            $sold = $pdo->query("SELECT product_id, SUM(quantity) FROM distribution_sales WHERE distributor_id = $did GROUP BY product_id ORDER BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame([$pids[0] => 4, $pids[2] => 2], array_map('intval', $sold), 'the empty line is skipped');

            // Too many of one: refused visibly, nothing written.
            $this->req('/admin/distribution.php', $base + [
                'action' => 'record_sale', 'distributor_id' => $did, 'sale_date' => '2026-07-11',
                'line_item' => [$keys[1], $keys[2]], 'line_qty' => ['1', '9'],
            ]);
            [, $body] = $this->req('/admin/distribution.php');
            $this->assertStringContainsString('Серия тест — Първа', $body);
            $this->assertStringContainsString('Нищо не е записано', $body);
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM distribution_sales WHERE distributor_id = $did")->fetchColumn());

            // Lists show every product; a tab's filter narrows only its list.
            [, $body] = $this->req('/admin/distribution.php?fp=' . $pids[1] . '#allocations');
            $this->assertStringContainsString('Книга две тест', $body);

            // Distributors filter.
            [, $body] = $this->req('/admin/distribution.php?df=unpaid#distributors');
            $this->assertStringContainsString('<strong>HTTP тест много</strong>', $body, 'has unpaid sales');
            [, $body] = $this->req('/admin/distribution.php?df=holding&dq=111222333');
            $this->assertStringContainsString('<strong>HTTP тест много</strong>', $body, 'still holds stock; found by EIK');
            [, $body] = $this->req('/admin/distribution.php?df=holding&fp=' . $pids[0]);
            $this->assertStringNotContainsString('<strong>HTTP тест много</strong>', $body, 'sold all of book one');
            [, $body] = $this->req('/admin/distribution.php?dq=' . urlencode('няма-такъв'));
            $this->assertStringContainsString('Няма дистрибутори, които отговарят на филтъра', $body);
        } finally {
            foreach ($pdo->query("SELECT DISTINCT protocol_file_path FROM distribution_allocations WHERE product_id IN ($in) AND protocol_file_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $f) {
                @unlink(self::$root . '/' . $f);
                @rmdir(dirname(self::$root . '/' . $f));
            }
            foreach (['distribution_sales', 'distribution_allocations', 'distribution_batches', 'product_variants'] as $t) {
                $pdo->exec("DELETE FROM $t WHERE product_id IN ($in)");
            }
            $pdo->exec("DELETE FROM products WHERE id IN ($in)");
        }
    }
}
