<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for BoxNowCourier.
 *
 * Requires courier.config.php with BOXNOW_TEST_MODE=true and valid credentials.
 *
 * Run:  vendor/bin/phpunit --group boxnow
 * Skip: vendor/bin/phpunit --exclude-group boxnow
 */
#[Group('boxnow')]
#[Group('integration')]
class BoxNowCourierTest extends TestCase
{
    private BoxNowCourier $boxnow;

    protected function setUp(): void
    {
        $this->boxnow = new BoxNowCourier();
    }

    // ── getOffices ─────────────────────────────────────────────────────────────

    public function testGetOfficesReturnsNonEmptyArray(): void
    {
        $lockers = $this->boxnow->getOffices();

        $this->assertIsArray($lockers);
        $this->assertNotEmpty($lockers, 'Expected at least one locker');
    }

    public function testGetOfficesHasRequiredKeys(): void
    {
        $lockers = $this->boxnow->getOffices();

        $this->assertArrayHasKey('id',      $lockers[0]);
        $this->assertArrayHasKey('name',    $lockers[0]);
        $this->assertArrayHasKey('city',    $lockers[0]);
        $this->assertArrayHasKey('address', $lockers[0]);
    }

    public function testGetOfficesFiltersByCity(): void
    {
        $lockers = $this->boxnow->getOffices('София');

        $this->assertNotEmpty($lockers, 'Expected lockers in София');
        foreach ($lockers as $locker) {
            $this->assertNotEmpty($locker['id']);
        }
    }

    // ── calculateShipping ──────────────────────────────────────────────────────
    // BoxNow uses local flat-rate tiers, no API call — pure unit tests.

    public function testCalculateShippingWeightTiersAreOrdered(): void
    {
        // Tiers are read from DB settings; exact values aren't hardcoded here.
        // We only assert that heavier parcels cost the same or more.
        $t1 = $this->boxnow->calculateShipping(['weight' => 1.0],  '', '');
        $t2 = $this->boxnow->calculateShipping(['weight' => 3.1],  '', '');
        $t3 = $this->boxnow->calculateShipping(['weight' => 6.1],  '', '');
        $t4 = $this->boxnow->calculateShipping(['weight' => 10.1], '', '');

        $this->assertGreaterThanOrEqual($t1, $t2, 'Tier 2 should be >= Tier 1');
        $this->assertGreaterThanOrEqual($t2, $t3, 'Tier 3 should be >= Tier 2');
        $this->assertGreaterThanOrEqual($t3, $t4, 'Tier 4 should be >= Tier 3');
        $this->assertGreaterThan(0, $t1, 'Shipping must be positive');
    }

    public function testCalculateShippingBoundaryConsistency(): void
    {
        // A parcel exactly at the tier boundary should cost the same as one just below.
        $at3  = $this->boxnow->calculateShipping(['weight' => 3.0], '', '');
        $at31 = $this->boxnow->calculateShipping(['weight' => 3.1], '', '');

        $this->assertGreaterThanOrEqual($at3, $at31, '3.1kg should be >= 3.0kg');
    }

    public function testCalculateShippingIgnoresCityAndDeliveryType(): void
    {
        $a = $this->boxnow->calculateShipping(['weight' => 1], 'Sofia', 'apt');
        $b = $this->boxnow->calculateShipping(['weight' => 1], 'Varna', 'door');

        $this->assertSame($a, $b, 'BoxNow pricing should be flat-rate regardless of city/type');
    }

    // ── getTracking ────────────────────────────────────────────────────────────

    public function testGetTrackingReturnsArrayWithExpectedKeys(): void
    {
        try {
            $result = $this->boxnow->getTracking('TEST-INVALID-000');
            $this->assertIsArray($result);
            $this->assertArrayHasKey('status',    $result);
            $this->assertArrayHasKey('delivered', $result);
            $this->assertArrayHasKey('events',    $result);
            $this->assertArrayHasKey('raw',       $result);
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/BoxNow (API error|auth failed|auth cURL error|cURL error)/', $e->getMessage());
        }
    }

    public function testGetTrackingDeliveredIsBool(): void
    {
        try {
            $result = $this->boxnow->getTracking('TEST-INVALID-000');
            $this->assertIsBool($result['delivered']);
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/BoxNow (API error|auth failed|auth cURL error|cURL error)/', $e->getMessage());
        }
    }

    // ── getLabelUrl ────────────────────────────────────────────────────────────

    public function testGetLabelUrlContainsParcelId(): void
    {
        $url = $this->boxnow->getLabelUrl('ABC123');

        $this->assertStringContainsString('ABC123', $url);
        $this->assertStringContainsString('/api/v1/parcels/', $url);
        $this->assertStringContainsString('label.pdf', $url);
    }

    // ── normalizePhone ─────────────────────────────────────────────────────────
    // Pure unit tests — no API call. Reflection used because the method is private.

    #[Group('unit')]
    #[DataProvider('phoneProvider')]
    public function testNormalizePhoneProducesCleanE164(string $input, string $expected): void
    {
        $m = new ReflectionMethod(BoxNowCourier::class, 'normalizePhone');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke($this->boxnow, $input));
    }

    public static function phoneProvider(): array
    {
        return [
            'local with dashes'        => ['0888-123-456',        '+359888123456'],
            'local with parens/spaces' => ['(088) 812 34 56',     '+359888123456'],
            'plain local'              => ['0888123456',          '+359888123456'],
            'intl with plus & spaces'  => ['+359 888 123 456',    '+359888123456'],
            'intl 359 no plus'         => ['359888123456',        '+359888123456'],
            'intl 00 prefix'           => ['00359888123456',      '+359888123456'],
            'dotted local'            => ['0888.123.456',        '+359888123456'],
            'empty string'             => ['',                     ''],
            'whitespace only'          => ['   ',                  ''],
        ];
    }

    // ── Auth ───────────────────────────────────────────────────────────────────

    public function testGetAccessTokenReturnsNonEmptyString(): void
    {
        $token = $this->boxnow->getAccessToken();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGetAccessTokenIsCachedAcrossCalls(): void
    {
        $token1 = $this->boxnow->getAccessToken();
        $token2 = $this->boxnow->getAccessToken();

        $this->assertSame($token1, $token2, 'Token should be cached and not re-fetched');
    }
}
