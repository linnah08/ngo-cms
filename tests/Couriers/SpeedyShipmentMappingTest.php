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
        $this->assertSame('+359888', $in['receiver_phone'], 'normalised to E.164 (Speedy rejects a number without +359)');
    }

    /**
     * Regression (oddminds 0d22c17): the checkout stored phones without +359, and
     * Speedy rejected them ("Получател Телефон 1: Невалидна стойност"). The
     * mapping normalises to E.164, so already-stored orders still ship.
     */
    public function testReceiverPhoneNormalizedToE164(): void
    {
        $cases = [
            '0888123456'     => '+359888123456',
            '888123456'      => '+359888123456',
            '+359888123456'  => '+359888123456',
            '00359888123456' => '+359888123456',
            '359888123456'   => '+359888123456',
            '0888 123 456'   => '+359888123456',
            ''               => '',
        ];
        foreach ($cases as $stored => $expected) {
            $in = SpeedyCourier::shipmentInputFromOrder(['delivery_type' => 'door', 'customer_phone' => $stored], 1.0, 1, 'd');
            $this->assertSame($expected, $in['receiver_phone'], "phone '$stored'");
        }
    }

    public function testCheckoutPostsTheFullInternationalNumber(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/checkout/index.php');
        $this->assertStringContainsString('input.value = iti.getNumber();', $src, 'separateDialCode keeps +359 out of the field unless written back');
        foreach (['SpeedyCourier', 'EcontCourier', 'BoxNowCourier'] as $c) {
            $this->assertStringContainsString('courier_normalize_phone(', (string) file_get_contents(dirname(__DIR__, 2) . "/includes/couriers/$c.php"), $c);
        }
    }
}
