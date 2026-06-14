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
    if (!is_array($res)) return ['ok' => false, 'errors' => ['Unexpected response: ' . substr($raw, 0, 300)], 'data' => null];
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

$errors  = [];
$success = false;
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

    // Admin
    $admin_name  = $p('admin_name') ?: 'Administrator';
    $admin_email = $p('admin_email');
    $admin_pass  = (string) ($_POST['admin_password'] ?? '');

    // Database
    $db_mode = $p('db_mode');
    $db_host = $p('db_host') ?: 'localhost';
    $db_name = $p('db_name');
    $db_user = $p('db_user');
    $db_pass = (string) ($_POST['db_pass'] ?? '');

    // Validate org + admin
    if ($name_bg === '')                                    $errors[] = 'Site name (BG) is required.';
    if ($name_en === '')                                    $errors[] = 'Site name (EN) is required.';
    if (!filter_var($site_url, FILTER_VALIDATE_URL))        $errors[] = 'A valid site URL is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'A valid organisation email is required.';
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL))   $errors[] = 'A valid admin email is required.';
    if (strlen($admin_pass) < 8)                            $errors[] = 'Admin password must be at least 8 characters.';

    // Optionally create the database on cPanel.
    if (!$errors && $db_mode === 'create' && cpanel_available()) {
        $suffix = strtolower(preg_replace('/[^a-z0-9]/i', '', $p('db_suffix')));
        if ($suffix === '' || strlen($suffix) > 12) {
            $errors[] = 'Database name must be 1–12 letters/digits.';
        } else {
            $prefix  = get_current_user();              // cPanel account user
            $db_name = $prefix . '_' . $suffix;
            $db_user = $prefix . '_' . $suffix;
            $db_pass = bin2hex(random_bytes(12));
            $db_host = 'localhost';

            $r1 = uapi('Mysql', 'create_database', ['name' => $db_name]);
            if (!$r1['ok']) $errors[] = 'Create database: ' . implode('; ', (array) $r1['errors']);

            if (!$errors) {
                $r2 = uapi('Mysql', 'create_user', ['name' => $db_user, 'password' => $db_pass]);
                if (!$r2['ok']) $errors[] = 'Create DB user: ' . implode('; ', (array) $r2['errors']);
            }
            if (!$errors) {
                $r3 = uapi('Mysql', 'set_privileges_on_database',
                    ['user' => $db_user, 'database' => $db_name, 'privileges' => 'ALL PRIVILEGES']);
                if (!$r3['ok']) $errors[] = 'Grant privileges: ' . implode('; ', (array) $r3['errors']);
            }
            if (!$errors) $created_db_info = ['name' => $db_name, 'user' => $db_user, 'pass' => $db_pass];
        }
    } elseif (!$errors && $db_mode !== 'create') {
        if ($db_name === '' || $db_user === '') $errors[] = 'Database name and user are required.';
    }

    // Test the DB connection.
    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
                $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $ex) {
            $errors[] = 'Could not connect to the database: ' . $ex->getMessage();
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

        // Run migrations (migrate.php reads the db.config.php we just wrote).
        ob_start();
        require $ROOT . '/migrate.php';
        $migrate_output = ob_get_clean();

        // Create the first admin.
        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($n === 0) {
                $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,\'admin\')')
                    ->execute([$admin_name, $admin_email, password_hash($admin_pass, PASSWORD_DEFAULT)]);
            }
            $success = true;
        } catch (Throwable $ex) {
            $errors[] = 'Migrations ran but admin creation failed: ' . $ex->getMessage();
        }
    }
}

// Repopulate form values after a failed POST.
$v = fn(string $k, string $d = '') => e((string) ($_POST[$k] ?? $d));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install — NGO platform</title>
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
  fieldset { border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin: 0; }
  .muted-box { background: var(--bg); border-radius: 8px; padding: 1rem; font-size: .85rem; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Install your NGO site</h1>
    <p class="sub">Fill this in once. It writes your configuration, creates the database tables, and your admin login.</p>

<?php if ($already): ?>
    <div class="alert alert-error">
      This site is already installed (<code>site.config.php</code> exists). For security, delete the
      <code>install/</code> directory. To reconfigure, edit <code>site.config.php</code> directly.
    </div>
<?php elseif ($success): ?>
    <div class="alert alert-ok">
      <strong>Installation complete.</strong><br>
      Your site is live at <a href="<?= e($_POST['site_url'] ?? '/') ?>"><?= e($_POST['site_url'] ?? '/') ?></a>
      and you can log in at <a href="/admin/">/admin/</a>.
<?php if ($created_db_info): ?>
      <br><br>A database was created for you:<br>
      name <code><?= e($created_db_info['name']) ?></code>,
      user <code><?= e($created_db_info['user']) ?></code>,
      password <code><?= e($created_db_info['pass']) ?></code><br>
      (saved in <code>db.config.php</code>).
<?php endif; ?>
      <br><br><strong>Now delete the <code>install/</code> directory.</strong>
    </div>
<?php else: ?>
<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>
    <form method="post">
      <h2>1. Database</h2>
<?php if (cpanel_available()): ?>
      <div class="radio">
        <label><input type="radio" name="db_mode" value="create" <?= ($v('db_mode','create')==='create')?'checked':'' ?> onclick="dbMode('create')"> Create a new database (cPanel)</label>
        <label><input type="radio" name="db_mode" value="existing" <?= ($v('db_mode')==='existing')?'checked':'' ?> onclick="dbMode('existing')"> Use existing credentials</label>
      </div>
      <div id="db-create">
        <label>Database name suffix</label>
        <input type="text" name="db_suffix" value="<?= $v('db_suffix') ?>" placeholder="e.g. site" maxlength="12">
        <div class="hint">A database and user named <code><?= e(get_current_user()) ?>_&lt;suffix&gt;</code> will be created with a generated password.</div>
      </div>
<?php else: ?>
      <input type="hidden" name="db_mode" value="existing">
<?php endif; ?>
      <div id="db-existing">
        <div class="row">
          <div><label>DB host</label><input type="text" name="db_host" value="<?= $v('db_host','localhost') ?>"></div>
          <div><label>DB name</label><input type="text" name="db_name" value="<?= $v('db_name') ?>"></div>
        </div>
        <div class="row">
          <div><label>DB user</label><input type="text" name="db_user" value="<?= $v('db_user') ?>"></div>
          <div><label>DB password</label><input type="password" name="db_pass"></div>
        </div>
      </div>

      <h2>2. Organisation</h2>
      <div class="row">
        <div><label>Site name (Bulgarian)</label><input type="text" name="site_name_bg" value="<?= $v('site_name_bg') ?>" required></div>
        <div><label>Site name (English)</label><input type="text" name="site_name_en" value="<?= $v('site_name_en') ?>" required></div>
      </div>
      <label>Public site URL</label>
      <input type="url" name="site_url" value="<?= $v('site_url', $guess_url) ?>" required>
      <div class="hint">No trailing slash. All emails and payment callbacks derive from this.</div>
      <div class="row">
        <div><label>Contact email</label><input type="email" name="site_email" value="<?= $v('site_email') ?>" required></div>
        <div><label>Contact phone</label><input type="text" name="site_phone" value="<?= $v('site_phone') ?>"></div>
      </div>
      <div class="row">
        <div><label>IBAN (for donations)</label><input type="text" name="site_iban" value="<?= $v('site_iban') ?>"></div>
        <div><label>BIC</label><input type="text" name="site_bic" value="<?= $v('site_bic') ?>"></div>
      </div>
      <label>Bank name</label>
      <input type="text" name="site_bank_name" value="<?= $v('site_bank_name') ?>">

      <h2>3. Administrator account</h2>
      <div class="row">
        <div><label>Your name</label><input type="text" name="admin_name" value="<?= $v('admin_name') ?>"></div>
        <div><label>Admin email</label><input type="email" name="admin_email" value="<?= $v('admin_email') ?>" required></div>
      </div>
      <label>Admin password</label>
      <input type="password" name="admin_password" required>
      <div class="hint">At least 8 characters.</div>

      <button type="submit">Install</button>
    </form>
    <script>
      function dbMode(m){
        document.getElementById('db-create').style.display   = (m==='create')   ? '' : 'none';
        document.getElementById('db-existing').style.display = (m==='existing') ? '' : 'none';
      }
      dbMode(document.querySelector('input[name=db_mode]:checked')?.value || 'existing');
    </script>
<?php endif; ?>
  </div>
</div>
</body>
</html>
