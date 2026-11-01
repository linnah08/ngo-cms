<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Paid orders and donations reported to GA4 from the server (includes/ga4.php):
 * the ids read from the visitor's cookies, the event sent for each kind of order,
 * and the "exactly once" guarantee when callbacks race or Google fails.
 */
#[Group('payment')]
final class Ga4ConversionTest extends TestCase
{
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ga4.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ga4_test_config']);
    }

    public static function tearDownAfterClass(): void
    {
        if (empty(self::$order_ids) || !test_db_available()) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        get_pdo()->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
    }

    // ── ids from the cookies ──────────────────────────────────────────────────

    public function test_client_id_is_the_last_two_parts_of_the_ga_cookie(): void
    {
        $this->assertSame('123456789.1730000000', ga4_client_id_from_cookie('GA1.1.123456789.1730000000'));
        $this->assertSame('987.654', ga4_client_id_from_cookie('GA1.2.987.654'));
        $this->assertNull(ga4_client_id_from_cookie('GA1.1.abc.def'));
        $this->assertNull(ga4_client_id_from_cookie(''));
        $this->assertNull(ga4_client_id_from_cookie(null));
    }

    public function test_session_id_is_read_from_both_cookie_formats(): void
    {
        $this->assertSame('1730000000', ga4_session_id_from_cookie('GS1.1.1730000000.3.1.1730000100.0.0.0'));
        $this->assertSame('1730000000', ga4_session_id_from_cookie('GS2.1.s1730000000$o3$g1$t1730000100$j60$l0$h0'));
        $this->assertNull(ga4_session_id_from_cookie('GS9.garbage'));
        $this->assertNull(ga4_session_id_from_cookie(null));
    }

    public function test_order_keeps_the_ids_only_with_cookie_consent(): void
    {
        $cookies = ['_ga' => 'GA1.1.111.222', '_ga_AB12CD34EF' => 'GS2.1.s1730000000$o1', 'om_gclid' => 'Cj0KCQjw-abc_123'];

        $ids = ga4_order_tracking_ids(['om_cookie_consent' => 'all'] + $cookies, [], 'G-AB12CD34EF');
        $this->assertSame(['ga_client_id' => '111.222', 'ga_session_id' => '1730000000', 'gclid' => 'Cj0KCQjw-abc_123'], $ids);

        foreach (['essential', ''] as $choice) {
            $none = ga4_order_tracking_ids(['om_cookie_consent' => $choice] + $cookies, ['ga_gclid' => 'Cj0KCQjw-abc_123'], 'G-AB12CD34EF');
            $this->assertSame(['ga_client_id' => null, 'ga_session_id' => null, 'gclid' => null], $none, "consent '$choice'");
        }
    }

    public function test_click_id_from_the_session_when_there_is_no_cookie_yet(): void
    {
        $ids = ga4_order_tracking_ids(['om_cookie_consent' => 'all', '_ga' => 'GA1.1.1.2'], ['ga_gclid' => 'EAIaIQobChMI-xyz'], 'G-X1');
        $this->assertSame('EAIaIQobChMI-xyz', $ids['gclid']);
        $this->assertNull(ga4_clean_gclid('<script>'), 'a click id is only letters, digits, - and _');
    }

    // ── the event ─────────────────────────────────────────────────────────────

    public function test_shop_order_is_a_purchase_with_its_items(): void
    {
        $order = ['order_number' => 'OM-20261101-AB12', 'type' => 'physical', 'total_eur' => '47.50',
                  'items' => json_encode([
                      ['product_id' => 7, 'name_bg' => 'Чаша', 'price_eur' => 12.5, 'quantity' => 2],
                      ['product_id' => 9, 'name_bg' => 'Тениска', 'variant_label' => 'Лафетки', 'price_eur' => 22.5, 'quantity' => 1],
                  ])];
        [$name, $p] = ga4_conversion_event($order);

        $this->assertSame('purchase', $name);
        $this->assertSame('OM-20261101-AB12', $p['transaction_id']);
        $this->assertSame(47.5, $p['value']);
        $this->assertSame('EUR', $p['currency']);
        $this->assertSame(['item_id' => '7', 'item_name' => 'Чаша', 'price' => 12.5, 'quantity' => 2], $p['items'][0]);
        $this->assertSame('Тениска — Лафетки', $p['items'][1]['item_name']);
    }

    public function test_donation_is_a_donate_event_with_a_D_prefixed_id(): void
    {
        [$name, $p] = ga4_conversion_event(['order_number' => 'OM-20261101-CD34', 'type' => 'donation', 'total_eur' => '20.00', 'items' => '[]']);
        $this->assertSame('donate', $name);
        $this->assertSame(['transaction_id' => 'D-OM-20261101-CD34', 'value' => 20.0, 'currency' => 'EUR'], $p);
    }

    public function test_nothing_may_be_sent_without_a_client_id(): void
    {
        $this->assertNull(ga4_conversion_payload(['order_number' => 'OM-1', 'type' => 'donation', 'total_eur' => 5, 'ga_client_id' => null]));
    }

    public function test_payload_carries_client_and_session_id_and_the_payment_time(): void
    {
        $body = ga4_conversion_payload(['order_number' => 'OM-1', 'type' => 'donation', 'total_eur' => 5,
                                        'ga_client_id' => '111.222', 'ga_session_id' => '1730000000', 'paid_age_s' => '3600']);
        $this->assertSame('111.222', $body['client_id']);
        $this->assertSame('1730000000', $body['events'][0]['params']['session_id']);
        $this->assertEqualsWithDelta((time() - 3600) * 1000000, $body['timestamp_micros'], 5 * 1000000);
    }

    // ── exactly once ──────────────────────────────────────────────────────────

    private function paidOrder(array $over = []): array
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $pdo = get_pdo();
        $d = $over + ['order_number' => generate_order_number(), 'type' => 'donation', 'payment_status' => 'paid',
                      'ga_client_id' => '111.222', 'paid_at' => date('Y-m-d H:i:s')];
        $pdo->prepare("INSERT INTO orders (order_number, type, status, lang, customer_name, customer_email, items,
                         subtotal_eur, shipping_eur, total_eur, payment_method, payment_status, ga_client_id, paid_at)
                       VALUES (?, ?, 'confirmed', 'bg', 'Тест', 't@example.com', '[]', 20, 0, 20, 'iris', ?, ?, ?)")
            ->execute([$d['order_number'], $d['type'], $d['payment_status'], $d['ga_client_id'], $d['paid_at']]);
        $id = (int) $pdo->lastInsertId();
        self::$order_ids[] = $id;
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function sentAt(int $id): ?string
    {
        $stmt = get_pdo()->prepare('SELECT conversion_sent_at FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    public function test_sent_once_even_when_the_callback_and_the_return_both_report_it(): void
    {
        $GLOBALS['ga4_test_config'] = ['id' => 'G-TEST1234', 'secret' => 'secret-12345'];
        $order = $this->paidOrder();
        $calls = [];
        $post  = function (string $url, string $json) use (&$calls): int { $calls[] = [$url, json_decode($json, true)]; return 204; };

        $this->assertTrue(ga4_send_conversion(get_pdo(), $order, $post));
        $this->assertFalse(ga4_send_conversion(get_pdo(), $order, $post), 'the second report finds it already sent');

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('measurement_id=G-TEST1234', $calls[0][0]);
        $this->assertSame('donate', $calls[0][1]['events'][0]['name']);
        $this->assertNotNull($this->sentAt((int) $order['id']));
    }

    public function test_a_failed_send_is_left_for_the_cron_to_retry(): void
    {
        $GLOBALS['ga4_test_config'] = ['id' => 'G-TEST1234', 'secret' => 'secret-12345'];
        $order = $this->paidOrder();

        $this->assertFalse(ga4_send_conversion(get_pdo(), $order, fn () => 500));
        $this->assertNull($this->sentAt((int) $order['id']));
        $this->assertContains((int) $order['id'], array_map('intval', array_column(ga4_conversions_due(get_pdo()), 'id')));

        [$sent] = run_ga4_conversions_job(get_pdo(), fn () => 204);
        $this->assertGreaterThanOrEqual(1, $sent);
        $this->assertNotNull($this->sentAt((int) $order['id']));
    }

    public function test_cron_skips_orders_without_consent_and_older_than_google_accepts(): void
    {
        $GLOBALS['ga4_test_config'] = ['id' => 'G-TEST1234', 'secret' => 'secret-12345'];
        $no_consent = $this->paidOrder(['ga_client_id' => null]);
        $too_old    = $this->paidOrder(['paid_at' => date('Y-m-d H:i:s', time() - 80 * 3600)]);
        $unpaid     = $this->paidOrder(['payment_status' => 'pending']);

        $due = array_map('intval', array_column(ga4_conversions_due(get_pdo()), 'id'));
        foreach ([$no_consent, $too_old, $unpaid] as $o) $this->assertNotContains((int) $o['id'], $due);
        $this->assertFalse(ga4_send_conversion(get_pdo(), $no_consent, fn () => 204));
    }

    public function test_nothing_is_sent_while_the_api_secret_is_missing(): void
    {
        $GLOBALS['ga4_test_config'] = ['id' => 'G-TEST1234', 'secret' => ''];
        $order = $this->paidOrder();
        $this->assertFalse(ga4_send_conversion(get_pdo(), $order, fn () => $this->fail('no request without a secret')));
        $this->assertNull($this->sentAt((int) $order['id']));
    }

    public function test_marking_paid_notes_the_time_once(): void
    {
        $GLOBALS['ga4_test_config'] = ['id' => '', 'secret' => ''];
        $order = $this->paidOrder(['paid_at' => '2026-10-30 10:00:00']);
        ga4_order_paid(get_pdo(), $order);
        $stmt = get_pdo()->prepare('SELECT paid_at FROM orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        $this->assertSame('2026-10-30 10:00:00', $stmt->fetchColumn(), 'a second report keeps the first payment time');
    }

    public function test_both_payment_flows_report_the_moment_an_order_turns_paid(): void
    {
        foreach (['process_payment.php', 'process_iris_result.php'] as $f) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/payment/' . $f);
            $this->assertStringContainsString('ga4_order_paid($pdo, $order);', $src, $f);
            $this->assertLessThan(strpos($src, 'notify_order_paid($order);', (int) strpos($src, 'ga4_order_paid(')),
                                  strpos($src, 'ga4_order_paid('), "$f: reported before the emails, which may fail");
        }
    }
}
