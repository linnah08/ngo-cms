<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Deleting an unsigned donation certificate, then generating it again with the
 * counter's next number — how a certificate's number is fixed after the
 * numbering was corrected. Signed certificates are never deleted.
 */
final class DonationCertDeleteTest extends TestCase
{
    private PDO $pdo;
    private int $counterBefore = 0;
    private array $orders  = [];
    private array $pledges = [];
    private string $root;

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $this->pdo  = get_pdo();
        $this->root = dirname(__DIR__, 2);
        $this->counterBefore = (int) $this->pdo->query("SELECT last_number FROM document_sequences WHERE type = 'donation_cert'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        // Leave the counter where it was, so other tests' numbering is undisturbed.
        $this->pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'donation_cert'")->execute([$this->counterBefore]);
        foreach ($this->pledges as $num) {
            $p = $this->pdo->prepare('SELECT cert_path FROM campaign_pledges WHERE pledge_number = ?');
            $p->execute([$num]);
            if ($path = $p->fetchColumn()) @unlink($this->root . $path);
            $this->pdo->prepare('DELETE FROM campaign_pledges WHERE pledge_number = ?')->execute([$num]);
            $o = $this->pdo->prepare('SELECT id FROM orders WHERE order_number = ?');
            $o->execute([$num]);
            if ($id = $o->fetchColumn()) $this->orders[] = (int) $id;
        }
        foreach (array_unique($this->orders) as $id) {
            $f = $this->pdo->prepare('SELECT file_path FROM documents WHERE order_id = ?');
            $f->execute([$id]);
            foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $path) @unlink($this->root . '/' . $path);
            $this->pdo->prepare('DELETE FROM documents WHERE order_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        }
    }

    private function setNext(int $next): void
    {
        $this->pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'donation_cert'")->execute([$next - 1]);
    }

    private function donationOrder(): int
    {
        $num = 'PHPUNIT-CDEL-' . strtoupper(bin2hex(random_bytes(3)));
        $this->pdo->prepare("INSERT INTO orders (order_number, type, status, customer_name, customer_email, items,
                               subtotal_eur, shipping_eur, total_eur, payment_method, payment_status, invoice_data)
                             VALUES (?, 'donation', 'confirmed', 'Дарител', 'cdel@example.test', ?, 20, 0, 20, 'card', 'paid', ?)")
            ->execute([$num, json_encode([['type' => 'donation', 'amount_eur' => 20, 'recipient' => 'foundation']]), json_encode(['donor_type' => 'individual'])]);
        return $this->orders[] = (int) $this->pdo->lastInsertId();
    }

    private function cert(int $order_id): array|false
    {
        $s = $this->pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $s->execute([$order_id]);
        return $s->fetch();
    }

    private function pledge(string $num): array
    {
        $s = $this->pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
        $s->execute([$num]);
        return $s->fetch();
    }

    public function test_deleting_an_order_certificate_lets_it_be_generated_with_the_next_number(): void
    {
        $id = $this->donationOrder();
        $this->setNext(900100);
        run_admin_page('admin/generate-document.php', ['order_id' => $id, 'doc_type' => 'donation_cert']);
        $first = $this->cert($id);
        $this->assertSame(900100, (int) $first['number']);
        $this->assertFileExists($this->root . '/' . $first['file_path']);

        $this->setNext(900028);   // the numbering was corrected in Плащания
        run_admin_page('admin/generate-document.php', ['order_id' => $id, 'doc_type' => 'donation_cert']);
        $this->assertSame(900100, (int) $this->cert($id)['number'], 'regenerating alone keeps the number');

        $r = run_admin_page('admin/order-view.php', ['action' => 'delete_cert'], ['get' => ['id' => $id]]);
        $this->assertFalse($this->cert($id), 'the certificate is gone');
        $this->assertFileDoesNotExist($this->root . '/' . $first['file_path'], 'and so is its PDF');

        run_admin_page('admin/generate-document.php', ['order_id' => $id, 'doc_type' => 'donation_cert']);
        $this->assertSame(900028, (int) $this->cert($id)['number'], 'the new certificate takes the corrected number');
    }

    public function test_a_signed_certificate_is_never_deleted(): void
    {
        $id = $this->donationOrder();
        $this->setNext(900200);
        run_admin_page('admin/generate-document.php', ['order_id' => $id, 'doc_type' => 'donation_cert']);
        $this->pdo->prepare("UPDATE documents SET signed_at = NOW() WHERE order_id = ? AND type = 'donation_cert'")->execute([$id]);

        run_admin_page('admin/order-view.php', ['action' => 'delete_cert'], ['get' => ['id' => $id]]);
        $cert = $this->cert($id);
        $this->assertNotFalse($cert);
        $this->assertFileExists($this->root . '/' . $cert['file_path']);
    }

    public function test_order_view_offers_delete_only_for_an_unsigned_certificate(): void
    {
        $id = $this->donationOrder();
        $this->setNext(900300);
        run_admin_page('admin/generate-document.php', ['order_id' => $id, 'doc_type' => 'donation_cert']);
        $page = run_admin_page('admin/order-view.php', [], ['method' => 'GET', 'get' => ['id' => $id]])['body'];
        $this->assertStringContainsString('value="delete_cert"', $page);
        $this->assertStringContainsString('следващия номер от брояча (900301)', $page, 'the confirm names the number the next certificate gets');

        $this->pdo->prepare("UPDATE documents SET signed_at = NOW() WHERE order_id = ? AND type = 'donation_cert'")->execute([$id]);
        $page = run_admin_page('admin/order-view.php', [], ['method' => 'GET', 'get' => ['id' => $id]])['body'];
        $this->assertStringNotContainsString('value="delete_cert"', $page);
    }

    public function test_a_pledge_certificate_can_be_deleted_and_generated_again_from_the_pledge_page(): void
    {
        if (!feature_enabled('campaign')) $this->markTestSkipped('The campaign is off on this site, so there is no pledge page.');
        $num = 'PHPUNIT-CDP-' . strtoupper(bin2hex(random_bytes(3)));
        $this->pledges[] = $num;
        $this->pdo->prepare("INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status)
                             VALUES (?, 'Поддръжник', 'cdp@example.test', 30, 'paid')")->execute([$num]);
        $pid = (int) $this->pdo->lastInsertId();

        $this->setNext(900400);
        run_admin_page('admin/pledge-view.php', ['action' => 'regenerate_cert'], ['get' => ['id' => $pid]]);
        $old = $this->pledge($num);
        $this->assertSame(900400, (int) $old['cert_number']);
        $this->assertFileExists($this->root . $old['cert_path']);

        $this->setNext(900028);
        run_admin_page('admin/pledge-view.php', ['action' => 'delete_cert'], ['get' => ['id' => $pid]]);
        $p = $this->pledge($num);
        $this->assertNull($p['cert_number']);
        $this->assertNull($p['cert_path']);
        $this->assertFileDoesNotExist($this->root . $old['cert_path']);
        $page = run_admin_page('admin/pledge-view.php', [], ['method' => 'GET', 'get' => ['id' => $pid]])['body'];
        $this->assertStringContainsString('+ Генерирай сертификат', $page);

        run_admin_page('admin/pledge-view.php', ['action' => 'regenerate_cert'], ['get' => ['id' => $pid]]);
        $this->assertSame(900028, (int) $this->pledge($num)['cert_number'], 'the new certificate takes the corrected number');
    }

    public function test_an_old_system_pledge_certificate_can_be_deleted(): void
    {
        if (!feature_enabled('campaign')) $this->markTestSkipped('The campaign is off on this site, so there is no pledge page.');
        $num = 'PHPUNIT-CDL-' . strtoupper(bin2hex(random_bytes(3)));
        $this->pledges[] = $num;
        $rel = '/documents/donation_certs/' . date('Y') . "/00007_{$num}.pdf";
        @mkdir(dirname($this->root . $rel), 0755, true);
        file_put_contents($this->root . $rel, '%PDF-old');
        $this->pdo->prepare("INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status, cert_number, cert_path)
                             VALUES (?, 'Стар поддръжник', 'cdl@example.test', 30, 'paid', 7, ?)")->execute([$num, $rel]);
        $pid = (int) $this->pdo->lastInsertId();

        run_admin_page('admin/pledge-view.php', ['action' => 'delete_cert'], ['get' => ['id' => $pid]]);
        $p = $this->pledge($num);
        $this->assertNull($p['cert_number']);
        $this->assertFileDoesNotExist($this->root . $rel);
    }

    public function test_an_unpaid_pledge_gets_no_certificate(): void
    {
        if (!feature_enabled('campaign')) $this->markTestSkipped('The campaign is off on this site, so there is no pledge page.');
        $num = 'PHPUNIT-CDU-' . strtoupper(bin2hex(random_bytes(3)));
        $this->pledges[] = $num;
        $this->pdo->prepare("INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status)
                             VALUES (?, 'Неплатил', 'cdu@example.test', 30, 'pending')")->execute([$num]);
        $pid = (int) $this->pdo->lastInsertId();

        $page = run_admin_page('admin/pledge-view.php', [], ['method' => 'GET', 'get' => ['id' => $pid]])['body'];
        $this->assertStringNotContainsString('+ Генерирай сертификат', $page);
        run_admin_page('admin/pledge-view.php', ['action' => 'regenerate_cert'], ['get' => ['id' => $pid]]);
        $this->assertNull($this->pledge($num)['cert_number']);
    }
}
