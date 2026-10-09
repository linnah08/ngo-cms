<?php
/**
 * DSK Bank vPOS integration.
 * Uses the DSK Bank REST API (epg.dskbank.bg / uat.dskbank.bg).
 * Credentials are sent in each POST body — no session token.
 *
 * Requires DB settings: dsk_merchant, dsk_password, dsk_test_mode
 */
class DSKBankPayment
{
    private string $merchant;
    private string $password;
    private string $base;

    // Payment status codes returned by getOrderStatusExtended.do
    const STATUS_CREATED      = 0;
    const STATUS_APPROVED     = 1;   // 2-stage: pre-auth approved, not yet captured
    const STATUS_DEPOSITED    = 2;   // Payment captured / completed
    const STATUS_REVERSED     = 3;   // Cancelled before settlement — money never left the card
    const STATUS_REFUNDED     = 4;   // Money sent back after settlement
    const STATUS_AUTH_STARTED = 5;   // 3-D Secure check in progress
    const STATUS_DECLINED     = 6;

    public function __construct()
    {
        $this->merchant = setting_get('dsk_merchant', '');
        $this->password = setting_get('dsk_password', '');
        $test = setting_get('dsk_test_mode', '1') === '1';
        $this->base = $test
            ? 'https://uat.dskbank.bg/payment/rest/'
            : 'https://epg.dskbank.bg/payment/rest/';
    }

    public static function isEnabled(): bool
    {
        return setting_get('dsk_enabled', '0') === '1'
            && setting_is_set('dsk_merchant')
            && setting_is_set('dsk_password');
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    protected function post(string $endpoint, array $params): array
    {
        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('DSK Bank cURL error: ' . $error);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('DSK Bank invalid JSON: ' . $response);
        }
        return $data;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Register a payment session with DSK Bank.
     *
     * @param  string $orderNumber  Unique order reference (our order_number + timestamp)
     * @param  float  $amountEur    Amount in euros
     * @param  string $returnUrl    URL the bank redirects the customer to after payment
     * @return array  ['formUrl' => string, 'dsk_order_id' => string (UUID)]
     * @throws RuntimeException on API or auth error
     */
    public function register(string $orderNumber, float $amountEur, string $returnUrl): array
    {
        $data = $this->post('register.do', [
            'userName'    => $this->merchant,
            'password'    => $this->password,
            'amount'      => (int)round($amountEur * 100),   // cents
            'currency'    => '978',                           // EUR numeric code
            'orderNumber' => $orderNumber,
            'returnUrl'   => $returnUrl,
            'jsonParams'  => json_encode(['CMS' => 'ngo-cms']),
        ]);

        $code = (string)($data['errorCode'] ?? '');
        if ($code !== '' && $code !== '0') {
            throw new RuntimeException('DSK Bank: ' . ($data['errorMessage'] ?? json_encode($data)));
        }

        return [
            'formUrl'      => $data['formUrl'],
            'dsk_order_id' => $data['orderId'],
        ];
    }

    /**
     * Query the status of a payment by DSK Bank's internal order UUID.
     *
     * @param  string $dskOrderId  The UUID returned by register() / sent in callback
     * @return array  Full API response; key fields: orderStatus (int), authRefNum (string)
     */
    public function getStatus(string $dskOrderId): array
    {
        return $this->post('getOrderStatusExtended.do', [
            'userName' => $this->merchant,
            'password' => $this->password,
            'orderId'  => $dskOrderId,
        ]);
    }

    /**
     * Refund a completed payment (full or partial).
     *
     * @param  string     $dskOrderId  The UUID of the paid order
     * @param  float|null $amountEur   Amount to refund; null = full refund
     */
    public function refund(string $dskOrderId, ?float $amountEur = null): array
    {
        $params = [
            'userName' => $this->merchant,
            'password' => $this->password,
            'orderId'  => $dskOrderId,
        ];
        if ($amountEur !== null) {
            $params['amount'] = (int)round($amountEur * 100);
        }
        $data = $this->post('refund.do', $params);
        $code = (string)($data['errorCode'] ?? '');
        if ($code !== '' && $code !== '0') {
            throw new RuntimeException('DSK Bank refund error: ' . ($data['errorMessage'] ?? json_encode($data)));
        }
        return $data;
    }

    /**
     * Cancel a payment before the bank settles it (same day, or a pre-auth hold).
     */
    public function reverse(string $dskOrderId): array
    {
        $data = $this->post('reverse.do', [
            'userName' => $this->merchant,
            'password' => $this->password,
            'orderId'  => $dskOrderId,
        ]);
        $code = (string)($data['errorCode'] ?? '');
        if ($code !== '' && $code !== '0') {
            throw new RuntimeException('DSK Bank reverse error: ' . ($data['errorMessage'] ?? json_encode($data)));
        }
        return $data;
    }

    /**
     * Give a card payment back in full, whichever way the bank allows for its state,
     * and report what the bank says afterwards.
     *
     * The bank's status is the only thing trusted: DSK has answered a refund with an
     * error while still reversing the payment, so every call is followed by a fresh
     * status check, and only "reversed" or "refunded" counts as done.
     *
     * @return array{ok: bool, state: ?int, detail: string}
     *         ok    — the money is back with the customer (state 3 or 4)
     *         state — the bank's last reported orderStatus, null if it never answered
     *         detail — what was tried and what the bank said, for the admin error report
     */
    public function returnPayment(string $dskOrderId, float $amountEur): array
    {
        $log   = [];
        $state = null;
        $check = function () use ($dskOrderId, &$state, &$log): ?int {
            try {
                $status = $this->getStatus($dskOrderId);
                $state  = isset($status['orderStatus']) ? (int)$status['orderStatus'] : null;
                $log[]  = 'статус ' . self::stateLabel($state);
            } catch (Throwable $e) {
                $log[] = 'статус: ' . $e->getMessage();
            }
            return $state;
        };
        $try = function (string $what, callable $call) use (&$log): void {
            try {
                $call();
                $log[] = $what . ': OK';
            } catch (Throwable $e) {
                $log[] = $what . ': ' . $e->getMessage();
            }
        };
        $done = fn(?int $s): bool => $s === self::STATUS_REVERSED || $s === self::STATUS_REFUNDED;

        $before = $check();
        if (!$done($before)) {
            if ($before === self::STATUS_APPROVED) {
                $try('reverse', fn() => $this->reverse($dskOrderId));
                $check();
            } elseif ($before === self::STATUS_DEPOSITED) {
                $try('refund', fn() => $this->refund($dskOrderId, $amountEur));
                // Paid today and not settled yet: the bank only allows a reversal.
                if ($check() === self::STATUS_DEPOSITED) {
                    $try('reverse', fn() => $this->reverse($dskOrderId));
                    $check();
                }
            }
        }

        return ['ok' => $done($state), 'state' => $state, 'detail' => implode('; ', $log)];
    }

    /** Plain-language name of a DSK orderStatus, for messages to the admin. */
    public static function stateLabel(?int $state): string
    {
        return match ($state) {
            self::STATUS_CREATED      => 'неплатено',
            self::STATUS_APPROVED     => 'блокирана сума (неизтеглена)',
            self::STATUS_DEPOSITED    => 'платено',
            self::STATUS_REVERSED     => 'отменено (reversed)',
            self::STATUS_REFUNDED     => 'върнато (refunded)',
            self::STATUS_AUTH_STARTED => 'в процес на проверка',
            self::STATUS_DECLINED     => 'отказано',
            null                      => 'неизвестен',
            default                   => 'непознат (' . $state . ')',
        };
    }
}
