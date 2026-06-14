<?php
/**
 * Courier label issuing for campaign reward pledges.
 *
 * Pledges that ship a physical reward carry their delivery details on
 * campaign_pledges (delivery_courier / delivery_type / office_code /
 * office_name / office_city / delivery_address JSON). This module creates the
 * Speedy/BoxNow shipment, stores the shipment id on the pledge's synthetic
 * orders row (so admin/speedy-label.php + admin/boxnow-label.php can serve the
 * PDF unchanged), marks the reward shipped, and emails the backer.
 */

require_once __DIR__ . '/pledge_documents.php';

/**
 * Decode the pledge delivery_address JSON into an array (address/city/postcode/phone).
 */
function pledge_delivery_addr(array $pledge): array
{
    $addr = !empty($pledge['delivery_address']) ? json_decode($pledge['delivery_address'], true) : [];
    return is_array($addr) ? $addr : [];
}

/**
 * Best phone for the pledge: explicit column, else the one captured in the address JSON.
 */
function pledge_phone(array $pledge): string
{
    if (!empty($pledge['phone'])) return (string)$pledge['phone'];
    $addr = pledge_delivery_addr($pledge);
    return (string)($addr['phone'] ?? '');
}

/**
 * Short shipment description from the reward title (falls back to a generic label).
 */
function pledge_shipment_description(PDO $pdo, array $pledge): string
{
    if (!empty($pledge['reward_id'])) {
        $st = $pdo->prepare('SELECT title FROM campaign_rewards WHERE id = ?');
        $st->execute([(int)$pledge['reward_id']]);
        $title = $st->fetchColumn();
        if ($title) return (string)$title;
    }
    return 'Награда #' . ($pledge['pledge_number'] ?? '');
}

/**
 * Current shipment state from the pledge's synthetic orders row. Read-only —
 * does NOT create the orders row (so merely viewing the card has no side effect).
 * Returns ['order_id'=>int (0 if none), 'speedy_shipment_id'=>?string, 'boxnow_parcel_id'=>?string].
 */
function pledge_current_shipment(PDO $pdo, array $pledge): array
{
    $st = $pdo->prepare(
        "SELECT id, speedy_shipment_id, boxnow_parcel_id
           FROM orders WHERE order_number = ? AND type = 'pledge'"
    );
    $st->execute([$pledge['pledge_number']]);
    $o = $st->fetch() ?: [];
    return [
        'order_id'           => (int)($o['id'] ?? 0),
        'speedy_shipment_id' => $o['speedy_shipment_id'] ?? null,
        'boxnow_parcel_id'   => $o['boxnow_parcel_id'] ?? null,
    ];
}

/**
 * Ensure the pledge's synthetic orders row exists and carries the delivery
 * columns the label-PDF endpoints / order-view expect. Returns orders.id.
 */
function pledge_sync_order_delivery(PDO $pdo, array $pledge): int
{
    $order_id = pledge_ensure_order_row($pdo, $pledge);
    $addr     = pledge_delivery_addr($pledge);
    $is_office = in_array($pledge['delivery_type'] ?? '', ['office', 'apt', 'locker'], true);

    $pdo->prepare(
        'UPDATE orders SET courier=?, delivery_type=?, courier_office_code=?,
                courier_office_name=?, delivery_address=?, delivery_city=?,
                customer_phone=?, updated_at=NOW()
         WHERE id=?'
    )->execute([
        $pledge['delivery_courier'] ?: null,
        $pledge['delivery_type'] ?: null,
        $pledge['office_code'] ?: null,
        $pledge['office_name'] ?: null,
        $is_office ? null : ($addr['address'] ?? null),
        $is_office ? ($pledge['office_city'] ?: null) : ($addr['city'] ?? null),
        pledge_phone($pledge) ?: null,
        $order_id,
    ]);

    return $order_id;
}

/**
 * Email the backer that their reward shipped, reusing the order-shipped template.
 */
function pledge_send_shipped_email(array $pledge, string $courier, string $tracking): void
{
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';

    $lang          = $pledge['lang'] ?? 'bg';
    $courier_label = ['speedy' => 'Speedy', 'boxnow' => 'BoxNow'][$courier] ?? ucfirst($courier);
    $synthetic     = [
        'customer_name'  => $pledge['name'],
        'order_number'   => $pledge['pledge_number'],
        'customer_email' => $pledge['email'],
        'courier'        => $courier,
        'lang'           => $lang,
    ];

    send_mail(
        $pledge['email'],
        render_email_subject('order-shipped-customer', $lang, [
            'customer_name' => $pledge['name'],
            'order_number'  => $pledge['pledge_number'],
            'courier'       => $courier_label,
        ]),
        render_email('order-shipped-customer', [
            'order'           => $synthetic,
            'tracking_number' => $tracking,
            'courier_label'   => $courier_label,
        ])
    );
}

/**
 * Create a Speedy shipment for a pledge reward. Returns the shipment number.
 *
 * @param string|null $office_code_override  Supply the Speedy office id when the
 *        pledge lost it at checkout (legacy records); persisted back to the pledge.
 * @throws RuntimeException on validation/API failure
 */
function pledge_create_speedy_label(
    PDO $pdo,
    array $pledge,
    float $weight,
    int $pack_count,
    ?string $office_code_override = null
): string {
    if (($pledge['delivery_courier'] ?? '') !== 'speedy') {
        throw new RuntimeException('Тази награда не е за доставка със Speedy.');
    }
    if (!empty(pledge_current_shipment($pdo, $pledge)['speedy_shipment_id'])) {
        throw new RuntimeException('Товарителницата вече е създадена.');
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';

    $addr      = pledge_delivery_addr($pledge);
    $is_office = in_array($pledge['delivery_type'] ?? '', ['office', 'apt'], true);
    $office    = $office_code_override !== null && $office_code_override !== ''
        ? $office_code_override
        : (string)($pledge['office_code'] ?? '');

    if ($is_office && $office === '') {
        throw new RuntimeException('Липсва Speedy office ID за този офис. Въведете го, за да създадете товарителницата.');
    }
    if (!$is_office && empty($addr['address'])) {
        throw new RuntimeException('Липсва адрес за доставка.');
    }
    if (pledge_phone($pledge) === '') {
        throw new RuntimeException('Липсва телефон на получателя за тази награда.');
    }

    $result = (new SpeedyCourier())->createShipment([
        'weight'           => max(0.1, $weight),
        'pack_count'       => max(1, $pack_count),
        'description'      => pledge_shipment_description($pdo, $pledge),
        'receiver_name'    => $pledge['name'],
        'receiver_phone'   => pledge_phone($pledge),
        'receiver_city'    => $is_office ? ($pledge['office_city'] ?? '') : ($addr['city'] ?? ''),
        'receiver_address' => $is_office ? '' : ($addr['address'] ?? ''),
        'receiver_office'  => $is_office ? (int)$office : null,
        'delivery_type'    => $is_office ? 'office' : 'door',
    ]);

    $shipment_id = $result['shipment_number'] ?? '';
    if (!$shipment_id) {
        throw new RuntimeException('Speedy не върна номер на пратката.');
    }

    // Persist a supplied office id back onto the pledge so re-prints/displays are consistent
    if ($office_code_override !== null && $office_code_override !== '' && empty($pledge['office_code'])) {
        $pdo->prepare('UPDATE campaign_pledges SET office_code=? WHERE id=?')
            ->execute([$office, (int)$pledge['id']]);
        $pledge['office_code'] = $office;
    }

    $order_id = pledge_sync_order_delivery($pdo, $pledge);
    $pdo->prepare(
        'UPDATE orders SET courier=?, speedy_shipment_id=?, tracking_number=?, updated_at=NOW() WHERE id=?'
    )->execute(['speedy', $shipment_id, $shipment_id, $order_id]);

    pledge_send_shipped_email($pledge, 'speedy', $shipment_id);

    return $shipment_id;
}

/**
 * Create a BoxNow shipment for a pledge reward. Returns the parcel id.
 *
 * @throws RuntimeException on validation/API failure
 */
function pledge_create_boxnow_label(PDO $pdo, array $pledge, int $compartment_size): string
{
    if (($pledge['delivery_courier'] ?? '') !== 'boxnow') {
        throw new RuntimeException('Тази награда не е за доставка с BoxNow.');
    }
    if (!empty(pledge_current_shipment($pdo, $pledge)['boxnow_parcel_id'])) {
        throw new RuntimeException('Товарителницата вече е създадена.');
    }

    $locker = (string)($pledge['office_code'] ?? '');
    if ($locker === '') {
        throw new RuntimeException('Липсва BoxNow автомат за тази награда.');
    }
    if (pledge_phone($pledge) === '') {
        throw new RuntimeException('Липсва телефон на получателя за тази награда.');
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';

    $result = (new BoxNowCourier())->createShipment([
        'weight'           => 1.0,
        'description'      => pledge_shipment_description($pdo, $pledge),
        'order_number'     => $pledge['pledge_number'],
        'receiver_name'    => $pledge['name'],
        'receiver_phone'   => pledge_phone($pledge),
        'receiver_email'   => $pledge['email'],
        'locker_id'        => $locker,
        'compartment_size' => max(1, $compartment_size),
    ]);

    $parcel_id = $result['parcel_id'] ?? '';
    if (!$parcel_id) {
        throw new RuntimeException('BoxNow не върна parcel ID.');
    }

    $order_id = pledge_sync_order_delivery($pdo, $pledge);
    $pdo->prepare(
        'UPDATE orders SET courier=?, boxnow_parcel_id=?, tracking_number=?, updated_at=NOW() WHERE id=?'
    )->execute(['boxnow', $parcel_id, $parcel_id, $order_id]);

    pledge_send_shipped_email($pledge, 'boxnow', $parcel_id);

    return $parcel_id;
}

/**
 * Cancel a pledge reward shipment (Speedy or BoxNow) and reset reward_shipped.
 *
 * @throws RuntimeException on API failure
 */
function pledge_cancel_label(PDO $pdo, array $pledge): void
{
    $order_id = pledge_ensure_order_row($pdo, $pledge);
    $row = $pdo->prepare('SELECT courier, speedy_shipment_id, boxnow_parcel_id FROM orders WHERE id = ?');
    $row->execute([$order_id]);
    $o = $row->fetch() ?: [];

    $courier = $pledge['delivery_courier'] ?? '';
    if ($courier === 'speedy' && !empty($o['speedy_shipment_id'])) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
        (new SpeedyCourier())->cancelShipment($o['speedy_shipment_id']);
        $pdo->prepare('UPDATE orders SET speedy_shipment_id=NULL, tracking_number=NULL, updated_at=NOW() WHERE id=?')
            ->execute([$order_id]);
    } elseif ($courier === 'boxnow' && !empty($o['boxnow_parcel_id'])) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';
        (new BoxNowCourier())->cancelShipment($o['boxnow_parcel_id']);
        $pdo->prepare('UPDATE orders SET boxnow_parcel_id=NULL, tracking_number=NULL, updated_at=NOW() WHERE id=?')
            ->execute([$order_id]);
    } else {
        throw new RuntimeException('Няма активна товарителница за анулиране.');
    }
}

/**
 * Shared POST handler for the pledge shipping card. Both pledge-view.php and
 * order-view.php call this after csrf_verify(). Returns ['errors'=>[], 'success'=>''].
 * No-op (empty result) when the action is not a pledge-shipping action.
 *
 * Re-fetches the pledge into $pledge by reference so the caller renders fresh state.
 */
function pledge_shipping_handle_post(PDO $pdo, array &$pledge): array
{
    $action = $_POST['action'] ?? '';
    $errors = [];
    $success = '';

    try {
        if ($action === 'pledge_create_speedy_label') {
            $weight   = (float)($_POST['weight'] ?? 1.0);
            $packs    = (int)($_POST['pack_count'] ?? 1);
            $override = trim($_POST['office_code_override'] ?? '');
            $id = pledge_create_speedy_label($pdo, $pledge, $weight, $packs, $override !== '' ? $override : null);
            $success = 'Товарителницата е създадена: ' . $id;
        } elseif ($action === 'pledge_create_boxnow_label') {
            $size = (int)($_POST['compartment_size'] ?? 1);
            $id = pledge_create_boxnow_label($pdo, $pledge, $size);
            $success = 'Товарителницата е създадена: ' . $id;
        } elseif ($action === 'pledge_cancel_label') {
            pledge_cancel_label($pdo, $pledge);
            $success = 'Товарителницата е анулирана.';
        } else {
            return ['errors' => [], 'success' => ''];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    // Re-fetch pledge for fresh render
    $st = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id = ?');
    $st->execute([(int)$pledge['id']]);
    $fresh = $st->fetch();
    if ($fresh) $pledge = $fresh;

    return ['errors' => $errors, 'success' => $success];
}

/**
 * Paid pledges that still need a parcel sent — for the admin "За изпращане"
 * dashboard card.
 *
 * "Due to ship" = a paid pledge that carries a physical reward to deliver and
 * whose order has not yet been marked shipped. Two independent signals identify
 * a shippable pledge (either is enough):
 *   - reward_id is set (a physical reward tier was pledged), or
 *   - delivery_courier is set (delivery was arranged, e.g. lafetki donations
 *     where the backer entered an address without picking a reward tier).
 *
 * Shipped state is read from the linked orders row's status — NOT the pledge's
 * reward_shipped flag. reward_shipped is set when a courier *label* is created,
 * which does not mean the parcel left: the admin marks the order 'shipped' in
 * order-view when it actually ships. Keying on reward_shipped hid pledges that
 * had a label but were not yet sent.
 *
 * LEFT JOIN to campaign_rewards keeps rows whose reward was later deleted.
 * LEFT JOIN to orders keeps pledges whose synthetic order row does not exist yet
 * (treated as not shipped). order_id is returned so the card can link to
 * order-view, where both the label and the status live.
 *
 * @return array<int,array{id:int,number:string,name:string,created_at:string,reward_title:?string,order_id:?int}>
 */
function pledges_awaiting_shipping(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT cp.id, cp.pledge_number AS number, cp.name, cp.created_at,
                cr.title AS reward_title, o.id AS order_id
         FROM campaign_pledges cp
         LEFT JOIN campaign_rewards cr ON cr.id = cp.reward_id
         LEFT JOIN orders o ON o.order_number = cp.pledge_number AND o.type = 'pledge'
         WHERE cp.payment_status = 'paid'
           AND (
                 cp.reward_id IS NOT NULL
              OR (cp.delivery_courier IS NOT NULL AND cp.delivery_courier <> '')
           )
           AND (o.id IS NULL OR o.status NOT IN ('shipped', 'delivered', 'cancelled'))
         ORDER BY cp.created_at ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
