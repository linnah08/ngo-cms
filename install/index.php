<?php
/**
 * Web installation wizard — one short step per screen:
 *   1. Database — created automatically on cPanel hosts; otherwise the host's details.
 *   2. Your login (the first admin).
 *   3. Organisation — only its name, the site address and a contact email.
 *   4. Look — theme, colours, logo (all optional).
 *   5. Modules — which optional parts the organisation will use (includes/modules.php).
 *   6. Check and install — install-run.php runs the migrations, writes the config files, creates the admin.
 * Everything else (legal name, phone, bank account) is filled in later in Админ → Организация.
 *
 * Each step is checked by the server before the next one opens, so a wrong
 * database password shows up at step 1, not after the whole form. Answers are
 * kept in the session until the install finishes (the admin password only as
 * a hash), then cleared. Works without JavaScript.
 *
 * SECURITY: refuses to run once site.config.php exists. Delete this install/
 * directory after a successful install.
 */

$ROOT = dirname(__DIR__);

// ── Guard: already installed? ────────────────────────────────────────────────
$already = is_file($ROOT . '/site.config.php') && is_file($ROOT . '/db.config.php');

// ── Helpers ──────────────────────────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/wizard-lib.php';
require_once dirname(__DIR__) . '/includes/organisation.php';  // org_save_logo()

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

/** cPanel's command-line API. NGO_INSTALL_UAPI points elsewhere for local testing only. */
function uapi_path(): string {
    return (string) (getenv('NGO_INSTALL_UAPI') ?: '/usr/bin/uapi');
}

/** Is this a cPanel account where we can create the database ourselves? */
function cpanel_available(): bool {
    // shell_exec/exec are often disabled on cPanel; proc_open usually is not.
    return proc_enabled() && is_executable(uapi_path());
}

/** Call cPanel UAPI, return decoded ['ok'=>bool,'errors'=>[],'data'=>...]. */
function uapi(string $module, string $func, array $args): array {
    $argv = [uapi_path(), '--output=json', $module, $func];
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

/** null when the database answers, else the reason. */
function db_connect_error(string $host, string $name, string $user, string $pass): ?string {
    try {
        new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return null;
    } catch (PDOException $ex) {
        return $ex->getMessage();
    }
}

/** Where a logo chosen at step 4 waits until the install (validated and re-encoded already). */
function logo_staging_dir(): string {
    if (empty($_SESSION['install']['logo_dir'])) {
        $_SESSION['install']['logo_dir'] = sys_get_temp_dir() . '/ngo-install-' . bin2hex(random_bytes(8));
    }
    return $_SESSION['install']['logo_dir'];
}

function staged_logo(): ?string {
    $dir = $_SESSION['install']['logo_dir'] ?? '';
    return ($dir !== '' && is_file($dir . '/logo.png')) ? $dir . '/logo.png' : null;
}

function go(string $step): never {
    header('Location: ?step=' . rawurlencode($step), true, 303);
    exit;
}

$theme_labels_bg = ['classic' => 'Класически', 'friendly' => 'Приветлив', 'modern' => 'Модерен', 'editorial' => 'Списание'];
$steps = wizard_steps();

// ── Session ──────────────────────────────────────────────────────────────────
$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443;
if (!$already) {
    session_name('ngo_install');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https, 'path' => '/install/']);
    session_start();
    $_SESSION['install'] ??= ['done' => [], 'token' => bin2hex(random_bytes(16))];
}
$done   = $_SESSION['install']['done'] ?? [];
$token  = $_SESSION['install']['token'] ?? '';
$cpanel = !$already && cpanel_available();

$step = (string) ($_GET['step'] ?? '');
if (!isset($steps[$step])) $step = wizard_first_open_step($done);
$return_review = ($_GET['return'] ?? $_POST['return'] ?? '') === 'review' && isset($done['modules']);

$errors  = [];     // field => message
$input   = null;   // the rejected form, to show again
$success = false;
$install_error = null;
$logo_note = null;
$done_final = null;

// ── Handle a step ────────────────────────────────────────────────────────────
if (!$already && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        go($step);   // an expired or foreign form: show the step again rather than trust it
    }
    if (!wizard_step_reachable($step, $done)) go(wizard_first_open_step($done));
    $keys = array_keys($steps);
    $next = $keys[array_search($step, $keys, true) + 1] ?? 'review';

    if ($step === 'review') {
        require __DIR__ . '/install-run.php';   // sets $success, $install_error, $logo_note, $done_final
    } else {
        $result = match ($step) {
            'db'      => wizard_validate_db($_POST, $cpanel, 'db_connect_error'),
            'account' => wizard_validate_account($_POST, $done['account']['password_hash'] ?? null),
            'org'     => wizard_validate_org($_POST),
            'look'    => wizard_validate_look(isset($_POST['skip']) ? [] : $_POST),
            'modules' => wizard_validate_modules($_POST),
        };
        if ($step === 'look') {
            if ((isset($_POST['skip']) || isset($_POST['logo_remove'])) && ($l = staged_logo())) {
                @unlink($l);
                @unlink(dirname($l) . '/favicon.png');
            }
            if (!isset($_POST['skip']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $dir = logo_staging_dir();
                if (!is_dir($dir)) @mkdir($dir, 0700, true);
                $why = org_save_logo($_FILES['logo'], $dir);
                if ($why !== null) $result['errors']['logo'] = $why;
            }
        }
        if ($result['errors']) {
            $errors = $result['errors'];
            $input  = $_POST;
        } else {
            $_SESSION['install']['done'][$step] = $result['data'];
            go($return_review ? 'review' : $next);
        }
    }
} elseif (!$already && !wizard_step_reachable($step, $done)) {
    go(wizard_first_open_step($done));
}

// ── Values to show in a step's fields ────────────────────────────────────────
$guess_url = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$defaults = [
    'db_host'       => 'localhost',
    'site_url'      => $guess_url,
    'site_email'    => $done['account']['admin_email'] ?? '',   // agreed: prefilled, visibly, from the login
    'brand_theme'   => 'classic',
    'brand_primary' => brand_themes()['classic']['primary'],
    'brand_accent'  => brand_themes()['classic']['accent'],
];
$saved = [];
foreach ($done as $d) if (is_array($d)) $saved += $d;
/** The value a field shows: what was just typed, else what was saved, else a sensible default. */
$val = function (string $k) use ($input, $saved, $defaults): string {
    if (is_array($input) && array_key_exists($k, $input) && !is_array($input[$k])) return (string) $input[$k];
    if (array_key_exists($k, $saved) && is_scalar($saved[$k])) return (string) $saved[$k];
    return (string) ($defaults[$k] ?? '');
};

/**
 * One labelled text input. The label says in words whether it is required;
 * hint and error are tied to the input with aria-describedby. $hint is HTML.
 */
$field = function (string $name, string $label, bool $required, string $type = 'text', string $hint = '', string $extra = '') use (&$errors, $val): string {
    $ids  = [];
    $html = '<div class="field' . (isset($errors[$name]) ? ' field-bad' : '') . '">';
    $html .= '<label for="' . e($name) . '">' . e($label) . ' <span class="tag">' . ($required ? '(задължително)' : '(по желание)') . '</span></label>';
    if ($hint !== '') { $html .= '<p class="hint" id="' . e($name) . '-hint">' . $hint . '</p>'; $ids[] = $name . '-hint'; }
    if (isset($errors[$name])) {
        $html .= '<p class="field-error" id="' . e($name) . '-error"><span class="vh">Грешка: </span>' . e($errors[$name]) . '</p>';
        $ids[] = $name . '-error';
    }
    $value = $type === 'password' ? '' : $val($name);
    $html .= '<input type="' . e($type) . '" id="' . e($name) . '" name="' . e($name) . '" value="' . e($value) . '"'
          . ($required ? ' required aria-required="true"' : '')
          . (isset($errors[$name]) ? ' aria-invalid="true"' : '')
          . ($ids ? ' aria-describedby="' . e(implode(' ', $ids)) . '"' : '')
          . ($extra !== '' ? ' ' . $extra : '') . '>';
    return $html . '</div>';
};

$keys    = array_keys($steps);
$step_no = array_search($step, $keys, true) + 1;
$prev    = $step_no > 1 ? $keys[$step_no - 2] : null;
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= ($already || $success) ? 'Инсталиране на сайта' : e('Стъпка ' . $step_no . ' от ' . count($steps) . ': ' . $steps[$step] . ' — Инсталиране на сайта') ?></title>
<style>
  :root { --teal:#03758f; --border:#d9d6d0; --bg:#f8f6f2; --text:#1a1916; --muted:#5c5752; --bad:#b3261e; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg);
         color: var(--text); margin: 0; padding: 1.5rem 1rem 3rem; line-height: 1.5; font-size: 1rem; }
  .wrap { max-width: 640px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 1.75rem; }
  @media (max-width: 520px) { .card { padding: 1.25rem 1rem; } body { padding-top: 1rem; } }
  .site-title { margin: 0 0 .75rem; font-size: .95rem; font-weight: 600; color: var(--muted); }
  h1 { margin: 0 0 .5rem; font-size: 1.45rem; line-height: 1.25; }
  h1:focus { outline: none; }
  .step-of { display: block; font-size: .9rem; font-weight: 600; color: var(--muted); margin-bottom: .2rem; }
  p.intro { margin: 0 0 1.25rem; color: var(--muted); }
  .vh { position: absolute !important; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .progress ol { list-style: none; display: flex; gap: .3rem; margin: 0 0 1.1rem; padding: 0; }
  .progress li { flex: 1; height: 6px; border-radius: 3px; background: var(--border); }
  .progress li.done { background: #7fb9c7; }
  .progress li.current { background: var(--teal); }
  .back { display: inline-flex; align-items: center; min-height: 44px; margin: 0 0 .25rem; color: var(--teal); font-weight: 600; }
  .field { margin: 0 0 1.25rem; }
  label, legend { display: block; font-weight: 600; margin: 0 0 .3rem; font-size: 1rem; padding: 0; }
  .tag { font-weight: 400; color: var(--muted); font-size: .9rem; }
  .hint { font-size: .92rem; color: var(--muted); margin: 0 0 .45rem; }
  code { background: var(--bg); padding: 0 4px; border-radius: 4px; overflow-wrap: anywhere; }
  input[type=text], input[type=email], input[type=url], input[type=password] {
    width: 100%; padding: .65rem .75rem; border: 1px solid #8a857f; border-radius: 7px; font-size: 1rem; font-family: inherit; min-height: 44px; }
  input:focus-visible, button:focus-visible, a:focus-visible, summary:focus-visible { outline: 3px solid #f2b705; outline-offset: 2px; }
  .field-bad input:not([type=checkbox]) { border: 2px solid var(--bad); }
  .field-error { color: var(--bad); font-weight: 600; margin: 0 0 .35rem; font-size: .95rem; }
  fieldset { border: 0; padding: 0; margin: 0 0 1.25rem; min-width: 0; }
  .choice { display: flex; gap: .7rem; align-items: flex-start; padding: .8rem 1rem; border: 1px solid var(--border); border-radius: 10px;
            margin: 0 0 .55rem; cursor: pointer; font-weight: 400; min-height: 44px; }
  .choice input { width: 22px; height: 22px; margin: .1rem 0 0; flex-shrink: 0; accent-color: var(--teal); }
  .choice strong { display: block; }
  .choice .desc { display: block; color: var(--muted); font-size: .9rem; margin-top: .1rem; }
  .choice:has(input:checked) { border-color: var(--teal); box-shadow: 0 0 0 1px var(--teal); }
  .sw { width: 16px; height: 16px; border-radius: 50%; display: inline-block; vertical-align: -2px; margin-right: .4rem; }
  .colours { display: flex; gap: 1rem; flex-wrap: wrap; }
  .colours .field { flex: 1; min-width: 140px; }
  input[type=color] { width: 100%; height: 44px; padding: 3px; border: 1px solid #8a857f; border-radius: 7px; background: #fff; }
  details { margin: 0 0 1.25rem; }
  summary { cursor: pointer; color: var(--teal); font-weight: 600; min-height: 44px; display: list-item; padding-top: .6rem; }
  .box { background: var(--bg); border-radius: 10px; padding: 1rem; margin: 0 0 1.25rem; }
  .box p { margin: 0 0 .5rem; } .box p:last-child { margin: 0; }
  .quote { border-left: 3px solid var(--teal); padding-left: .75rem; font-style: italic; }
  .actions { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; margin-top: 1.5rem; }
  button { background: var(--teal); color: #fff; border: 0; border-radius: 8px; min-height: 48px;
           padding: .75rem 1.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: inherit; }
  button.secondary { background: #fff; color: var(--teal); border: 2px solid var(--teal); }
  @media (max-width: 520px) { .actions button { width: 100%; } }
  .error-summary { border: 3px solid var(--bad); border-radius: 10px; padding: 1rem 1.1rem; margin: 0 0 1.25rem; }
  .error-summary:focus { outline: 3px solid #f2b705; outline-offset: 2px; }
  .error-summary h2 { margin: 0 0 .5rem; font-size: 1.1rem; }
  .error-summary ul { margin: 0; padding-left: 1.2rem; }
  .error-summary a { color: var(--bad); font-weight: 600; }
  .review section { border-top: 1px solid var(--border); padding: .9rem 0; }
  .review h2 { font-size: 1.05rem; margin: 0 0 .4rem; display: flex; justify-content: space-between; gap: 1rem; align-items: baseline; }
  .review h2 a { font-size: .95rem; color: var(--teal); }
  .review dl { margin: 0; display: grid; grid-template-columns: minmax(110px, 38%) 1fr; gap: .25rem .75rem; font-size: .95rem; }
  .review dt { color: var(--muted); } .review dd { margin: 0; overflow-wrap: anywhere; }
  @media (max-width: 520px) { .review dl { grid-template-columns: 1fr; gap: 0; } .review dd { margin-bottom: .45rem; } }
  .alert { padding: 1rem 1.1rem; border-radius: 10px; margin-bottom: 1.25rem; }
  .alert-ok { background: #e6f4ea; border: 1px solid #b5dcc0; }
  .alert-error { background: #fdecea; border: 1px solid #f5c6c2; color: #8a1c12; }
  .card h2 { font-size: 1.1rem; }
  .next-list { padding-left: 1.2rem; }
  .next-list li { margin-bottom: .5rem; }
  .next-list a, .alert a { color: var(--teal); font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">
  <p class="site-title">Инсталиране на сайта</p>
  <div class="card">
<?php if ($already): ?>
    <h1>Сайтът вече е инсталиран</h1>
    <p>От съображения за сигурност изтрийте папката <code>install</code> от File Manager в cPanel.
       Администраторският панел е на адрес <a href="/admin/">/admin/</a>.</p>

<?php elseif ($success): ?>
    <h1 tabindex="-1" id="focus-target">Готово — сайтът работи</h1>
    <div class="alert alert-ok">
      Сайтът е на адрес <a href="<?= e($done_final['org']['site_url']) ?>"><?= e($done_final['org']['site_url']) ?></a>.
      Влезте в <a href="/admin/">администраторския панел</a> с имейла <strong><?= e($done_final['account']['admin_email']) ?></strong> и паролата, която избрахте.
    </div>
<?php if ($logo_note): ?>
    <div class="alert alert-error"><?= e($logo_note) ?></div>
<?php endif; ?>
    <h2>Какво да попълните след това</h2>
    <p class="hint">Не е спешно, но тези данни се показват на дарителите и се печатат на документите. Всичко е в администраторския панел → „Организация“.</p>
    <ul class="next-list">
      <li><a href="/admin/organisation.php#f-site_iban">Банкова сметка (IBAN, BIC, банка)</a> — за да могат хората да даряват по банков път.</li>
      <li><a href="/admin/organisation.php#f-site_legal_name_bg">Юридическо име</a> — ако сайтът е проект на фондация или сдружение с друго име. Печата се на сертификатите и фактурите.</li>
      <li><a href="/admin/organisation.php#f-site_phone">Телефон за контакт</a></li>
      <li><a href="/admin/modules.php">Модули</a> — включвайте и изключвайте допълнителните части по всяко време. Нищо не се губи, когато изключите модул.</li>
    </ul>
    <div class="alert alert-error" style="margin:1.25rem 0 0;">
      <strong>Последна стъпка за сигурност:</strong> изтрийте папката <code>install</code> от File Manager в cPanel.
    </div>

<?php else: ?>
    <nav class="progress" aria-label="Напредък">
      <ol>
<?php foreach ($keys as $i => $k): ?>
        <li class="<?= $i + 1 < $step_no ? 'done' : ($i + 1 === $step_no ? 'current' : '') ?>"<?= $i + 1 === $step_no ? ' aria-current="step"' : '' ?>><span class="vh"><?= e(($i + 1) . '. ' . $steps[$k]) ?><?= $i + 1 < $step_no ? ' — готово' : '' ?></span></li>
<?php endforeach; ?>
      </ol>
    </nav>
<?php if ($return_review): ?>
    <a class="back" href="?step=review">← Обратно към проверката</a>
<?php elseif ($prev): ?>
    <a class="back" href="?step=<?= e($prev) ?>">← Назад</a>
<?php endif; ?>

<?php if ($errors || $install_error): ?>
    <div class="error-summary" id="error-summary" tabindex="-1" aria-labelledby="error-summary-title">
      <h2 id="error-summary-title">Нещо трябва да се поправи</h2>
      <ul>
<?php foreach ($errors as $f => $msg): ?>
        <li><a href="#<?= e($f === 'modules' ? 'modules-group' : $f) ?>"><?= e($msg) ?></a></li>
<?php endforeach; ?>
<?php if ($install_error): ?>
        <li><?= e($install_error) ?></li>
<?php endif; ?>
      </ul>
    </div>
<?php endif; ?>

    <h1 tabindex="-1" id="focus-target"><span class="step-of">Стъпка <?= $step_no ?> от <?= count($steps) ?></span><?= e($steps[$step]) ?></h1>

    <form method="post" action="?step=<?= e($step) ?>" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="token" value="<?= e($token) ?>">
<?php if ($return_review): ?>
      <input type="hidden" name="return" value="review">
<?php endif; ?>

<?php if ($step === 'db'): ?>
<?php   $mode = (!$cpanel || $val('db_mode') === 'existing') ? 'existing' : 'create'; ?>
<?php   if ($cpanel): ?>
      <p class="intro">Сайтът пази съдържанието си в база данни. Ще я създадем вместо вас — не е нужно да въвеждате нищо.</p>
      <fieldset>
        <legend class="vh">База данни</legend>
        <label class="choice"><input type="radio" name="db_mode" value="create" <?= $mode === 'create' ? 'checked' : '' ?>>
          <span><strong>Създайте я автоматично</strong><span class="desc">Препоръчително. Създаваме база данни и потребител с надеждна парола и ги запазваме в настройките на сайта.</span></span></label>
        <label class="choice"><input type="radio" name="db_mode" value="existing" <?= $mode === 'existing' ? 'checked' : '' ?> aria-controls="db-existing">
          <span><strong>Вече имам база данни</strong><span class="desc">Изберете това само ако хостингът или ваш помощник вече я е създал за този сайт.</span></span></label>
      </fieldset>
<?php   else: ?>
      <p class="intro">Сайтът пази съдържанието си в база данни. Създава я хостинг доставчикът — на този сървър не можем да го направим вместо вас.</p>
      <div class="box">
        <p><strong>Нямате тези данни?</strong> Пишете на поддръжката на хостинга, например:</p>
        <p class="quote">„Здравейте, моля създайте MySQL база данни и потребител с пълни права за нея и ми изпратете името на базата, потребителя и паролата.“</p>
        <p>Отговорът им съдържа всичко, което трябва да попълните по-долу.</p>
      </div>
<?php   endif; ?>
      <div id="db-existing"<?= ($cpanel && $mode === 'create') ? ' hidden' : '' ?>>
        <?= $field('db_name', 'Име на базата данни', true, 'text', 'Често започва с името на акаунта ви и долна черта, например <code>akaunt_site</code>.', 'autocomplete="off" spellcheck="false" autocapitalize="off"') ?>
        <?= $field('db_user', 'Потребител', true, 'text', '', 'autocomplete="off" spellcheck="false" autocapitalize="off"') ?>
        <?= $field('db_pass', 'Парола на базата данни', false, 'password', 'Оставете празно само ако хостингът изрично ви е казал, че няма парола.', 'autocomplete="off"') ?>
        <details<?= $val('db_host') !== 'localhost' ? ' open' : '' ?>>
          <summary>Хостингът ми е дал и адрес на сървъра</summary>
          <?= $field('db_host', 'Сървър на базата данни', false, 'text', 'Почти винаги е <code>localhost</code> — сменете го само ако хостингът ви е дал друг.', 'autocomplete="off" spellcheck="false" autocapitalize="off"') ?>
        </details>
      </div>
      <div class="actions"><button type="submit">Напред</button></div>

<?php elseif ($step === 'account'): ?>
<?php   $has_pass = !empty($done['account']['password_hash']); ?>
      <p class="intro">С тези данни ще влизате в администраторския панел, откъдето управлявате сайта.</p>
      <?= $field('admin_email', 'Имейл', true, 'email', '', 'autocomplete="email"') ?>
      <?= $field('admin_password', 'Парола', !$has_pass, 'password',
            $has_pass ? 'Вече сте избрали парола. Оставете полето празно, за да я запазите.' : 'Поне 8 знака. Запишете я на сигурно място.',
            'autocomplete="new-password"') ?>
      <label class="choice" id="show-pass-row" hidden style="margin-top:-.6rem;"><input type="checkbox" id="show-pass"> <span>Покажи паролата</span></label>
      <?= $field('admin_name', 'Вашето име', false, 'text', 'Показва се в администраторския панел.', 'autocomplete="name"') ?>
      <div class="actions"><button type="submit">Напред</button></div>

<?php elseif ($step === 'org'): ?>
      <p class="intro">Само най-необходимото. Юридическо име, телефон и банкова сметка добавяте после от администраторския панел → „Организация“.</p>
      <?= $field('site_name_bg', 'Име на организацията', true, 'text', 'Както искате да се показва на сайта, например „Фондация Пример“.', 'autocomplete="organization"') ?>
      <?= $field('site_name_en', 'Име на английски', false, 'text', 'За английската версия на сайта. Ако го оставите празно, ще се ползва българското.', 'lang="en"') ?>
      <?= $field('site_email', 'Имейл за връзка', true, 'email', 'Показва се публично на сайта и в имейлите до дарители и купувачи. Попълнихме имейла ви за вход — сменете го, ако предпочитате общ адрес като info@….', 'autocomplete="email"') ?>
      <?= $field('site_url', 'Адрес на сайта', true, 'url', 'Попълнен е автоматично и обикновено е правилен. Ползва се в имейлите и плащанията.', 'spellcheck="false" autocapitalize="off"') ?>
      <div class="actions"><button type="submit">Напред</button></div>

<?php elseif ($step === 'look'): ?>
      <p class="intro">Всичко тук е по желание и се сменя по всяко време от администраторския панел → „Организация“.</p>
      <fieldset>
        <legend>Стил <span class="tag">(по желание)</span></legend>
        <p class="hint" id="theme-hint">Шрифт и форма на бутоните. Цветовете по-долу се сменят според стила.</p>
<?php   foreach (brand_themes() as $tk => $tv): ?>
        <label class="choice"><input type="radio" name="brand_theme" value="<?= e($tk) ?>" <?= $val('brand_theme') === $tk ? 'checked' : '' ?> data-p="<?= e($tv['primary']) ?>" data-a="<?= e($tv['accent']) ?>" aria-describedby="theme-hint">
          <span style="font-family:'<?= e($tv['font']) ?>',sans-serif;"><span class="sw" style="background:<?= e($tv['primary']) ?>" aria-hidden="true"></span><?= e($theme_labels_bg[$tk] ?? $tv['label']) ?></span></label>
<?php   endforeach; ?>
      </fieldset>
      <div class="colours">
        <div class="field"><label for="brand_primary">Основен цвят <span class="tag">(по желание)</span></label><input type="color" id="brand_primary" name="brand_primary" value="<?= e($val('brand_primary')) ?>"></div>
        <div class="field"><label for="brand_accent">Допълнителен цвят <span class="tag">(по желание)</span></label><input type="color" id="brand_accent" name="brand_accent" value="<?= e($val('brand_accent')) ?>"></div>
      </div>
      <div class="field<?= isset($errors['logo']) ? ' field-bad' : '' ?>">
        <label for="logo">Лого <span class="tag">(по желание)</span></label>
        <p class="hint" id="logo-hint">PNG, JPG или WebP, до 2 MB. Без лого сайтът показва неутрален знак.<?= staged_logo() ? ' Вече сте качили лого — изберете нов файл, за да го смените.' : '' ?></p>
<?php   if (isset($errors['logo'])): ?>
        <p class="field-error" id="logo-error"><span class="vh">Грешка: </span><?= e($errors['logo']) ?></p>
<?php   endif; ?>
        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-hint<?= isset($errors['logo']) ? ' logo-error' : '' ?>"<?= isset($errors['logo']) ? ' aria-invalid="true"' : '' ?>>
<?php   if (staged_logo()): ?>
        <label class="choice" style="margin-top:.6rem;"><input type="checkbox" name="logo_remove" value="1"> <span>Махни каченото лого</span></label>
<?php   endif; ?>
      </div>
      <div class="actions">
        <button type="submit">Напред</button>
        <button type="submit" name="skip" value="1" class="secondary">Пропусни — стандартна визия</button>
      </div>

<?php elseif ($step === 'modules'): ?>
<?php   $picked = array_flip(is_array($input) ? array_filter((array) ($input['modules'] ?? []), 'is_string') : ($done['modules']['modules'] ?? [])); ?>
      <p class="intro">Страниците и новините са винаги включени. Отбележете допълнителните части, които организацията ви ще ползва.
        Не сте сигурни? Оставете ги — включвате ги по всяко време от администраторския панел → „Модули“.</p>
      <fieldset id="modules-group" tabindex="-1"<?= isset($errors['modules']) ? ' aria-describedby="modules-error"' : '' ?>>
        <legend>Допълнителни части <span class="tag">(по желание)</span></legend>
<?php   if (isset($errors['modules'])): ?>
        <p class="field-error" id="modules-error"><span class="vh">Грешка: </span><?= e($errors['modules']) ?></p>
<?php   endif; ?>
<?php   foreach (modules_registry() as $mname => $mod): ?>
        <label class="choice"><input type="checkbox" name="modules[]" value="<?= e($mname) ?>" <?= isset($picked[$mname]) ? 'checked' : '' ?>>
          <span><strong><?= e($mod['label']) ?></strong><span class="desc"><?= e($mod['description']) ?></span>
<?php     if ($mod['needs']): ?>
            <span class="desc">Нуждае се от: <?= e(implode(', ', array_map(fn($n) => '„' . module_label($n) . '“', $mod['needs']))) ?>.</span>
<?php     endif; ?>
          </span></label>
<?php   endforeach; ?>
      </fieldset>
      <div class="actions"><button type="submit">Напред</button></div>

<?php elseif ($step === 'review'): ?>
<?php
        $d = $done;
        $mod_labels = array_map('module_label', $d['modules']['modules'] ?? []);
        $sections = [
            'db' => ($d['db']['db_mode'] ?? '') === 'create'
                ? ['База данни' => 'ще бъде създадена автоматично']
                : ['База данни' => $d['db']['db_name'] ?? '', 'Потребител' => $d['db']['db_user'] ?? '', 'Сървър' => $d['db']['db_host'] ?? ''],
            'account' => ['Имейл' => $d['account']['admin_email'] ?? '', 'Парола' => 'избрана', 'Име' => ($d['account']['admin_name'] ?? '') ?: '—'],
            'org' => ['Име' => $d['org']['site_name_bg'] ?? '',
                      'На английски' => ($d['org']['site_name_en'] ?? '') ?: (($d['org']['site_name_bg'] ?? '') . ' (същото)'),
                      'Имейл за връзка' => $d['org']['site_email'] ?? '', 'Адрес' => $d['org']['site_url'] ?? ''],
            'look' => ['Стил' => $theme_labels_bg[$d['look']['brand_theme'] ?? 'classic'] ?? '', 'Лого' => staged_logo() ? 'качено' : 'неутрален знак'],
            'modules' => ['Включени' => $mod_labels ? implode(', ', $mod_labels) : 'само страници и новини'],
        ];
?>
      <p class="intro">Проверете отговорите си. Когато натиснете „Инсталирай“, създаваме таблиците и вашия профил. Отнема няколко секунди.</p>
      <div class="review">
<?php   foreach ($sections as $sk => $rows): ?>
        <section aria-labelledby="rv-<?= e($sk) ?>">
          <h2 id="rv-<?= e($sk) ?>"><?= e($steps[$sk]) ?> <a href="?step=<?= e($sk) ?>&amp;return=review">Промени<span class="vh"> — <?= e($steps[$sk]) ?></span></a></h2>
          <dl>
<?php     foreach ($rows as $label => $value): ?>
            <dt><?= e($label) ?></dt><dd><?= e((string) $value) ?></dd>
<?php     endforeach; ?>
          </dl>
        </section>
<?php   endforeach; ?>
      </div>
      <div class="actions"><button type="submit">Инсталирай</button></div>
<?php endif; ?>
    </form>
<?php endif; ?>
  </div>
</div>
<script>
  // Move focus to what changed: the error summary, or the newly opened step's heading.
  (function () {
    var t = document.getElementById('error-summary') || (location.search ? document.getElementById('focus-target') : null);
    if (t) t.focus();
    var box = document.getElementById('db-existing');
    document.querySelectorAll('input[name=db_mode]').forEach(function (r) {
      r.addEventListener('change', function () { if (r.checked) box.hidden = r.value === 'create'; });
    });
    var show = document.getElementById('show-pass'), pass = document.getElementById('admin_password');
    if (show && pass) {
      document.getElementById('show-pass-row').hidden = false;
      show.addEventListener('change', function () { pass.type = show.checked ? 'text' : 'password'; });
    }
    document.querySelectorAll('input[name=brand_theme]').forEach(function (r) {
      r.addEventListener('change', function () {
        document.getElementById('brand_primary').value = r.dataset.p;
        document.getElementById('brand_accent').value  = r.dataset.a;
      });
    });
  })();
</script>
</body>
</html>
