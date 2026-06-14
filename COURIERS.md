# Courier Integration Layer

Three standalone PHP classes under `includes/couriers/`. Each reads credentials
from `courier.config.php` (gitignored — create manually on the server from
`courier.config.php.example`).

---

## EcontCourier

**API:** Econt JSON API — `https://ee.econt.com/services/`
**Test:** `https://demo.econt.com/services/` (set `ECONT_TEST_MODE = true`)
**Auth:** Credentials injected into each request body as `{"credentials": {...}}`

### Methods

#### `getOffices(string $city = ''): array`
Lists Econt offices. Optional BG city name filter (e.g. `"София"`).

```
Endpoint: POST Nomenclatures/NomenclaturesService.getOffices.json
Returns:  [['id', 'name', 'city', 'address', 'phone', 'work_hours'], ...]
```

#### `calculateShipping(array $parcel, string $toCity, string $deliveryType): float`
Returns total price in BGN.

```
$parcel:       ['weight' => 1.5, 'pack_count' => 1]
$toCity:       BG city name, e.g. "Пловдив"
$deliveryType: 'door' | 'office'
Endpoint: POST Shipments/ShipmentsService.calculateShipment.json
```

#### `createShipment(array $order): array`
Registers a shipment, returns shipment number and price.

```
$order keys:
  weight, pack_count, description,
  receiver_name, receiver_phone, receiver_city, receiver_address,
  receiver_office (office code, only for delivery_type='office'),
  delivery_type ('door' | 'office'),
  cod (float, optional)

Returns: ['shipment_number' => '...', 'price' => 9.50, 'raw' => [...]]
Endpoint: POST Shipments/ShipmentsService.createShipments.json
```

#### `getTracking(string $trackingNumber): array`
```
Returns: ['status' => '...', 'status_code' => 14, 'events' => [...], 'raw' => [...]]
Endpoint: POST Shipments/ShipmentsService.getShipmentStatuses.json
```

### Limitations
- `calculateShipping` requires `SENDER_CITY` and `SENDER_ADDRESS` to be set in `courier.config.php`.
- The demo environment requires a separate demo account from Econt.

---

## SpeedyCourier

**API:** Speedy REST API v1 — `https://api.speedy.bg/v1/`
**Test:** `https://test.api.speedy.bg/v1/` (set `SPEEDY_TEST_MODE = true`)
**Auth:** `userName` + `password` merged into each request body

### Service ID constants
| Constant             | ID   | Description              |
|----------------------|------|--------------------------|
| `SERVICE_STANDARD`   | 505  | Standard (1–2 days)      |
| `SERVICE_EXPRESS`    | 506  | Express (next day)       |
| `SERVICE_TO_OFFICE`  | 1    | Delivery to office       |
| `SERVICE_TO_APT`     | 1092 | Delivery to APT locker   |

### Methods

#### `getOffices(string $city = ''): array`
Lists Speedy offices and APT terminals. Optional BG city name filter.

```
Endpoint: POST location/office/
Returns:  [['id', 'name', 'city', 'address', 'type'], ...]
          type: 'office' | 'apt'
```

#### `calculateShipping(array $parcel, string $toCity, string $deliveryType): float`
```
$parcel:       ['weight' => 1.5, 'pack_count' => 1, 'service_id' => 505 (optional)]
$toCity:       BG city name
$deliveryType: 'door' | 'office' | 'apt'
Endpoint: POST calculate/
Returns:  float (BGN)
```

#### `createShipment(array $order): array`
```
$order keys:
  weight, pack_count, description,
  receiver_name, receiver_phone,
  receiver_city, receiver_address (for door delivery),
  receiver_office (office/APT id, for office/apt delivery),
  delivery_type ('door' | 'office' | 'apt'),
  service_id (optional),
  cod (float, optional)

Returns: ['shipment_number' => '...', 'price' => 8.20, 'raw' => [...]]
Endpoint: POST shipment/
```

#### `getTracking(string $trackingNumber): array`
```
Returns: ['status' => '...', 'delivered' => bool, 'events' => [...], 'raw' => [...]]
Endpoint: POST track/
```

### Limitations
- `SPEEDY_CLIENT_ID` must be set — this is the numeric contract/sender ID from your Speedy account.
- Service IDs may vary by region (Romania uses different IDs). Constants are for Bulgaria only.
- APT (automated parcel terminal) support requires `SPEEDY_TEST_MODE = false` and a Speedy account with APT enabled.

---

## BoxNowCourier

**API:** BoxNow REST API v1
**Production:** `https://api.boxnow.bg`
**Test/Staging:** `https://api-stage.boxnow.bg` (set `BOXNOW_TEST_MODE = true`)
**Auth:** OAuth2 `client_credentials` — token fetched automatically, cached per instance

### Methods

#### `getOffices(string $city = ''): array`
Lists BoxNow APM locker locations. Optional city name filter.

```
Endpoint: GET /api/v1/lockers[?lockerCity=Sofia]
Returns:  [['id', 'name', 'city', 'address', 'lat', 'lng'], ...]
```

#### `calculateShipping(array $parcel, string $toCity, string $deliveryType): float`
No dedicated API endpoint — applies flat-rate weight tiers:

| Weight   | Price (BGN) |
|----------|-------------|
| 0–3 kg   | 3.99        |
| 3–6 kg   | 4.99        |
| 6–10 kg  | 5.99        |
| 10–20 kg | 7.99        |

Update these values in `BoxNowCourier::calculateShipping()` to match your contract.

#### `createShipment(array $order): array`
```
$order keys:
  weight, description,
  receiver_name, receiver_phone, receiver_email,
  locker_id (BoxNow APM locker ID from getOffices),
  cod (float, optional)

Returns: ['parcel_id' => '...', 'tracking_url' => '...', 'raw' => [...]]
Endpoint: POST /api/v1/parcels
```

#### `getTracking(string $trackingNumber): array`
```
Returns: ['status' => 'DELIVERED', 'delivered' => true, 'events' => [...], 'raw' => [...]]
Endpoint: GET /api/v1/parcels/{id}
```

#### `cancelShipment(string $parcelId): bool`
```
Endpoint: POST /api/v1/parcels/{id}:cancel
Returns:  true on success, false on error
```

#### `getLabelUrl(string $parcelId): string` + `getAccessToken(): string`
For streaming the PDF label. Fetch the URL with `Authorization: Bearer {token}` header.

### Limitations
- BoxNow only delivers to APM lockers — no door delivery or office pickup.
- `calculateShipping` is client-side only (flat tiers); verify pricing with your BoxNow contract.
- `BOXNOW_PARTNER_ID` and `BOXNOW_WAREHOUSE_ID` are required for shipment creation.
- The exact staging API hostname may differ — confirm with `integrationsupport@boxnow.bg`.

---

## Setup

1. Copy `courier.config.php.example` → `courier.config.php` on the server
2. Fill in all credentials and sender details
3. Include the config and the relevant class:

```php
require_once __DIR__ . '/courier.config.php';
require_once __DIR__ . '/includes/couriers/EcontCourier.php';

$econt = new EcontCourier();
$offices = $econt->getOffices('Пловдив');
```
