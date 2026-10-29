<?php
/**
 * Deleting an unsigned donation certificate, so it can be generated again with
 * the counter's next number — the way to fix a certificate's number after the
 * numbering in Плащания → Номерация was corrected. Used by admin/order-view.php
 * and admin/pledge-view.php.
 *
 * A signed certificate is final and is never deleted.
 */

/**
 * The counter's next donation-certificate number, formatted — what a certificate
 * generated after the delete will get. For the confirm dialog.
 */
function donation_cert_next_formatted(PDO $pdo): string
{
    return str_pad((string) (document_sequence_last($pdo, 'donation_cert') + 1), 5, '0', STR_PAD_LEFT);
}

/**
 * Delete an order's unsigned certificate: its documents row, its PDF and, for a
 * campaign pledge, the number and file kept on the pledge itself.
 * Returns '' when done, or a plain-language reason it wasn't.
 */
function donation_cert_delete(PDO $pdo, int $order_id): string
{
    $s = $pdo->prepare("
        SELECT d.id, d.file_path, d.signed_at, o.type AS order_type, o.order_number
          FROM documents d JOIN orders o ON o.id = d.order_id
         WHERE d.order_id = ? AND d.type = 'donation_cert'
    ");
    $s->execute([$order_id]);
    $doc = $s->fetch();
    if (!$doc) return 'Няма сертификат за изтриване.';

    // The signed_at check sits in the DELETE too, so a signature that lands meanwhile wins.
    $del = $pdo->prepare('DELETE FROM documents WHERE id = ? AND signed_at IS NULL');
    $del->execute([$doc['id']]);
    if ($del->rowCount() === 0) return 'Подписан сертификат не може да бъде изтрит.';

    _donation_cert_unlink((string) $doc['file_path']);

    if ($doc['order_type'] === 'pledge') {
        $p = $pdo->prepare("SELECT * FROM campaign_pledges WHERE pledge_number = ?");
        $p->execute([$doc['order_number']]);
        if ($pledge = $p->fetch()) _donation_cert_clear_pledge($pdo, $pledge);
    }
    return '';
}

/**
 * Delete a campaign pledge's certificate. A pledge certificate from before the
 * documents table lives only on the pledge row; a newer one goes through its order.
 * Returns '' when done, or a plain-language reason it wasn't.
 */
function donation_cert_delete_for_pledge(PDO $pdo, array $pledge): string
{
    $o = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? AND type = 'pledge'");
    $o->execute([$pledge['pledge_number']]);
    $order_id = $o->fetchColumn();
    if ($order_id !== false) {
        $d = $pdo->prepare("SELECT 1 FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $d->execute([$order_id]);
        if ($d->fetchColumn()) return donation_cert_delete($pdo, (int) $order_id);
    }
    if ((int) ($pledge['cert_number'] ?? 0) <= 0 && empty($pledge['cert_path'])) return 'Няма сертификат за изтриване.';
    _donation_cert_clear_pledge($pdo, $pledge);
    return '';
}

function _donation_cert_clear_pledge(PDO $pdo, array $pledge): void
{
    $pdo->prepare('UPDATE campaign_pledges SET cert_number = NULL, cert_path = NULL WHERE id = ?')
        ->execute([$pledge['id']]);
    _donation_cert_unlink((string) ($pledge['cert_path'] ?? ''));
}

/** Remove a certificate PDF — only ever inside the certificates folder. */
function _donation_cert_unlink(string $rel): void
{
    $rel = ltrim($rel, '/');
    if (!str_starts_with($rel, 'documents/donation_certs/') || str_contains($rel, '..')) return;
    $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
    if (is_file($abs)) @unlink($abs);
}
