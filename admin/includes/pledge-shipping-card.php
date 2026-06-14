<?php
/**
 * Reusable admin card for issuing a courier label for a campaign reward pledge.
 *
 * Expects in scope:
 *   $pdo          PDO
 *   $psc_pledge   array  campaign_pledges row (must have delivery_courier set)
 *
 * Forms POST back to the current page; the host page must call
 * pledge_shipping_handle_post($pdo, $pledge) after csrf_verify().
 */
if (empty($psc_pledge['delivery_courier'])) return;

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_shipping.php';

$psc_ship    = pledge_current_shipment($pdo, $psc_pledge);
$psc_courier = $psc_pledge['delivery_courier'];
$psc_type    = $psc_pledge['delivery_type'] ?? '';
$psc_addr    = pledge_delivery_addr($psc_pledge);
$psc_is_office = in_array($psc_type, ['office', 'apt', 'locker'], true);

$psc_courier_lbl = ['speedy' => 'Speedy', 'boxnow' => 'BoxNow'][$psc_courier] ?? ucfirst($psc_courier);
$psc_type_lbl    = ['office' => 'до офис', 'apt' => 'до автомат', 'address' => 'до адрес', 'locker' => 'до автомат'][$psc_type] ?? $psc_type;

$psc_dest = $psc_is_office
    ? trim(($psc_pledge['office_name'] ?? '') . ($psc_pledge['office_city'] ? ', ' . $psc_pledge['office_city'] : ''))
    : trim(implode(', ', array_filter([$psc_addr['address'] ?? '', $psc_addr['city'] ?? '', $psc_addr['postcode'] ?? ''])));

$psc_has_shipment = ($psc_courier === 'speedy' && !empty($psc_ship['speedy_shipment_id']))
                 || ($psc_courier === 'boxnow' && !empty($psc_ship['boxnow_parcel_id']));
$psc_shipment_id  = $psc_courier === 'speedy' ? ($psc_ship['speedy_shipment_id'] ?? '') : ($psc_ship['boxnow_parcel_id'] ?? '');
$psc_label_url    = $psc_courier === 'speedy'
    ? '/admin/speedy-label.php?order_id=' . (int)$psc_ship['order_id']
    : '/admin/boxnow-label.php?order_id=' . (int)$psc_ship['order_id'];
?>
<div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid #e8ddd5;border-radius:8px;background:#fff;">
  <h3 style="margin-top:0;font-size:1rem;"><?= h($psc_courier_lbl) ?> товарителница</h3>
  <p style="font-size:.82rem;color:#6b6560;margin:.25rem 0 1rem;">
    <?= h($psc_type_lbl) ?><?php if ($psc_dest !== ''): ?> · <?= h($psc_dest) ?><?php endif; ?>
  </p>

  <?php if ($psc_has_shipment): ?>
    <p style="font-size:.85rem;margin:.5rem 0;">
      <strong><?= $psc_courier === 'speedy' ? 'Номер' : 'Parcel ID' ?>:</strong><br>
      <code style="font-size:.8rem;"><?= h($psc_shipment_id) ?></code>
    </p>
    <a href="<?= h($psc_label_url) ?>" target="_blank"
       class="btn btn--primary" style="width:100%;justify-content:center;margin-bottom:.5rem;display:flex;">
      📄 Печат на етикет (PDF)
    </a>
    <form method="POST" data-confirm="Анулиране на товарителницата. Сигурни ли сте?" data-confirm-ok="Анулирай">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pledge_cancel_label">
      <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;color:#c0392b;border-color:#c0392b;">
        ✕ Анулирай пратката
      </button>
    </form>

  <?php elseif ($psc_courier === 'boxnow'): ?>
    <?php if (empty($psc_pledge['office_code'])): ?>
      <p style="font-size:.82rem;color:#c0392b;margin:0;">Липсва BoxNow автомат за тази награда — не може да се създаде товарителница.</p>
    <?php else: ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pledge_create_boxnow_label">
        <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Размер на отделението</label>
        <select name="compartment_size" style="width:100%;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;margin-bottom:.65rem;">
          <option value="1">Малко (до 8 см височина)</option>
          <option value="2">Средно (до 17 см)</option>
          <option value="3">Голямо (до 36 см)</option>
        </select>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
          📦 Създай товарителница
        </button>
      </form>
    <?php endif; ?>

  <?php else: /* speedy */ ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pledge_create_speedy_label">
      <?php if (in_array($psc_type, ['office', 'apt'], true) && empty($psc_pledge['office_code'])): ?>
        <div style="margin-bottom:.65rem;">
          <label for="psc_office_code" style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;color:#c0392b;">
            Speedy office ID (липсва — въведете го ръчно)
          </label>
          <input type="text" id="psc_office_code" name="office_code_override" placeholder="напр. 123"
                 style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #f0c4c0;border-radius:6px;font-size:.875rem;font-family:inherit;">
          <span style="font-size:.74rem;color:#9b9590;">Офис: <?= h($psc_pledge['office_name'] ?? '') ?></span>
        </div>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.65rem;">
        <div>
          <label for="psc_weight" style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Тегло (кг)</label>
          <input type="number" id="psc_weight" name="weight" value="1.0" min="0.1" step="0.1"
                 style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
        </div>
        <div>
          <label for="psc_pack_count" style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Брой пакети</label>
          <input type="number" id="psc_pack_count" name="pack_count" value="1" min="1" step="1"
                 style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
        </div>
      </div>
      <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
        📦 Създай товарителница
      </button>
    </form>
  <?php endif; ?>
</div>
