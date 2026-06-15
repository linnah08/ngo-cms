<?php
/**
 * IRIS Pay by Bank integration (irispay.bg — "PayByClick").
 * Account-to-account (open banking) redirect payment.
 *
 * IRIS exposes NO status-query endpoint and NO callback signature, so we
 * authenticate the server-to-server callback ourselves with a per-order
 * capability token that travels only in the hookUrl (server→IRIS→our callback)
 * and is never exposed to the customer's browser. See api/iris-payment-callback.php.
 *
 * Requires DB settings: iris_merchant_key, iris_iban, iris_test_mode, iris_enabled
 */
class IRISPayment
{
    private string $merchantKey;
    private string $iban;
    private string $base;

    /** Currencies accepted by IRIS Pay. */
    const CURRENCIES = ['BGN', 'RON', 'EUR'];

    public function __construct()
    {
        $this->merchantKey = setting_get('iris_merchant_key', '');
        $this->iban        = setting_get('iris_iban', '');
        $test = setting_get('iris_test_mode', '1') === '1';
        $this->base = $test
            ? 'https://payperclick.infn.dev/backend/payment/external/'
            : 'https://paybyclick.irispay.bg/backend/payment/external/';
    }

    public static function isEnabled(): bool
    {
        return setting_get('iris_enabled', '0') === '1'
            && setting_is_set('iris_merchant_key')
            && setting_is_set('iris_iban');
    }

    /**
     * Initiate a payment with IRIS and return the URL to redirect the customer to.
     *
     * @param array $args [
     *     'currency'    => 'EUR'|'BGN'|'RON',
     *     'amountEur'   => float,                       // amount in the order currency
     *     'name'        => string,                      // <=34 chars, shown in the bank transfer description
     *     'description' => string,                      // <=240 chars, internal to IRIS
     *     'orderId'     => string,                      // our order_number
     *     'redirectUrl' => string,                      // browser return — MUST NOT carry the token
     *     'hookUrl'     => string,                      // server-to-server callback — carries the token
     *     'lang'        => 'bg'|'en',
     * ]
     * @return string  paymentLink to redirect the customer to
     * @throws RuntimeException on API or validation error
     */
    public function register(array $args): string
    {
        $currency = strtoupper((string)($args['currency'] ?? 'EUR'));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new RuntimeException('IRIS: unsupported currency ' . $currency);
        }

        $payload = [
            'currency'    => $currency,
            'name'        => mb_substr((string)($args['name'] ?? ''), 0, 34),
            'description' => mb_substr((string)($args['description'] ?? ''), 0, 240),
            'sum'         => number_format((float)$args['amountEur'], 2, '.', ''),
            'toIban'      => $this->iban,
            'orderId'     => (string)$args['orderId'],
            'redirectUrl' => $args['redirectUrl'],
            'hookUrl'     => $args['hookUrl'],
            'lang'        => in_array(($args['lang'] ?? 'bg'), ['bg', 'en'], true) ? $args['lang'] : 'bg',
        ];

        $resp = $this->post($this->base . $this->merchantKey, $payload);

        // IRIS returns {message: "..."} on error, {paymentLink: "..."} on success.
        if (!empty($resp['message'])) {
            throw new RuntimeException('IRIS: ' . $resp['message']);
        }
        if (empty($resp['paymentLink'])) {
            throw new RuntimeException('IRIS: no paymentLink in response — ' . json_encode($resp));
        }
        return $resp['paymentLink'];
    }

    // ── Internal ────────────────────────────────────────────────────────────────

    private function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
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
