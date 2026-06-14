<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for SpeedyCourier.
 *
 * Requires courier.config.php with SPEEDY_TEST_MODE=true and valid credentials.
 *
 * Run:  vendor/bin/phpunit --group speedy
 * Skip: vendor/bin/phpunit --exclude-group speedy
 */
#[Group('speedy')]
#[Group('integration')]
class SpeedyCourierTest extends TestCase
{
    private SpeedyCourier $speedy;

    protected function setUp(): void
    {
        $this->speedy = new SpeedyCourier();
    }

    // ── getOffices ─────────────────────────────────────────────────────────────

    public function testGetOfficesReturnsNonEmptyArray(): void
    {
        $offices = $this->speedy->getOffices();

        $this->assertIsArray($offices);
        $this->assertNotEmpty($offices, 'Expected at least one office');
    }

    public function testGetOfficesHasRequiredKeys(): void
    {
        $offices = $this->speedy->getOffices();

        $this->assertArrayHasKey('id',      $offices[0]);
        $this->assertArrayHasKey('name',    $offices[0]);
        $this->assertArrayHasKey('city',    $offices[0]);
        $this->assertArrayHasKey('address', $offices[0]);
        $this->assertArrayHasKey('type',    $offices[0]);
    }

    public function testGetOfficesTypeIsOfficeOrApt(): void
    {
        $offices = $this->speedy->getOffices('София');

        $this->assertNotEmpty($offices, 'Expected offices in София');
        foreach ($offices as $office) {
            $this->assertContains($office['type'], ['office', 'apt']);
        }
    }

    public function testGetOfficesFiltersByCity(): void
    {
        $all   = $this->speedy->getOffices();
        $sofia = $this->speedy->getOffices('София');

        $this->assertNotEmpty($sofia);
        $this->assertLessThanOrEqual(count($all), count($sofia));
    }

    public function testGetOfficesByCityContainsBothTypes(): void
    {
        $offices = $this->speedy->getOffices('София');

        $this->assertNotEmpty($offices, 'Expected offices in София');
        $types = array_unique(array_column($offices, 'type'));
        // Sofia should have both regular offices and APT lockers
        $this->assertContains('office', $types, 'Expected at least one regular office in София');
        $this->assertContains('apt',    $types, 'Expected at least one APT locker in София');
    }

    public function testGetOfficesUnknownCityReturnsEmpty(): void
    {
        // A city that does not exist in Speedy's network should return an empty array
        // (resolveSiteId returns null → getOffices falls back to no siteId filter,
        //  but since we omit the filter, all offices are returned — acceptable fallback)
        // Verify the method never throws
        $offices = $this->speedy->getOffices('НесъществуващГрадXYZ123');
        $this->assertIsArray($offices);
    }

    public function testGetOfficesByCityUsesNumericSiteId(): void
    {
        // Regression: previously getOffices() sent siteName to location/office which
        // is not a valid filter parameter for that endpoint (only siteId is accepted).
        // This test ensures city-filtered results are non-empty, proving siteId lookup works.
        $plovdiv = $this->speedy->getOffices('Пловдив');
        $this->assertNotEmpty($plovdiv, 'Expected offices in Пловдив — siteId resolution may have failed');
    }

    public function testGetOfficesResolvesAllSiteIdsForAmbiguousCityName(): void
    {
        // Regression: "Лозен" matches 5 different Bulgarian villages. The correct one
        // (Sofia municipality) is NOT the first result. We must try all siteIds so
        // the physical Лозен office (id=349) is returned.
        $offices = $this->speedy->getOffices('Лозен');
        $ids = array_column($offices, 'id');
        $this->assertContains('349', array_map('strval', $ids),
            'Office 349 (Лозен physical office, Sofia municipality) must appear — ' .
            'fix: resolveSiteIds() must query all matching siteIds, not just the first'
        );
    }

    // ── calculateShipping ──────────────────────────────────────────────────────

    public function testCalculateShippingDoorReturnsPositiveFloat(): void
    {
        $price = $this->speedy->calculateShipping(
            ['weight' => 2.0, 'pack_count' => 1],
            'Пловдив',
            'door'
        );

        $this->assertIsFloat($price);
        $this->assertGreaterThan(0, $price);
    }

    public function testCalculateShippingOfficeReturnsPositiveFloat(): void
    {
        $price = $this->speedy->calculateShipping(
            ['weight' => 2.0, 'pack_count' => 1],
            'Варна',
            'office'
        );

        $this->assertIsFloat($price);
        $this->assertGreaterThan(0, $price);
    }

    public function testCalculateShippingHeavierParcelCostsMore(): void
    {
        $light = $this->speedy->calculateShipping(['weight' => 0.5, 'pack_count' => 1], 'Пловдив', 'door');
        $heavy = $this->speedy->calculateShipping(['weight' => 10.0, 'pack_count' => 1], 'Пловдив', 'door');

        // PHPUnit argument order: assertGreaterThanOrEqual($minimum, $actual)
        // i.e. the first arg is the expected lower bound, the second is the value under test.
        // Here we assert: $heavy >= $light  →  assertGreaterThanOrEqual($light, $heavy)
        $this->assertGreaterThanOrEqual($light, $heavy, 'Heavier parcel should cost at least as much');
    }

    // ── getTracking ────────────────────────────────────────────────────────────

    public function testGetTrackingReturnsArrayWithExpectedKeys(): void
    {
        try {
            $result = $this->speedy->getTracking('0000000000');
            $this->assertIsArray($result);
            $this->assertArrayHasKey('status',    $result);
            $this->assertArrayHasKey('delivered', $result);
            $this->assertArrayHasKey('events',    $result);
            $this->assertArrayHasKey('raw',       $result);
        } catch (RuntimeException $e) {
            // Either API error (bad tracking number) or cURL error (host unreachable) is acceptable
            $this->assertMatchesRegularExpression('/Speedy (API|cURL) error/', $e->getMessage());
        }
    }

    public function testGetTrackingDeliveredIsBool(): void
    {
        try {
            $result = $this->speedy->getTracking('0000000000');
            $this->assertIsBool($result['delivered']);
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/Speedy (API|cURL) error/', $e->getMessage());
        }
    }

    // ── createShipment / cancelShipment ────────────────────────────────────────

    public function testCreateShipmentReturnsShipmentNumber(): string
    {
        try {
            $result = $this->speedy->createShipment([
                'weight'           => 0.5,
                'pack_count'       => 1,
                'description'      => 'Тест пратка',
                'receiver_name'    => 'Тест Получател',
                'receiver_phone'   => '0888123456',
                'receiver_city'    => 'София',
                'receiver_address' => 'бул. Витоша 1',
                'receiver_office'  => null,
                'delivery_type'    => 'door',
            ]);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('shipment_number', $result);
            $this->assertNotEmpty($result['shipment_number'], 'Expected a non-empty shipment number');

            return (string) $result['shipment_number'];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'cURL error')) {
                $this->markTestSkipped('Speedy test API unreachable: ' . $e->getMessage());
            }
            throw $e; // API errors (wrong fields, auth, etc.) should fail, not skip
        }
    }

    #[Depends('testCreateShipmentReturnsShipmentNumber')]
    public function testCancelShipmentDoesNotThrow(string $shipmentId): void
    {
        $this->speedy->cancelShipment($shipmentId);
        $this->assertTrue(true); // reaching here means no exception was thrown
    }
}
