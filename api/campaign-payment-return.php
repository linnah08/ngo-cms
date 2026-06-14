<?php
/**
 * DSK Bank return URL for campaign pledges.
 * Customer lands here after paying (or cancelling) on the bank page.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/TicketGenerator.php';
define('ICEBREAKER_VARIANT_ID', 8);
start_session();

$pdo           = get_pdo();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';

// Extend orders.type to include 'pledge' (idempotent)
try {
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN type ENUM('physical','donation','ticket','pledge') NOT NULL");
} catch (Throwable) {}

$pledge_number = trim($_GET['pledge'] ?? '');

if (!preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $pledge_number)) {
    header('Location: /');
    exit;
}

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
        error_log('campaign-payment-return retry: ' . $e->getMessage());
        header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
        exit;
    }
}

// ── Verify payment status ─────────────────────────────────────────────────────
$dsk_order_id = $_GET['mdOrder'] ?? $_GET['orderId'] ?? $pledge['dsk_order_id'] ?? '';

if (!$dsk_order_id) {
    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number));
    exit;
}

try {
    $dsk    = new DSKBankPayment();
    $status = $dsk->getStatus($dsk_order_id);
    process_campaign_dsk_result($pdo, $pledge, $dsk_order_id, $status);
} catch (Throwable $e) {
    error_log('campaign-payment-return: ' . $e->getMessage());
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

            $is_ticket = ($pledge['pledge_type'] ?? 'donation') === 'ticket';

            if ($is_ticket) {
                // Generate one ticket PDF per quantity
                $qty = max(1, (int)($pledge['ticket_qty'] ?? 1));
                for ($i = 0; $i < $qty; $i++) {
                    generate_campaign_ticket($pdo, $pledge, $i + 1, $qty);
                }
            } else {
                // Generate donation certificate
                generate_campaign_cert($pdo, $pledge);
            }

            // Reload pledge with generated doc data
            $updated = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id=?');
            $updated->execute([$pledge['id']]);
            $pledge = $updated->fetch() ?: $pledge;

            $_pledge_lang = $pledge['lang'] ?? 'bg';
            if ($is_ticket) {
                // Attach all generated ticket PDFs (one per quantity)
                $ticket_attachments = [];
                $qty = max(1, (int)($pledge['ticket_qty'] ?? 1));
                $ticket_paths = json_decode($pledge['ticket_path'] ?? '', true);
                if (!is_array($ticket_paths)) {
                    // Legacy single path
                    $ticket_paths = $pledge['ticket_path'] ? [$pledge['ticket_path']] : [];
                }
                foreach ($ticket_paths as $i => $rel_path) {
                    $ticket_file = $_SERVER['DOCUMENT_ROOT'] . $rel_path;
                    if (file_exists($ticket_file)) {
                        $n = $i + 1;
                        $ticket_attachments[] = [
                            'path' => $ticket_file,
                            'name' => 'ticket-' . $pledge['pledge_number'] . ($qty > 1 ? "-{$n}" : '') . '.pdf',
                        ];
                    }
                }
                send_mail(
                    $pledge['email'],
                    render_email_subject('campaign-ticket', $_pledge_lang, ['event_name' => setting_get('event_name', 'събитието'), 'pledge_number' => $pledge['pledge_number']]),
                    render_email('campaign-ticket', ['pledge' => $pledge, 'lang' => $_pledge_lang]),
                    '',
                    $ticket_attachments
                );
            } else {
                // Send donation confirmation email
                send_mail(
                    $pledge['email'],
                    render_email_subject('campaign-confirmation', $_pledge_lang, ['name' => $pledge['name'], 'pledge_number' => $pledge['pledge_number']]),
                    render_email('campaign-confirmation', ['pledge' => $pledge, 'lang' => $_pledge_lang])
                );
            }

            // Admin notification
            send_mail(
                SITE_EMAIL,
                ($is_ticket ? 'Нов билет' : 'Нов поддръжник') . ' на кампанията — ' . $pledge['pledge_number'],
                render_email('campaign-admin-notification', ['pledge' => $pledge])
            );
        }
    } elseif ($orderStatus === 3) {
        $pdo->prepare("UPDATE campaign_pledges SET payment_status='failed' WHERE id=? AND payment_status='pending'")
            ->execute([$pledge['id']]);
    }
}

function generate_campaign_ticket(PDO $pdo, array $pledge, int $ticket_num = 1, int $total_qty = 1): void
{
    try {
        // Unique ticket code per ticket: TKT-YYYYMMDD-XXXXXXXX
        $ticket_code = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $year = date('Y');
        $dir  = $_SERVER['DOCUMENT_ROOT'] . "/documents/tickets/{$year}/";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log("generate_campaign_ticket: cannot create dir {$dir}");
            return;
        }
        $suffix   = $total_qty > 1 ? "-{$ticket_num}" : '';
        $filename = $ticket_code . '_' . $pledge['pledge_number'] . $suffix . '.pdf';
        $filepath = $dir . $filename;

        // Per-ticket price (total divided evenly)
        $price_per_ticket = $total_qty > 1
            ? round((float)$pledge['amount_eur'] / $total_qty, 2)
            : (float)$pledge['amount_eur'];

        $order_row = [
            'name'          => $pledge['name'],
            'email'         => $pledge['email'],
            'pledge_number' => $pledge['pledge_number'],
            'amount_eur'    => $price_per_ticket,
            'created_at'    => $pledge['created_at'],
        ];
        $doc_row = [
            'ticket_code'  => $ticket_code,
            'event_name'   => setting_get('event_name',  'Събитие'),
            'event_date'   => setting_get('event_date',  ''),
            'event_time'   => setting_get('event_time',  ''),
            'event_place'  => setting_get('event_place', ''),
        ];

        $generator = new TicketGenerator();
        $pdf_bytes = $generator->generate($order_row, [], $doc_row);

        if (file_put_contents($filepath, $pdf_bytes) === false) {
            error_log("generate_campaign_ticket: file_put_contents failed for {$filepath}");
            return;
        }

        $rel_path = "/documents/tickets/{$year}/{$filename}";

        // Append this path to the JSON array stored in ticket_path.
        // Re-read from DB so concurrent/sequential calls don't overwrite each other.
        $cur = $pdo->prepare('SELECT ticket_path FROM campaign_pledges WHERE id=?');
        $cur->execute([$pledge['id']]);
        $cur_path = (string)($cur->fetchColumn() ?: '');
        $existing = json_decode($cur_path, true);
        if (!is_array($existing)) {
            $existing = $cur_path !== '' ? [$cur_path] : [];
        }
        $existing[] = $rel_path;
        $paths_json = json_encode($existing);

        // Store first ticket's code; append all paths as JSON array
        $pdo->prepare("UPDATE campaign_pledges SET ticket_code=COALESCE(NULLIF(ticket_code,''),?), ticket_path=? WHERE id=?")
            ->execute([$ticket_code, $paths_json, $pledge['id']]);
        // Keep pledge array in sync for the orders insert on last ticket
        $pledge['ticket_path'] = $paths_json;

        // Insert into orders table on the first ticket only (represents the whole purchase)
        if ($ticket_num === 1) {
            $ev_name = setting_get('event_name', 'Билет');
            $items = [];
            for ($i = 0; $i < $total_qty; $i++) {
                $items[] = [
                    'type'          => 'ticket',
                    'name'          => $ev_name . ($total_qty > 1 ? ' (' . ($i + 1) . '/' . $total_qty . ')' : ''),
                    'ticket_code'   => $ticket_code, // will be updated as more generate
                    'pledge_number' => $pledge['pledge_number'],
                    'amount_eur'    => $price_per_ticket,
                ];
            }
            $pdo->prepare("
                INSERT IGNORE INTO orders
                    (order_number, type, status, customer_name, customer_email,
                     items, subtotal_eur, shipping_eur, total_eur,
                     payment_method, payment_status, created_at)
                VALUES (?, 'ticket', 'confirmed', ?, ?, ?, ?, 0, ?, 'card', 'paid', ?)
            ")->execute([
                $pledge['pledge_number'],
                $pledge['name'],
                $pledge['email'],
                json_encode($items),
                (float)$pledge['amount_eur'],
                (float)$pledge['amount_eur'],
                $pledge['created_at'],
            ]);
        }

    } catch (Throwable $e) {
        error_log('generate_campaign_ticket: ' . $e->getMessage());
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
    if ($pledge['pledge_type'] === 'ticket') {
        $qty = max(1, (int)($pledge['ticket_qty'] ?? 1));
    } elseif (!empty($pledge['reward_id'])) {
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
