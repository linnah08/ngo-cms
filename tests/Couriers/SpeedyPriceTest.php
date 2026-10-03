<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/couriers/SpeedyCourier.php';

/**
 * SpeedyCourier::priceEur — the shipping price the customer is charged.
 *
 * Regression: checkout charged Speedy's net `amount`, but Speedy bills the
 * sender amount + 20% VAT, so every Speedy order was ~20% short (Sept 2026
 * invoice: customers paid €2.70 for a shipment that cost €2.78 with VAT).
 * The price object below is a real calculate/ reply (Sofia, address, 1 kg).
 */
#[Group('couriers')]
final class SpeedyPriceTest extends TestCase
{
    public function testChargesThePriceWithVat(): void
    {
        $price = ['amount' => 3.69, 'vat' => 0.74, 'total' => 4.43, 'currency' => 'EUR'];
        $this->assertSame(4.43, SpeedyCourier::priceEur($price));
    }

    public function testAddsVatWhenTotalIsMissing(): void
    {
        $this->assertSame(3.36, SpeedyCourier::priceEur(['amount' => 2.80, 'vat' => 0.56, 'currency' => 'EUR']));
    }

    public function testConvertsLevaToEuro(): void
    {
        $this->assertSame(4.43, SpeedyCourier::priceEur(['amount' => 7.22, 'vat' => 1.44, 'total' => 8.66, 'currency' => 'BGN']));
    }

    public function testRefusesANetOnlyPriceRatherThanUndercharging(): void
    {
        $this->expectException(RuntimeException::class);
        SpeedyCourier::priceEur(['amount' => 3.69, 'currency' => 'EUR']);
    }
}
