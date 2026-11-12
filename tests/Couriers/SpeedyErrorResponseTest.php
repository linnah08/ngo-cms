<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Speedy reports most failures with HTTP 200 and an "error" object. Reading only
 * the status let a refused cancel pass as a success, so the waybill stayed open
 * and was billed.
 */
final class SpeedyErrorResponseTest extends TestCase
{
    public function testErrorBodyWithHttp200IsAnError(): void
    {
        $data = ['error' => ['context' => 'not-null', 'message' => 'Value is required (EE1)', 'code' => 1]];
        $this->assertSame('Value is required (EE1)', SpeedyCourier::errorFromResponse(200, $data));
    }

    public function testErrorWithoutMessageStillCounts(): void
    {
        $this->assertNotNull(SpeedyCourier::errorFromResponse(200, ['error' => ['code' => 1]]));
        $this->assertSame('boom', SpeedyCourier::errorFromResponse(200, ['error' => 'boom']));
    }

    public function testHttpErrorStatusIsAnError(): void
    {
        $this->assertSame('Bad gateway', SpeedyCourier::errorFromResponse(502, ['message' => 'Bad gateway']));
        $this->assertSame('HTTP 500', SpeedyCourier::errorFromResponse(500, null));
    }

    public function testSuccessfulRepliesAreNotErrors(): void
    {
        $this->assertNull(SpeedyCourier::errorFromResponse(200, []));
        $this->assertNull(SpeedyCourier::errorFromResponse(200, ['id' => '63775790629', 'parcels' => []]));
        $this->assertNull(SpeedyCourier::errorFromResponse(200, ['error' => null]));
        $this->assertNull(SpeedyCourier::errorFromResponse(200, null)); // a PDF from print/
    }

    public function testCreateShipmentTestIsOptIn(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/SpeedyCourierTest.php');
        $this->assertSame(1, substr_count($src, '->createShipment('), 'only the opt-in test may create a waybill');
        $this->assertStringContainsString("getenv('SPEEDY_LIVE_SHIPMENT_TEST') !== '1'", $src);
    }
}
