<?php
$page_title_admin = 'Плащания';
$active_nav       = 'payment';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_admin();

$saved       = false;
$errors      = [];
$test_result = null;  // ['ok' => bool, 'message' => string, 'raw' => string]
$pdo         = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Невалиден CSRF токен.';
    } else {
        $section = $_POST['section'] ?? '';

        // ── Save credentials ──────────────────────────────────────────────────
        if ($section === 'claude') {
            if (!empty($_POST['claude_api_key'])) setting_set('claude_api_key', trim($_POST['claude_api_key']));
            $saved = true;
        }

        if ($section === 'tinymce') {
            if (!empty($_POST['tinymce_api_key'])) setting_set('tinymce_api_key', trim($_POST['tinymce_api_key']));
            $saved = true;
        }

        // ── Document sequence: set next number ────────────────────────────────
        if ($section === 'doc_sequence_set') {
            $seq_type = $_POST['seq_type'] ?? '';
            $next_num = (int) ($_POST['next_number'] ?? 0);
            $valid_types = ['invoice', 'receipt', 'donation_cert'];
            if (!in_array($seq_type, $valid_types, true)) {
                $errors[] = 'Невалиден тип документ.';
            } elseif ($next_num < 1) {
                $errors[] = 'Номерът трябва да е поне 1.';
            } else {
                $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')
                    ->execute([$next_num - 1, $seq_type]);
                $saved = true;
            }
        }

        // ── Document sequence: reset to 0 ─────────────────────────────────────
        if ($section === 'doc_sequence_reset') {
            $seq_type = $_POST['seq_type'] ?? '';
            $valid_types = ['invoice', 'receipt', 'donation_cert'];
            if (in_array($seq_type, $valid_types, true)) {
                $pdo->prepare('UPDATE document_sequences SET last_number = 0 WHERE type = ?')
                    ->execute([$seq_type]);
                $saved = true;
            }
        }

        if ($section === 'claude_test') {
            $api_key = setting_get('claude_api_key');
            if (!$api_key) {
                $test_result = ['ok' => false, 'message' => 'Няма запазен API ключ.', 'raw' => ''];
            } else {
                $payload = json_encode([
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 10,
                    'messages'   => [['role' => 'user', 'content' => 'Hi']],
                ]);
                $ch = curl_init('https://api.anthropic.com/v1/messages');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => [
                        'x-api-key: ' . $api_key,
                        'anthropic-version: 2023-06-01',
                        'content-type: application/json',
                    ],
                ]);
                $raw      = curl_exec($ch);
                $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = curl_error($ch);
                curl_close($ch);

                if ($curl_err) {
                    $test_result = ['ok' => false, 'message' => 'cURL грешка: ' . $curl_err, 'raw' => ''];
                } elseif ($http !== 200) {
                    $d   = json_decode((string)$raw, true);
                    $msg = $d['error']['message'] ?? "HTTP $http";
                    $test_result = ['ok' => false, 'message' => 'API грешка: ' . $msg, 'raw' => $raw];
                } else {
                    $test_result = ['ok' => true, 'message' => 'Claude API ключ е валиден и работи.', 'raw' => ''];
                }
            }
        }


        if ($section === 'dskbank') {
            if (!empty($_POST['dsk_merchant'])) setting_set('dsk_merchant', trim($_POST['dsk_merchant']));
            if (!empty($_POST['dsk_password'])) setting_set('dsk_password', trim($_POST['dsk_password']));
            setting_set('dsk_test_mode', isset($_POST['dsk_test_mode']) ? '1' : '0');
            setting_set('dsk_enabled',   isset($_POST['dsk_enabled'])   ? '1' : '0');
            $saved = true;
        }

        if ($section === 'iris') {
            if (!empty($_POST['iris_merchant_key'])) setting_set('iris_merchant_key', trim($_POST['iris_merchant_key']));
            if (!empty($_POST['iris_iban']))         setting_set('iris_iban', trim($_POST['iris_iban']));
            setting_set('iris_test_mode', isset($_POST['iris_test_mode']) ? '1' : '0');
            setting_set('iris_enabled',   isset($_POST['iris_enabled'])   ? '1' : '0');
            $saved = true;
        }

        // ── Test credentials ──────────────────────────────────────────────────
        if ($section === 'dskbank_test') {
            $merchant = setting_get('dsk_merchant');
            $password = setting_get('dsk_password');
            $test     = setting_get('dsk_test_mode', '1') === '1';

            if (!$merchant || !$password) {
                $test_result = ['ok' => false, 'message' => 'Няма запазени credentials. Запазете първо.', 'raw' => ''];
            } else {
                $base = $test
                    ? 'https://uat.dskbank.bg/payment/rest/'
                    : 'https://epg.dskbank.bg/payment/rest/';

                // Query a non-existent order — auth errors return before "order not found"
                $payload = http_build_query([
                    'userName' => $merchant,
                    'password' => $password,
                    'orderId'  => '00000000-0000-0000-0000-000000000000',
                ]);

                $ch = curl_init($base . 'getOrderStatusExtended.do');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $raw      = curl_exec($ch);
                $curl_err = curl_error($ch);
                $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($curl_err) {
                    $test_result = ['ok' => false, 'message' => 'cURL грешка: ' . $curl_err, 'raw' => ''];
                } else {
                    $data = json_decode($raw, true);
                    $code = (string)($data['errorCode'] ?? '');
                    $msg  = $data['errorMessage'] ?? '';

                    // errorCode 2 = "order not found" — means auth passed
                    // errorCode 5 = also a non-auth error on some versions
                    // Any auth failure comes back with "Access denied" or similar
                    $auth_failure = stripos($msg, 'access') !== false
                                 || stripos($msg, 'denied') !== false
                                 || stripos($msg, 'invalid') !== false
                                 || stripos($msg, 'userName') !== false
                                 || stripos($msg, 'password') !== false
                                 || $code === '1';

                    if ($auth_failure) {
                        $test_result = ['ok' => false, 'message' => 'Грешни credentials: ' . $msg, 'raw' => $raw];
                    } else {
                        // Got a non-auth error (order not found etc.) — credentials are valid
                        $env = $test ? 'UAT (тестова)' : 'Production';
                        $test_result = ['ok' => true, 'message' => 'Credentials валидни — ' . $env . ' среда достъпна.', 'raw' => $raw];
                    }
                }
            }
        }
    }
}

function current_val(string $key): string {
    return setting_is_set($key) ? setting_get($key) : '';
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
  Паролите се съхраняват криптирани в базата данни (AES-256-CBC).<br>
  Оставете поле за парола <strong>празно</strong>, ако не искате да го промените.
</p>

<!-- ── DSK Bank vPOS ─────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">DSK Bank vPOS (картово плащане)</h2>
  <p class="admin-meta" style="margin-bottom:1.25rem;">
    Получете Login-API и парола от DSK Bank (виртуален ПОС терминал).<br>
    Тестова среда: <code>uat.dskbank.bg</code> — Продукционна: <code>epg.dskbank.bg</code>
  </p>

  <form method="post" style="margin-bottom:1.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="dskbank">

    <div class="admin-form-grid">
      <label>Login-API (потребителско име)
        <input type="text" name="dsk_merchant" value="<?= h(current_val('dsk_merchant')) ?>"
               placeholder="LOGIN-API">
      </label>
      <label>Парола
        <div style="position:relative;">
          <input type="password" name="dsk_password" value="<?= h(current_val('dsk_password')) ?>"
                 placeholder="Парола" style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
    </div>

    <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.6rem;">
      <label class="admin-checkbox">
        <input type="checkbox" name="dsk_enabled" value="1"
               <?= setting_get('dsk_enabled', '0') === '1' ? 'checked' : '' ?>>
        Активирай картово плащане при checkout
      </label>
      <label class="admin-checkbox">
        <input type="checkbox" name="dsk_test_mode" value="1"
               <?= setting_get('dsk_test_mode', '1') === '1' ? 'checked' : '' ?>>
        Тестова среда (uat.dskbank.bg)
      </label>
    </div>

    <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn--primary">Запази</button>
      <?php if (setting_is_set('dsk_merchant')): ?>
        <span class="badge badge--published">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft">Не е конфигуриран</span>
      <?php endif; ?>
      <?php if (setting_get('dsk_enabled', '0') === '1'): ?>
        <span class="badge badge--published">Активен</span>
      <?php else: ?>
        <span class="badge badge--draft">Неактивен</span>
      <?php endif; ?>
    </div>
  </form>

  <?php if (setting_is_set('dsk_merchant')): ?>
  <!-- ── Test connection ──────────────────────────────────────────────────────── -->
  <hr style="border:none;border-top:1px solid var(--border);margin-bottom:1.5rem;">
  <h3 style="font-size:.95rem;font-weight:600;margin-bottom:.75rem;">Тест на връзката</h3>

  <?php if ($test_result !== null): ?>
    <div style="padding:.85rem 1.1rem;border-radius:6px;margin-bottom:1rem;
         <?= $test_result['ok']
             ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;'
             : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
      <?= $test_result['ok'] ? '✓ ' : '✗ ' ?><?= h($test_result['message']) ?>
    </div>
    <?php if ($test_result['raw']): ?>
      <details style="margin-bottom:1rem;">
        <summary style="font-size:.8rem;color:var(--text-muted);cursor:pointer;">Суров отговор от API</summary>
        <pre style="font-size:.78rem;background:#f5f5f5;padding:.75rem;border-radius:4px;overflow-x:auto;margin-top:.5rem;"><?= h($test_result['raw']) ?></pre>
      </details>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="dskbank_test">
    <button type="submit" class="btn btn--secondary">Провери credentials</button>
    <span style="font-size:.8rem;color:var(--text-muted);margin-left:.75rem;">
      Изпраща заявка към <?= setting_get('dsk_test_mode', '1') === '1' ? 'uat.dskbank.bg' : 'epg.dskbank.bg' ?>
    </span>
  </form>

  <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0;">
  <h3 style="font-size:.95rem;font-weight:600;margin-bottom:.5rem;">Callback URLs (конфигурирайте в DSK Bank)</h3>
  <p class="admin-meta">Предоставете тези адреси на DSK Bank при настройка на терминала:</p>
  <table style="font-size:.85rem;border-collapse:collapse;width:100%;">
    <tr>
      <td style="padding:.4rem .75rem .4rem 0;color:var(--text-muted);white-space:nowrap;">Return URL</td>
      <td><code><?= h(SITE_URL) ?>/api/payment-return.php</code></td>
    </tr>
    <tr>
      <td style="padding:.4rem .75rem .4rem 0;color:var(--text-muted);white-space:nowrap;">Callback URL</td>
      <td><code><?= h(SITE_URL) ?>/api/payment-callback.php</code></td>
    </tr>
  </table>
  <?php endif; ?>
</section>

<!-- ── IRIS Pay by Bank ─────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">IRIS Pay by Bank (банков превод)</h2>
  <p class="admin-meta" style="margin-bottom:1.25rem;">
    Плащане директно от банковата сметка на клиента (open banking). Регистрирайте се в
    <a href="https://www.irisbgsf.com" target="_blank" rel="noopener">IRIS Solutions</a>
    и въведете Merchant Key и IBAN на получателя.<br>
    Поддържани валути: BGN, RON, EUR. Тестова среда: <code>payperclick.infn.dev</code> — Продукционна: <code>paybyclick.irispay.bg</code>
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="iris">

    <div class="admin-form-grid">
      <label>Merchant Key
        <div style="position:relative;">
          <input type="password" name="iris_merchant_key" value="<?= h(current_val('iris_merchant_key')) ?>"
                 placeholder="xxxxxxxx-xxxx-xxxx-…" style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
      <label>IBAN на получателя
        <input type="text" name="iris_iban" value="<?= h(current_val('iris_iban')) ?>" placeholder="BG..">
      </label>
    </div>

    <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.6rem;">
      <label class="admin-checkbox">
        <input type="checkbox" name="iris_enabled" value="1"
               <?= setting_get('iris_enabled', '0') === '1' ? 'checked' : '' ?>>
        Активирай банков превод при checkout
      </label>
      <label class="admin-checkbox">
        <input type="checkbox" name="iris_test_mode" value="1"
               <?= setting_get('iris_test_mode', '1') === '1' ? 'checked' : '' ?>>
        Тестова среда (payperclick.infn.dev)
      </label>
    </div>

    <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn--primary">Запази</button>
      <?php if (setting_is_set('iris_merchant_key')): ?>
        <span class="badge badge--published">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft">Не е конфигуриран</span>
      <?php endif; ?>
      <?php if (setting_get('iris_enabled', '0') === '1'): ?>
        <span class="badge badge--published">Активен</span>
      <?php else: ?>
        <span class="badge badge--draft">Неактивен</span>
      <?php endif; ?>
    </div>
  </form>

  <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0;">
  <h3 style="font-size:.95rem;font-weight:600;margin-bottom:.5rem;">Callback URLs</h3>
  <p class="admin-meta">
    IRIS получава тези адреси автоматично при всяко плащане — не е нужна ръчна конфигурация.
    Callback-ът се удостоверява с уникален токен за всяка поръчка.
  </p>
  <table style="font-size:.85rem;border-collapse:collapse;width:100%;">
    <tr>
      <td style="padding:.4rem .75rem .4rem 0;color:var(--text-muted);white-space:nowrap;">Callback (hookUrl)</td>
      <td><code><?= h(SITE_URL) ?>/api/iris-payment-callback.php</code></td>
    </tr>
    <tr>
      <td style="padding:.4rem .75rem .4rem 0;color:var(--text-muted);white-space:nowrap;">Return (redirectUrl)</td>
      <td><code><?= h(SITE_URL) ?>/api/iris-payment-return.php</code></td>
    </tr>
  </table>
</section>

<!-- ── TinyMCE ─────────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">TinyMCE (редактор на статии)</h2>
  <p class="admin-meta" style="margin-bottom:1.25rem;">
    Използва се за rich-text редактиране на статии.<br>
    Вземете безплатен API ключ от <a href="https://www.tiny.cloud/" target="_blank" rel="noopener">tiny.cloud</a>.
    Регистрирайте домейна <strong><?= h(parse_url(SITE_URL, PHP_URL_HOST)) ?></strong> и домейна на вашата администрация в Tiny Cloud.
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="tinymce">

    <div class="admin-form-grid" style="grid-template-columns:1fr;">
      <label>TinyMCE API Key
        <div style="position:relative;">
          <input type="password" name="tinymce_api_key"
                 value="<?= h(current_val('tinymce_api_key')) ?>"
                 placeholder="…"
                 style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
    </div>

    <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn--primary">Запази</button>
      <button type="button" id="tinyTestBtn" class="btn btn--outline"
              <?= setting_is_set('tinymce_api_key') ? '' : 'disabled' ?>>Тест</button>
      <?php if (setting_is_set('tinymce_api_key')): ?>
        <span class="badge badge--published">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft">Не е конфигуриран</span>
      <?php endif; ?>
    </div>
  </form>
  <div id="tinyTestResult" style="display:none;margin-top:1rem;padding:.85rem 1.1rem;border-radius:6px;font-size:.88rem;"></div>
</section>

<!-- ── Claude AI ──────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Claude AI (SEO ключови думи + LinkedIn)</h2>
  <p class="admin-meta" style="margin-bottom:1.25rem;">
    Използва се за автоматично предлагане на SEO ключови думи и генериране на LinkedIn публикации.<br>
    Вземете API ключ от <code>console.anthropic.com</code>.
  </p>

  <form method="post" style="margin-bottom:1.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="claude">

    <div class="admin-form-grid" style="grid-template-columns:1fr;">
      <label>Claude API Key
        <div style="position:relative;">
          <input type="password" name="claude_api_key"
                 value="<?= h(current_val('claude_api_key')) ?>"
                 placeholder="sk-ant-…"
                 style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
          <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
        </div>
      </label>
    </div>

    <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn--primary">Запази</button>
      <?php if (setting_is_set('claude_api_key')): ?>
        <span class="badge badge--published">Конфигуриран</span>
      <?php else: ?>
        <span class="badge badge--draft">Не е конфигуриран</span>
      <?php endif; ?>
    </div>
  </form>

  <?php if (setting_is_set('claude_api_key')): ?>
  <?php if ($test_result !== null && isset($_POST['section']) && $_POST['section'] === 'claude_test'): ?>
    <div style="padding:.85rem 1.1rem;border-radius:6px;margin-bottom:1rem;
         <?= $test_result['ok']
             ? 'background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;'
             : 'background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;' ?>">
      <?= $test_result['ok'] ? '✓ ' : '✗ ' ?><?= h($test_result['message']) ?>
    </div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="claude_test">
    <button type="submit" class="btn btn--outline">Тест на връзката</button>
  </form>
  <?php endif; ?>
</section>

<!-- ── Buffer ─────────────────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Buffer (Социални мрежи)</h2>
  <p class="admin-meta" style="margin-bottom:1.25rem;">
    Използва се за планиране на LinkedIn, Facebook и Instagram публикации директно от редактора на статии.<br>
    Вземете API ключ от <a href="https://publish.buffer.com/settings/apps" target="_blank" rel="noopener">publish.buffer.com → Settings → Apps</a>,
    поставете го по-долу и натиснете „Свържи". Каналите се намират автоматично.
  </p>

  <div class="admin-form-grid" style="grid-template-columns:1fr;margin-bottom:1rem;">
    <label>Buffer API Key
      <div style="position:relative;">
        <input type="password" id="bufferApiKeyInput"
               value="<?= h(current_val('buffer_api_key')) ?>"
               placeholder="buffer_pub_…"
               style="padding-right:4.5rem;width:100%;box-sizing:border-box;">
        <button type="button" onclick="togglePwd(this)" class="pwd-toggle">Покажи</button>
      </div>
    </label>
  </div>

  <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
    <button type="button" id="bufferConnectBtn" class="btn btn--primary">
      <?= setting_is_set('buffer_api_key') ? 'Тест / Презапиши' : 'Свържи с Buffer' ?>
    </button>
    <span id="bufferSpinner" style="display:none;font-size:.85rem;color:var(--text-muted);">Свързва се…</span>
    <span id="bufferError"   style="display:none;font-size:.85rem;color:#c0392b;"></span>
  </div>

  <?php
    $buf_connected = setting_is_set('buffer_api_key');
    $buf_channels  = [
      'LinkedIn'  => current_val('buffer_linkedin_channel_id'),
      'Facebook'  => current_val('buffer_facebook_channel_id'),
      'Instagram' => current_val('buffer_instagram_channel_id'),
    ];
  ?>
  <div id="bufferStatus" style="font-size:.88rem;">
    <?php if ($buf_connected): ?>
      <span style="color:#2e7d32;font-weight:600;">✓ Свързан</span>
      — Org: <code><?= h(current_val('buffer_org_id')) ?></code><br>
      <div style="margin-top:.5rem;display:flex;flex-wrap:wrap;gap:.75rem;">
      <?php foreach ($buf_channels as $label => $cid): ?>
        <span style="font-size:.82rem;">
          <?php if ($cid): ?>
            <span style="color:#2e7d32;">✓</span> <?= $label ?>: <code><?= h($cid) ?></code>
          <?php else: ?>
            <span style="color:var(--text-muted);">— <?= $label ?>: не е намерен</span>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
      </div>
    <?php else: ?>
      <span style="color:var(--text-muted);">Не е конфигуриран</span>
    <?php endif; ?>
  </div>
</section>

<script>
document.getElementById('bufferConnectBtn').addEventListener('click', async function() {
  var btn     = this;
  var spinner = document.getElementById('bufferSpinner');
  var errEl   = document.getElementById('bufferError');
  var apiKey  = document.getElementById('bufferApiKeyInput').value.trim();

  if (!apiKey) { errEl.textContent = 'Въведете API ключ.'; errEl.style.display = ''; return; }

  btn.disabled          = true;
  spinner.style.display = '';
  errEl.style.display   = 'none';

  try {
    var r    = await fetch('/admin/buffer-setup-ajax.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ csrf_token: '<?= csrf_token() ?>', buffer_api_key: apiKey })
    });
    var data = await r.json();
    if (!data.ok) {
      errEl.textContent   = data.error;
      errEl.style.display = '';
    } else {
      function chHtml(label, name, id) {
        if (!id) return '<span style="color:var(--text-muted);">— ' + label + ': не е намерен</span>';
        return '<span style="color:#2e7d32;">✓</span> ' + label + ': <strong>' + name + '</strong> (<code>' + id + '</code>)';
      }
      document.getElementById('bufferStatus').innerHTML =
        '<span style="color:#2e7d32;font-weight:600;">✓ Свързан</span>'
        + ' — Org: <strong>' + data.org_name + '</strong> (<code>' + data.org_id + '</code>)<br>'
        + '<div style="margin-top:.5rem;display:flex;flex-wrap:wrap;gap:.75rem;font-size:.82rem;">'
        + chHtml('LinkedIn',  data.linkedin_name,  data.linkedin_id)
        + chHtml('Facebook',  data.facebook_name,  data.facebook_id)
        + chHtml('Instagram', data.instagram_name, data.instagram_id)
        + '</div>';
    }
  } catch(e) {
    errEl.textContent   = 'Грешка при свързване.';
    errEl.style.display = '';
    console.error(e);
  }

  btn.disabled          = false;
  spinner.style.display = 'none';
});
</script>

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
<?php
// Load current sequence values (last_number + 1 = next number to be issued)
$seq_rows = $pdo->query('SELECT type, last_number FROM document_sequences')->fetchAll();
$sequences = array_column($seq_rows, 'last_number', 'type');

$seq_labels = [
    'invoice'       => 'Фактури',
    'receipt'       => 'Електронни бележки',
    'donation_cert' => 'Сертификати за дарение',
];
$seq_formats = [
    'invoice'       => '0000000001 (10 цифри)',
    'receipt'       => '0000000001 (10 цифри)',
    'donation_cert' => '00001 (5 цифри)',
];
?>

<!-- ── Document counters ──────────────────────────────────────────────────────── -->
<section class="admin-card" style="margin-bottom:2rem;">
  <h2 class="admin-card__title">Номерация на документи</h2>
  <p class="admin-meta" style="margin-bottom:1.5rem;">
    Всяко генериране на документ (включително повторно) взима следващия номер от брояча. Задайте желания начален номер преди да генерирате.
  </p>

  <?php foreach ($seq_labels as $type => $label): ?>
  <?php
    $last = (int) ($sequences[$type] ?? 0);
    $next = $last + 1;
    $pad  = ($type === 'donation_cert') ? 5 : 10;
    $next_formatted = str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
  ?>
  <div style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
      <div>
        <div style="font-weight:600;font-size:.95rem;"><?= $label ?></div>
        <div style="margin-top:.3rem;font-size:.85rem;color:var(--text-muted);">
          Следващ номер: <strong style="color:var(--teal);font-family:monospace;font-size:1rem;"><?= $next_formatted ?></strong>
          &nbsp;·&nbsp; Формат: <?= $seq_formats[$type] ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
        <!-- Set next number -->
        <form method="POST" style="display:flex;align-items:center;gap:.4rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="section"  value="doc_sequence_set">
          <input type="hidden" name="seq_type" value="<?= $type ?>">
          <input type="number" name="next_number" value="<?= $next ?>" min="1"
                 style="width:90px;padding:.4rem .6rem;border:1px solid var(--border);border-radius:6px;font-size:.875rem;font-family:monospace;text-align:center;">
          <button type="submit" class="btn btn--outline" style="font-size:.85rem;padding:.4rem .9rem;">
            Задай
          </button>
        </form>
        <!-- Reset -->
        <form method="POST"
              data-confirm="Нулиране на брояча за „<?= $label ?>"? Следващият документ ще получи номер 1."
              data-confirm-ok="Нулирай">
          <?= csrf_field() ?>
          <input type="hidden" name="section"  value="doc_sequence_reset">
          <input type="hidden" name="seq_type" value="<?= $type ?>">
          <button type="submit" class="btn btn--outline"
                  style="font-size:.85rem;padding:.4rem .9rem;color:#c0392b;border-color:#c0392b;">
            Нулирай
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</section>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
