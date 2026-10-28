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
        if ($this->inTx) {
            get_pdo()->rollBack();
            $this->inTx = false;
        }
        if (function_exists('signing_admin')) signing_admin(true);   // forget the temporary signer
    }

    // ── admin_can_sign ──────────────────────────────────────────────────────

    public function testAdminCanSignReturnsFalseWhenNotLoggedIn(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $this->assertFalse(admin_can_sign());
    }

    private bool $inTx = false;

    /**
     * The signer this install resolves to (includes/signer.php). When none does,
     * a temporary admin with SIGNING_ADMIN_EMAIL (the fallback) is added inside
     * a transaction that tearDown() rolls back. Skips without a database.
     */
    private function signerOrSkip(): array
    {
        $signer = signing_admin(true);
        if ($signer === null && test_db_available() && defined('SIGNING_ADMIN_EMAIL')) {
            $pdo = get_pdo();
            $pdo->beginTransaction();
            $this->inTx = true;
            $pdo->prepare("DELETE FROM admin_users WHERE email = ? AND role <> 'admin'")->execute([SIGNING_ADMIN_EMAIL]);
            $pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES ('Signer', ?, 'x', 'admin')")
                ->execute([SIGNING_ADMIN_EMAIL]);
            $signer = signing_admin(true);
        }
        if ($signer === null) $this->markTestSkipped('No signer resolves on this install (no DB, or a signer chosen who no longer exists).');
        return $signer;
    }

    public function testAdminCanSignReturnsFalseForAnotherAdmin(): void
    {
        $signer = $this->signerOrSkip();
        // Another admin — even one sharing the signer's email in a stale session — cannot sign.
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'id'    => $signer['id'] + 1000,
            'name'  => 'Other Admin',
            'email' => $signer['email'],
            'role'  => 'admin',
            'time'  => time(),
        ];
        $this->assertFalse(admin_can_sign());
    }

    public function testAdminCanSignReturnsTrueForTheSigner(): void
    {
        $signer = $this->signerOrSkip();
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'id'    => $signer['id'],
            'name'  => $signer['name'],
            'email' => $signer['email'],
            'role'  => 'admin',
            'time'  => time(),
        ];
        $this->assertTrue(admin_can_sign());
    }

    public function testAdminCanSignReturnsFalseWhenSessionExpired(): void
    {
        $signer = $this->signerOrSkip();
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'id'    => $signer['id'],
            'name'  => $signer['name'],
            'email' => $signer['email'],
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

    public function testBuildHtmlAlwaysContainsSignerName(): void
    {
        $html = $this->callBuildHtml(null);
        // Signature line falls back to the organisation name when no МОЛ is set.
        $this->assertStringContainsString(SITE_NAME_BG, $html);
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
