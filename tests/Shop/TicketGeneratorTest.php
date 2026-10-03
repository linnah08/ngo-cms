<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * HTML output of TicketGenerator (event tickets). buildHtml() is protected,
 * so it is subclassed to expose it; no PDF is rendered.
 */
#[Group('shop')]
#[Group('documents')]
final class TicketGeneratorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/TicketGenerator.php';
        if (!class_exists('TestableTicketGenerator')) {
            eval('class TestableTicketGenerator extends TicketGenerator {
                public function html(array $o, array $i, array $d): string { return $this->buildHtml($o,$i,$d); }
            }');
        }
    }

    // ── Ticket ───────────────────────────────────────────────────────────────

    private function ticket(array $pledge = [], array $doc = []): string
    {
        return (new TestableTicketGenerator())->html(
            array_merge(['name' => 'Мария Петрова', 'email' => 'maria@example.com', 'pledge_number' => 'CP-2026-0042',
                         'amount_eur' => 25, 'created_at' => '2026-05-20 10:00:00'], $pledge),
            [],
            array_merge(['ticket_code' => 'TKT-20260520-ABCD1234', 'event_name' => 'Лафетки парти',
                         'event_date' => '2026-06-12', 'event_time' => '19:00', 'event_place' => 'София'], $doc)
        );
    }

    public function testTicketShowsHolderEventAndCode(): void
    {
        $html = $this->ticket();
        foreach (['Мария Петрова', 'CP-2026-0042', 'TKT-20260520-ABCD1234', 'Лафетки парти', '12.06.2026, 19:00 ч.', 'София', '25.00'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testTicketEmbedsAScannableQrCode(): void
    {
        $this->assertMatchesRegularExpression('#<img src="data:image/svg\+xml;base64,([A-Za-z0-9+/=]{100,})" alt="QR"#', $this->ticket());
        preg_match('#data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)#', $this->ticket(), $m);
        $this->assertStringContainsString('<svg', (string) base64_decode($m[1]), 'the QR data URI must hold an SVG that mPDF can draw');
    }

    public function testTicketWithoutCodeHasNoQrAndStillRenders(): void
    {
        $html = $this->ticket([], ['ticket_code' => '']);
        $this->assertStringNotContainsString('alt="QR"', $html);
        $this->assertStringContainsString('Мария Петрова', $html);
    }

    public function testTicketOmitsDateAndPlaceRowsWhenUnknown(): void
    {
        $html = $this->ticket([], ['event_date' => '', 'event_time' => '', 'event_place' => '']);
        $this->assertStringNotContainsString('Дата / час', $html);
        $this->assertStringNotContainsString('>Място<', $html);
    }

    public function testTicketEscapesBuyerInput(): void
    {
        $html = $this->ticket(['name' => '<script>alert(1)</script>', 'email' => '"><img src=x>']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('"><img src=x>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
