<?php
/**
 * IRIS Pay by Bank integration (irispay.bg — "PayByClick / PayByLink").
 * Account-to-account (open banking) redirect payment.
 *
 * Verification model: IRIS fires the hookUrl callback with only ?status=...,
 * so we identify the order from our own hookUrl params and then RE-QUERY the
 * authoritative payment status server-to-server via getStatus() — the callback
 * status param itself is never trusted. A per-order capability token in the
 * hookUrl additionally authenticates the caller. See api/iris-payment-callback.php.
 *
 * API ref: PayByLink_QR_v.3.5.3.
 * Requires DB settings: iris_merchant_key, iris_iban, iris_test_mode, iris_enabled
 */
class IRISPayment
{
    private string $merchantKey;
    private string $iban;
    private string $host;

    /** Currencies accepted by IRIS Pay (v3.5.3). */
    const CURRENCIES = ['EUR', 'RON'];

    public function __construct()
    {
        $this->merchantKey = setting_get('iris_merchant_key', '');
        $this->iban        = setting_get('iris_iban', '');
        $test = setting_get('iris_test_mode', '1') === '1';
        // Per the v3.5.3 doc: dev.paybyclick.irispay.bg (test) / paybyclick.irispay.bg (prod).
        $this->host = $test
            ? 'https://dev.paybyclick.irispay.bg'
            : 'https://paybyclick.irispay.bg';
    }

    public static function isEnabled(): bool
    {
        return setting_get('iris_enabled', '0') === '1'
            && setting_is_set('iris_merchant_key')
            && setting_is_set('iris_iban');
    }

    public function iban(): string
    {
        return $this->iban;
    }

    /**
     * Generate a payment link.
     *
     * @param array $args [
     *     'currency'    => 'EUR'|'RON',
     *     'amountEur'   => float,                       // amount in the order currency
     *     'name'        => string,                      // <=34 chars, shown in the bank transfer description
     *     'description' => string,                      // <=240 chars, internal to IRIS
     *     'orderId'     => string,                      // our order_number
     *     'redirectUrl' => string,                      // browser return — MUST NOT carry the token
     *     'hookUrl'     => string,                      // server-to-server callback — carries the token
     *     'lang'        => 'bg'|'en'|'ro'|'el'|'hr',
     * ]
     * @return array ['paymentLink' => string, 'paymentHash' => string]
     * @throws RuntimeException on API or validation error
     */
    public function register(array $args): array
    {
        $currency = strtoupper((string)($args['currency'] ?? 'EUR'));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new RuntimeException('IRIS: unsupported currency ' . $currency);
        }

        $payload = [
            'currency'    => $currency,
            'name'        => mb_substr((string)($args['name'] ?? ''), 0, 34),
            'description' => mb_substr((string)($args['description'] ?? ''), 0, 240),
            'sum'         => round((float)$args['amountEur'], 2),
            'toIban'      => $this->iban,
            'orderId'     => (string)$args['orderId'],
            'redirectUrl' => $args['redirectUrl'],
            'hookUrl'     => $args['hookUrl'],
            'lang'        => in_array(($args['lang'] ?? 'bg'), ['bg', 'en', 'ro', 'el', 'hr'], true) ? $args['lang'] : 'bg',
        ];

        $resp = $this->request('POST', '/backend/payment/external/' . $this->merchantKey, $payload);

        // IRIS returns {message: "..."} on error, {paymentLink, paymentHash, ...} on success.
        if (!empty($resp['message'])) {
            throw new RuntimeException('IRIS: ' . $resp['message']);
        }
        if (empty($resp['paymentLink']) || empty($resp['paymentHash'])) {
            throw new RuntimeException('IRIS: unexpected register response — ' . json_encode($resp));
        }
        return [
            'paymentLink' => $resp['paymentLink'],
            'paymentHash' => $resp['paymentHash'],
        ];
    }

    /**
     * Authoritative payment status by paymentHash.
     * Auth is the unguessable paymentHash itself (no merchant key required).
     *
     * @return array Decoded response; key fields: status (WAITING|FAILED|CONFIRMED),
     *               sum (float), receiverIban (string), orderId (string).
     * @throws RuntimeException on API error
     */
    public function getStatus(string $paymentHash): array
    {
        return $this->request('GET', '/backend/payment/status/' . rawurlencode($paymentHash));
    }

    // ── Internal ────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init($this->host . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('IRIS cURL error: ' . $error);
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('IRIS invalid JSON: ' . $response);
        }
        return $data;
    }
}
