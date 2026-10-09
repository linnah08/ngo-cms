<?php
/**
 * The install wizard's rules, kept apart from install/index.php (which handles
 * the session, pages and side effects) so they can be tested on their own.
 *
 * Each wizard_validate_*() takes the raw form input of one step and returns
 * ['data' => clean values, 'errors' => [field name => plain-Bulgarian message]].
 * Field names double as the input ids, so the error summary can link to them.
 */

require_once dirname(__DIR__) . '/includes/themes.php';    // brand_themes()
require_once dirname(__DIR__) . '/includes/modules.php';   // modules_registry(), modules_install_flags()

/** step key => title, in order. */
function wizard_steps(): array
{
    return [
        'db'      => 'База данни',
        'account' => 'Вашият вход',
        'org'     => 'Организация',
        'look'    => 'Визия',
        'modules' => 'Модули',
        'review'  => 'Проверка и инсталиране',
    ];
}

/** The first step not yet completed ($done = step key => data); 'review' once all are. */
function wizard_first_open_step(array $done): string
{
    foreach (array_keys(wizard_steps()) as $step) {
        if ($step === 'review' || !isset($done[$step])) return $step;
    }
    return 'review';
}

/** May this step be opened, given what is done? (Only steps up to the first open one.) */
function wizard_step_reachable(string $step, array $done): bool
{
    $keys = array_keys(wizard_steps());
    $pos  = array_search($step, $keys, true);
    return $pos !== false && $pos <= array_search(wizard_first_open_step($done), $keys, true);
}

function wizard_trim(array $in, string $k): string
{
    return trim((string) ($in[$k] ?? ''));
}

/**
 * Step 1. $cpanel: this host lets us create the database ourselves, so "create"
 * needs no input at all. $connect(host, name, user, pass) returns null when the
 * database answers, or the reason it didn't — only called for an existing one.
 */
function wizard_validate_db(array $in, bool $cpanel, ?callable $connect = null): array
{
    $mode = ($cpanel && wizard_trim($in, 'db_mode') !== 'existing') ? 'create' : 'existing';
    if ($mode === 'create') return ['data' => ['db_mode' => 'create'], 'errors' => []];

    $data = [
        'db_mode' => 'existing',
        'db_host' => wizard_trim($in, 'db_host') ?: 'localhost',
        'db_name' => wizard_trim($in, 'db_name'),
        'db_user' => wizard_trim($in, 'db_user'),
        'db_pass' => (string) ($in['db_pass'] ?? ''),
    ];
    $errors = [];
    if ($data['db_name'] === '') $errors['db_name'] = 'Въведете името на базата данни.';
    if ($data['db_user'] === '') $errors['db_user'] = 'Въведете потребителя на базата данни.';
    if (!$errors && $connect) {
        $why = $connect($data['db_host'], $data['db_name'], $data['db_user'], $data['db_pass']);
        if ($why !== null) {
            $errors['db_pass'] = 'Не успяхме да се свържем с базата данни с тези данни. Проверете името, потребителя и паролата — '
                               . 'най-често липсва представката, която хостингът добавя (например akaunt_site вместо site).';
        }
    }
    return ['data' => $data, 'errors' => $errors];
}

/**
 * Step 2. The password is hashed straight away, so it is never kept in plain
 * text between steps. Left empty on a revisit, the earlier one stays.
 */
function wizard_validate_account(array $in, ?string $existing_hash = null): array
{
    $email = wizard_trim($in, 'admin_email');
    // Trimmed to match the login form, which trims the password before verifying.
    $pass  = trim((string) ($in['admin_password'] ?? ''));
    $errors = [];
    if ($email === '')                                    $errors['admin_email'] = 'Въведете имейла, с който ще влизате.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors['admin_email'] = 'Този имейл не изглежда правилен. Проверете за правописна грешка, например липсващо @.';
    $hash = $existing_hash;
    if ($pass === '' && $existing_hash === null)          $errors['admin_password'] = 'Изберете парола за вход.';
    elseif ($pass !== '' && mb_strlen($pass) < 8)         $errors['admin_password'] = 'Паролата трябва да е поне 8 знака.';
    elseif ($pass !== '')                                 $hash = password_hash($pass, PASSWORD_DEFAULT);
    return [
        'data'   => ['admin_name' => mb_substr(wizard_trim($in, 'admin_name'), 0, 100), 'admin_email' => $email, 'password_hash' => $hash],
        'errors' => $errors,
    ];
}

/** Step 3. Only what the site cannot start without; the rest is in Админ → Организация. */
function wizard_validate_org(array $in): array
{
    $data = [
        'site_name_bg' => mb_substr(wizard_trim($in, 'site_name_bg'), 0, 150),
        'site_name_en' => mb_substr(wizard_trim($in, 'site_name_en'), 0, 150),
        'site_url'     => rtrim(wizard_trim($in, 'site_url'), '/'),
        'site_email'   => wizard_trim($in, 'site_email'),
    ];
    $errors = [];
    if ($data['site_name_bg'] === '') $errors['site_name_bg'] = 'Въведете името на организацията.';
    if (!filter_var($data['site_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $data['site_url'])) {
        $errors['site_url'] = 'Въведете пълния адрес на сайта, например https://vashata-organizacia.bg';
    }
    if ($data['site_email'] === '')                                  $errors['site_email'] = 'Въведете имейл за връзка с организацията.';
    elseif (!filter_var($data['site_email'], FILTER_VALIDATE_EMAIL)) $errors['site_email'] = 'Този имейл не изглежда правилен. Проверете за правописна грешка.';
    return ['data' => $data, 'errors' => $errors];
}

/** Step 4. Nothing here is required; anything odd falls back to the theme's own colours. */
function wizard_validate_look(array $in): array
{
    $themes = brand_themes();
    $theme  = array_key_exists(wizard_trim($in, 'brand_theme'), $themes) ? wizard_trim($in, 'brand_theme') : 'classic';
    $colour = static fn(string $k, string $d) => preg_match('/^#[0-9a-fA-F]{6}$/', wizard_trim($in, $k)) ? wizard_trim($in, $k) : $d;
    return ['data' => [
        'brand_theme'   => $theme,
        'brand_primary' => $colour('brand_primary', $themes[$theme]['primary']),
        'brand_accent'  => $colour('brand_accent', $themes[$theme]['accent']),
    ], 'errors' => []];
}

/** Step 5. */
function wizard_validate_modules(array $in): array
{
    $known  = modules_registry();
    $chosen = array_values(array_filter((array) ($in['modules'] ?? []), fn($m) => is_string($m) && isset($known[$m])));
    $on     = array_fill_keys($chosen, true);
    $problems = modules_needs_problems($on);
    return ['data' => ['modules' => $chosen], 'errors' => $problems ? ['modules' => implode(' ', $problems)] : []];
}

/**
 * A database name for a new cPanel database: <account>_site, or _site2, _site3…
 * when taken. Kept to 16 characters after the prefix-free part so the matching
 * MySQL user name (same string) stays within MySQL's 32-character limit.
 */
function wizard_pick_db_name(string $account, array $existing): string
{
    $taken = array_flip($existing);
    for ($i = 1; $i < 100; $i++) {
        $name = $account . '_site' . ($i === 1 ? '' : $i);
        if (!isset($taken[$name])) return $name;
    }
    return $account . '_' . bin2hex(random_bytes(3));
}

/** The define()s for site.config.php from a finished wizard. */
function wizard_site_config(array $done): array
{
    $org  = $done['org'];
    $look = $done['look'];
    $name_en = $org['site_name_en'] !== '' ? $org['site_name_en'] : $org['site_name_bg'];
    return array_merge([
        'SITE_NAME_BG'       => $org['site_name_bg'],
        'SITE_NAME_EN'       => $name_en,
        'SITE_LEGAL_NAME_BG' => '',
        'SITE_LEGAL_NAME_EN' => '',
        'SITE_URL'           => $org['site_url'],
        'SITE_EMAIL'         => $org['site_email'],
        'SITE_PHONE'         => '',
        'SITE_IBAN'          => '',
        'SITE_BIC'           => '',
        'SITE_BANK_NAME'     => '',
        'BRAND_THEME'        => $look['brand_theme'],
        'BRAND_PRIMARY'      => $look['brand_primary'],
        'BRAND_ACCENT'       => $look['brand_accent'],
        'SIGNING_ADMIN_EMAIL' => $done['account']['admin_email'],
        'DONATION_PURPOSE_BG' => 'За дейността и програмите на ' . $org['site_name_bg'],
        'DONATION_PURPOSE_EN' => 'For the activities and programmes of ' . $name_en,
        'SOCIAL_FACEBOOK'  => '',
        'SOCIAL_INSTAGRAM' => '',
        'SOCIAL_LINKEDIN'  => '',
        'GTM_ID' => '', 'GA4_ID' => '', 'GOOGLE_ADS_ID' => '', 'GOOGLE_ADS_PURCHASE_LABEL' => '',
    ], modules_install_flags($done['modules']['modules'] ?? []));
}
