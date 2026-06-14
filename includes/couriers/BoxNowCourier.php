<?php
/**
 * BoxNow courier integration.
 * Uses the BoxNow REST API v1 with OAuth2 client_credentials auth.
 * A short-lived access token is fetched automatically and cached per request.
 *
 * Requires courier.config.php to define:
 *   BOXNOW_CLIENT_ID, BOXNOW_CLIENT_SECRET,
 *   BOXNOW_PARTNER_ID, BOXNOW_WAREHOUSE_ID,
 *   BOXNOW_TEST_MODE
 */
class BoxNowCourier
{
    private string $base;
    private ?string $accessToken = null;

    public function __construct()
    {
        $test = function_exists('setting_resolve_bool')
            ? setting_resolve_bool('boxnow_test_mode', 'BOXNOW_TEST_MODE', true)
            : (defined('BOXNOW_TEST_MODE') ? BOXNOW_TEST_MODE : true);

        $this->base = $test
            ? 'https://api-stage.boxnow.bg'
            : 'https://api-production.boxnow.bg';
    }

    // ── Auth ───────────────────────────────────────────────────────────────────

    private function getToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $s        = function_exists('setting_resolve');
        $clientId = $s ? setting_resolve('boxnow_client_id',     'BOXNOW_CLIENT_ID')     : (defined('BOXNOW_CLIENT_ID')     ? BOXNOW_CLIENT_ID     : '');
        $secret   = $s ? setting_resolve('boxnow_client_secret', 'BOXNOW_CLIENT_SECRET') : (defined('BOXNOW_CLIENT_SECRET') ? BOXNOW_CLIENT_SECRET : '');

        $ch = curl_init($this->base . '/api/v1/auth-sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $secret,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('BoxNow auth cURL error: ' . $error);
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('BoxNow auth failed: ' . $response);
        }

        $this->accessToken = $data['access_token'];
        return $this->accessToken;
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = []): array
    {
        $token = $this->getToken();
        $url   = $this->base . $path;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $body ? json_encode($body) : '{}';
        } elseif ($method === 'GET' && $body) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($body));
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('BoxNow cURL error: ' . $error);
        }

        // 204 No Content (e.g. cancel) — treat as success
        if ($httpCode === 204 || $response === '') {
            return ['success' => true];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('BoxNow invalid JSON response: ' . $response);
        }

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['error'] ?? "HTTP $httpCode");
            throw new RuntimeException('BoxNow API error: ' . $msg . ' — ' . $response);
        }

        return $data;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Normalize a Bulgarian phone number to E.164 (+359XXXXXXXXX).
     * Strips all separators (spaces, dashes, parens, dots) — BoxNow rejects
     * anything that isn't clean E.164 with error P405.
     * Handles: 08XXXXXXXX, 8XXXXXXXX, 00359…, 359…, +3598XXXXXXXX
     */
    private function normalizePhone(string $phone): string
    {
        $hasPlus = str_starts_with(ltrim($phone), '+');
        $digits  = preg_replace('/\D+/', '', $phone);            // keep digits only

        if ($digits === '')                     return '';
        if ($hasPlus)                           return '+' . $digits;       // already international
        if (str_starts_with($digits, '00'))     return '+' . substr($digits, 2);
        if (str_starts_with($digits, '359'))    return '+' . $digits;       // 359… without +
        if (str_starts_with($digits, '0'))      return '+359' . substr($digits, 1);
        return '+359' . $digits;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * List BoxNow APM lockers.
     * Data is fetched from BoxNow's public static location API (no auth required).
     *
     * @param  string $city  Optional city name filter (case-insensitive substring match)
     * @return array  [ ['id', 'name', 'city', 'address', 'lat', 'lng'], ... ]
     */
    public function getOffices(string $city = ''): array
    {
        $ch = curl_init('https://locationapi-production.boxnow.bg/v1/apms_bg-BG.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('BoxNow cURL error: ' . $curlErr);
        }
        if ($httpCode >= 400) {
            throw new RuntimeException('BoxNow API error: HTTP ' . $httpCode);
        }

        $data = json_decode((string)$response, true);

        $lockers = [];
        foreach ($data['data'] ?? [] as $l) {
            if (!is_array($l) || empty($l['id'])) continue;

            $lockerCity = $l['city'] ?? ($l['addressLine2'] ?? '');
            if ($city !== '' && stripos($lockerCity, $city) === false) {
                continue;
            }

            $lockers[] = [
                'id'      => (string)$l['id'],
                'name'    => $l['name']         ?? '',
                'city'    => $lockerCity,
                'address' => $l['addressLine1'] ?? '',
                'lat'     => $l['latitude']     ?? ($l['lat'] ?? null),
                'lng'     => $l['longitude']    ?? ($l['lng'] ?? null),
            ];
        }

        return $lockers;
    }

    /**
     * Calculate shipping price.
     * BoxNow uses flat-rate tiers by weight — there is no dedicated calculate endpoint.
     * This method applies the standard weight-based pricing tiers.
     *
     * @param  array  $parcel        ['weight' => float (kg)]
     * @param  string $toCity        Ignored (BoxNow is flat-rate nationwide)
     * @param  string $deliveryType  Ignored (always APM locker)
     * @return float  Price in BGN
     */
    public function calculateShipping(array $parcel, string $toCity, string $deliveryType): float
    {
        $weight = (float)($parcel['weight'] ?? 0);

        // Weight-based tiers — configurable via admin/couriers.php (settings table)
        $t1 = function_exists('setting_get') ? (float) setting_get('boxnow_rate_tier1', '3.99') : 3.99;
        $t2 = function_exists('setting_get') ? (float) setting_get('boxnow_rate_tier2', '4.99') : 4.99;
        $t3 = function_exists('setting_get') ? (float) setting_get('boxnow_rate_tier3', '5.99') : 5.99;
        $t4 = function_exists('setting_get') ? (float) setting_get('boxnow_rate_tier4', '7.99') : 7.99;

        if ($weight <= 3)  return $t1;
        if ($weight <= 6)  return $t2;
        if ($weight <= 10) return $t3;
        return $t4;  // up to 20 kg
    }

    /**
     * Create a delivery request (товарителница).
     *
     * @param  array $order {
     *   'weight'            => float (kg),
     *   'description'       => string,
     *   'order_number'      => string,
     *   'receiver_name'     => string,
     *   'receiver_phone'    => string,
     *   'receiver_email'    => string,
     *   'locker_id'         => string|int,  // BoxNow APM locker ID
     *   'compartment_size'  => int,         // 1=small, 2=medium, 3=large (default 1)
     * }
     * @return array ['parcel_id' => string, 'delivery_id' => string, 'raw' => array]
     */
    public function createShipment(array $order): array
    {
        $s = function_exists('setting_resolve');

        $warehouseId = $s
            ? setting_resolve('boxnow_warehouse_id', 'BOXNOW_WAREHOUSE_ID')
            : (defined('BOXNOW_WAREHOUSE_ID') ? BOXNOW_WAREHOUSE_ID : '');

        $senderPhone = $s
            ? setting_resolve('sender_phone', 'SENDER_PHONE')
            : (defined('SENDER_PHONE') ? SENDER_PHONE : '');

        if ($this->normalizePhone($order['receiver_phone'] ?? '') === '') {
            throw new RuntimeException('Липсва телефон на получателя — добавете телефонен номер, преди да издадете товарителница.');
        }

        $body = [
            'notifyOnAccepted'    => '',
            'orderNumber'         => $order['order_number'] ?? '',
            'invoiceValue'        => '0',
            'paymentMode'         => 'prepaid',
            'amountToBeCollected' => '0',
            'allowReturn'         => true,
            'origin'      => [
                'contactNumber' => $this->normalizePhone($senderPhone),
                'contactEmail'  => defined('SITE_EMAIL') ? SITE_EMAIL : '',
                'locationId'    => (string)$warehouseId,
            ],
            'destination' => [
                'contactNumber' => $this->normalizePhone($order['receiver_phone'] ?? ''),
                'contactEmail'  => $order['receiver_email'] ?? '',
                'contactName'   => $order['receiver_name']  ?? '',
                'locationId'    => (string)($order['locker_id'] ?? ''),
            ],
            'items' => [[
                'value'           => '0',
                'weight'          => (float)($order['weight'] ?? 1),
                'compartmentSize' => (int)($order['compartment_size'] ?? 1),
            ]],
            'additionalInformation' => $order['description'] ?? '',
        ];

        $data = $this->request('POST', '/api/v1/delivery-requests', $body);

        return [
            'parcel_id'   => (string)($data['parcels'][0]['id'] ?? ''),
            'delivery_id' => (string)($data['id'] ?? ''),
            'raw'         => $data,
        ];
    }

    /**
     * Get tracking status for a parcel.
     *
     * @param  string $trackingNumber  BoxNow parcel ID
     * @return array  ['status' => string, 'delivered' => bool, 'events' => array, 'raw' => array]
     */
    public function getTracking(string $trackingNumber): array
    {
        $data = $this->request('GET', '/api/v1/parcels/' . urlencode($trackingNumber));

        $status = $data['status'] ?? ($data['parcelStatus'] ?? '');

        return [
            'status'    => $status,
            'delivered' => strtoupper($status) === 'DELIVERED',
            'events'    => $data['statusHistory'] ?? [],
            'raw'       => $data,
        ];
    }

    /**
     * Cancel a parcel.
     *
     * @param  string $parcelId  BoxNow parcel ID
     * @return bool
     */
    public function cancelShipment(string $parcelId): bool
    {
        try {
            $this->request('POST', '/api/v1/parcels/' . urlencode($parcelId) . ':cancel');
            return true;
        } catch (RuntimeException $e) {
            error_log('BoxNow cancel error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the PDF label URL for a parcel (requires valid access token).
     * Caller is responsible for streaming the response.
     *
     * @param  string $parcelId
     * @return string  URL to fetch label PDF with Bearer token
     */
    public function getLabelUrl(string $parcelId): string
    {
        return $this->base . '/api/v1/parcels/' . urlencode($parcelId) . '/label.pdf';
    }

    /**
     * Return the current access token (needed to fetch the label PDF).
     */
    public function getAccessToken(): string
    {
        return $this->getToken();
    }
}
