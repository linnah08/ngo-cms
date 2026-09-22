<?php
/**
 * Bulk BoxNow labelling: pick the orders that are ready to ship, create any
 * missing BoxNow labels, lay the labels out several-per-A4-sheet for printing,
 * and build the packing list that is emailed to the admin.
 *
 * Kept free of HTTP/session concerns so it can be unit-tested.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';   // mPDF (+ FPDI) for the A4 sheets

/** Labels per A4 sheet: BoxNow labels are A6 (105×148 mm), so 2×2 fits at full size. */
const BOXNOW_LABELS_PER_PAGE = 4;
/** Max orders handled per click, so the request can't time out mid-batch. */
const BOXNOW_BULK_LIMIT = 40;

/**
 * Paid, not-yet-shipped BoxNow orders (oldest first, so the oldest orders go out first).
 *
 * @return array<int,array<string,mixed>>
 */
function boxnow_eligible_orders(PDO $pdo, ?array $only_ids = null): array
{
    $sql = "SELECT * FROM orders
             WHERE courier = 'boxnow' AND type = 'physical'
               AND payment_status = 'paid'
               AND status IN ('new','confirmed')";
    $params = [];
    if ($only_ids !== null) {
        if (!$only_ids) return [];
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($only_ids), '?')) . ')';
        $params = array_values($only_ids);
    }
    $sql .= ' ORDER BY created_at ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * The physical lines to put in the box (donation add-ons are not packed).
 *
 * @return array<int,array{name:string,qty:int,detail:string}>
 */
function boxnow_pack_lines(array $order): array
{
    $lines = [];
    foreach (json_decode((string)($order['items'] ?? ''), true) ?: [] as $item) {
        if (($item['type'] ?? '') === 'donation') continue;
        $name = trim((string)($item['name_bg'] ?? $item['name'] ?? ''));
        if ($name === '') continue;
        $detail = [];
        if (!empty($item['colour']))        $detail[] = 'Цвят: ' . ucfirst((string)$item['colour']);
        if (!empty($item['size']))          $detail[] = 'Размер: ' . $item['size'];
        if (!empty($item['variant_label'])) $detail[] = (string)$item['variant_label'];
        $lines[] = ['name' => $name, 'qty' => max(1, (int)($item['quantity'] ?? 1)), 'detail' => implode(' · ', $detail)];
    }
    return $lines;
}

/** "Name x2, Other x1" — the parcel description BoxNow shows on the shipment. */
function boxnow_description(array $order): string
{
    $parts = array_map(fn($l) => $l['name'] . ' x' . $l['qty'], boxnow_pack_lines($order));
    return $parts ? implode(', ', $parts) : 'Поръчка #' . ($order['order_number'] ?? '');
}

/**
 * Create the BoxNow parcel for an order and store its id.
 * Returns the parcel id; throws a plain-language RuntimeException on failure.
 */
function boxnow_create_parcel(PDO $pdo, BoxNowCourier $boxnow, array $order, int $compartment_size = 1): string
{
    if (empty($order['courier_office_code'])) {
        throw new RuntimeException('Не е избран BoxNow автомат.');
    }
    $result = $boxnow->createShipment([
        'weight'           => 1.0,
        'description'      => boxnow_description($order),
        'order_number'     => $order['order_number'],
        'receiver_name'    => $order['customer_name'],
        'receiver_phone'   => $order['customer_phone'],
        'receiver_email'   => $order['customer_email'],
        'locker_id'        => $order['courier_office_code'],
        'compartment_size' => $compartment_size,
    ]);
    $parcel_id = $result['parcel_id'] ?? '';
    if (!$parcel_id) throw new RuntimeException('BoxNow не върна номер на пратката.');

    $pdo->prepare('UPDATE orders SET boxnow_parcel_id=?, tracking_number=?, updated_at=NOW() WHERE id=?')
        ->execute([$parcel_id, $parcel_id, (int)$order['id']]);
    return $parcel_id;
}

/** Download one label as PDF bytes. Throws on any failure. */
function boxnow_fetch_label_pdf(BoxNowCourier $boxnow, string $parcel_id): string
{
    $ch = curl_init($boxnow->getLabelUrl($parcel_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $boxnow->getAccessToken(), 'Accept: application/pdf'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $pdf  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)                              throw new RuntimeException('Връзката с BoxNow не успя.');
    if ($code >= 400)                      throw new RuntimeException('BoxNow не върна етикета (код ' . $code . ').');
    if (!is_string($pdf) || strncmp($pdf, '%PDF', 4) !== 0) throw new RuntimeException('BoxNow върна невалиден етикет.');
    return $pdf;
}

/**
 * Lay label PDFs (one page each) out 2×2 on A4 sheets, at full size so barcodes stay scannable.
 *
 * @param string[] $pdfs raw PDF bytes, in print order
 * @return string A4 PDF bytes
 */
function boxnow_a4_sheets(array $pdfs): string
{
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
        'tempDir' => sys_get_temp_dir(),
    ]);
    $mpdf->SetAutoPageBreak(false);

    $tmp = [];
    try {
        foreach (array_values($pdfs) as $i => $bytes) {
            $slot = $i % BOXNOW_LABELS_PER_PAGE;
            if ($slot === 0) $mpdf->AddPage();
            $file = tempnam(sys_get_temp_dir(), 'bnl');
            $tmp[] = $file;
            file_put_contents($file, $bytes);
            $mpdf->setSourceFile($file);
            $mpdf->useTemplate($mpdf->importPage(1), ($slot % 2) * 105, intdiv($slot, 2) * 148.5, 105, 148);
        }
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } finally {
        foreach ($tmp as $f) @unlink($f);
    }
}

/**
 * Mark an order shipped with its BoxNow parcel as tracking number and email the
 * customer — same effects as the status form on the order page.
 */
function boxnow_mark_shipped(PDO $pdo, array $order, string $parcel_id): void
{
    $pdo->prepare("UPDATE orders SET status='shipped', tracking_number=?, updated_at=NOW() WHERE id=?")
        ->execute([$parcel_id, (int)$order['id']]);

    if (empty($order['customer_email'])) return;
    $lang = $order['lang'] ?? 'bg';
    send_order_mail(
        (int)$order['id'],
        $order['customer_email'],
        render_email_subject('order-shipped-customer', $lang, ['customer_name' => $order['customer_name'], 'order_number' => $order['order_number'], 'courier' => 'BoxNow']),
        render_email('order-shipped-customer', ['order' => $order, 'tracking_number' => $parcel_id, 'courier_label' => 'BoxNow']),
        ['template_key' => 'order-shipped-customer']
    );
}
