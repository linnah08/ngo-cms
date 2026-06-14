<?php
/**
 * Campaign pledge checkout — validates form, inserts pledge, redirects to DSK Bank.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
start_session();

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /campaign/');
    exit;
}

if (!csrf_verify()) {
    http_response_code(400);
    exit('Invalid token');
}

// Campaign must be active
if (setting_get('campaign_active', '0') !== '1') {
    header('Location: /');
    exit;
}

$pdo    = get_pdo();
$errors = [];

// ── Detect pledge type ────────────────────────────────────────────────────────
$pledge_type_raw = trim($_POST['pledge_type'] ?? 'donation');
$pledge_type     = in_array($pledge_type_raw, ['donation', 'ticket'], true) ? $pledge_type_raw : 'donation';
$is_ticket       = $pledge_type === 'ticket';
$lang_raw        = trim($_POST['lang'] ?? 'bg');
$pledge_lang     = in_array($lang_raw, ['bg', 'en'], true) ? $lang_raw : 'bg';

// ── Validate inputs ───────────────────────────────────────────────────────────
$name      = trim($_POST['name']      ?? '');
$email     = trim($_POST['email']     ?? '');
$reward_id = (int)($_POST['reward_id'] ?? 0);

// For tickets, lock amount to configured price × qty; for donations, take POST value
if ($is_ticket) {
    $ev_price   = (float)(setting_get('event_ticket_price', '0') ?: '0');
    $ticket_qty = max(1, min(10, (int)($_POST['ticket_qty'] ?? 1)));
    $amount_eur = round($ev_price * $ticket_qty, 2);
    if ($ev_price < 1) {
        $_SESSION['campaign_error'] = 'Билетите не са конфигурирани. Моля, свържете се с нас.';
        header('Location: /campaign/');
        exit;
    }
} else {
    $ticket_qty = 1;
    $amount_eur = round((float)($_POST['amount_eur'] ?? 0), 2);
}

if ($name === '')                      $errors[] = 'Моля, въведете вашето име.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Моля, въведете валиден имейл адрес.';
if ($amount_eur < 1)                   $errors[] = 'Минималната сума е 1 EUR.';
if ($amount_eur > 100000)              $errors[] = 'Сумата е твърде голяма.';

// Validate reward exists if one was selected (donations only)
$reward = null;
if (!$is_ticket && $reward_id > 0) {
    $reward = $pdo->prepare("SELECT * FROM campaign_rewards WHERE id=? AND active=1");
    $reward->execute([$reward_id]);
    $reward = $reward->fetch();
    if (!$reward) {
        $reward_id = 0;
    }
}

// Delivery for reward pledges is handled at checkout step 2
$delivery_address  = null;
$delivery_courier  = null;
$delivery_type_val = null;
$office_code_val   = null;
$office_name_val   = null;
$office_city_val   = null;
$pledge_phone      = null;

if (!empty($errors)) {
    // Redirect back with error stored in session
    $_SESSION['campaign_error'] = implode(' ', $errors);
    header('Location: /campaign/');
    exit;
}

// ── Generate pledge number ────────────────────────────────────────────────────
function generate_pledge_number(): string {
    return 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

// ── Insert pledge ─────────────────────────────────────────────────────────────
try {
    $pledge_number = generate_pledge_number();
    $pdo->prepare("
        INSERT INTO campaign_pledges
            (pledge_number, pledge_type, lang, name, email, phone, amount_eur, ticket_qty, reward_id,
             delivery_address, delivery_courier, delivery_type, office_code, office_name, office_city,
             payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([
        $pledge_number,
        $pledge_type,
        $pledge_lang,
        $name,
        $email,
        $pledge_phone,
        $amount_eur,
        $ticket_qty,
        (!$is_ticket && $reward_id > 0) ? $reward_id : null,
        $delivery_address,
        $delivery_courier,
        $delivery_type_val,
        $office_code_val,
        $office_name_val,
        $office_city_val,
    ]);
    $pledge_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('campaign/checkout: insert pledge failed: ' . $e->getMessage());
    $_SESSION['campaign_error'] = 'Техническа грешка. Моля, опитайте отново.';
    header('Location: /campaign/');
    exit;
}

// ── Reward pledges: go to checkout step 2 for delivery selection ──────────────
if (!$is_ticket && $reward_id > 0) {
    $_SESSION['checkout_data'] = [
        'customer_name'  => $name,
        'customer_email' => $email,
        'customer_phone' => '',
        '_pledge_number' => $pledge_number,
        '_pledge_id'     => $pledge_id,
        '_pledge_amount' => $amount_eur,
    ];
    // Reward pledges use the standard shop checkout from step 1 so the
    // mandatory phone (and contact details) are collected the same way as
    // any order — step 2 then persists them onto the pledge.
    header('Location: /checkout/?step=1');
    exit;
}

// ── Register with DSK Bank ────────────────────────────────────────────────────
if (!DSKBankPayment::isEnabled()) {
    error_log('campaign/checkout: DSK Bank not configured');
    $_SESSION['campaign_error'] = 'Плащанията не са конфигурирани. Моля, свържете се с нас.';
    header('Location: /campaign/');
    exit;
}

try {
    $dsk       = new DSKBankPayment();
    $ref       = $pledge_number . '_' . time();
    $returnUrl = SITE_URL . '/api/campaign-payment-return.php?pledge=' . urlencode($pledge_number);
    $result    = $dsk->register($ref, $amount_eur, $returnUrl);

    $pdo->prepare("UPDATE campaign_pledges SET dsk_order_id=? WHERE id=?")
        ->execute([$result['dsk_order_id'], $pledge_id]);

    header('Location: ' . $result['formUrl']);
    exit;
} catch (Throwable $e) {
    error_log('campaign/checkout: DSK register failed: ' . $e->getMessage());
    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number) . '&err=' . urlencode($e->getMessage()));
    exit;
}
