<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the read-side logic extracted out of admin/order-view.php into
 * includes/order_view.php: ticket-path decoding, donation detection and the
 * print placement → cm measurement maths that was previously inlined (and
 * untestable) in the order item-row view.
 */
#[Group('admin')]
final class OrderViewLogicTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_view.php';
    }

    // ── order_ticket_paths ───────────────────────────────────────────────────

    public function testTicketPathsEmptyForNullOrEmpty(): void
    {
        $this->assertSame([], order_ticket_paths(null));
        $this->assertSame([], order_ticket_paths(''));
    }

    public function testTicketPathsDecodesJsonArray(): void
    {
        $this->assertSame(['a.pdf', 'b.pdf'], order_ticket_paths('["a.pdf","b.pdf"]'));
    }

    public function testTicketPathsWrapsBareScalarPath(): void
    {
        $this->assertSame(['ticket.pdf'], order_ticket_paths('ticket.pdf'));
    }

    // ── order_has_donation ───────────────────────────────────────────────────

    public function testHasDonationWhenOrderTypeIsDonation(): void
    {
        $this->assertTrue(order_has_donation('donation', []));
    }

    public function testHasDonationWhenAnyItemIsDonation(): void
    {
        $items = [['type' => 'physical'], ['type' => 'donation']];
        $this->assertTrue(order_has_donation('physical', $items));
    }

    public function testNoDonationForPhysicalOrderWithoutDonationItems(): void
    {
        $this->assertFalse(order_has_donation('physical', [['type' => 'physical']]));
    }

    // ── order_print_spec ─────────────────────────────────────────────────────

    public function testPrintSpecComputesCmFromPlacementAndSize(): void
    {
        // scale 0.5 of a 40×60 cm garment, square design, centred horizontally.
        $pos   = ['scale' => 0.5, 'x' => 0.5, 'y' => 0.3];
        $sdims = ['w' => 40, 'h' => 60];
        $spec  = order_print_spec($pos, 1.0, $sdims, 'L');

        $this->assertSame('20 × 30 cm · 10 cm от ляво · 3 cm от горе · Размер: L', $spec);
    }

    public function testPrintSpecAppliesAspectRatioToHeight(): void
    {
        // ar = 2.0 → printed height doubles relative to a square design.
        $pos   = ['scale' => 0.5, 'x' => 0.5, 'y' => 0.5];
        $sdims = ['w' => 40, 'h' => 60];
        $spec  = order_print_spec($pos, 2.0, $sdims, '');

        $this->assertStringStartsWith('20 × 60 cm', $spec);
        $this->assertStringNotContainsString('Размер:', $spec); // no size suffix when empty
    }

    public function testPrintSpecFallsBackWhenSizeHasNoDimensions(): void
    {
        $pos = ['scale' => 0.5, 'x' => 0.5, 'y' => 0.5];
        $this->assertSame(
            'Добавете размери на тениската в продукта за да изчислим cm · Размер: M',
            order_print_spec($pos, 1.0, null, 'M')
        );
        // also when w is zero
        $this->assertStringStartsWith(
            'Добавете размери',
            order_print_spec($pos, 1.0, ['w' => 0, 'h' => 0], '')
        );
    }

    public function testPrintSpecClampsNegativeOffsetsToZero(): void
    {
        // A large design centred at x=0 would overflow left; offset clamps to 0 cm.
        $pos  = ['scale' => 0.8, 'x' => 0.0, 'y' => 0.0];
        $spec = order_print_spec($pos, 1.0, ['w' => 50, 'h' => 70], '');
        $this->assertStringContainsString('0 cm от ляво', $spec);
        $this->assertStringContainsString('0 cm от горе', $spec);
    }
}
