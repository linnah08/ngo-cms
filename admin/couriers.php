<?php
$page_title_admin = 'Куриери';
$active_nav       = 'couriers';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

$pdo   = get_pdo();
$saved = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Невалиден CSRF токен.';
    } else {
        $section = $_POST['section'] ?? '';

        // ── Sender info ───────────────────────────────────────────────────────
        if ($section === 'sender') {
            setting_set('sender_name',    trim($_POST['sender_name']    ?? ''));
            setting_set('sender_phone',   trim($_POST['sender_phone']   ?? ''));
            setting_set('sender_city',    trim($_POST['sender_city']    ?? ''));
            setting_set('sender_address', trim($_POST['sender_address'] ?? ''));
            $saved = true;
        }

        // ── Speedy ────────────────────────────────────────────────────────────
        if ($section === 'speedy') {
            if (!empty($_POST['speedy_user']))      setting_set('speedy_user',      trim($_POST['speedy_user']));
            if (!empty($_POST['speedy_pass']))      setting_set('speedy_pass',      trim($_POST['speedy_pass']));
            if (!empty($_POST['speedy_client_id'])) setting_set('speedy_client_id', trim($_POST['speedy_client_id']));
            setting_set('speedy_test_mode', isset($_POST['speedy_test_mode']) ? '1' : '0');
            $saved = true;
        }

        // ── BoxNow ────────────────────────────────────────────────────────────
        if ($section === 'boxnow') {
            if (!empty($_POST['boxnow_client_id']))     setting_set('boxnow_client_id',     trim($_POST['boxnow_client_id']));
            if (!empty($_POST['boxnow_client_secret'])) setting_set('boxnow_client_secret', trim($_POST['boxnow_client_secret']));
            if (!empty($_POST['boxnow_partner_id']))    setting_set('boxnow_partner_id',    trim($_POST['boxnow_partner_id']));
            if (!empty($_POST['boxnow_warehouse_id']))        setting_set('boxnow_warehouse_id',        trim($_POST['boxnow_warehouse_id']));
            setting_set('boxnow_test_mode', isset($_POST['boxnow_test_mode']) ? '1' : '0');
            $saved = true;
        }

        // ── BoxNow tiers (saved together with credentials) ────────────────────
        if ($section === 'boxnow_rates') {
            $t1 = (float)($_POST['boxnow_rate_tier1'] ?? 3.99);
            $t2 = (float)($_POST['boxnow_rate_tier2'] ?? 4.99);
            $t3 = (float)($_POST['boxnow_rate_tier3'] ?? 5.99);
            $t4 = (float)($_POST['boxnow_rate_tier4'] ?? 7.99);
            setting_set('boxnow_rate_tier1', (string)$t1);
            setting_set('boxnow_rate_tier2', (string)$t2);
            setting_set('boxnow_rate_tier3', (string)$t3);
            setting_set('boxnow_rate_tier4', (string)$t4);
            // Keep shipping_rates table in sync — checkout reads from there for display
            $pdo->prepare("INSERT INTO shipping_rates (courier, delivery_type, rate_eur) VALUES ('boxnow','locker',?)
                           ON DUPLICATE KEY UPDATE rate_eur=VALUES(rate_eur)")
                ->execute([$t1]);
            $saved = true;
        }
    }
}

// Helper: "●●●●●●●●" if set, empty string if not
function masked(string $key): string {
    return setting_get($key, '');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if ($saved): ?>
  <div class="admin-alert admin-alert--success">Настройките бяха запазени.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="admin-alert admin-alert--error"><?= h($e) ?></div>
<?php endforeach; ?>

<p class="admin-meta">
  Паролите и токените се съхраняват криптирани в базата данни (AES-256-CBC).<br>
  Оставете поле за парола <strong>празно</strong>, ако не искате да го промените.<br>
  Speedy изчислява цената на доставка чрез API-то си. BoxNow използва фиксирана тарифа по тегло.
</p>

<!-- ── Sender info ──────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Подател (споделено за всички куриери)</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="sender">
    <div class="admin-form-grid">
      <label>Имена
        <input type="text" name="sender_name"    value="<?= h(setting_get('sender_name')) ?>">
      </label>
      <label>Телефон
        <input type="text" name="sender_phone"   value="<?= h(setting_get('sender_phone')) ?>">
      </label>
      <label>Град
        <input type="text" name="sender_city"    value="<?= h(setting_get('sender_city')) ?>">
      </label>
      <label>Адрес
        <input type="text" name="sender_address" value="<?= h(setting_get('sender_address')) ?>">
      </label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запази</button>
  </form>
</section>

<!-- ── Speedy ───────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Speedy</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="speedy">
    <div class="admin-form-grid">
      <label>Потребителско име
        <input type="text" name="speedy_user" value="<?= h(masked('speedy_user')) ?>" placeholder="Потребителско име">
      </label>
      <label>Парола
        <div style="position:relative;">
          <input type="password" name="speedy_pass" value="<?= h(masked('speedy_pass')) ?>" placeholder="Парола" style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
      <label>Client ID (договор №)
        <input type="text" name="speedy_client_id" value="<?= h(masked('speedy_client_id')) ?>" placeholder="Числов ID">
      </label>
    </div>
    <label class="admin-checkbox" style="margin-top:.75rem;">
      <input type="checkbox" name="speedy_test_mode" value="1"
             <?= setting_get('speedy_test_mode', '1') === '1' ? 'checked' : '' ?>>
      Тестова среда (test.api.speedy.bg)
    </label>
    <div>
      <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запази</button>
      <?php if (setting_is_set('speedy_user')): ?>
        <span class="badge badge--published" style="margin-left:.75rem;">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft" style="margin-left:.75rem;">Не е конфигуриран</span>
      <?php endif; ?>
    </div>
  </form>
</section>

<!-- ── BoxNow ───────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">BoxNow</h2>
  <p class="admin-meta">
    BoxNow използва фиксирана цена на доставка по тегло (nationwide flat-rate).<br>
    Speedy изчислява цената автоматично чрез своето API.
  </p>
  <form method="post" style="margin-bottom:1.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="boxnow">
    <div class="admin-form-grid">
      <label>Client ID
        <input type="text" name="boxnow_client_id"     value="<?= h(masked('boxnow_client_id')) ?>"     placeholder="Client ID">
      </label>
      <label>Client Secret
        <div style="position:relative;">
          <input type="password" name="boxnow_client_secret" value="<?= h(masked('boxnow_client_secret')) ?>" placeholder="Client Secret" style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
      <label>Partner ID
        <input type="text" name="boxnow_partner_id"    value="<?= h(masked('boxnow_partner_id')) ?>"    placeholder="Partner ID">
      </label>
      <label>Warehouse ID
        <input type="text" name="boxnow_warehouse_id"  value="<?= h(masked('boxnow_warehouse_id')) ?>"  placeholder="Warehouse ID">
      </label>
    </div>
    <label class="admin-checkbox" style="margin-top:.75rem;">
      <input type="checkbox" name="boxnow_test_mode" value="1"
             <?= setting_get('boxnow_test_mode', '1') === '1' ? 'checked' : '' ?>>
      Тестова среда (api-stage.boxnow.bg)
    </label>
    <div>
      <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запази</button>
      <?php if (setting_is_set('boxnow_client_id')): ?>
        <span class="badge badge--published" style="margin-left:.75rem;">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft" style="margin-left:.75rem;">Не е конфигуриран</span>
      <?php endif; ?>
    </div>
  </form>

  <hr style="border:none;border-top:1px solid var(--border);margin-bottom:1.5rem;">
  <h3 style="font-size:1rem;margin-bottom:0.75rem;">Тарифа по тегло</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="boxnow_rates">
    <div class="admin-form-grid">
      <label>До 3 кг (€)
        <input type="text" name="boxnow_rate_tier1" value="<?= h(setting_get('boxnow_rate_tier1', '3.99')) ?>">
      </label>
      <label>До 6 кг (€)
        <input type="text" name="boxnow_rate_tier2" value="<?= h(setting_get('boxnow_rate_tier2', '4.99')) ?>">
      </label>
      <label>До 10 кг (€)
        <input type="text" name="boxnow_rate_tier3" value="<?= h(setting_get('boxnow_rate_tier3', '5.99')) ?>">
      </label>
      <label>Над 10 кг (€)
        <input type="text" name="boxnow_rate_tier4" value="<?= h(setting_get('boxnow_rate_tier4', '7.99')) ?>">
      </label>
    </div>
    <button type="submit" class="btn btn--primary" style="margin-top:1rem;">Запази тарифа</button>
  </form>
</section>

<style>
.pwd-toggle {
    position: absolute; right: .5rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-muted); font-size: .8rem; padding: .25rem .4rem;
}
.pwd-toggle:hover { color: var(--text); }
</style>
<script>
function togglePwd(btn) {
    const input = btn.previousElementSibling;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Скрий' : 'Покажи';
}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
