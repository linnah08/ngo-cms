<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/couriers/SpeedyCourier.php';

/**
 * Unit tests for SpeedyCourier::shipmentInputFromOrder — the order-row → Speedy
 * createShipment() input mapping. Pure (no API), so unlike the integration tests
 * in SpeedyCourierTest (group "speedy", excluded by default) this runs every time.
 *
 * Regression: order 68 was a Speedy automat order (delivery_type 'apt', empty
 * city/address, office code 9013). The old inline glue in admin/order-view.php
 * mapped only 'office' to the pickup path, so 'apt' fell through to 'door' with an
 * empty street → Speedy rejected the recipient address.
 */
#[Group('couriers')]
final class SpeedyShipmentMappingTest extends TestCase
{
    public function testAptDeliveryUsesPickupOfficeNotDoorAddress(): void
    {
        $order = [
            'delivery_type'       => 'apt',
            'delivery_city'       => '',
            'delivery_address'    => '',
            'courier_office_code' => '9013',
            'customer_name'       => 'Иван',
            'customer_phone'      => '+359888123456',
        ];
        $in = SpeedyCourier::shipmentInputFromOrder($order, 1.0, 1, 'desc');

        $this->assertSame('apt', $in['delivery_type']);  // NOT collapsed to 'door'
        $this->assertSame(9013, $in['receiver_office']);  // pickup point is set
    }

    public function testOfficeDeliveryUsesPickupOffice(): void
    {
        $in = SpeedyCourier::shipmentInputFromOrder(
            ['delivery_type' => 'office', 'courier_office_code' => '42'], 1.0, 1, 'd'
        );
        $this->assertSame('office', $in['delivery_type']);
        $this->assertSame(42, $in['receiver_office']);
    }

    public function testDoorDeliveryUsesAddressAndNoOffice(): void
    {
        $order = [
            'delivery_type'       => 'door',
            'delivery_city'       => 'София',
            'delivery_address'    => 'ул. Тест 1',
            'courier_office_code' => '9013', // present but must be ignored for door
        ];
        $in = SpeedyCourier::shipmentInputFromOrder($order, 2.0, 3, 'd');

        $this->assertSame('door', $in['delivery_type']);
        $this->assertNull($in['receiver_office']);
        $this->assertSame('София', $in['receiver_city']);
        $this->assertSame('ул. Тест 1', $in['receiver_address']);
    }

    public function testEmptyOrMissingDeliveryTypeDefaultsToDoor(): void
    {
        $this->assertSame('door', SpeedyCourier::shipmentInputFromOrder(['delivery_type' => ''], 1.0, 1, 'd')['delivery_type']);
        $this->assertSame('door', SpeedyCourier::shipmentInputFromOrder([], 1.0, 1, 'd')['delivery_type']);
    }

    public function testCarriesParcelOptionsAndRecipientThrough(): void
    {
        $in = SpeedyCourier::shipmentInputFromOrder(
            ['delivery_type' => 'door', 'customer_name' => 'X', 'customer_phone' => '0888'],
            2.5, 4, 'My contents'
        );
        $this->assertSame(2.5, $in['weight']);
        $this->assertSame(4, $in['pack_count']);
        $this->assertSame('My contents', $in['description']);
        $this->assertSame('X', $in['receiver_name']);
        $this->assertSame('0888', $in['receiver_phone']);
    }
}
