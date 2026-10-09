<?php
/**
 * DSK Bank return URL for campaign pledges.
 * Customer lands here after paying (or cancelling) on the bank page.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/payment_errors.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_payment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';
define('ICEBREAKER_VARIANT_ID', 8);
start_session();

$pdo           = get_pdo();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';

$pledge_number = trim($_GET['pledge'] ?? '');

if (!preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $pledge_number)) {
    header('Location: /');
    exit;
}

// Tickets belong to the events module now. A ticket bought through the
// campaign before the update comes back here; its own return address takes it
// from there (and answers for the events module being on or off).
$is_ticket = $pdo->prepare("SELECT 1 FROM campaign_pledges WHERE pledge_number = ? AND pledge_type = 'ticket'");
$is_ticket->execute([$pledge_number]);
if ($is_ticket->fetchColumn()) {
    header('Location: /api/event-payment-return.php?' . http_build_query(array_intersect_key($_GET, array_flip(['pledge', 'retry', 'mdOrder', 'orderId']))));
    exit;
}

// Module switched off in Admin → Модули — the page does not exist (site's 404).
module_public_guard('campaign');

$stmt = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
$stmt->execute([$pledge_number]);
$pledge = $stmt->fetch();

if (!$pledge) {
    header('Location: /campaign/');
    exit;
}

// Already processed
if ($pledge['payment_status'] === 'paid') {
    header('Location: /campaign/confirmation/?pledge=' . urlencode($pledge_number));
    exit;
}

// ── Retry: re-register with DSK Bank ─────────────────────────────────────────
if (isset($_GET['retry'])) {
    if ($pledge['payment_status'] !== 'pending') {
        header('Location: /campaign/confirmation/?pledge=' . urlencode($pledge_number));
        exit;
    }
    try {
        $dsk       = new DSKBankPayment();
        $ref       = $pledge_number . '_' . time();
        $returnUrl = SITE_URL . '/api/campaign-payment-return.php?pledge=' . urlencode($pledge_number);
        $result    = $dsk->register($ref, (float)$pledge['amount_eur'], $returnUrl);
        $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id=? WHERE id=?')
            ->execute([$result['dsk_order_id'], $pledge['id']]);
        header('Location: ' . $result['formUrl']);
        exit;
    } catch (Throwable $e) {
        payment_error_report('„Опитай отново“ за подкрепа не успя — DSK не създаде плащане', $pledge_number, $e);
        header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
        exit;
    }
}

// ── Verify payment status ─────────────────────────────────────────────────────
// The id we saved when the payment started comes first: the one in the address
// is only a fallback, and anything else could be pasted there. The reply is also
// checked against this pledge's number and amount before anything changes.
$dsk_order_id = ($pledge['dsk_order_id'] ?? '') ?: ($_GET['mdOrder'] ?? $_GET['orderId'] ?? '');

if (!$dsk_order_id) {
    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
    exit;
}

try {
    $dsk    = new DSKBankPayment();
    $status = $dsk->getStatus($dsk_order_id);
    process_campaign_dsk_result($pdo, $pledge, $dsk_order_id, $status);
} catch (Throwable $e) {
    payment_error_report('Статусът на плащането за подкрепа не можа да бъде проверен', $pledge_number, $e);
    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
    exit;
}

$orderStatus = (int)($status['orderStatus'] ?? -1);
if ($orderStatus === 2 || $orderStatus === 1) {
    header('Location: /campaign/confirmation/?pledge=' . urlencode($pledge_number));
} else {
    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
}
exit;

// ── Handler ───────────────────────────────────────────────────────────────────
function process_campaign_dsk_result(PDO $pdo, array $pledge, string $dskOrderId, array $status): void
{
    // Never act on a reply about some other payment — not to mark paid, not to fail.
    if (!dsk_status_belongs_to($status, (string) $pledge['pledge_number'], (float) $pledge['amount_eur'])) {
        payment_error_report(
            'Отговорът на банката не е за тази подкрепа — плащането не е отбелязано',
            (string) $pledge['pledge_number'],
            new RuntimeException('DSK reply for ' . ($status['orderNumber'] ?? '?') . ' / ' . ($status['amount'] ?? '?') . ' cents')
        );
        return;
    }

    $orderStatus = (int)($status['orderStatus'] ?? -1);

    if (!$pledge['dsk_order_id']) {
        $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id=? WHERE id=?')
            ->execute([$dskOrderId, $pledge['id']]);
        $pledge['dsk_order_id'] = $dskOrderId;
    }

    if ($orderStatus === 2 || $orderStatus === 1) {
        if ($pledge['payment_status'] !== 'paid') {
            $upd = $pdo->prepare("UPDATE campaign_pledges SET payment_status='paid' WHERE id=? AND payment_status='pending'");
            $upd->execute([$pledge['id']]);
            if ($upd->rowCount() !== 1) {
                return; // another callback already processed this pledge
            }
            $pledge['payment_status'] = 'paid';

            reduce_icebreaker_stock($pdo, $pledge);

            // Generate donation certificate
            generate_campaign_cert($pdo, $pledge);

            // Reload pledge with generated doc data
            $updated = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id=?');
            $updated->execute([$pledge['id']]);
            $pledge = $updated->fetch() ?: $pledge;

            $_pledge_lang = $pledge['lang'] ?? 'bg';
            send_order_mail(
                pledge_ensure_order_row($pdo, $pledge),
                $pledge['email'],
                render_email_subject('campaign-confirmation', $_pledge_lang, ['name' => $pledge['name'], 'pledge_number' => $pledge['pledge_number']]),
                render_email('campaign-confirmation', ['pledge' => $pledge, 'lang' => $_pledge_lang]),
                ['template_key' => 'campaign-confirmation']
            );

            // Admin notification
            send_mail(
                SITE_EMAIL,
                'Нов поддръжник на кампанията — ' . $pledge['pledge_number'],
                render_email('campaign-admin-notification', ['pledge' => $pledge])
            );
        }
    } elseif ($orderStatus === 3) {
        $pdo->prepare("UPDATE campaign_pledges SET payment_status='failed' WHERE id=? AND payment_status='pending'")
            ->execute([$pledge['id']]);
    }
}

function generate_campaign_cert(PDO $pdo, array $pledge): void
{
    try {
        // Atomically claim the next donation_cert sequence number
        $pdo->beginTransaction();
        $pdo->exec("UPDATE document_sequences SET last_number = last_number + 1 WHERE type = 'donation_cert'");
        $num = (int)$pdo->query("SELECT last_number FROM document_sequences WHERE type = 'donation_cert'")->fetchColumn();
        $pdo->commit();

        $formatted = str_pad((string)$num, 5, '0', STR_PAD_LEFT);
        $year      = date('Y');
        $dir       = $_SERVER['DOCUMENT_ROOT'] . "/documents/donation_certs/{$year}/";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log("generate_campaign_cert: cannot create dir {$dir}");
            return;
        }
        $filename = "{$formatted}_{$pledge['pledge_number']}.pdf";
        $filepath = $dir . $filename;

        // Build a pledge-shaped order array that DonationCertGenerator can consume
        $fake_order = [
            'id'              => 0,
            'order_number'    => $pledge['pledge_number'],
            'customer_name'   => $pledge['name'],
            'customer_email'  => $pledge['email'],
            'total_eur'       => (float)$pledge['amount_eur'],
            'payment_method'  => 'card',
            'created_at'      => $pledge['created_at'],
            'invoice_data'    => json_encode(['donor_type' => 'individual']),
        ];
        $fake_items = [[
            'type'       => 'donation',
            'amount_eur' => (float)$pledge['amount_eur'],
            'recipient'  => 'foundation',
        ]];
        $fake_doc = [
            'formatted_number' => $formatted,
        ];

        $generator = new DonationCertGenerator();
        $pdf_bytes = $generator->generate($fake_order, $fake_items, $fake_doc);

        if (file_put_contents($filepath, $pdf_bytes) === false) {
            error_log("generate_campaign_cert: file_put_contents failed for {$filepath}");
            return;
        }

        $rel_path = "/documents/donation_certs/{$year}/{$filename}";
        $pdo->prepare("UPDATE campaign_pledges SET cert_number=?, cert_path=? WHERE id=?")
            ->execute([$num, $rel_path, $pledge['id']]);

        // Create orders anchor row + document record so cert appears in signing flow
        $order_id = pledge_ensure_order_row($pdo, $pledge);
        pledge_insert_cert_document($pdo, $order_id, $num, $formatted, ltrim($rel_path, '/'));

        // Notify signing admin
        if (function_exists('send_mail') && defined('SIGNING_ADMIN_EMAIL')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
            send_mail(
                SIGNING_ADMIN_EMAIL,
                'Сертификат за дарение #' . $formatted . ' — нужен подпис',
                render_email('cert-needs-signature', [
                    'order'    => [
                        'customer_name'  => $pledge['name'],
                        'customer_email' => $pledge['email'],
                        'order_number'   => $pledge['pledge_number'],
                        'total_eur'      => (float)$pledge['amount_eur'],
                    ],
                    'document' => ['formatted_number' => $formatted],
                    'order_id' => $order_id,
                ])
            );
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('generate_campaign_cert: ' . $e->getMessage());
    }
}

function reduce_icebreaker_stock(PDO $pdo, array $pledge): void
{
    if (!empty($pledge['reward_id'])) {
        $row = $pdo->prepare('SELECT icebreaker_qty FROM campaign_rewards WHERE id = ?');
        $row->execute([$pledge['reward_id']]);
        $qty = (int)($row->fetchColumn() ?: 0);
    } else {
        return; // pure donation — no Icebreakers included
    }
    if ($qty <= 0) return;

    $stmt = $pdo->prepare(
        'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
    );
    $stmt->execute([$qty, ICEBREAKER_VARIANT_ID, $qty]);
    if ($stmt->rowCount() === 0) {
        error_log("reduce_icebreaker_stock: insufficient stock for pledge {$pledge['pledge_number']}, wanted {$qty}");
    }
}
