<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The ✍ Подпиши button opens its dialog on the order and pledge pages, also for
 * a signer who hasn't drawn a signature yet (the dialog then links to drawing
 * one). The script used to set a form field that dialog doesn't have, threw,
 * and the button did nothing.
 */
final class SignButtonTest extends TestCase
{
    private PDO $pdo;
    private ?array $signer = null;
    private $savedSig = false;
    private array $orders  = [];
    private array $pledges = [];
    private ?int $tempSigner = null;

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $this->pdo    = get_pdo();
        $this->signer = signing_admin(true);
        if ($this->signer === null && defined('SIGNING_ADMIN_EMAIL') && SIGNING_ADMIN_EMAIL !== '') {
            // No signer on this install: a temporary admin with the fallback email (includes/signer.php).
            $taken = $this->pdo->prepare('SELECT 1 FROM admin_users WHERE email = ?');
            $taken->execute([SIGNING_ADMIN_EMAIL]);
            if (!$taken->fetchColumn()) {
                $this->pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES ('PHPUnit Signer', ?, 'x', 'admin')")
                    ->execute([SIGNING_ADMIN_EMAIL]);
                $this->tempSigner = (int) $this->pdo->lastInsertId();
                $this->signer = signing_admin(true);
            }
        }
        if ($this->signer === null) $this->markTestSkipped('No signer resolves on this install.');
        $s = $this->pdo->prepare('SELECT signature_data FROM admin_signatures WHERE admin_user_id = ?');
        $s->execute([$this->signer['id']]);
        $this->savedSig = $s->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->tempSigner !== null) {
            $this->pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->tempSigner]);
            signing_admin(true);
        }
        if ($this->signer === null) return;
        $this->pdo->prepare('DELETE FROM admin_signatures WHERE admin_user_id = ?')->execute([$this->signer['id']]);
        if ($this->savedSig !== false) {
            $this->pdo->prepare('INSERT INTO admin_signatures (admin_user_id, signature_data) VALUES (?, ?)')
                ->execute([$this->signer['id'], $this->savedSig]);
        }
        foreach ($this->pledges as $num) {
            $this->pdo->prepare('DELETE FROM campaign_pledges WHERE pledge_number = ?')->execute([$num]);
            $o = $this->pdo->prepare('SELECT id FROM orders WHERE order_number = ?');
            $o->execute([$num]);
            if ($id = $o->fetchColumn()) $this->orders[] = (int) $id;
        }
        foreach (array_unique($this->orders) as $id) {
            $this->pdo->prepare('DELETE FROM documents WHERE order_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
    }

    private function withSignature(bool $has): void
    {
        $this->pdo->prepare('DELETE FROM admin_signatures WHERE admin_user_id = ?')->execute([$this->signer['id']]);
        if ($has) {
            $this->pdo->prepare('INSERT INTO admin_signatures (admin_user_id, signature_data) VALUES (?, ?)')
                ->execute([$this->signer['id'], 'data:image/png;base64,iVBORw0KGgo=']);
        }
    }

    /** An order with an unsigned donation certificate; returns the order id. */
    private function orderWithCert(string $type, string $num): int
    {
        $this->pdo->prepare("INSERT INTO orders (order_number, type, status, customer_name, customer_email, items,
                               subtotal_eur, shipping_eur, total_eur, payment_method, payment_status, invoice_data)
                             VALUES (?, ?, 'confirmed', 'Дарител', 'sign@example.test', ?, 20, 0, 20, 'card', 'paid', ?)")
            ->execute([$num, $type, json_encode([['type' => 'donation', 'amount_eur' => 20, 'recipient' => 'foundation']]), json_encode(['donor_type' => 'individual'])]);
        $id = (int) $this->pdo->lastInsertId();
        $this->orders[] = $id;
        $this->pdo->prepare("INSERT INTO documents (order_id, type, number, formatted_number, file_path)
                             VALUES (?, 'donation_cert', 900500, '900500', 'documents/donation_certs/x.pdf')")->execute([$id]);
        return $id;
    }

    private function page(string $rel, int $id): string
    {
        $r = run_admin_page($rel, [], ['method' => 'GET', 'get' => ['id' => $id],
            'user' => ['id' => $this->signer['id'], 'email' => $this->signer['email'], 'name' => $this->signer['name']]]);
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['body'];
    }

    /** Every element the sign button's script looks up must be on the page. */
    private function assertSignScriptFindsItsElements(string $html, string $opener): void
    {
        $this->assertStringContainsString($opener . '(', $html, 'the signer sees the sign button');
        $start  = strpos($html, 'function ' . $opener);
        $this->assertNotFalse($start);
        $script = substr($html, $start, strpos($html, '</script>', $start) - $start);
        preg_match_all("/getElementById\\('([^']+)'\\)/", $script, $m);
        foreach (array_unique($m[1]) as $elId) {
            if (str_contains($script, "var docField = document.getElementById('{$elId}')")) continue;   // optional by design
            $this->assertStringContainsString('id="' . $elId . '"', $html, "the sign dialog needs #{$elId}");
        }
    }

    public function test_order_page_sign_dialog_opens_with_and_without_a_saved_signature(): void
    {
        $id = $this->orderWithCert('donation', 'PHPUNIT-SIGN-' . strtoupper(bin2hex(random_bytes(3))));
        foreach ([true, false] as $has) {
            $this->withSignature($has);
            $html = $this->page('admin/order-view.php', $id);
            $this->assertSignScriptFindsItsElements($html, 'openSignModal');
            $this->assertStringContainsString($has ? 'Приложи подписа' : 'Добави подпис', $html);
        }
        $this->assertStringContainsString('if (docField) docField.value = docId;', $html);
    }

    public function test_pledge_page_sign_dialog_opens_with_and_without_a_saved_signature(): void
    {
        $num = 'PHPUNIT-SIGNP-' . strtoupper(bin2hex(random_bytes(3)));
        $this->pledges[] = $num;
        $this->pdo->prepare("INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status)
                             VALUES (?, 'Поддръжник', 'signp@example.test', 30, 'paid')")->execute([$num]);
        $pid = (int) $this->pdo->lastInsertId();
        $this->orderWithCert('pledge', $num);

        foreach ([true, false] as $has) {
            $this->withSignature($has);
            $html = $this->page('admin/pledge-view.php', $pid);
            $this->assertSignScriptFindsItsElements($html, 'openPledgeSignModal');
            $this->assertStringContainsString($has ? 'Приложи подписа' : 'Добави подпис', $html);
        }
    }
}
