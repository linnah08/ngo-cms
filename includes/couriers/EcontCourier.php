<?php
/**
 * Econt courier integration.
 * Uses the standard Econt JSON API (ee.econt.com).
 * Credentials are passed in each request body — no session token needed.
 *
 * Credentials are resolved in order:
 *   1. Encrypted DB settings (admin panel)
 *   2. Constants from courier.config.php (CLI / tests)
 */
class EcontCourier
{
    private string $base;
    private array  $creds;

    public function __construct()
    {
        $settingsAvailable = function_exists('setting_resolve');

        $user = $settingsAvailable
            ? setting_resolve('econt_user', 'ECONT_USER')
            : (defined('ECONT_USER') ? ECONT_USER : '');

        $pass = $settingsAvailable
            ? setting_resolve('econt_pass', 'ECONT_PASS')
            : (defined('ECONT_PASS') ? ECONT_PASS : '');

        $test = $settingsAvailable
            ? setting_resolve_bool('econt_test_mode', 'ECONT_TEST_MODE', true)
            : (defined('ECONT_TEST_MODE') ? ECONT_TEST_MODE : true);

        $this->base  = $test
            ? 'https://demo.econt.com/services/'
            : 'https://ee.econt.com/services/';
        $this->creds = ['username' => $user, 'password' => $pass];
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    private function post(string $endpoint, array $body): array
    {
        $body['credentials'] = $this->creds;

        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Econt cURL error: ' . $error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Econt invalid JSON response: ' . $response);
        }

        if (!empty($data['type']) || !empty($data['innerExceptions'])) {
            $msg = $data['message'] ?? ($data['innerExceptions'][0]['message'] ?? 'Unknown Econt error');
            throw new RuntimeException('Econt API error: ' . $msg);
        }

        return $data;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * List Econt offices / APT points.
     *
     * @param  string $city  Optional city name filter (BG name, e.g. "София")
     * @return array  [ ['id', 'name', 'city', 'address', 'phone', 'work_hours'], ... ]
     */
    public function getOffices(string $city = ''): array
    {
        $cacheFile = sys_get_temp_dir() . '/ngo_econt_offices.json';
        $cacheTtl  = 86400;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $data = json_decode(file_get_contents($cacheFile), true) ?: [];
        } else {
            $data = $this->post('Nomenclatures/NomenclaturesService.getOffices.json', []);
            file_put_contents($cacheFile, json_encode($data));
        }

        $offices = [];
        foreach ($data['offices'] ?? [] as $o) {
            // Skip non-Bulgarian offices
            if (($o['address']['city']['country']['code3'] ?? '') !== 'BGR') continue;

            $officeCityName = $o['address']['city']['name'] ?? '';

            // Filter by city locally (API doesn't support server-side city filtering)
            if ($city !== '' && mb_strtolower($officeCityName) !== mb_strtolower($city)) continue;

            // isMPS = Multi-Parcel Station, isAPS = Automated Parcel Station — both are APT machines
            $isApt = !empty($o['isMPS']) || !empty($o['isAPS']);

            $offices[] = [
                'id'      => $o['code'] ?? '',
                'name'    => $o['name'] ?? '',
                'city'    => $officeCityName,
                'address' => $o['address']['fullAddress'] ?? '',
                'type'    => $isApt ? 'apt' : 'office',
            ];
        }

        return $offices;
    }

    /**
     * List all cities in Bulgaria where Econt has offices.
     *
     * @return string[]  Sorted city names in Bulgarian
     */
    public function getCities(): array
    {
        $data = $this->post(
            'Nomenclatures/NomenclaturesService.getCities.json',
            ['countryCode' => 'BGR']
        );
        $cities = [];
        foreach ($data['cities'] ?? [] as $c) {
            $name = $c['name'] ?? '';
            if ($name !== '') $cities[] = $name;
        }
        sort($cities, SORT_LOCALE_STRING);
        return array_values(array_unique($cities));
    }

    /**
     * Calculate shipping price.
     *
     * @param  array  $parcel       ['weight' => float (kg), 'pack_count' => int]
     * @param  string $toCity       Destination city name (BG)
     * @param  string $deliveryType 'door' | 'office'
     * @return float  Price in EUR (Econt API returns EUR directly for this account)
     */
    public function calculateShipping(array $parcel, string $toCity, string $deliveryType): float
    {
        $s = function_exists('setting_resolve');

        // For office/APT delivery, pass the officeCode so Econt returns the correct
        // (lower) office rate rather than the door-to-door rate.
        $receiverAddress = ['city' => ['name' => $toCity]];
        $officeId = isset($parcel['office_id']) && $parcel['office_id'] !== '' ? (string)$parcel['office_id'] : null;
        if ($officeId !== null && in_array($deliveryType, ['office', 'apt'])) {
            $receiverAddress['officeCode'] = $officeId;
        }

        $data = $this->post(
            'Shipments/LabelService.createLabel.json',
            [
                'label' => [
                    'shipmentType'    => 'pack',
                    'weight'          => (float)($parcel['weight'] ?? 1),
                    'packCount'       => (int)($parcel['pack_count'] ?? 1),
                    'senderAddress'   => [
                        'city' => ['name' => $s ? setting_resolve('sender_city', 'SENDER_CITY') : (defined('SENDER_CITY') ? SENDER_CITY : '')],
                    ],
                    'receiverAddress' => $receiverAddress,
                ],
                'mode' => 'calculate',
            ]
        );

        return round((float)($data['label']['totalPrice'] ?? $data['totalPrice'] ?? 0.0), 2);
    }

    /**
     * Create a shipment (товарителница).
     *
     * @param  array $order {
     *   'weight'           => float,
     *   'pack_count'       => int,
     *   'description'      => string,
     *   'receiver_name'    => string,
     *   'receiver_phone'   => string,
     *   'receiver_city'    => string,
     *   'receiver_address' => string,
     *   'receiver_office'  => string|null,  // office code if deliveryType=office
     *   'delivery_type'    => 'door'|'office',
     *   'cod'              => float|null,   // cash-on-delivery amount, or null
     * }
     * @return array ['shipment_number' => string, 'price' => float, 'raw' => array]
     */
    public function createShipment(array $order): array
    {
        $s = function_exists('setting_resolve');
        $senderName    = $s ? setting_resolve('sender_name',    'SENDER_NAME')    : (defined('SENDER_NAME')    ? SENDER_NAME    : '');
        $senderPhone   = $s ? setting_resolve('sender_phone',   'SENDER_PHONE')   : (defined('SENDER_PHONE')   ? SENDER_PHONE   : '');
        $senderCity    = $s ? setting_resolve('sender_city',    'SENDER_CITY')    : (defined('SENDER_CITY')    ? SENDER_CITY    : '');
        $senderAddress = $s ? setting_resolve('sender_address', 'SENDER_ADDRESS') : (defined('SENDER_ADDRESS') ? SENDER_ADDRESS : '');

        $shipment = [
            'weightDeclared'  => (float)($order['weight'] ?? 1),
            'packCount'       => (int)($order['pack_count'] ?? 1),
            'shipmentDescription' => $order['description'] ?? '',
            'deliveryType'    => $order['delivery_type'] ?? 'door',
            'senderClient'    => [
                'name'   => $senderName,
                'phones' => [['phone' => $senderPhone]],
            ],
            'senderAddress'   => [
                'city'   => ['name' => $senderCity],
                'street' => $senderAddress,
            ],
            'receiverClient'  => [
                'name'   => $order['receiver_name'] ?? '',
                'phones' => [['phone' => $order['receiver_phone'] ?? '']],
            ],
        ];

        if (($order['delivery_type'] ?? 'door') === 'office') {
            $shipment['receiverAddress'] = [
                'city'       => ['name' => $order['receiver_city'] ?? ''],
                'officeCode' => $order['receiver_office'] ?? '',
            ];
        } else {
            $shipment['receiverAddress'] = [
                'city'   => ['name' => $order['receiver_city'] ?? ''],
                'street' => $order['receiver_address'] ?? '',
            ];
        }

        if (!empty($order['cod'])) {
            $shipment['paymentReceiverMethod'] = 'cash';
            $shipment['amountCOD']             = (float)$order['cod'];
        }

        $data = $this->post(
            'Shipments/ShipmentsService.createShipments.json',
            ['shipments' => [$shipment]]
        );

        $created = $data['shipments'][0] ?? [];

        return [
            'shipment_number' => $created['shipmentNumber'] ?? '',
            'price'           => (float)($created['price']['totalPrice'] ?? 0),
            'raw'             => $created,
        ];
    }

    /**
     * Get tracking status for a shipment number.
     *
     * @param  string $trackingNumber  Econt shipment number
     * @return array  ['status' => string, 'status_code' => int, 'events' => array, 'raw' => array]
     */
    public function getTracking(string $trackingNumber): array
    {
        $data = $this->post(
            'Shipments/ShipmentsService.getShipmentStatuses.json',
            ['shipmentNumbers' => [$trackingNumber]]
        );

        $status = $data['shipmentStatuses'][0] ?? [];

        return [
            'status'      => $status['shipmentStatus']['label'] ?? '',
            'status_code' => $status['shipmentStatus']['id']    ?? 0,
            'events'      => $status['statusLog']               ?? [],
            'raw'         => $status,
        ];
    }
}
