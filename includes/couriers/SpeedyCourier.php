<?php
/**
 * Speedy courier integration.
 * Uses the Speedy REST API v1 (api.speedy.bg).
 * Credentials are passed in each request body — no session needed.
 *
 * Requires courier.config.php to define:
 *   SPEEDY_USER, SPEEDY_PASS, SPEEDY_CLIENT_ID, SPEEDY_TEST_MODE
 */
require_once __DIR__ . '/courier_phone.php';

class SpeedyCourier
{
    private string $base;
    private array  $auth;

    // Common Speedy service IDs (Bulgaria)
    const SERVICE_STANDARD = 505;   // Standard (1–2 days), used for all domestic delivery types
    const SERVICE_EXPRESS  = 506;   // Express (next day)

    public function __construct()
    {
        $s = function_exists('setting_resolve');

        $user = $s ? setting_resolve('speedy_user', 'SPEEDY_USER') : (defined('SPEEDY_USER') ? SPEEDY_USER : '');
        $pass = $s ? setting_resolve('speedy_pass', 'SPEEDY_PASS') : (defined('SPEEDY_PASS') ? SPEEDY_PASS : '');
        $test = $s ? setting_resolve_bool('speedy_test_mode', 'SPEEDY_TEST_MODE', true) : (defined('SPEEDY_TEST_MODE') ? SPEEDY_TEST_MODE : true);

        $this->base = $test
            ? 'https://test.api.speedy.bg/v1/'
            : 'https://api.speedy.bg/v1/';

        $this->auth = [
            'userName' => $user,
            'password' => $pass,
            'language' => 'BG',
        ];
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    private function post(string $endpoint, array $body): array
    {
        $payload = array_merge($this->auth, $body);

        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Speedy cURL error: ' . $error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Speedy invalid JSON response: ' . $response);
        }

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ($data['message'] ?? "HTTP $httpCode");
            throw new RuntimeException('Speedy API error: ' . $msg);
        }

        return $data;
    }

    private function postRaw(string $endpoint, array $body): string
    {
        $payload = array_merge($this->auth, $body);

        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                // No Accept header — lets the server return binary PDF without triggering 406
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Speedy cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            $data = json_decode((string)$response, true);
            $msg  = $data['error']['message'] ?? ($data['message'] ?? "HTTP $httpCode");
            throw new RuntimeException('Speedy print error: ' . $msg);
        }

        return (string)$response;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * List Speedy offices / APT lockers.
     *
     * @param  string $city  Optional city name filter (BG name)
     * @return array  [ ['id', 'name', 'city', 'address', 'type'], ... ]
     *                type: 'office' | 'apt'
     */
    public function getOffices(string $city = ''): array
    {
        if ($city !== '') {
            // A city name may match multiple Speedy sites (e.g. 5 villages named "Лозен").
            // Fetch offices for every matching siteId and merge, so we don't miss the right one.
            $siteIds = $this->resolveSiteIds($city);
            if (empty($siteIds)) {
                return [];
            }
            $raw = [];
            foreach ($siteIds as $siteId) {
                $data = $this->post('location/office', ['countryId' => 100, 'siteId' => $siteId]);
                foreach ($data['offices'] ?? [] as $o) {
                    $raw[$o['id'] ?? uniqid()] = $o;  // deduplicate by office id
                }
            }
        } else {
            $data = $this->post('location/office', ['countryId' => 100]);
            $raw  = [];
            foreach ($data['offices'] ?? [] as $o) {
                $raw[$o['id'] ?? uniqid()] = $o;
            }
        }

        $offices = [];
        foreach ($raw as $o) {
            $addr = $o['address'] ?? [];
            $offices[] = [
                'id'      => $o['id'] ?? '',
                'name'    => $o['name'] ?? '',
                'city'    => is_array($addr) ? ($addr['siteName'] ?? '') : '',
                'address' => is_array($addr) ? ($addr['fullAddressString'] ?? $addr['localAddressString'] ?? '') : (string)$addr,
                'type'    => strtolower($o['type'] ?? 'office') === 'apt' ? 'apt' : 'office',
            ];
        }

        return $offices;
    }

    /**
     * List all cities/sites in Bulgaria where Speedy has offices.
     * NOTE: the bulk endpoint returns a limited set; prefer searchCities() for autocomplete.
     *
     * @return string[]  Sorted city names in Bulgarian
     */
    public function getCities(): array
    {
        $data = $this->post('location/site', ['countryId' => 100]);
        $cities = [];
        foreach ($data['sites'] ?? [] as $s) {
            $name = $s['name'] ?? '';
            if ($name !== '') $cities[] = $name;
        }
        sort($cities, SORT_LOCALE_STRING);
        return array_values(array_unique($cities));
    }

    /**
     * Search for cities/sites by partial name — uses the Speedy name-search endpoint
     * which returns many more results than the bulk list (includes villages, etc.).
     *
     * @param  string $query  Partial city name (min 2 chars recommended)
     * @return string[]  Unique matching city names, sorted
     */
    public function searchCities(string $query): array
    {
        if (mb_strlen(trim($query)) < 2) return [];
        try {
            $data = $this->post('location/site', ['countryId' => 100, 'name' => $query]);
        } catch (RuntimeException) {
            return [];
        }
        $cities = [];
        foreach ($data['sites'] ?? [] as $s) {
            $name = $s['name'] ?? '';
            if ($name !== '') $cities[] = $name;
        }
        sort($cities, SORT_LOCALE_STRING);
        return array_values(array_unique($cities));
    }

    /**
     * Resolve a city name to all matching Speedy siteIds.
     * Multiple villages can share the same name (e.g. "Лозен" exists in 5 Bulgarian municipalities).
     *
     * @return int[]
     */
    private function resolveSiteIds(string $cityName): array
    {
        try {
            return $this->findSiteIds($cityName);
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Like resolveSiteIds(), but a failed API call throws instead of looking
     * like "no such town", so callers can tell an unknown town from Speedy
     * being unreachable.
     *
     * @return int[]
     */
    private function findSiteIds(string $cityName): array
    {
        ['name' => $name, 'postCode' => $postCode] = self::parseCityInput($cityName);
        if ($name === '') {
            return [];
        }
        // The postcode picks the right one of several same-named villages;
        // if it doesn't match anything, fall back to the name alone.
        if ($postCode !== null) {
            $ids = $this->siteIdsFor(['name' => $name, 'postCode' => $postCode]);
            if ($ids) {
                return $ids;
            }
        }
        return $this->siteIdsFor(['name' => $name]);
    }

    /** @return int[] */
    private function siteIdsFor(array $query): array
    {
        $data = $this->post('location/site', ['countryId' => 100] + $query);
        if (!empty($data['error'])) {
            throw new RuntimeException('Speedy API error: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }
        $ids = [];
        foreach ($data['sites'] ?? [] as $s) {
            if (isset($s['id'])) {
                $ids[] = (int)$s['id'];
            }
        }
        return $ids;
    }

    /**
     * Split what was typed in a town field into the name Speedy searches by and
     * the postcode, if there is one. Browser autofill often fills in the full
     * form "гр. СОФИЯ (1000), общ. СТОЛИЧНА, обл. СОФИЯ (СТОЛИЦА)", and
     * Speedy's site search matches neither that nor a "гр." / "с." prefix.
     *
     * @return array{name: string, postCode: ?string}
     */
    public static function parseCityInput(string $input): array
    {
        $first    = explode(',', $input, 2)[0];
        $postCode = preg_match('/(?<!\d)(\d{4})(?!\d)/u', $first, $m) ? $m[1] : null;

        $name = preg_replace('/\([^)]*\)/u', ' ', $first);
        $name = preg_replace('/(?<!\d)\d{4}(?!\d)/u', ' ', $name);
        $name = preg_replace('/^\s*(?:(?:гр|с)\.|(?:гр|град|с|село)\s)\s*/iu', '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return ['name' => $name, 'postCode' => $postCode];
    }

    /**
     * Whether Speedy knows the town typed at checkout.
     * Returns null when Speedy can't be reached, so an outage doesn't block checkout.
     */
    public function isKnownTown(string $cityName): ?bool
    {
        try {
            return $this->findSiteIds($cityName) !== [];
        } catch (RuntimeException) {
            return null;
        }
    }

    /** Error for a door-delivery town Speedy doesn't recognise. */
    private static function unknownTownError(string $cityName): RuntimeException
    {
        return new RuntimeException('Населеното място „' . trim($cityName) . '“ не е намерено в Speedy. Провери града в адреса на поръчката.');
    }

    /**
     * Calculate shipping price.
     *
     * @param  array  $parcel {
     *   'weight'      => float (kg),
     *   'pack_count'  => int,
     *   'service_id'  => int|null  (defaults to SERVICE_STANDARD)
     * }
     * @param  string $toCity        Destination city name (BG)
     * @param  string $deliveryType  'door' | 'office' | 'apt'
     * @return float  Price in EUR including VAT (API returns EUR or BGN; BGN is converted at fixed peg 1.95583)
     */
    public function calculateShipping(array $parcel, string $toCity, string $deliveryType): float
    {
        $serviceId = $parcel['service_id'] ?? self::SERVICE_STANDARD;
        $officeId  = isset($parcel['office_id']) && $parcel['office_id'] !== '' ? (int)$parcel['office_id'] : null;

        // For office/APT delivery the recipient is identified by pickupOfficeId, not an address.
        // Service 505 (Standard) is used for all domestic delivery types.
        if ($officeId !== null) {
            $recipient = [
                'privatePerson'  => true,
                'pickupOfficeId' => $officeId,
            ];
        } else {
            $siteId = $this->findSiteIds($toCity)[0] ?? null;
            if ($siteId === null) {
                throw self::unknownTownError($toCity);
            }
            $recipient = [
                'privatePerson'   => true,
                'addressLocation' => ['countryId' => 100, 'siteId' => $siteId],
            ];
        }

        $clientId = (int)(function_exists('setting_resolve') ? setting_resolve('speedy_client_id', 'SPEEDY_CLIENT_ID', '0') : (defined('SPEEDY_CLIENT_ID') ? SPEEDY_CLIENT_ID : 0));
        $data = $this->post('calculate/', [
            'sender'    => [
                'clientId' => $clientId,
            ],
            'recipient' => $recipient,
            'service'   => [
                'serviceIds'       => [$serviceId],
                'pickupDate'       => date('Y-m-d', strtotime('next Monday +1 week')),
                'saturdayDelivery' => false,
            ],
            'content'   => [
                'parcelsCount' => (int)($parcel['pack_count'] ?? 1),
                'totalWeight'  => (float)($parcel['weight'] ?? 1),
                'documents'    => false,
            ],
            'payment'   => [
                'courierServicePayer' => 'SENDER',
            ],
        ]);

        // Surface API-level errors (e.g. invalid sender client ID)
        if (!empty($data['error'])) {
            throw new RuntimeException('Speedy calculate error: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }
        if (empty($data['calculations'])) {
            throw new RuntimeException('Speedy calculate: no calculations returned');
        }
        $calculation = $data['calculations'][0];
        if (!empty($calculation['error'])) {
            throw new RuntimeException('Speedy calculate error: ' . ($calculation['error']['message'] ?? json_encode($calculation['error'])));
        }
        return self::priceEur($calculation['price'] ?? []);
    }

    /**
     * What the customer pays for shipping: Speedy's price WITH VAT, in EUR.
     *
     * Speedy bills the sender the price plus 20% VAT, which an organisation
     * that isn't VAT-registered can't reclaim — charging the net `amount`
     * left every Speedy order ~20% short.
     *
     * Pure: no API — extracted so the price choice is unit-testable.
     *
     * @param  array $price Speedy calculation `price` object (amount, vat, total, currency)
     * @throws RuntimeException when the reply carries no price with VAT
     *         (checkout then falls back to the flat rate from the admin)
     */
    public static function priceEur(array $price): float
    {
        if (isset($price['total'])) {
            $gross = (float)$price['total'];
        } elseif (isset($price['amount'], $price['vat'])) {
            $gross = (float)$price['amount'] + (float)$price['vat'];
        } else {
            throw new RuntimeException('Speedy calculate: no price with VAT in the reply');
        }
        return strtoupper($price['currency'] ?? 'BGN') === 'EUR'
            ? round($gross, 2)
            : round($gross / 1.95583, 2); // BGN → EUR (fixed peg)
    }

    /**
     * Map an order row (+ parcel options) to the createShipment() input array.
     *
     * Pure: no API, no DB — extracted so the delivery-type handling is unit-testable.
     * Crucially BOTH 'office' and 'apt' (Speedy automat / parcel machine) are
     * pickup-point deliveries identified by courier_office_code via pickupOfficeId;
     * only a true 'door' order uses the city/street address. Treating 'apt' as
     * 'door' sends an empty street and Speedy rejects the recipient address.
     *
     * @param array  $order       Order row (delivery_type, delivery_city,
     *                             delivery_address, courier_office_code, customer_*).
     * @param float  $weight      Parcel weight (kg).
     * @param int    $packCount   Number of parcels.
     * @param string $description Shipment contents description.
     */
    public static function shipmentInputFromOrder(array $order, float $weight, int $packCount, string $description): array
    {
        $deliveryType = ($order['delivery_type'] ?? '') ?: 'door';
        $isPickup     = in_array($deliveryType, ['office', 'apt'], true);

        return [
            'weight'           => $weight,
            'pack_count'       => $packCount,
            'description'      => $description,
            'receiver_name'    => $order['customer_name']  ?? '',
            'receiver_phone'   => courier_normalize_phone($order['customer_phone'] ?? ''),
            'receiver_city'    => $order['delivery_city']    ?? '',
            'receiver_address' => $order['delivery_address'] ?? '',
            'receiver_office'  => $isPickup ? (int)($order['courier_office_code'] ?? 0) : null,
            'delivery_type'    => $isPickup ? $deliveryType : 'door',
        ];
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
     *   'receiver_address' => string,     // used for door delivery
     *   'receiver_office'  => int|null,   // office/APT id for office delivery
     *   'delivery_type'    => 'door'|'office'|'apt',
     *   'service_id'       => int|null,
     *   'cod'              => float|null,
     * }
     * @return array ['shipment_number' => string, 'price' => float, 'raw' => array]
     */
    public function createShipment(array $order): array
    {
        $deliveryType = $order['delivery_type'] ?? 'door';
        $serviceId    = $order['service_id'] ?? self::SERVICE_STANDARD;

        $recipient = [
            'privatePerson' => true,
            'clientName'    => $order['receiver_name'] ?? '',
            // Normalised at the API boundary too: pledge shipments call this directly,
            // and a phone without +359 makes Speedy reject the recipient.
            'phone1'        => ['number' => courier_normalize_phone($order['receiver_phone'] ?? '')],
        ];

        if ($deliveryType === 'door') {
            // Never fall back to sending the raw text as siteName: Speedy rejects
            // anything over 50 characters with a message nobody can act on.
            $siteId = $this->findSiteIds($order['receiver_city'] ?? '')[0] ?? null;
            if ($siteId === null) {
                throw self::unknownTownError($order['receiver_city'] ?? '');
            }
            $addr = ['countryId' => 100, 'siteId' => $siteId];
            $rawAddress = trim($order['receiver_address'] ?? '');
            if ($rawAddress !== '') {
                $addr['addressNote'] = $rawAddress;
            }
            $recipient['address'] = $addr;
        } else {
            $recipient['pickupOfficeId'] = (int)($order['receiver_office'] ?? 0);
        }

        $body = [
            'sender'    => [
                'clientId' => (int)(function_exists('setting_resolve') ? setting_resolve('speedy_client_id', 'SPEEDY_CLIENT_ID', '0') : (defined('SPEEDY_CLIENT_ID') ? SPEEDY_CLIENT_ID : 0)),
            ],
            'recipient' => $recipient,
            'service'   => [
                'serviceId'            => $serviceId,
                'autoAdjustPickupDate' => true,
            ],
            'content'   => [
                'parcelsCount'   => (int)($order['pack_count'] ?? 1),
                'totalWeight'    => (float)($order['weight'] ?? 1),
                'contents'       => $order['description'] ?? '',
                'package'        => 'BOX',
            ],
            'payment'   => [
                'courierServicePayer' => 'SENDER',
            ],
        ];

        if (!empty($order['cod'])) {
            $body['payment']['declaredValuePayer'] = 'RECIPIENT';
            $body['payment']['declaredValue']      = (float)$order['cod'];
            $body['payment']['cod']                = [
                'amount'         => (float)$order['cod'],
                'processingType' => 'CASH',
                'includeShipping' => false,
            ];
        }

        $data = $this->post('shipment/', $body);

        if (!empty($data['error'])) {
            throw new RuntimeException('Speedy API error: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }

        return [
            'shipment_number' => (string)($data['id'] ?? ''),
            'price'           => (float)($data['price']['total'] ?? 0),
            'raw'             => $data,
        ];
    }

    /**
     * Get tracking status.
     *
     * @param  string $trackingNumber  Speedy shipment ID
     * @return array  ['status' => string, 'delivered' => bool, 'events' => array, 'raw' => array]
     */
    public function getTracking(string $trackingNumber): array
    {
        $data = $this->post('track/', [
            'paramObject' => [['id' => $trackingNumber]],
        ]);

        $parcel = $data['parcels'][0] ?? [];
        $ops    = $parcel['operations'] ?? [];
        $last   = end($ops) ?: [];

        return [
            'status'    => $last['description'] ?? '',
            'delivered' => ($parcel['finalStatus'] ?? '') === 'DELIVERED',
            'events'    => $ops,
            'raw'       => $parcel,
        ];
    }

    /**
     * Cancel a shipment.
     *
     * @param  string $shipmentId  Speedy shipment number (returned by createShipment)
     * @throws RuntimeException on API or network error
     */
    public function cancelShipment(string $shipmentId): void
    {
        $this->post('shipment/cancel', [
            'shipmentId' => $shipmentId,
            'comment'    => 'Отменена поръчка',
        ]);
    }

    /**
     * Download a shipment label as a PDF binary string.
     *
     * Calls POST /print/ which returns JSON with a "data" field containing
     * the PDF as an array of byte integers (ExtendedPrintResponse schema).
     *
     * @param  string $shipmentId  Speedy shipment number
     * @return string  Raw PDF binary
     * @throws RuntimeException if the API returns no PDF data
     */
    public function getLabel(string $shipmentId): string
    {
        $pdf = $this->postRaw('print/', [
            'paperSize'                   => 'A6',
            'parcels'                     => [['parcel' => ['id' => $shipmentId]]],
            'additionalWaybillSenderCopy' => 'NONE',
        ]);

        if ($pdf === '') {
            throw new RuntimeException('Speedy print returned empty response');
        }

        return $pdf;
    }
}
