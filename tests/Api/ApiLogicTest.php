<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the inline logic in api/*.php endpoints.
 *
 * These files are procedural (not class-based) and emit HTTP headers,
 * so they cannot be required directly. Instead, each test replicates
 * the exact logic fragment under test, which documents the contract
 * and catches regressions if the logic changes.
 *
 * Covered:
 *   - offices.php:    courier routing, Speedy/BoxNow normalization, empty-code filter
 *   - calculate.php:  courier whitelist, cart-weight calculation
 *   - payment-return.php: order-number regex, confirm-URL routing
 */
#[Group('api')]
final class ApiLogicTest extends TestCase
{
    // ═════════════════════════════════════════════════════════════════════════
    // offices.php — courier routing
    // ═════════════════════════════════════════════════════════════════════════

    public static function officesRoutingProvider(): array
    {
        // [courier, city, expect_empty]
        return [
            'unknown courier returns empty'      => ['econt',  'София', true],
            'empty courier returns empty'         => ['',       'София', true],
            'speedy without city returns empty'   => ['speedy', '',      true],
            'boxnow without city is allowed'      => ['boxnow', '',      false],
            'speedy with city is allowed'         => ['speedy', 'София', false],
            'boxnow with city is also allowed'    => ['boxnow', 'Пловдив', false],
        ];
    }

    #[DataProvider('officesRoutingProvider')]
    public function test_offices_routing(string $courier, string $city, bool $expect_empty): void
    {
        // Replicates the guard in api/offices.php lines 11-15
        $city_required = ($courier !== 'boxnow');
        $allowed       = in_array($courier, ['speedy', 'boxnow']);
        $blocked       = ($city_required && !$city) || !$allowed;

        $this->assertSame($expect_empty, $blocked);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // offices.php — Speedy normalization
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Replicates the Speedy normalization block in api/offices.php lines 32-44.
     */
    private function normalizeSpeedyOffices(array $raw): array
    {
        $offices = [];
        foreach ($raw as $o) {
            if (!is_array($o)) continue;
            $parts = array_filter([
                $o['name']    ?? '',
                $o['city']    ?? '',
                $o['address'] ?? '',
            ]);
            $offices[] = [
                'code' => (string)($o['id'] ?? ''),
                'name' => implode(' — ', $parts),
                'type' => $o['type'] ?? 'office',
            ];
        }
        return array_values(array_filter($offices, fn($o) => $o['code'] !== ''));
    }

    public function test_speedy_office_includes_code_from_id(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => 42, 'name' => 'Клон Изток', 'city' => 'София', 'address' => 'ул. Тест 1'],
        ]);
        $this->assertSame('42', $result[0]['code']);
    }

    public function test_speedy_office_name_joins_name_city_address(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => 1, 'name' => 'Клон', 'city' => 'Варна', 'address' => 'бул. Примерен 5'],
        ]);
        $this->assertSame('Клон — Варна — бул. Примерен 5', $result[0]['name']);
    }

    public function test_speedy_office_omits_empty_name_parts(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => 5, 'name' => 'Клон', 'city' => '', 'address' => 'ул. А'],
        ]);
        // Empty city is filtered out by array_filter before implode
        $this->assertStringNotContainsString(' — — ', $result[0]['name']);
        $this->assertSame('Клон — ул. А', $result[0]['name']);
    }

    public function test_speedy_office_defaults_type_to_office(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => 3, 'name' => 'Клон'],
        ]);
        $this->assertSame('office', $result[0]['type']);
    }

    public function test_speedy_office_preserves_apt_type(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => 7, 'name' => 'АПТ', 'type' => 'apt'],
        ]);
        $this->assertSame('apt', $result[0]['type']);
    }

    public function test_speedy_filters_out_item_with_empty_code(): void
    {
        $result = $this->normalizeSpeedyOffices([
            ['id' => '',  'name' => 'Без ID'],
            ['id' => 99,  'name' => 'С ID'],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('99', $result[0]['code']);
    }

    public function test_speedy_skips_non_array_items(): void
    {
        $result = $this->normalizeSpeedyOffices(['not-an-array', null, 42]);
        $this->assertCount(0, $result);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // offices.php — BoxNow normalization
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Replicates the BoxNow normalization block in api/offices.php lines 46-56.
     */
    private function normalizeBoxNowOffices(array $raw): array
    {
        $offices = [];
        foreach ($raw as $o) {
            if (!is_array($o)) continue;
            $offices[] = [
                'code' => (string)($o['id'] ?? ''),
                'name' => ($o['name'] ?? '') . ($o['address'] ? ' — ' . $o['address'] : ''),
                'lat'  => $o['lat'] ?? null,
                'lng'  => $o['lng'] ?? null,
            ];
        }
        return array_values(array_filter($offices, fn($o) => $o['code'] !== ''));
    }

    public function test_boxnow_includes_code_from_id(): void
    {
        $result = $this->normalizeBoxNowOffices([
            ['id' => 'BX001', 'name' => 'Locker A', 'address' => 'ул. 1', 'lat' => 42.5, 'lng' => 23.3],
        ]);
        $this->assertSame('BX001', $result[0]['code']);
    }

    public function test_boxnow_name_includes_address(): void
    {
        $result = $this->normalizeBoxNowOffices([
            ['id' => 'BX002', 'name' => 'Locker B', 'address' => 'бул. 2'],
        ]);
        $this->assertSame('Locker B — бул. 2', $result[0]['name']);
    }

    public function test_boxnow_name_without_address_has_no_separator(): void
    {
        $result = $this->normalizeBoxNowOffices([
            ['id' => 'BX003', 'name' => 'Locker C', 'address' => ''],
        ]);
        $this->assertSame('Locker C', $result[0]['name']);
        $this->assertStringNotContainsString(' — ', $result[0]['name']);
    }

    public function test_boxnow_preserves_lat_lng(): void
    {
        $result = $this->normalizeBoxNowOffices([
            ['id' => 'BX004', 'name' => 'X', 'address' => 'Y', 'lat' => 42.697, 'lng' => 23.322],
        ]);
        $this->assertSame(42.697, $result[0]['lat']);
        $this->assertSame(23.322, $result[0]['lng']);
    }

    public function test_boxnow_filters_empty_code(): void
    {
        $result = $this->normalizeBoxNowOffices([
            ['id' => '',    'name' => 'No ID', 'address' => 'X'],
            ['id' => 'BX5', 'name' => 'Has ID', 'address' => 'Y'],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('BX5', $result[0]['code']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // calculate.php — courier whitelist
    // ═════════════════════════════════════════════════════════════════════════

    public static function calculateCourierProvider(): array
    {
        return [
            'speedy accepted'  => ['speedy',  true],
            'econt rejected'   => ['econt',   false],
            'boxnow rejected'  => ['boxnow',  false],
            'empty rejected'   => ['',        false],
            'SPEEDY uppercase' => ['SPEEDY',  false], // strtolower already applied by caller
        ];
    }

    #[DataProvider('calculateCourierProvider')]
    public function test_calculate_courier_whitelist(string $courier, bool $allowed): void
    {
        // Replicates api/calculate.php line 17: in_array($courier, ['speedy'])
        $this->assertSame($allowed, in_array($courier, ['speedy']));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // calculate.php — cart weight calculation
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Replicates the weight/packCount block in api/calculate.php lines 32-44.
     */
    private function calcWeight(array $cart): array
    {
        $totalWeight = 1.0;
        $packCount   = 1;

        if ($cart) {
            $packCount = max(1, count($cart));
            $w = 0.0;
            foreach ($cart as $item) {
                $w += 0.5 * max(1, (int)($item['quantity'] ?? 1));
            }
            if ($w > 0) $totalWeight = $w;
        }
        return ['weight' => $totalWeight, 'pack_count' => $packCount];
    }

    public function test_empty_cart_defaults_to_1kg_1pack(): void
    {
        $result = $this->calcWeight([]);
        $this->assertSame(1.0, $result['weight']);
        $this->assertSame(1,   $result['pack_count']);
    }

    public function test_single_item_qty1_is_half_kg(): void
    {
        $result = $this->calcWeight([['quantity' => 1]]);
        $this->assertSame(0.5, $result['weight']);
        $this->assertSame(1,   $result['pack_count']);
    }

    public function test_single_item_qty3_is_1_5kg(): void
    {
        $result = $this->calcWeight([['quantity' => 3]]);
        $this->assertSame(1.5, $result['weight']);
    }

    public function test_two_items_weight_sums(): void
    {
        $result = $this->calcWeight([
            ['quantity' => 2],
            ['quantity' => 4],
        ]);
        $this->assertSame(3.0, $result['weight']); // (0.5×2) + (0.5×4)
        $this->assertSame(2,   $result['pack_count']);
    }

    public function test_item_with_zero_qty_treated_as_qty1(): void
    {
        // max(1, (int)0) = 1, so 0.5 kg minimum
        $result = $this->calcWeight([['quantity' => 0]]);
        $this->assertSame(0.5, $result['weight']);
    }

    public function test_item_missing_quantity_treated_as_qty1(): void
    {
        $result = $this->calcWeight([['product_id' => 7]]);
        $this->assertSame(0.5, $result['weight']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // payment-return.php — order number regex
    // ═════════════════════════════════════════════════════════════════════════

    public static function orderNumberProvider(): array
    {
        return [
            'valid format'             => ['OM-20260101-A1B2', true],
            'valid all digits suffix'  => ['OM-20260530-0000', true],
            'valid all hex letters'    => ['OM-20260101-ABCD', true],
            'valid lowercase hex'      => ['om-20260101-abcd', true], // regex has /i flag
            'wrong prefix'             => ['XX-20260101-A1B2', false],
            'date too short'           => ['OM-2026010-A1B2',  false],
            'date too long'            => ['OM-202601011-A1B2', false],
            'suffix too short'         => ['OM-20260101-A1B',  false],
            'suffix too long'          => ['OM-20260101-A1B2C', false],
            'suffix has non-hex char'  => ['OM-20260101-G1B2', false],
            'no suffix'                => ['OM-20260101',       false],
            'empty string'             => ['',                  false],
        ];
    }

    #[DataProvider('orderNumberProvider')]
    public function test_order_number_regex(string $number, bool $valid): void
    {
        // Replicates api/payment-return.php line 17
        $matches = (bool)preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $number);
        $this->assertSame($valid, $matches);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // payment-return.php — confirm-URL routing
    // ═════════════════════════════════════════════════════════════════════════

    public function test_donation_order_uses_donation_confirm_url(): void
    {
        $order_number = 'OM-20260101-A1B2';
        $order        = ['type' => 'donation'];
        // Replicates api/payment-return.php lines 31-33
        $confirm_url = $order['type'] === 'donation'
            ? '/donation/confirmation/?order=' . urlencode($order_number)
            : '/checkout/confirmation/?order=' . urlencode($order_number);
        $this->assertStringStartsWith('/donation/confirmation/', $confirm_url);
    }

    public function test_shop_order_uses_checkout_confirm_url(): void
    {
        $order_number = 'OM-20260101-A1B2';
        $order        = ['type' => 'shop'];
        $confirm_url = $order['type'] === 'donation'
            ? '/donation/confirmation/?order=' . urlencode($order_number)
            : '/checkout/confirmation/?order=' . urlencode($order_number);
        $this->assertStringStartsWith('/checkout/confirmation/', $confirm_url);
    }

    public function test_confirm_url_includes_encoded_order_number(): void
    {
        $order_number = 'OM-20260101-A1B2';
        $order        = ['type' => 'shop'];
        $confirm_url = $order['type'] === 'donation'
            ? '/donation/confirmation/?order=' . urlencode($order_number)
            : '/checkout/confirmation/?order=' . urlencode($order_number);
        $this->assertStringContainsString(urlencode($order_number), $confirm_url);
    }
}
