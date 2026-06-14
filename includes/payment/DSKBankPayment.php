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
    const STATUS_CREATED   = 0;
    const STATUS_APPROVED  = 1;   // 2-stage: pre-auth approved, not yet captured
    const STATUS_DEPOSITED = 2;   // Payment captured / completed
    const STATUS_DECLINED  = 3;
    const STATUS_REVERSED  = 4;   // Refunded / reversed

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

    private function post(string $endpoint, array $params): array
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
            'jsonParams'  => json_encode(['CMS' => 'oddminds-custom-php']),
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
}
