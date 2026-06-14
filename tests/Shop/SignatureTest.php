<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('signature')]
final class SignatureTest extends TestCase
{
    private array $savedSession = [];

    protected function setUp(): void
    {
        // Snapshot session so we can restore it after each test
        $this->savedSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    // ── admin_can_sign ──────────────────────────────────────────────────────

    public function testAdminCanSignReturnsFalseWhenNotLoggedIn(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $this->assertFalse(admin_can_sign());
    }

    public function testAdminCanSignReturnsFalseForWrongEmail(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'id'    => 99,
            'name'  => 'Other Admin',
            'email' => 'other@example.com',
            'role'  => 'admin',
            'time'  => time(),
        ];
        $this->assertFalse(admin_can_sign());
    }

    public function testAdminCanSignReturnsFalseForNonFoundationEmail(): void
    {
        // Explicitly verify that a different personal email cannot sign,
        // even if the account has admin role.
        $_SESSION[ADMIN_SESSION_NAME] = [
            'id'    => 2,
            'name'  => 'Detelina',
            'email' => 'linamilcheva@gmail.com',
            'role'  => 'admin',
            'time'  => time(),
        ];
        $this->assertFalse(admin_can_sign());
        $this->assertNotEquals('linamilcheva@gmail.com', SIGNING_ADMIN_EMAIL,
            'Signing must use the foundation email, not a personal account.');
    }

    public function testSigningEmailIsFoundationDomain(): void
    {
        $this->assertStringEndsWith('@oddminds.org', SIGNING_ADMIN_EMAIL,
            'SIGNING_ADMIN_EMAIL must be an @oddminds.org address.');
        $this->assertEquals('detelina@oddminds.org', SIGNING_ADMIN_EMAIL);
    }

    public function testAdminCanSignReturnsTrueForSigningEmail(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'id'    => 1,
            'name'  => 'Detelina',
            'email' => SIGNING_ADMIN_EMAIL,
            'role'  => 'admin',
            'time'  => time(),
        ];
        $this->assertTrue(admin_can_sign());
    }

    public function testAdminCanSignReturnsFalseWhenSessionExpired(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'id'    => 1,
            'name'  => 'Detelina',
            'email' => SIGNING_ADMIN_EMAIL,
            'role'  => 'admin',
            'time'  => time() - (ADMIN_SESSION_HOURS * 3600 + 1),
        ];
        $this->assertFalse(admin_can_sign());
    }

    // ── DonationCertGenerator HTML ──────────────────────────────────────────

    private function callBuildHtml(?string $signatureB64 = null): string
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

        $gen = new DonationCertGenerator();
        $ref = new ReflectionMethod($gen, 'buildHtml');
        $ref->setAccessible(true);

        $order = [
            'customer_name'    => 'Тест Дарител',
            'customer_email'   => 'donor@example.com',
            'type'             => 'donation',
            'total_eur'        => 100.0,
            'payment_method'   => 'bank_transfer',
            'created_at'       => '2026-04-01 10:00:00',
            'invoice_data'     => '{}',
            'items'            => json_encode([['type' => 'donation', 'amount_eur' => 100.0, 'recipient' => 'foundation']]),
            'donation_message' => null,
        ];
        $items    = [['type' => 'donation', 'amount_eur' => 100.0, 'recipient' => 'foundation']];
        $document = ['number' => 1, 'formatted_number' => '00001', 'type' => 'donation_cert'];

        return $ref->invoke($gen, $order, $items, $document, $signatureB64);
    }

    public function testBuildHtmlContainsSignatureImgWhenProvided(): void
    {
        $b64  = base64_encode('fake-png-bytes');
        $html = $this->callBuildHtml($b64);
        $this->assertStringContainsString('data:image/png;base64,' . $b64, $html);
        $this->assertStringContainsString('<img ', $html);
    }

    public function testBuildHtmlHasPlaceholderDivWithoutSignature(): void
    {
        $html = $this->callBuildHtml(null);
        $this->assertStringContainsString('height:32px', $html);
        $this->assertStringNotContainsString('<img ', $html);
    }

    public function testBuildHtmlAlwaysContainsManagerName(): void
    {
        $html = $this->callBuildHtml(null);
        $this->assertStringContainsString('Детелина Боянова Василева', $html);
    }

    // ── cert-needs-signature email template ─────────────────────────────────

    public function testCertNeedsSignatureEmailRendersCorrectly(): void
    {
        $order    = ['customer_name' => 'Тест Дарител', 'total_eur' => 75.0];
        $document = ['formatted_number' => '00007'];
        $order_id = 42;

        $html = render_email('cert-needs-signature', compact('order', 'document', 'order_id'));

        $this->assertStringContainsString('00007', $html);
        $this->assertStringContainsString('Тест Дарител', $html);
        $this->assertStringContainsString('75.00', $html);
        $this->assertStringContainsString('/admin/order-view.php?id=42', $html);
        $this->assertStringContainsString('нужен подпис', mb_strtolower($html));
    }
}
