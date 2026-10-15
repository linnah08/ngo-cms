<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * „✎ Редактирай получателя“ on Админ → Поръчка: correcting an issued invoice's
 * recipient stored in orders.invoice_data (order_invoice_recipient_update() in
 * includes/order_view.php, wired into admin/order-view.php).
 */
#[Group('admin')]
final class InvoiceRecipientEditTest extends TestCase
{
    private const VALID_EIK = '831641791';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_view.php';
    }

    private function post(array $over = []): array
    {
        return $over + [
            'company_name' => 'Пример ООД', 'eik' => self::VALID_EIK, 'vat_number' => 'BG' . self::VALID_EIK,
            'mol' => 'Иван Иванов', 'company_address' => 'гр. София, ул. Примерна 1',
        ];
    }

    public function testValidCorrectionReplacesTheRecipientAndKeepsOtherKeys(): void
    {
        $current = ['needs_invoice' => true, 'company_name' => 'Прмер ООД', 'eik' => '1', 'something_else' => 'kept'];
        $res = order_invoice_recipient_update($current, $this->post());
        $this->assertArrayNotHasKey('errors', $res);
        $this->assertSame([
            'needs_invoice' => true, 'company_name' => 'Пример ООД', 'eik' => self::VALID_EIK, 'something_else' => 'kept',
            'vat_number' => 'BG' . self::VALID_EIK, 'mol' => 'Иван Иванов', 'company_address' => 'гр. София, ул. Примерна 1',
        ], $res['data']);
    }

    public function testEikIsStoredCleanAndVatUppercased(): void
    {
        $res = order_invoice_recipient_update(null, $this->post(['eik' => ' 831 641 791 ', 'vat_number' => 'bg 831641791']));
        $this->assertSame(self::VALID_EIK, $res['data']['eik']);
        $this->assertSame('BG831641791', $res['data']['vat_number']);
        $this->assertTrue($res['data']['needs_invoice']);
    }

    public function testNameAndEikAreRequired(): void
    {
        $res = order_invoice_recipient_update([], $this->post(['company_name' => '  ', 'eik' => '']));
        $this->assertCount(2, $res['errors']);
        $this->assertArrayNotHasKey('data', $res);
    }

    public function testEikWithWrongCheckDigitIsRefusedInPlainWords(): void
    {
        $res = order_invoice_recipient_update([], $this->post(['eik' => '831641792']));
        $this->assertCount(1, $res['errors']);
        $this->assertStringContainsString('не е верен', $res['errors'][0]);
    }

    public function testVatNumberIsOptionalButMustLookLikeOne(): void
    {
        $this->assertSame('', order_invoice_recipient_update([], $this->post(['vat_number' => '']))['data']['vat_number']);
        $res = order_invoice_recipient_update([], $this->post(['vat_number' => '12345']));
        $this->assertStringContainsString('BG123456789', $res['errors'][0]);
    }

    public function testNonStringInputIsTreatedAsEmpty(): void
    {
        $res = order_invoice_recipient_update([], $this->post(['company_name' => ['x'], 'mol' => ['y']]));
        $this->assertCount(1, $res['errors'], 'an array name is no name');
    }

    public function testLongValuesAreCut(): void
    {
        $res = order_invoice_recipient_update([], $this->post(['company_address' => str_repeat('а', 1000)]));
        $this->assertSame(300, mb_strlen($res['data']['company_address']));
    }

    public function testOrderViewWiresTheFormAndHandler(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/order-view.php');
        $this->assertStringContainsString('admin_require_shop();', $src);
        $post = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        $this->assertNotFalse($post);
        $csrf = strpos($src, 'csrf_verify()', $post);
        $handler = strpos($src, "\$action === 'update_invoice_data'");
        $this->assertNotFalse($handler);
        $this->assertLessThan($handler, $csrf, 'CSRF is checked before the handler runs');
        $this->assertStringContainsString('order_invoice_recipient_update(', $src);
        $this->assertStringContainsString('UPDATE orders SET invoice_data = ?', $src);
        $this->assertStringContainsString('value="update_invoice_data"', $src);
        $this->assertStringContainsString('✎ Редактирай получателя', $src);
        foreach (['company_name', 'eik', 'vat_number', 'mol', 'company_address'] as $f) {
            $this->assertStringContainsString('for="rcp-' . $f . '"', $src, "$f has a real label");
        }
    }
}
