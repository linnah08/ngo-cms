<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pure unit tests for monthly_report_order_lines() — the accounting split logic
 * that turns one order into its report line rows — and monthly_report_payment_label().
 * No DB required.
 */
#[Group('reports')]
final class MonthlyReportOrderLinesTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/reports/monthly-orders.php';
    }

    protected function setUp(): void
    {
        // A site with no extensions file: one recipient, the organisation itself.
        $GLOBALS['_om_site_extensions_file'] = sys_get_temp_dir() . '/no-site-ext-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    /** A site with two donation recipients: the organisation and a partner. */
    private function twoRecipients(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, '<?php return ["recipients" => [
            "foundation" => ["label_bg" => "Фондацията", "purpose_bg" => "За фондацията", "default" => true],
            "partner"    => ["label_bg" => "Партньорски център", "purpose_bg" => "За центъра"],
        ]];');
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function testPlainPurchaseIsOnePurchaseRow(): void
    {
        $lines = monthly_report_order_lines(['type' => 'physical', 'total_eur' => 25.00, 'items' => '[]']);
        $this->assertSame([['label' => 'Покупка', 'amount' => 25.00]], $lines);
    }

    public function testTicketLabelled(): void
    {
        $lines = monthly_report_order_lines(['type' => 'ticket', 'total_eur' => 12.00, 'items' => '[]']);
        $this->assertSame([['label' => 'Билет', 'amount' => 12.00]], $lines);
    }

    public function testStandaloneDonationNoItemsIsOneDonationRow(): void
    {
        $lines = monthly_report_order_lines(['type' => 'donation', 'total_eur' => 15.00, 'items' => '[]']);
        $this->assertSame([['label' => 'Дарение', 'amount' => 15.00]], $lines);
    }

    public function testMixedOrderSplitsAndReconciles(): void
    {
        // 40 products + 5 shipping + 10 donation = 55 total.
        $lines = monthly_report_order_lines([
            'type' => 'physical', 'total_eur' => 55.00,
            'items' => json_encode([
                ['product_id' => 1, 'subtotal_eur' => 40.00],
                ['type' => 'donation', 'amount_eur' => 10.00],
            ]),
        ]);
        $this->assertSame([
            ['label' => 'Покупка', 'amount' => 45.00],
            ['label' => 'Дарение', 'amount' => 10.00],
        ], $lines);
        $this->assertEqualsWithDelta(55.00, array_sum(array_column($lines, 'amount')), 0.001, 'Rows must reconcile to total_eur');
    }

    public function testDonationAddOnAmountFromSubtotal(): void
    {
        $lines = monthly_report_order_lines([
            'type' => 'physical', 'total_eur' => 30.00,
            'items' => json_encode([
                ['product_id' => 1, 'subtotal_eur' => 20.00],
                ['type' => 'donation', 'subtotal_eur' => 10.00],
            ]),
        ]);
        $this->assertSame([
            ['label' => 'Покупка', 'amount' => 20.00],
            ['label' => 'Дарение', 'amount' => 10.00],
        ], $lines);
    }

    public function testWithSeveralRecipientsEachDonationNamesItsRecipient(): void
    {
        $this->twoRecipients();
        $lines = monthly_report_order_lines([
            'type' => 'physical', 'total_eur' => 60.00,
            'items' => json_encode([
                ['product_id' => 1, 'subtotal_eur' => 30.00],
                ['type' => 'donation', 'recipient' => 'partner', 'amount_eur' => 20.00],
                ['type' => 'donation', 'recipient' => 'foundation', 'amount_eur' => 10.00],
            ]),
        ]);
        $this->assertSame([
            ['label' => 'Покупка', 'amount' => 30.00],
            ['label' => 'Дарение (Партньорски център)', 'amount' => 20.00],
            ['label' => 'Дарение (Фондацията)', 'amount' => 10.00],
        ], $lines);
    }

    public function testWithSeveralRecipientsAnOldLineGoesToTheDefault(): void
    {
        $this->twoRecipients();
        $lines = monthly_report_order_lines([
            'type' => 'donation', 'total_eur' => 20.00,
            'items' => json_encode([['type' => 'donation', 'amount_eur' => 20.00, 'recipient' => 'gone']]),
        ]);
        $this->assertSame([['label' => 'Дарение (Фондацията)', 'amount' => 20.00]], $lines);
    }

    public function testWithSeveralRecipientsStandalonePartnerDonationWithoutAmount(): void
    {
        $this->twoRecipients();
        $lines = monthly_report_order_lines([
            'type' => 'donation', 'total_eur' => 25.00,
            'items' => json_encode([['type' => 'donation', 'recipient' => 'partner']]),
        ]);
        $this->assertSame([['label' => 'Дарение (Партньорски център)', 'amount' => 25.00]], $lines);
    }

    public function testBrokenItemsNeverDropTheOrder(): void
    {
        $lines = monthly_report_order_lines(['type' => 'physical', 'total_eur' => 0.0, 'items' => '{not json']);
        $this->assertSame([['label' => 'Покупка', 'amount' => 0.0]], $lines);
    }

    public function testBankTransferInvoiceNotYetPaidLabelled(): void
    {
        // Proforma issued (payment_status set to 'paid' on issue) but the order is
        // still 'new' → the money has not arrived.
        $this->assertSame('Неплатена (издадена фактура)', monthly_report_payment_label([
            'payment_status' => 'paid', 'status' => 'new', 'payment_method' => 'bank_transfer',
        ]));
        $this->assertSame('Неплатена (издадена фактура)', monthly_report_payment_label([
            'payment_status' => 'paid', 'status' => 'new', 'payment_method' => 'cod',
        ]));
    }

    public function testBankTransferConfirmedCountsAsPaid(): void
    {
        $this->assertSame('Платена', monthly_report_payment_label([
            'payment_status' => 'paid', 'status' => 'confirmed', 'payment_method' => 'bank_transfer',
        ]));
    }

    public function testCardPaidLabelled(): void
    {
        $this->assertSame('Платена', monthly_report_payment_label([
            'payment_status' => 'paid', 'status' => 'new', 'payment_method' => 'card',
        ]));
    }

    public function testRefundedLabelled(): void
    {
        $this->assertSame('Върната', monthly_report_payment_label([
            'payment_status' => 'refunded', 'status' => 'cancelled', 'payment_method' => 'card',
        ]));
    }
}
