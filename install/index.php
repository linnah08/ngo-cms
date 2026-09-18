<?php
/**
 * Web installation wizard.
 *
 * One page that configures a fresh deployment:
 *   1. Database — use existing credentials, or create a new database on cPanel.
 *   2. Organisation — name, contact, bank details (writes site.config.php).
 *   3. Admin account.
 * It then runs all migrations and creates the first admin.
 *
 * SECURITY: refuses to run once site.config.php exists. Delete this install/
 * directory after a successful install.
 */

$ROOT = dirname(__DIR__);

// ── Guard: already installed? ────────────────────────────────────────────────
$already = is_file($ROOT . '/site.config.php') && is_file($ROOT . '/db.config.php');

// ── Helpers ──────────────────────────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

require_once dirname(__DIR__) . '/includes/themes.php';        // brand_themes()
require_once dirname(__DIR__) . '/includes/organisation.php';  // IBAN/BIC checks, org_save_logo()

function proc_enabled(): bool {
    if (!function_exists('proc_open')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

/** Run a command (argv array — no shell) and return its stdout. */
function run_argv(array $argv): string {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($argv, $desc, $pipes);
    if (!is_resource($proc)) return '';
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return (string) $out;
}

/** Is this a cPanel account where we can auto-create a database? */
function cpanel_available(): bool {
    // shell_exec/exec are often disabled on cPanel; proc_open usually is not.
    return proc_enabled() && is_executable('/usr/bin/uapi');
}

/** Call cPanel UAPI, return decoded ['ok'=>bool,'errors'=>[],'data'=>...]. */
function uapi(string $module, string $func, array $args): array {
    $argv = ['/usr/bin/uapi', '--output=json', $module, $func];
    foreach ($args as $k => $v) $argv[] = $k . '=' . $v;
    $raw  = run_argv($argv);
    $json = json_decode($raw, true);
    $res  = $json['result'] ?? null;
    if (!is_array($res)) return ['ok' => false, 'errors' => ['Неочакван отговор от cPanel: ' . substr($raw, 0, 300)], 'data' => null];
    return [
        'ok'     => ((int) ($res['status'] ?? 0)) === 1,
        'errors' => $res['errors'] ?? [],
        'data'   => $res['data'] ?? null,
    ];
}

/** Render a php config file: array of [CONST => value] → define() lines. */
function write_config(string $path, array $defs, string $header): bool {
    $php = "<?php\n// " . $header . "\n";
    foreach ($defs as $name => $val) {
        $php .= 'define(' . var_export($name, true) . ', ' . var_export($val, true) . ");\n";
    }
    return file_put_contents($path, $php) !== false;
}

$theme_labels_bg = ['classic' => 'Класически', 'friendly' => 'Приветлив', 'modern' => 'Модерен', 'editorial' => 'Списание'];

$errors  = [];
$success = false;
$logo_error = null;
$created_db_info = null;

// Prefill site URL from the current request.
$guess_scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https' : 'http';
$guess_url    = $guess_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// ── Handle submit ────────────────────────────────────────────────────────────
if (!$already && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = fn(string $k) => trim((string) ($_POST[$k] ?? ''));

    // Organisation
    $name_bg = $p('site_name_bg');
    $name_en = $p('site_name_en');
    $site_url = rtrim($p('site_url'), '/');
    $email   = $p('site_email');
    $phone   = $p('site_phone');
    $iban    = $p('site_iban');
    $bic     = $p('site_bic');
    $bank    = $p('site_bank_name');

    // Branding
    $brand_theme   = array_key_exists($p('brand_theme'), brand_themes()) ? $p('brand_theme') : 'classic';
    $brand_primary = preg_match('/^#[0-9a-fA-F]{6}$/', $p('brand_primary')) ? $p('brand_primary') : '#0387A5';
    $brand_accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $p('brand_accent'))  ? $p('brand_accent')  : '#04ADBF';

    // Admin
    $admin_name  = $p('admin_name') ?: 'Администратор';
    $admin_email = $p('admin_email');
    // Trim to match the login form, which trims the password before verifying.
    $admin_pass  = trim((string) ($_POST['admin_password'] ?? ''));

    // Database
    $db_mode = $p('db_mode');
    $db_host = $p('db_host') ?: 'localhost';
    $db_name = $p('db_name');
    $db_user = $p('db_user');
    $db_pass = (string) ($_POST['db_pass'] ?? '');

    // Validate org + admin
    if ($name_bg === '')                                    $errors[] = 'Моля, въведете името на организацията на български.';
    if ($name_en === '')                                    $errors[] = 'Моля, въведете името на организацията на английски.';
    if (!filter_var($site_url, FILTER_VALIDATE_URL))        $errors[] = 'Моля, въведете правилен адрес на сайта, например https://vashata-organizacia.bg';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'Моля, въведете правилен имейл за контакт.';
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL))   $errors[] = 'Моля, въведете правилен имейл за вход на администратора.';
    if (strlen($admin_pass) < 8)                            $errors[] = 'Паролата за вход трябва да е поне 8 знака.';
    if ($iban !== '') {
        $iban = org_normalize_iban($iban);
        if (!org_iban_valid($iban))                         $errors[] = 'Този IBAN не е правилен — вероятно има сгрешена или липсваща цифра. Препишете го внимателно от документ от банката.';
    }
    if ($bic !== '') {
        $bic = strtoupper($bic);
        if (!org_bic_valid($bic))                           $errors[] = 'BIC кодът трябва да е 8 или 11 латински букви и цифри, например STSABGSF.';
    }

    // Optionally create the database on cPanel.
    if (!$errors && $db_mode === 'create' && cpanel_available()) {
        $suffix = strtolower(preg_replace('/[^a-z0-9]/i', '', $p('db_suffix')));
        if ($suffix === '' || strlen($suffix) > 12) {
            $errors[] = 'Краткото име на базата данни трябва да е от 1 до 12 латински букви или цифри.';
        } else {
            $prefix  = get_current_user();              // cPanel account user
            $db_name = $prefix . '_' . $suffix;
            $db_user = $prefix . '_' . $suffix;
            $db_pass = bin2hex(random_bytes(12));
            $db_host = 'localhost';

            $r1 = uapi('Mysql', 'create_database', ['name' => $db_name]);
            if (!$r1['ok']) $errors[] = 'Не успяхме да създадем базата данни. Техническа информация: ' . implode('; ', (array) $r1['errors']);

            if (!$errors) {
                $r2 = uapi('Mysql', 'create_user', ['name' => $db_user, 'password' => $db_pass]);
                if (!$r2['ok']) $errors[] = 'Не успяхме да създадем потребител за базата данни. Техническа информация: ' . implode('; ', (array) $r2['errors']);
            }
            if (!$errors) {
                $r3 = uapi('Mysql', 'set_privileges_on_database',
                    ['user' => $db_user, 'database' => $db_name, 'privileges' => 'ALL PRIVILEGES']);
                if (!$r3['ok']) $errors[] = 'Не успяхме да дадем права на потребителя. Техническа информация: ' . implode('; ', (array) $r3['errors']);
            }
            if (!$errors) $created_db_info = ['name' => $db_name, 'user' => $db_user, 'pass' => $db_pass];
        }
    } elseif (!$errors && $db_mode !== 'create') {
        if ($db_name === '' || $db_user === '') $errors[] = 'Моля, въведете име на базата данни и потребител.';
    }

    // Test the DB connection.
    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
                $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $ex) {
            $errors[] = 'Не успяхме да се свържем с базата данни. Проверете името, потребителя и паролата. Техническа информация: ' . $ex->getMessage();
        }
    }

    // Write config files + run migrations + create admin.
    if (!$errors) {
        write_config($ROOT . '/db.config.php', [
            'DB_HOST' => $db_host,
            'DB_NAME' => $db_name,
            'DB_USER' => $db_user,
            'DB_PASS' => $db_pass,
            'SETTINGS_ENCRYPTION_KEY' => bin2hex(random_bytes(32)),
        ], 'Database connection — generated by the install wizard.');

        write_config($ROOT . '/site.config.php', [
            'SITE_NAME_BG' => $name_bg,
            'SITE_NAME_EN' => $name_en,
            'SITE_URL'     => $site_url,
            'SITE_EMAIL'   => $email,
            'SITE_PHONE'   => $phone,
            'SITE_IBAN'    => $iban,
            'SITE_BIC'     => $bic,
            'SITE_BANK_NAME' => $bank,
            'BRAND_THEME'    => $brand_theme,
            'BRAND_PRIMARY'  => $brand_primary,
            'BRAND_ACCENT'   => $brand_accent,
            'SIGNING_ADMIN_EMAIL' => $admin_email,
            'DONATION_PURPOSE_BG' => 'За дейността и програмите на ' . $name_bg,
            'DONATION_PURPOSE_EN' => 'For the activities and programmes of ' . $name_en,
            'SOCIAL_FACEBOOK'  => '',
            'SOCIAL_INSTAGRAM' => '',
            'SOCIAL_LINKEDIN'  => '',
            'GTM_ID' => '', 'GA4_ID' => '', 'GOOGLE_ADS_ID' => '', 'GOOGLE_ADS_PURCHASE_LABEL' => '',
            'EUR_BGN_RATE' => 1.95583,
            'DUAL_CURRENCY_UNTIL' => '2026-07-01',
            'FEATURE_CAMPAIGN' => true,
        ], 'Organisation configuration — generated by the install wizard. Edit freely.');

        // Optional logo upload → assets/images/logo.png (templates reference that path).
        if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // A bad logo must not block the install — it can be re-uploaded
            // from Admin → Организация. The error is shown on the success page.
            $logo_error = org_save_logo($_FILES['logo'], $ROOT . '/assets/images');
        }

        // Run migrations (migrate.php reads the db.config.php we just wrote).
        // migrate.php defines run_migrations() and only auto-runs itself when
        // invoked directly via the CLI, so it must be called explicitly here.
        require_once $ROOT . '/migrate.php';
        ob_start();
        $migration_result = run_migrations();
        $migrate_output = ob_get_clean();

        if (!$migration_result['success']) {
            $errors[] = 'Не успяхме да създадем таблиците в базата данни. Техническа информация: ' . ($migration_result['error'] ?? 'неизвестна грешка');
        } else {
            // Create the first admin.
            try {
                $n = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
                if ($n === 0) {
                    $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,\'admin\')')
                        ->execute([$admin_name, $admin_email, password_hash($admin_pass, PASSWORD_DEFAULT)]);
                }
                $success = true;
            } catch (Throwable $ex) {
                $errors[] = 'Таблиците са създадени, но администраторът не можа да бъде добавен. Техническа информация: ' . $ex->getMessage();
            }
        }
    }
}

// Repopulate form values after a failed POST.
$v = fn(string $k, string $d = '') => e((string) ($_POST[$k] ?? $d));
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Инсталиране на сайта</title>
<style>
  :root { --teal:#0387A5; --border:#e2e0db; --bg:#f8f6f2; --text:#1a1916; --muted:#6b6560; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg);
         color: var(--text); margin: 0; padding: 2rem 1rem; line-height: 1.5; }
  .wrap { max-width: 680px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
  h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
  p.sub { color: var(--muted); margin: 0 0 1.5rem; }
  h2 { font-size: 1rem; margin: 1.75rem 0 .75rem; padding-bottom: .35rem; border-bottom: 1px solid var(--border); }
  label { display: block; font-size: .85rem; font-weight: 600; margin: .85rem 0 .3rem; }
  input[type=text], input[type=email], input[type=url], input[type=password] {
    width: 100%; padding: .55rem .7rem; border: 1px solid #d1d5db; border-radius: 7px; font-size: .92rem; font-family: inherit; }
  .row { display: flex; gap: 1rem; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 200px; }
  .hint { font-size: .78rem; color: var(--muted); margin-top: .25rem; }
  button { margin-top: 1.75rem; background: var(--teal); color: #fff; border: 0; border-radius: 8px;
           padding: .75rem 1.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; }
  .alert { padding: .85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: .9rem; }
  .alert-error { background: #fdecea; border: 1px solid #f5c6c2; color: #8a1c12; }
  .alert-ok { background: #e6f4ea; border: 1px solid #b5dcc0; color: #1b5e2a; }
  .alert-ok code { background: #fff; padding: 1px 5px; border-radius: 4px; }
  .radio { display: flex; gap: 1.25rem; margin: .4rem 0 .25rem; }
  .radio label { font-weight: 500; display: flex; align-items: center; gap: .4rem; margin: 0; }
  .themes { display: flex; gap: .55rem; flex-wrap: wrap; margin: .4rem 0 .35rem; }
  .theme-card { display: flex; align-items: center; gap: .45rem; border: 1px solid var(--border); border-radius: 8px; padding: .45rem .7rem; cursor: pointer; font-size: .9rem; }
  .theme-card input { accent-color: var(--teal); }
  .theme-card .sw { width: 15px; height: 15px; border-radius: 50%; display: inline-block; }
  .theme-card:has(input:checked) { border-color: var(--teal); box-shadow: 0 0 0 1px var(--teal); }
  fieldset { border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin: 0; }
  .muted-box { background: var(--bg); border-radius: 8px; padding: 1rem; font-size: .85rem; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Инсталиране на сайта</h1>
    <p class="sub">Попълнете формата веднъж. Тя записва настройките на сайта, създава базата данни и вашия профил за вход в администраторския панел.</p>

<?php if ($already): ?>
    <div class="alert alert-error">
      Сайтът вече е инсталиран. От съображения за сигурност изтрийте папката <code>install</code>
      от File Manager в cPanel. Администраторският панел е на адрес <a href="/admin/">/admin/</a>.
    </div>
<?php elseif ($success): ?>
    <div class="alert alert-ok">
      <strong>Инсталирането завърши успешно.</strong><br>
      Сайтът ви работи на адрес <a href="<?= e($_POST['site_url'] ?? '/') ?>"><?= e($_POST['site_url'] ?? '/') ?></a>.
      Влезте в администраторския панел на <a href="/admin/">/admin/</a>.
<?php if ($created_db_info): ?>
      <br><br>Създадена е база данни:<br>
      име <code><?= e($created_db_info['name']) ?></code>,
      потребител <code><?= e($created_db_info['user']) ?></code>,
      парола <code><?= e($created_db_info['pass']) ?></code><br>
      Те вече са запазени в настройките на сайта — не е нужно да ги пазите отделно.
<?php endif; ?>
      <br><br><strong>Сега изтрийте папката <code>install</code> от File Manager в cPanel.</strong>
    </div>
<?php if ($logo_error): ?>
    <div class="alert alert-error">
      <strong>Логото не беше качено:</strong> <?= e($logo_error) ?><br>
      Сайтът работи с неутрално лого. Можете да качите вашето от администрацията → „Организация“.
    </div>
<?php endif; ?>
<?php else: ?>
<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <h2>1. База данни</h2>
<?php if (cpanel_available()): ?>
      <div class="radio">
        <label><input type="radio" name="db_mode" value="create" <?= ($v('db_mode','create')==='create')?'checked':'' ?> onclick="dbMode('create')"> Създай нова база данни (препоръчително)</label>
        <label><input type="radio" name="db_mode" value="existing" <?= ($v('db_mode')==='existing')?'checked':'' ?> onclick="dbMode('existing')"> Имам вече създадена база данни</label>
      </div>
      <div id="db-create">
        <label>Кратко име на базата данни</label>
        <input type="text" name="db_suffix" value="<?= $v('db_suffix') ?>" placeholder="напр. site" maxlength="12">
        <div class="hint">Само латински букви и цифри, до 12 знака. Ще бъдат създадени база данни и потребител с име <code><?= e(get_current_user()) ?>_…</code> и автоматично генерирана парола.</div>
      </div>
<?php else: ?>
      <input type="hidden" name="db_mode" value="existing">
<?php endif; ?>
      <div id="db-existing">
        <div class="row">
          <div><label>Сървър на базата данни</label><input type="text" name="db_host" value="<?= $v('db_host','localhost') ?>"></div>
          <div><label>Име на базата данни</label><input type="text" name="db_name" value="<?= $v('db_name') ?>"></div>
        </div>
        <div class="row">
          <div><label>Потребител</label><input type="text" name="db_user" value="<?= $v('db_user') ?>"></div>
          <div><label>Парола</label><input type="password" name="db_pass"></div>
        </div>
        <div class="hint">Сървърът обикновено е <code>localhost</code>. Името на базата и потребителят включват префикса, който cPanel добавя — например <code>akaunt_site</code>.</div>
      </div>

      <h2>2. Организация</h2>
      <div class="row">
        <div><label>Име на организацията (на български)</label><input type="text" name="site_name_bg" value="<?= $v('site_name_bg') ?>" required></div>
        <div><label>Име на организацията (на английски)</label><input type="text" name="site_name_en" value="<?= $v('site_name_en') ?>" required></div>
      </div>
      <label>Адрес на сайта</label>
      <input type="url" name="site_url" value="<?= $v('site_url', $guess_url) ?>" required>
      <div class="hint">Пълният адрес с <code>https://</code>, без наклонена черта накрая. Използва се във всички имейли и плащания.</div>
      <div class="row">
        <div><label>Имейл за контакт</label><input type="email" name="site_email" value="<?= $v('site_email') ?>" required></div>
        <div><label>Телефон за контакт</label><input type="text" name="site_phone" value="<?= $v('site_phone') ?>"></div>
      </div>
      <div class="row">
        <div><label>IBAN (за дарения)</label><input type="text" name="site_iban" value="<?= $v('site_iban') ?>"></div>
        <div><label>BIC</label><input type="text" name="site_bic" value="<?= $v('site_bic') ?>"></div>
      </div>
      <label>Име на банката</label>
      <input type="text" name="site_bank_name" value="<?= $v('site_bank_name') ?>">
      <div class="hint">Банковата сметка се показва на дарителите. Попълнете я сега, ако я имате под ръка.</div>

      <h2>3. Визия</h2>
      <label>Стил</label>
      <div class="themes">
        <?php foreach (brand_themes() as $tk => $tv): ?>
        <label class="theme-card">
          <input type="radio" name="brand_theme" value="<?= e($tk) ?>" <?= ($v('brand_theme','classic')===$tk)?'checked':'' ?> onchange="pickTheme('<?= e($tk) ?>')">
          <span class="sw" style="background:<?= e($tv['primary']) ?>"></span>
          <span style="font-family:'<?= e($tv['font']) ?>',sans-serif;"><?= e($theme_labels_bg[$tk] ?? $tv['label']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Изберете визия (шрифт и форма). Цветовете по-долу се попълват според стила — можете да ги промените.</div>
      <div class="row">
        <div><label>Основен цвят</label><input type="color" name="brand_primary" value="<?= $v('brand_primary','#0387A5') ?>" style="height:44px;padding:3px;"></div>
        <div><label>Допълнителен цвят</label><input type="color" name="brand_accent" value="<?= $v('brand_accent','#04ADBF') ?>" style="height:44px;padding:3px;"></div>
      </div>
      <label>Лого <span style="font-weight:400;color:var(--muted);">(по желание — PNG, JPG или WebP)</span></label>
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
      <div class="hint">Ако го оставите празно, ще се покаже неутрално лого. Можете да го смените по всяко време от администрацията → „Организация“.</div>

      <h2>4. Администратор</h2>
      <div class="row">
        <div><label>Вашето име</label><input type="text" name="admin_name" value="<?= $v('admin_name') ?>"></div>
        <div><label>Имейл за вход</label><input type="email" name="admin_email" value="<?= $v('admin_email') ?>" required></div>
      </div>
      <label>Парола за вход</label>
      <input type="password" name="admin_password" required>
      <div class="hint">Поне 8 знака. Запишете я на сигурно място.</div>

      <button type="submit">Инсталирай</button>
    </form>
    <script>
      function dbMode(m){
        var c = document.getElementById('db-create'), x = document.getElementById('db-existing');
        if (c) c.style.display = (m==='create')   ? '' : 'none';
        if (x) x.style.display = (m==='existing') ? '' : 'none';
      }
      dbMode(document.querySelector('input[name=db_mode]:checked')?.value || 'existing');
      var THEMES = <?= json_encode(array_map(fn($t) => ['p' => $t['primary'], 'a' => $t['accent']], brand_themes())) ?>;
      function pickTheme(k){ var t = THEMES[k]; if(!t) return;
        document.querySelector('input[name=brand_primary]').value = t.p;
        document.querySelector('input[name=brand_accent]').value  = t.a; }
    </script>
<?php endif; ?>
  </div>
</div>
</body>
</html>
