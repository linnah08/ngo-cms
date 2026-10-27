<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/couriers/SpeedyCourier.php';

/**
 * Unit tests for SpeedyCourier::parseCityInput — turning a typed/autofilled
 * town field into the name + postcode Speedy's site search understands.
 *
 * Regression: an order had the town autofilled as
 * "гр. СОФИЯ (1000), общ. СТОЛИЧНА, обл. СОФИЯ (СТОЛИЦА)". Speedy's search
 * found no match, the raw text was sent as siteName, and Speedy rejected the
 * label: "Максималната позволена дължина е 50".
 */
#[Group('couriers')]
final class SpeedyCityInputTest extends TestCase
{
    public static function inputs(): array
    {
        return [
            'full autofill form'      => ['гр. СОФИЯ (1000), общ. СТОЛИЧНА, обл. СОФИЯ (СТОЛИЦА)', 'СОФИЯ', '1000'],
            'village with postcode'   => ['с. Лозен (1151), общ. Столична', 'Лозен', '1151'],
            'plain name'              => ['София', 'София', null],
            'latin name'              => ['Sofia', 'Sofia', null],
            'prefix without space'    => ['гр.Пловдив', 'Пловдив', null],
            'word prefix'             => ['село Лозен', 'Лозен', null],
            'bare postcode'           => ['Варна 9000', 'Варна', '9000'],
            'two-word name'           => ['гр. Велико Търново', 'Велико Търново', null],
            'name starting with с'    => ['Сливен', 'Сливен', null],
            'name starting with гр'   => ['Гръцко', 'Гръцко', null],
            'surrounding whitespace'  => ['  Бургас  ', 'Бургас', null],
            'empty'                   => ['', '', null],
        ];
    }

    #[DataProvider('inputs')]
    public function testParsesTownNameAndPostcode(string $input, string $name, ?string $postCode): void
    {
        $this->assertSame(['name' => $name, 'postCode' => $postCode], SpeedyCourier::parseCityInput($input));
    }
}
