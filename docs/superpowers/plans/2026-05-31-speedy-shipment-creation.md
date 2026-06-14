# Speedy Shipment Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Speedy товарителница (shipment) card to the order admin page — mirroring the existing BoxNow flow — so admins can create, print label, and cancel Speedy shipments without leaving the admin panel.

**Architecture:** A DB migration adds `speedy_shipment_id` to `orders`. Two new methods on `SpeedyCourier` handle cancel and label-print API calls. `admin/order-view.php` gains a UI card and two POST handlers. A new `admin/speedy-label.php` streams the label PDF.

**Tech Stack:** PHP 8.4, PDO/MySQL, Speedy REST API v1 (`api.speedy.bg/v1/`), cURL, PHPUnit 13.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `migrations/022_speedy_shipment_id.sql` | Create | Adds `speedy_shipment_id` column to `orders` |
| `includes/couriers/SpeedyCourier.php` | Modify | Add `delete()` helper, `cancelShipment()`, `getLabel()` |
| `admin/order-view.php` | Modify | Add 2 POST handlers + Speedy card UI |
| `admin/speedy-label.php` | Create | Streams Speedy label PDF to browser |
| `tests/Couriers/SpeedyCourierTest.php` | Modify | Add `testCreateShipmentReturnsShipmentNumber()` + `testCancelShipmentDoesNotThrow()` |

---

## Task 1: DB Migration

**Files:**
- Create: `migrations/022_speedy_shipment_id.sql`

- [ ] **Step 1.1: Create the migration file**

```sql
-- migrations/022_speedy_shipment_id.sql
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS speedy_shipment_id VARCHAR(100) DEFAULT NULL;
```

- [ ] **Step 1.2: Run the migration**

```bash
php /Users/detelinavasileva/Code/oddminds/migrate.php
```

Expected output: line confirming `022_speedy_shipment_id.sql` applied, no errors.

- [ ] **Step 1.3: Verify column exists**

```bash
php -r "
require '/Users/detelinavasileva/Code/oddminds/config.php';
require '/Users/detelinavasileva/Code/oddminds/admin/includes/db.php';
\$r = get_pdo()->query('SHOW COLUMNS FROM orders LIKE \"speedy_shipment_id\"')->fetch();
echo \$r ? 'OK: ' . \$r['Field'] . PHP_EOL : 'MISSING' . PHP_EOL;
"
```

Expected: `OK: speedy_shipment_id`

- [ ] **Step 1.4: Commit**

```bash
git add migrations/022_speedy_shipment_id.sql
git commit -m "feat: add speedy_shipment_id column to orders"
```

---

## Task 2: SpeedyCourier — `cancelShipment()` and `getLabel()`

**Files:**
- Modify: `includes/couriers/SpeedyCourier.php` (after the existing `post()` private method, around line 77)

- [ ] **Step 2.1: Write failing integration tests first**

Open `tests/Couriers/SpeedyCourierTest.php`. Add the `Depends` import at the top (after the existing `use PHPUnit\Framework\Attributes\Group;`):

```php
use PHPUnit\Framework\Attributes\Depends;
```

Then add these two test methods at the end of the class body (before the closing `}`):

```php
public function testCreateShipmentReturnsShipmentNumber(): string
{
    $result = $this->speedy->createShipment([
        'weight'           => 0.5,
        'pack_count'       => 1,
        'description'      => 'Тест пратка',
        'receiver_name'    => 'Тест Получател',
        'receiver_phone'   => '0888123456',
        'receiver_city'    => 'София',
        'receiver_address' => 'бул. Витоша 1',
        'receiver_office'  => null,
        'delivery_type'    => 'door',
    ]);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('shipment_number', $result);
    $this->assertNotEmpty($result['shipment_number'], 'Expected a non-empty shipment number');

    return (string) $result['shipment_number'];
}

#[Depends('testCreateShipmentReturnsShipmentNumber')]
public function testCancelShipmentDoesNotThrow(string $shipmentId): void
{
    $this->speedy->cancelShipment($shipmentId);
    $this->assertTrue(true); // reaching here means no exception was thrown
}
```

- [ ] **Step 2.2: Run tests — expect create to pass, cancel to fail (method missing)**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --group speedy tests/Couriers/SpeedyCourierTest.php
```

Expected: `testCreateShipmentReturnsShipmentNumber` PASS (method already exists), `testCancelShipmentDoesNotThrow` FAIL with "Call to undefined method SpeedyCourier::cancelShipment()".

- [ ] **Step 2.3: Add `delete()` private helper to `SpeedyCourier`**

Open `includes/couriers/SpeedyCourier.php`. After the closing `}` of the `post()` method (around line 77), insert:

```php
    private function delete(string $endpoint, array $body = []): array
    {
        $payload = array_merge($this->auth, $body);

        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
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

        if ($httpCode === 200 && $response === '') return [];
        if ($httpCode === 204) return [];

        $data = $response ? (json_decode($response, true) ?? []) : [];

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ($data['message'] ?? "HTTP $httpCode");
            throw new RuntimeException('Speedy API error: ' . $msg);
        }

        return $data;
    }
```

- [ ] **Step 2.4: Add `cancelShipment()` public method**

In `includes/couriers/SpeedyCourier.php`, in the `// ── Public API ─` section, after `getTracking()` and before the closing `}` of the class, add:

```php
    /**
     * Cancel a shipment.
     *
     * @param  string $shipmentId  Speedy shipment number (returned by createShipment)
     * @throws RuntimeException on API or network error
     */
    public function cancelShipment(string $shipmentId): void
    {
        $this->delete('shipment/' . $shipmentId);
    }
```

- [ ] **Step 2.5: Run tests — both should pass**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --group speedy tests/Couriers/SpeedyCourierTest.php
```

Expected: all tests PASS including the two new ones.

- [ ] **Step 2.6: Add `getLabel()` public method**

In `includes/couriers/SpeedyCourier.php`, after `cancelShipment()` and before the closing `}` of the class, add:

```php
    /**
     * Download a shipment label as a PDF binary string.
     *
     * Calls POST /print/ which returns JSON with a base64-encoded PDF.
     * Note: verify response shape against Speedy test API if format changes.
     *
     * @param  string $shipmentId  Speedy shipment number
     * @return string  Raw PDF binary
     * @throws RuntimeException if the API returns no content or invalid base64
     */
    public function getLabel(string $shipmentId): string
    {
        $data = $this->post('print/', [
            'parcels'   => [['id' => $shipmentId]],
            'format'    => 'pdf',
            'groupBy'   => 'shipment',
            'paperSize' => 'A6_H',
        ]);

        $contents = $data['files'][0]['contents'] ?? '';
        if ($contents === '') {
            throw new RuntimeException('Speedy print returned no PDF content');
        }

        $pdf = base64_decode($contents, true);
        if ($pdf === false) {
            throw new RuntimeException('Speedy print: invalid base64 content');
        }

        return $pdf;
    }
```

- [ ] **Step 2.7: Run full test suite — no regressions**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --exclude-group speedy,integration
```

Expected: all tests PASS.

- [ ] **Step 2.8: Commit**

```bash
git add includes/couriers/SpeedyCourier.php tests/Couriers/SpeedyCourierTest.php
git commit -m "feat: add cancelShipment and getLabel to SpeedyCourier"
```

---

## Task 3: POST Handlers in `admin/order-view.php`

**Files:**
- Modify: `admin/order-view.php` (insert after line 215, before the `if ($action === 'resend_ticket')` block)

- [ ] **Step 3.1: Add `create_speedy_label` handler**

In `admin/order-view.php`, after line 215 (the closing `}` of the `cancel_boxnow_label` handler) and before line 217 (`if ($action === 'resend_ticket')`), insert:

```php
    if ($action === 'create_speedy_label') {
        if ($order['courier'] !== 'speedy' || $order['type'] !== 'physical') {
            $errors[] = 'Невалидна поръчка за Speedy.';
        } elseif (!empty($order['speedy_shipment_id'])) {
            $errors[] = 'Товарителницата вече е създадена.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
                $speedy = new SpeedyCourier();

                $weight     = max(0.1, (float)($_POST['weight']     ?? 1.0));
                $pack_count = max(1,   (int)  ($_POST['pack_count'] ?? 1));
                $delivery_type = $order['delivery_type'] === 'office' ? 'office' : 'door';

                $desc = implode(', ', array_map(
                    fn($i) => ($i['name_bg'] ?? '') . ' x' . ($i['quantity'] ?? 1),
                    $items
                ));

                $result = $speedy->createShipment([
                    'weight'           => $weight,
                    'pack_count'       => $pack_count,
                    'description'      => $desc ?: 'Поръчка #' . $order['order_number'],
                    'receiver_name'    => $order['customer_name'],
                    'receiver_phone'   => $order['customer_phone'],
                    'receiver_city'    => $order['delivery_city'] ?? '',
                    'receiver_address' => $order['delivery_address'] ?? '',
                    'receiver_office'  => $order['delivery_type'] === 'office'
                                         ? (int)$order['courier_office_code']
                                         : null,
                    'delivery_type'    => $delivery_type,
                ]);

                $shipment_id = $result['shipment_number'];
                if (!$shipment_id) throw new RuntimeException('Speedy не върна номер на пратката.');

                $pdo->prepare(
                    'UPDATE orders SET speedy_shipment_id=?, tracking_number=?, updated_at=NOW() WHERE id=?'
                )->execute([$shipment_id, $shipment_id, $id]);

                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е създадена: ' . $shipment_id;
            } catch (Throwable $e) {
                $errors[] = 'Грешка при създаване: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'cancel_speedy_label') {
        $shipment_id = $order['speedy_shipment_id'] ?? '';
        if (!$shipment_id) {
            $errors[] = 'Няма активна товарителница.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
                (new SpeedyCourier())->cancelShipment($shipment_id);
                $pdo->prepare(
                    'UPDATE orders SET speedy_shipment_id=NULL, updated_at=NOW() WHERE id=?'
                )->execute([$id]);
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е анулирана.';
            } catch (Throwable $e) {
                $errors[] = 'Грешка при анулиране: ' . $e->getMessage();
            }
        }
    }
```

- [ ] **Step 3.2: Run full test suite — no regressions**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --exclude-group speedy,integration
```

Expected: all tests PASS.

- [ ] **Step 3.3: Commit**

```bash
git add admin/order-view.php
git commit -m "feat: add Speedy shipment create/cancel POST handlers to order-view"
```

---

## Task 4: Speedy Card UI in `admin/order-view.php`

**Files:**
- Modify: `admin/order-view.php` (insert after line 688, before `<!-- Documents -->`)

- [ ] **Step 4.1: Add the Speedy card**

In `admin/order-view.php`, after the closing `<?php endif; ?>` of the BoxNow card (line 688) and before `<!-- Documents -->` (line 690), insert:

```php
    <?php if ($order['courier'] === 'speedy' && $order['type'] === 'physical'): ?>
    <!-- Speedy label -->
    <div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Speedy товарителница</h3>
      <?php if (!empty($order['speedy_shipment_id'])): ?>
        <p style="font-size:.85rem;margin:.5rem 0;">
          <strong>Номер:</strong><br>
          <code style="font-size:.8rem;"><?= h($order['speedy_shipment_id']) ?></code>
        </p>
        <a href="/admin/speedy-label.php?order_id=<?= $id ?>" target="_blank"
           class="btn btn--primary" style="width:100%;justify-content:center;margin-bottom:.5rem;display:flex;">
          📄 Печат на етикет (PDF)
        </a>
        <form method="POST" data-confirm="Анулиране на товарителницата. Сигурни ли сте?" data-confirm-ok="Анулирай">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel_speedy_label">
          <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;color:#c0392b;border-color:#c0392b;">
            ✕ Анулирай пратката
          </button>
        </form>
      <?php else: ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.25rem 0 1rem;">
          <?= $order['delivery_type'] === 'office'
              ? 'Офис: ' . h($order['courier_office_name'] ?: $order['courier_office_code'])
              : 'Адрес: ' . h($order['delivery_address'] . ', ' . $order['delivery_city']) ?>
        </p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_speedy_label">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.65rem;">
            <div>
              <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.3rem;">Тегло (кг)</label>
              <input type="number" name="weight" value="1.0" min="0.1" step="0.1"
                     style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
            </div>
            <div>
              <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.3rem;">Брой пакети</label>
              <input type="number" name="pack_count" value="1" min="1" step="1"
                     style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
            </div>
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
            📦 Създай товарителница
          </button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
```

- [ ] **Step 4.2: Run full test suite — no regressions**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --exclude-group speedy,integration
```

Expected: all tests PASS.

- [ ] **Step 4.3: Commit**

```bash
git add admin/order-view.php
git commit -m "feat: add Speedy товарителница card to order-view admin"
```

---

## Task 5: `admin/speedy-label.php`

**Files:**
- Create: `admin/speedy-label.php`

- [ ] **Step 5.1: Create the label endpoint**

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';

admin_require_login();

$pdo      = get_pdo();
$order_id = (int)($_GET['order_id'] ?? 0);
if (!$order_id) { http_response_code(400); exit('Missing order_id'); }

$stmt = $pdo->prepare('SELECT speedy_shipment_id, courier FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || $order['courier'] !== 'speedy' || empty($order['speedy_shipment_id'])) {
    http_response_code(404);
    exit('Товарителницата не е намерена.');
}

try {
    $speedy = new SpeedyCourier();
    $pdf    = $speedy->getLabel($order['speedy_shipment_id']);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="speedy-' . $order['speedy_shipment_id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Грешка: ' . $e->getMessage());
}
```

- [ ] **Step 5.2: Verify the file parses without errors**

```bash
php -l /Users/detelinavasileva/Code/oddminds/admin/speedy-label.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5.3: Run full test suite — no regressions**

```bash
cd /Users/detelinavasileva/Code/oddminds && php vendor/bin/phpunit --exclude-group speedy,integration
```

Expected: all tests PASS.

- [ ] **Step 5.4: Commit**

```bash
git add admin/speedy-label.php
git commit -m "feat: add speedy-label.php to stream Speedy label PDF"
```

---

## Manual Verification Checklist

After all tasks are complete, verify end-to-end against the Speedy **test** environment (ensure `speedy_test_mode = true` in settings):

- [ ] Open a Speedy physical order in the admin
- [ ] The "Speedy товарителница" card is visible in the right sidebar
- [ ] Card shows the correct delivery destination (office name or address)
- [ ] Adjust weight and parcel count, click "Създай товарителница" — success message appears with shipment number
- [ ] The card switches to State B: shipment number shown, two buttons visible
- [ ] "Печат на етикет (PDF)" opens the label PDF in a new tab
- [ ] "Анулирай пратката" shows the confirmation modal (not `window.confirm`), clicking "Анулирай" cancels and resets the card to State A
- [ ] The `tracking_number` field is populated in the DB after creation (confirms shipped-email flow will work)
- [ ] Non-Speedy orders (BoxNow, other couriers) do NOT show the Speedy card
