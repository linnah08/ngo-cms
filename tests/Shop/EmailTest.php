<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('emails')]
final class EmailTest extends TestCase
{
    private static array $mockOrder = [];
    private static array $mockDonationOrder = [];

    public static function setUpBeforeClass(): void
    {
        self::$mockOrder = [
            'id'                 => 1,
            'order_number'       => 'OM-20260405-ABCD',
            'type'               => 'physical',
            'status'             => 'new',
            'customer_name'      => 'Тест Потребител',
            'customer_email'     => 'test@example.com',
            'customer_phone'     => '+359888123456',
            'courier'            => 'econt',
            'delivery_type'      => 'office',
            'courier_office_code'=> '1001',
            'courier_office_name'=> 'Econt Sofia Center',
            'delivery_address'   => null,
            'delivery_city'      => null,
            'tracking_number'    => null,
            'items'              => json_encode([
                [
                    'product_id'   => 1,
                    'name_bg'      => 'Тениска Odd Minds',
                    'name_en'      => 'Odd Minds T-Shirt',
                    'price_eur'    => 15.00,
                    'quantity'     => 2,
                    'subtotal_eur' => 30.00,
                ]
            ]),
            'subtotal_eur'       => 30.00,
            'shipping_eur'       => 4.00,
            'total_eur'          => 34.00,
            'payment_method'     => 'cod',
            'payment_status'     => 'pending',
            'donation_message'   => null,
            'notes'              => null,
            'created_at'         => '2026-04-05 10:30:00',
            'updated_at'         => '2026-04-05 10:30:00',
        ];

        self::$mockDonationOrder = array_merge(self::$mockOrder, [
            'order_number'     => 'OM-20260405-DCBA',
            'type'             => 'donation',
            'payment_method'   => 'bank_transfer',
            'courier'          => null,
            'delivery_type'    => null,
            'items'            => json_encode([['type' => 'donation', 'amount_eur' => 50.0]]),
            'subtotal_eur'     => 50.00,
            'shipping_eur'     => 0.00,
            'total_eur'        => 50.00,
            'donation_message' => 'За децата от ЦНСТ Лесново',
        ]);
    }

    // ── email_wrap ───────────────────────────────────────────────────────────

    public function testEmailWrapIsValidHtml(): void
    {
        $html = email_wrap('<p>Test content</p>');
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testEmailWrapContainsBrandColor(): void
    {
        $html = email_wrap('body');
        $this->assertStringContainsString('#0387A5', $html);
    }

    public function testEmailWrapInjectsContent(): void
    {
        $html = email_wrap('<strong>Hello World</strong>');
        $this->assertStringContainsString('<strong>Hello World</strong>', $html);
    }

    public function testEmailWrapContainsSiteEmail(): void
    {
        $html = email_wrap('');
        $this->assertStringContainsString(SITE_EMAIL, $html);
    }

    // ── order-confirmation-customer ──────────────────────────────────────────

    public function testOrderConfirmationContainsOrderNumber(): void
    {
        $html = render_email('order-confirmation-customer', ['order' => self::$mockOrder]);
        $this->assertStringContainsString('OM-20260405-ABCD', $html);
    }

    public function testOrderConfirmationContainsCustomerName(): void
    {
        $html = render_email('order-confirmation-customer', ['order' => self::$mockOrder]);
        $this->assertStringContainsString('Тест Потребител', $html);
    }

    public function testOrderConfirmationContainsProductName(): void
    {
        $html = render_email('order-confirmation-customer', ['order' => self::$mockOrder]);
        $this->assertStringContainsString('Тениска Odd Minds', $html);
    }

    public function testOrderConfirmationContainsTotal(): void
    {
        $html = render_email('order-confirmation-customer', ['order' => self::$mockOrder]);
        $this->assertStringContainsString('34.00', $html);
    }

    public function testOrderConfirmationContainsDeliveryInfo(): void
    {
        $html = render_email('order-confirmation-customer', ['order' => self::$mockOrder]);
        $this->assertStringContainsString('Econt', $html);
        $this->assertStringContainsString('Econt Sofia Center', $html);
    }

    // ── order-notification-admin ─────────────────────────────────────────────

    public function testAdminNotificationContainsCustomerEmail(): void
    {
        $html = render_email('order-notification-admin', [
            'order'     => self::$mockOrder,
            'admin_url' => 'https://new.oddminds.org/admin/order-view.php?id=1',
        ]);
        $this->assertStringContainsString('test@example.com', $html);
    }

    public function testAdminNotificationContainsAdminLink(): void
    {
        $html = render_email('order-notification-admin', [
            'order'     => self::$mockOrder,
            'admin_url' => 'https://new.oddminds.org/admin/order-view.php?id=1',
        ]);
        $this->assertStringContainsString('https://new.oddminds.org/admin/order-view.php?id=1', $html);
    }

    public function testAdminNotificationContainsTotal(): void
    {
        $html = render_email('order-notification-admin', [
            'order'     => self::$mockOrder,
            'admin_url' => '',
        ]);
        $this->assertStringContainsString('34.00', $html);
    }

    // ── donation-confirmation-customer ───────────────────────────────────────

    public function testDonationConfirmationContainsDonorName(): void
    {
        $html = render_email('donation-confirmation-customer', [
            'donor_name'       => 'Иван Иванов',
            'amount_eur'       => 50.0,
            'donation_message' => 'За децата',
            'reference'        => 'Иван Иванов — дарение',
        ]);
        $this->assertStringContainsString('Иван Иванов', $html);
    }

    public function testDonationConfirmationDoesNotContainBankDetails(): void
    {
        $html = render_email('donation-confirmation-customer', [
            'donor_name'       => 'Иван Иванов',
            'amount_eur'       => 50.0,
            'donation_message' => '',
            'reference'        => 'Иван Иванов — дарение',
        ]);
        // Payment is by card — no bank transfer instructions in the confirmation email
        $this->assertStringNotContainsString('IBAN', $html);
        $this->assertStringNotContainsString('BIC', $html);
    }

    public function testDonationConfirmationContainsAmount(): void
    {
        $html = render_email('donation-confirmation-customer', [
            'donor_name'       => 'Тест',
            'amount_eur'       => 75.50,
            'donation_message' => '',
            'reference'        => 'Тест — дарение',
        ]);
        $this->assertStringContainsString('75.50', $html);
    }

    public function testDonationConfirmationShowsMessageWhenProvided(): void
    {
        $html = render_email('donation-confirmation-customer', [
            'donor_name'       => 'Тест',
            'amount_eur'       => 10.0,
            'donation_message' => 'Специално посвещение',
            'reference'        => 'Тест — дарение',
        ]);
        $this->assertStringContainsString('Специално посвещение', $html);
    }

    public function testDonationConfirmationNoMessageSection(): void
    {
        $html = render_email('donation-confirmation-customer', [
            'donor_name'       => 'Тест',
            'amount_eur'       => 10.0,
            'donation_message' => '',
            'reference'        => 'Тест — дарение',
        ]);
        $this->assertStringNotContainsString('Специално посвещение', $html);
    }

    // ── donation-notification-admin ──────────────────────────────────────────

    public function testDonationAdminNotificationContainsDonorInfo(): void
    {
        $html = render_email('donation-notification-admin', [
            'donor_name'       => 'Иван Иванов',
            'donor_email'      => 'ivan@example.com',
            'amount_eur'       => 100.0,
            'donation_message' => '',
            'order_number'     => 'OM-20260405-ABCD',
        ]);
        $this->assertStringContainsString('Иван Иванов', $html);
        $this->assertStringContainsString('ivan@example.com', $html);
        $this->assertStringContainsString('100.00', $html);
    }

    // ── campaign-ticket ───────────────────────────────────────────────────────

    public function testCampaignTicketEmailContainsPledgeNumber(): void
    {
        $pledge = [
            'name'          => 'Иван Тестов',
            'email'         => 'ivan@example.com',
            'pledge_number' => 'CP-20260508-ABCD',
            'amount_eur'    => 20.00,
            'ticket_code'   => 'TKT-20260508-AABBCCDD',
            'created_at'    => '2026-05-08 12:00:00',
            'lang'          => 'bg',
        ];
        $html = render_email('campaign-ticket', ['pledge' => $pledge, 'lang' => 'bg']);
        $this->assertStringContainsString('CP-20260508-ABCD', $html);
    }

    public function testCampaignTicketEmailContainsTicketCode(): void
    {
        $pledge = [
            'name'          => 'Иван Тестов',
            'email'         => 'ivan@example.com',
            'pledge_number' => 'CP-20260508-ABCD',
            'amount_eur'    => 20.00,
            'ticket_code'   => 'TKT-20260508-AABBCCDD',
            'created_at'    => '2026-05-08 12:00:00',
            'lang'          => 'bg',
        ];
        $html = render_email('campaign-ticket', ['pledge' => $pledge, 'lang' => 'bg']);
        $this->assertStringContainsString('TKT-20260508-AABBCCDD', $html);
    }

    public function testCampaignTicketEmailRendersInEnglish(): void
    {
        $pledge = [
            'name'          => 'Jane Test',
            'email'         => 'jane@example.com',
            'pledge_number' => 'CP-20260508-EFGH',
            'amount_eur'    => 10.00,
            'ticket_code'   => 'TKT-20260508-11223344',
            'created_at'    => '2026-05-08 12:00:00',
            'lang'          => 'en',
        ];
        $html = render_email('campaign-ticket', ['pledge' => $pledge, 'lang' => 'en']);
        $this->assertStringContainsString('CP-20260508-EFGH', $html);
        // English template should not contain Bulgarian-only strings
        $this->assertStringNotContainsString('Код за вход', $html);
    }

    // ── campaign-confirmation ────────────────────────────────────────────────

    public function testCampaignConfirmationEmailContainsPledgeNumber(): void
    {
        $pledge = [
            'name'             => 'Мария Тестова',
            'email'            => 'maria@example.com',
            'pledge_number'    => 'CP-20260508-1234',
            'amount_eur'       => 50.00,
            'created_at'       => '2026-05-08 12:00:00',
            'delivery_address' => null,
            'lang'             => 'bg',
        ];
        $html = render_email('campaign-confirmation', ['pledge' => $pledge, 'lang' => 'bg']);
        $this->assertStringContainsString('CP-20260508-1234', $html);
    }

    public function testCampaignConfirmationEmailContainsAmount(): void
    {
        $pledge = [
            'name'             => 'Мария Тестова',
            'email'            => 'maria@example.com',
            'pledge_number'    => 'CP-20260508-1234',
            'amount_eur'       => 50.00,
            'created_at'       => '2026-05-08 12:00:00',
            'delivery_address' => null,
            'lang'             => 'bg',
        ];
        $html = render_email('campaign-confirmation', ['pledge' => $pledge, 'lang' => 'bg']);
        $this->assertStringContainsString('50.00', $html);
    }

    // ── campaign-admin-notification ──────────────────────────────────────────

    public function testCampaignAdminNotificationContainsPledgeNumber(): void
    {
        $pledge = [
            'name'          => 'Тест Дарител',
            'email'         => 'test@example.com',
            'pledge_number' => 'CP-20260508-ZZZZ',
            'amount_eur'    => 30.00,
            'pledge_type'   => 'donation',
            'ticket_qty'    => 1,
            'created_at'    => '2026-05-08 12:00:00',
        ];
        $html = render_email('campaign-admin-notification', ['pledge' => $pledge]);
        $this->assertStringContainsString('CP-20260508-ZZZZ', $html);
    }

    // ── render_email_subject ────────────────────────────────────────────────

    public function testRenderEmailSubjectReturnsNonEmptyString(): void
    {
        $subject = render_email_subject(
            'order-confirmation-customer',
            'bg',
            ['order_number' => 'OM-20260516-ABCD', 'customer_name' => 'Тест']
        );
        $this->assertIsString($subject);
        $this->assertNotEmpty($subject);
        $this->assertStringContainsString('OM-20260516-ABCD', $subject);
    }

    // ── order-confirmation-customer with print item ──────────────────────────

    public function testOrderConfirmationEmailWithPrintItemRendersSuccessfully(): void
    {
        $orderWithPrint = array_merge(self::$mockOrder, [
            'order_number' => 'OM-20260516-PRNT',
            'items' => json_encode([
                [
                    'product_id'      => 5,
                    'name_bg'         => 'Тениска с печат',
                    'name_en'         => 'Print T-Shirt',
                    'price_eur'       => 20.00,
                    'quantity'        => 1,
                    'subtotal_eur'    => 20.00,
                    'colour'          => 'white',
                    'size'            => 'M',
                    'design_file'     => 'uploads/print-designs/test-design.png',
                    'design_position' => ['x' => 0.5, 'y' => 0.4, 'scale' => 0.3, 'rotation' => 0],
                ]
            ]),
            'subtotal_eur' => 20.00,
            'total_eur'    => 24.00,
        ]);

        $html = render_email('order-confirmation-customer', ['order' => $orderWithPrint]);

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('OM-20260516-PRNT', $html);
        $this->assertStringContainsString('Тениска с печат', $html);
        // Colour is rendered with ucfirst(); size appears as-is
        $this->assertStringContainsString('White', $html);
        $this->assertStringContainsString('M', $html);
        // Design file indicator should appear
        $this->assertStringContainsString('Персонализиран дизайн', $html);
    }

    // ── order-shipped-customer ───────────────────────────────────────────────

    public function testShippedEmailContainsTrackingNumber(): void
    {
        $html = render_email('order-shipped-customer', [
            'order'           => self::$mockOrder,
            'tracking_number' => '1234567890',
            'courier_label'   => 'Econt',
        ]);
        $this->assertStringContainsString('1234567890', $html);
    }

    public function testShippedEmailContainsCourierName(): void
    {
        $html = render_email('order-shipped-customer', [
            'order'           => self::$mockOrder,
            'tracking_number' => 'TRK001',
            'courier_label'   => 'Speedy',
        ]);
        $this->assertStringContainsString('Speedy', $html);
    }

    public function testShippedEmailContainsTrackingLink(): void
    {
        $html = render_email('order-shipped-customer', [
            'order'           => self::$mockOrder,
            'tracking_number' => 'TRK001',
            'courier_label'   => 'Econt',
        ]);
        // Econt tracking URL should be in the email
        $this->assertStringContainsString('econt.com', $html);
    }
}
