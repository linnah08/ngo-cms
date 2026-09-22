<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/couriers/BoxNowCourier.php';
require_once dirname(__DIR__, 2) . '/includes/couriers/boxnow_bulk.php';

class BoxNowBulkTest extends TestCase
{
    public function testPackLinesSkipsDonationsAndKeepsVariantDetail(): void
    {
        $order = ['items' => json_encode([
            ['name_bg' => 'Тениска', 'quantity' => 2, 'colour' => 'синьо', 'size' => 'M'],
            ['type' => 'donation', 'name_bg' => 'Дарение', 'quantity' => 1],
            ['name' => 'Чанта', 'quantity' => 0, 'variant_label' => 'Голяма'],
            ['quantity' => 3],
        ])];
        $lines = boxnow_pack_lines($order);
        $this->assertCount(2, $lines);
        $this->assertSame(['name' => 'Тениска', 'qty' => 2, 'detail' => 'Цвят: синьо · Размер: M'], $lines[0]);
        $this->assertSame(1, $lines[1]['qty'], 'quantity is never below 1');
        $this->assertSame('Голяма', $lines[1]['detail']);
    }

    public function testDescriptionFallsBackToOrderNumber(): void
    {
        $this->assertSame('Тениска x2', boxnow_description(['items' => json_encode([['name_bg' => 'Тениска', 'quantity' => 2]])]));
        $this->assertSame('Поръчка #A1', boxnow_description(['items' => '[]', 'order_number' => 'A1']));
    }

    public function testPackingListEmailListsEveryOrderInOrder(): void
    {
        $rows = [
            ['order_number' => 'A1', 'customer_name' => 'Ана <b>', 'parcel_id' => '111', 'lines' => [['name' => 'Тениска', 'qty' => 2, 'detail' => '']]],
            ['order_number' => 'A2', 'customer_name' => 'Боян', 'parcel_id' => '222', 'lines' => [['name' => 'Чанта', 'qty' => 1, 'detail' => 'Размер: M']]],
        ];
        ob_start(); include dirname(__DIR__, 2) . '/includes/emails/boxnow-packing-list.php'; $html = ob_get_clean();
        $this->assertLessThan(strpos($html, 'A2'), strpos($html, 'A1'));
        $this->assertStringContainsString('&lt;b&gt;', $html, 'customer name is escaped');
        $this->assertStringContainsString('× 2', $html);
        $this->assertStringContainsString('Размер: M', $html);
    }

    public function testA4SheetsHolds4LabelsPerPage(): void
    {
        // A stand-in A6 label, the size BoxNow returns.
        $label = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A6', 'tempDir' => sys_get_temp_dir()]);
        $label->WriteHTML('<p>Label</p>');
        $bytes = $label->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        $pdf = boxnow_a4_sheets(array_fill(0, 5, $bytes));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), '5 labels → 2 A4 pages');
    }
}
