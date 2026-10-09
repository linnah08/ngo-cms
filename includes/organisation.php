<?php
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/modules.php';   // modules_registry(): the optional modules and their switches
/**
 * Organisation identity that admins can change after installation
 * (admin/organisation.php): name, contacts, bank details, brand, social links.
 *
 * The install wizard writes these as constants in site.config.php. Changes made
 * in the admin are saved to content/organisation.json (flat JSON, like
 * partners.json / impact.json) and win over site.config.php: config.php defines
 * the saved values first, then loads site.config.php while silencing only the
 * "already defined" warnings for those constants. So every existing reader of
 * SITE_IBAN, SITE_NAME_BG, … picks up the new value without being changed, and
 * public pages never need the database for it.
 *
 * Standalone on purpose — loaded by config.php before anything else is defined,
 * and by the install wizard, which has no config.php yet.
 */

/** Form field => constant it overrides. Also the whitelist for the JSON file. */
function org_fields(): array
{
    return [
        'site_name_bg'     => 'SITE_NAME_BG',
        'site_name_en'     => 'SITE_NAME_EN',
        'site_legal_name_bg' => 'SITE_LEGAL_NAME_BG',
        'site_legal_name_en' => 'SITE_LEGAL_NAME_EN',
        'site_legal_same'  => 'SITE_LEGAL_SAME',   // '1': the name above is also the registered name
        'site_eik'         => 'SITE_EIK',
        'site_address'     => 'SITE_ADDRESS',
        'site_mol'         => 'SITE_MOL',
        'site_email'       => 'SITE_EMAIL',
        'site_phone'       => 'SITE_PHONE',
        'site_iban'        => 'SITE_IBAN',
        'site_bic'         => 'SITE_BIC',
        'site_bank_name'   => 'SITE_BANK_NAME',
        'brand_theme'      => 'BRAND_THEME',
        'brand_primary'    => 'BRAND_PRIMARY',
        'brand_accent'     => 'BRAND_ACCENT',
        'newsletter_band'  => 'BRAND_NEWSLETTER_BAND',   // see newsletter_band_choices()
        'newsletter_band_color' => 'BRAND_NEWSLETTER_COLOR',
        'social_facebook'  => 'SOCIAL_FACEBOOK',
        'social_instagram' => 'SOCIAL_INSTAGRAM',
        'social_linkedin'  => 'SOCIAL_LINKEDIN',
        'launch_banner'    => 'SITE_LAUNCH_BANNER',
        'launch_banner_bg' => 'SITE_LAUNCH_BANNER_BG',
        'launch_banner_en' => 'SITE_LAUNCH_BANNER_EN',
        'newsletter_donate_cta'        => 'NEWSLETTER_DONATE_CTA',
        'newsletter_donate_heading_bg' => 'NEWSLETTER_DONATE_HEADING_BG',
        'newsletter_donate_heading_en' => 'NEWSLETTER_DONATE_HEADING_EN',
        'newsletter_donate_text_bg'    => 'NEWSLETTER_DONATE_TEXT_BG',
        'newsletter_donate_text_en'    => 'NEWSLETTER_DONATE_TEXT_EN',
        'site_launched'    => 'SITE_LAUNCHED',     // set by „Пусни сайта“ (admin/launch.php), not by the form
    ] + org_module_fields();
}

/** Fields the Организация form never posts: module switches and the launch state. */
function org_system_fields(): array
{
    return org_module_fields() + ['site_launched' => 'SITE_LAUNCHED'];
}

/**
 * A Bulgarian ЕИК (9 digits) or БУЛСТАТ (13), with its check digits. Spaces and
 * a "BG" VAT prefix are allowed and removed. Returns the clean number or null.
 */
function org_eik_normalize(string $eik): ?string
{
    $d = preg_replace('/\s+/', '', strtoupper($eik));
    if (str_starts_with($d, 'BG')) $d = substr($d, 2);
    if (!preg_match('/^(\d{9}|\d{13})$/', $d)) return null;
    $check = static function (array $digits, array $w1, array $w2): int {
        $sum = 0; foreach ($w1 as $i => $w) $sum += $digits[$i] * $w;
        $r = $sum % 11;
        if ($r !== 10) return $r;
        $sum = 0; foreach ($w2 as $i => $w) $sum += $digits[$i] * $w;
        $r = $sum % 11;
        return $r === 10 ? 0 : $r;
    };
    $n = array_map('intval', str_split($d));
    if ($check($n, [1, 2, 3, 4, 5, 6, 7, 8], [3, 4, 5, 6, 7, 8, 9, 10]) !== $n[8]) return null;
    if (strlen($d) === 13) {
        $tail = array_slice($n, 8, 4);
        if ($check($tail, [2, 7, 3, 5], [4, 9, 5, 7]) !== $n[12]) return null;
    }
    return $d;
}

/**
 * The module switches, feature_<name> => FEATURE_<NAME>, one per module in
 * modules_registry() (includes/modules.php). Saved by Admin → Модули, not by
 * the Организация form — org_validate() leaves them out and
 * org_save_overrides() keeps whatever is saved for them.
 */
function org_module_fields(): array
{
    $out = [];
    foreach (array_keys(modules_registry()) as $name) {
        $out[module_field($name)] = 'FEATURE_' . strtoupper($name);
    }
    return $out;
}

// org_modules() — the old shape of the module list — now lives in includes/modules.php.

/**
 * Организация fields whose section is hidden because a module it serves is off
 * (Админ → Модули): the newsletter band colour without Бюлетин, the newsletter
 * donation box without Бюлетин or Дарения. Their saved values are kept.
 */
function org_hidden_fields(): array
{
    $hidden = [];
    $newsletter = module_enabled_with_needs('newsletter');
    if (!$newsletter) array_push($hidden, 'newsletter_band', 'newsletter_band_color');
    if (!$newsletter || !module_enabled_with_needs('donations')) {
        array_push($hidden, 'newsletter_donate_cta', 'newsletter_donate_heading_bg', 'newsletter_donate_heading_en',
            'newsletter_donate_text_bg', 'newsletter_donate_text_en');
    }
    return $hidden;
}

/**
 * The registered legal name of whoever issues documents and receives the
 * money — printed on donation certificates, invoices and receipts. Set in
 * Admin → Организация ("Юридическо име"). A site that is a brand or project
 * of a foundation puts the foundation's name there; left empty, it is the
 * site's own name, so a site whose name is its legal name needs nothing.
 */
function org_legal_name(string $lang = 'bg'): string
{
    $en    = $lang === 'en';
    $legal = $en ? 'SITE_LEGAL_NAME_EN' : 'SITE_LEGAL_NAME_BG';
    $name  = defined($legal) ? trim((string) constant($legal)) : '';
    if ($name !== '') return $name;
    $site  = $en && defined('SITE_NAME_EN') ? 'SITE_NAME_EN' : 'SITE_NAME_BG';
    return defined($site) ? trim((string) constant($site)) : '';
}

/**
 * Is an optional feature module switched on?
 *
 * Reads the FEATURE_<NAME> constant. Its value comes, in order of precedence,
 * from:
 *   1. the switch in Admin → Модули (admin/modules.php), saved to
 *      content/organisation.json and defined before site.config.php is loaded
 *      (see includes/organisation.php) — stored as '1' / '0';
 *   2. FEATURE_<NAME> in site.config.php — the initial value until an admin
 *      saves that page (a new install's wizard writes one for every module);
 *   3. nothing set at all → on, so installs that predate a flag keep working.
 *
 * Costs nothing per call: the JSON file is read once per request by
 * config.php's bootstrap, and no database is involved, so it is safe on every public
 * page and keeps working when the database is down.
 *
 *     feature_enabled('campaign')  →  bool, from FEATURE_CAMPAIGN
 */
// Guarded: up to v0.16 this function lived in config.php, and the updater keeps a
// site's own customised config.php — declaring it again here took such a site
// down with "Cannot redeclare". The old copy reads the same constants, so either
// one answers the same.
if (!function_exists('feature_enabled')) {
function feature_enabled(string $name): bool {
    $const = 'FEATURE_' . strtoupper($name);
    // Never set: on, so a site that updates keeps what it had — except a module
    // that is new in a release (registry 'unset_default' => false), which a site
    // gets only when someone switches it on.
    if (!defined($const)) {
        return function_exists('module_unset_default') ? module_unset_default($name) : true;
    }
    $value = constant($const);
    // The admin switch saves '1' / '0'; a hand-edited "false" / "off" means off too.
    if (is_string($value)) return filter_var(trim($value), FILTER_VALIDATE_BOOLEAN);
    return (bool) $value;
}
}

function org_overrides_path(): string
{
    return dirname(__DIR__) . '/content/organisation.json';
}

// ── Loading / applying ─────────────────────────────────────────────────────────

/**
 * Saved overrides as [CONSTANT => string]. Unknown keys and non-string values
 * are dropped; a missing or broken file means "no overrides".
 */
function org_load_overrides(?string $file = null, ?array $fields = null): array
{
    $file   ??= org_overrides_path();
    $fields ??= org_fields();
    if (!is_file($file)) return [];
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) return [];

    $out = [];
    foreach ($fields as $key => $const) {
        if (array_key_exists($key, $data) && is_string($data[$key])) {
            $out[$const] = $data[$key];
        }
    }
    return $out;
}

/** define() each override that isn't defined yet; returns the names defined. */
function org_define_overrides(array $overrides): array
{
    $defined = [];
    foreach ($overrides as $const => $value) {
        if (!defined($const)) {
            define($const, $value);
            $defined[] = $const;
        }
    }
    return $defined;
}

/**
 * require_once a config file whose define() calls may collide with constants
 * we already defined from the overrides. Only the "Constant X already defined"
 * warning for those exact names is silenced — anything else still reaches the
 * previous error handler.
 */
function org_require_config(string $path, array $predefined): void
{
    $names = array_flip($predefined);
    $prev  = null;
    $prev  = set_error_handler(function (int $no, string $str, string $file = '', int $line = 0) use ($names, &$prev): bool {
        if ($no === E_WARNING
            && preg_match('/^Constant (\w+) already defined/', $str, $m)
            && isset($names[$m[1]])) {
            return true;
        }
        return $prev ? (bool) $prev($no, $str, $file, $line) : false;
    });
    try {
        require_once $path;
    } finally {
        restore_error_handler();
    }
}

// ── Validation ─────────────────────────────────────────────────────────────────

/** Strip spaces/dashes and upper-case, so "bg80 bnbg 9661..." is accepted. */
function org_normalize_iban(string $iban): string
{
    return strtoupper((string) preg_replace('/[\s\-]+/', '', $iban));
}

/** ISO 13616 mod-97 check — catches a single mistyped digit. */
function org_iban_valid(string $iban): bool
{
    $iban = org_normalize_iban($iban);
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) return false;
    $moved   = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($moved) as $ch) {
        $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
    }
    $rem = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $rem = (int) (($rem . $chunk) % 97);
    }
    return $rem === 1;
}

function org_bic_valid(string $bic): bool
{
    return (bool) preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic)));
}

function org_color_valid(string $c): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $c);
}

function org_url_valid(string $url): bool
{
    // Not filter_var() on the raw string: it refuses https://фондация.бг, which
    // is exactly the address some of these organisations have. url_is_web()
    // checks the punycode form instead. See includes/url.php.
    return url_is_web($url);
}

/**
 * Validate the admin form. Returns ['values' => [field => clean string],
 * 'errors' => [field => plain-Bulgarian message]]. Values are returned even on
 * error so the form can be re-filled.
 */
function org_validate(array $in, array $themeKeys): array
{
    $v = [];
    // Module switches are not part of this form (Admin → Модули saves them).
    foreach (array_keys(array_diff_key(org_fields(), org_system_fields())) as $k) {
        $v[$k] = is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    }
    $e = [];

    foreach (['site_name_bg' => 'на български', 'site_name_en' => 'на английски'] as $k => $lang) {
        if ($v[$k] === '') {
            $e[$k] = "Моля, въведете името на организацията {$lang}.";
        } elseif (mb_strlen($v[$k]) > 150) {
            $e[$k] = 'Името е твърде дълго (най-много 150 знака).';
        }
    }

    // The legal name is optional, but it is used as a pair (the translator keeps
    // BG → EN exactly as written), so one language without the other is a mistake.
    $legal = ['site_legal_name_bg' => 'на български', 'site_legal_name_en' => 'на английски'];
    $anyLegal = $v['site_legal_name_bg'] !== '' || $v['site_legal_name_en'] !== '';
    foreach ($legal as $k => $lang) {
        if ($v[$k] === '' && $anyLegal) {
            $e[$k] = "Моля, въведете юридическото име и {$lang} — или оставете и двете полета празни.";
        } elseif (mb_strlen($v[$k]) > 150) {
            $e[$k] = 'Името е твърде дълго (най-много 150 знака).';
        }
    }

    $v['site_legal_same'] = in_array($v['site_legal_same'], ['1', 'on', 'true'], true) ? '1' : '0';

    if ($v['site_eik'] !== '') {
        $eik = org_eik_normalize($v['site_eik']);
        if ($eik === null) {
            $e['site_eik'] = 'Този ЕИК не е правилен — трябва да е 9 цифри (13 за БУЛСТАТ) и вероятно има сгрешена цифра. Препишете го от регистрацията на организацията.';
        } else {
            $v['site_eik'] = $eik;
        }
    }
    if (mb_strlen($v['site_address']) > 250) $e['site_address'] = 'Адресът е твърде дълъг (най-много 250 знака).';
    if (mb_strlen($v['site_mol']) > 150)     $e['site_mol'] = 'Името е твърде дълго (най-много 150 знака).';

    if (filter_var($v['site_email'], FILTER_VALIDATE_EMAIL) === false) {
        $e['site_email'] = 'Моля, въведете правилен имейл адрес, например info@vashata-organizacia.bg';
    }

    if ($v['site_phone'] !== '' && !preg_match('/^[0-9+()\s\-\/.]{5,30}$/', $v['site_phone'])) {
        $e['site_phone'] = 'Телефонът може да съдържа само цифри, интервали и знаците + ( ) - /';
    }

    if ($v['site_iban'] !== '') {
        $v['site_iban'] = org_normalize_iban($v['site_iban']);
        if (!org_iban_valid($v['site_iban'])) {
            $e['site_iban'] = 'Този IBAN не е правилен — вероятно има сгрешена или липсваща цифра. Препишете го внимателно от документ от банката.';
        }
    }

    if ($v['site_bic'] !== '') {
        $v['site_bic'] = strtoupper($v['site_bic']);
        if (!org_bic_valid($v['site_bic'])) {
            $e['site_bic'] = 'BIC кодът трябва да е 8 или 11 латински букви и цифри, например STSABGSF.';
        }
    }

    if (mb_strlen($v['site_bank_name']) > 150) {
        $e['site_bank_name'] = 'Името на банката е твърде дълго (най-много 150 знака).';
    }

    if (!in_array($v['brand_theme'], $themeKeys, true)) {
        $e['brand_theme'] = 'Моля, изберете един от стиловете.';
    }
    foreach (['brand_primary' => 'основния', 'brand_accent' => 'допълнителния'] as $k => $label) {
        if (!org_color_valid($v[$k])) {
            $e[$k] = "Моля, изберете {$label} цвят от палитрата.";
        } else {
            $v[$k] = strtoupper($v[$k]);
        }
    }

    // Newsletter band: one of the fixed choices (newsletter_band_choices() in
    // includes/themes.php); nothing chosen yet means "as it is now". Only "Друг
    // цвят" needs a colour; with another choice a colour still in the picker is
    // kept when valid, so switching back finds it again.
    if ($v['newsletter_band'] === '') $v['newsletter_band'] = 'default';
    if (!in_array($v['newsletter_band'], ['default', 'primary', 'accent', 'custom'], true)) {
        $v['newsletter_band'] = 'default';
        $e['newsletter_band'] = 'Моля, изберете цвят за лентата за бюлетина от вариантите.';
    }
    if (org_color_valid($v['newsletter_band_color'])) {
        $v['newsletter_band_color'] = strtoupper($v['newsletter_band_color']);
    } elseif ($v['newsletter_band'] === 'custom') {
        $e['newsletter_band_color'] = 'Моля, изберете цвят за лентата за бюлетина от палитрата.';
    } else {
        $v['newsletter_band_color'] = '';
    }

    foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_linkedin' => 'LinkedIn'] as $k => $label) {
        if ($v[$k] !== '' && !org_url_valid($v[$k])) {
            $e[$k] = "Моля, поставете пълния адрес на страницата ви във {$label}, започващ с https://";
        }
    }

    // An unticked checkbox is simply absent from the POST, which the loop above
    // turns into ''. Store an explicit '0' instead — otherwise the saved value
    // reads as "not set", and the banner could never be switched back off.
    $v['launch_banner'] = ($in['launch_banner'] ?? '') === '1' ? '1' : '0';
    // Same for the newsletter donate box — off unless ticked.
    $v['newsletter_donate_cta'] = ($in['newsletter_donate_cta'] ?? '') === '1' ? '1' : '0';

    foreach (['launch_banner_bg' => 'на български', 'launch_banner_en' => 'на английски'] as $k => $lang) {
        if (mb_strlen($v[$k]) > 200) {
            $e[$k] = "Съобщението {$lang} е твърде дълго (най-много 200 знака).";
        }
    }

    $nl_limits = [
        'newsletter_donate_heading_bg' => ['заглавието на български', 120],
        'newsletter_donate_heading_en' => ['заглавието на английски', 120],
        'newsletter_donate_text_bg'    => ['текстът на български', 300],
        'newsletter_donate_text_en'    => ['текстът на английски', 300],
    ];
    foreach ($nl_limits as $k => [$what, $max]) {
        if (mb_strlen($v[$k]) > $max) {
            $e[$k] = 'В поканата за дарение ' . $what . " е твърде дълъг (най-много {$max} знака).";
        }
    }

    // Sections the page hides because their module is off are not posted:
    // leave them out, so org_save_overrides() keeps what is saved for them.
    foreach (org_hidden_fields() as $k) unset($v[$k], $e[$k]);
    return ['values' => $v, 'errors' => $e];
}

// ── Saving ─────────────────────────────────────────────────────────────────────

/**
 * Atomically write the overrides file (write temp + rename).
 *
 * Fields missing from $values keep what is saved now, so the Организация form
 * and Admin → Модули can each save their own part without wiping the other's.
 */
function org_save_overrides(array $values, ?string $file = null): bool
{
    $file ??= org_overrides_path();
    $saved = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (!is_array($saved)) $saved = [];
    $out  = [];
    foreach (array_keys(org_fields()) as $k) {
        if (isset($values[$k]) && is_string($values[$k])) {
            $out[$k] = $values[$k];
        } elseif (isset($saved[$k]) && is_string($saved[$k])) {
            $out[$k] = $saved[$k];
        }
    }
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// ── Logo ───────────────────────────────────────────────────────────────────────

const ORG_LOGO_MAX_BYTES = 2 * 1024 * 1024;
const ORG_LOGO_MAX_SIDE  = 4000;

/** Build a square favicon from a logo (fit + centre on transparent). */
function make_favicon(string $src, string $dest, int $size = 64): void
{
    if (!function_exists('imagecreatetruecolor')) return;  // GD optional
    $info = @getimagesize($src);
    $im = match ($info['mime'] ?? '') {
        'image/png'  => @imagecreatefrompng($src),
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/webp' => @imagecreatefromwebp($src),
        default      => null,
    };
    if (!$im) return;
    $sw = imagesx($im); $sh = imagesy($im);
    $scale = min($size / $sw, $size / $sh);
    $nw = max(1, (int) round($sw * $scale));
    $nh = max(1, (int) round($sh * $scale));
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
    imagecopyresampled($canvas, $im, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $sw, $sh);
    @imagepng($canvas, $dest);
    imagedestroy($canvas);
    imagedestroy($im);
}

/**
 * Validate an uploaded logo and install it as $imagesDir/logo.png, then
 * regenerate $imagesDir/favicon.png. The image is decoded and re-encoded as a
 * real PNG, so nothing but pixel data ever lands on disk.
 *
 * $upload is one $_FILES entry. $requireUploaded=false lets tests pass a plain
 * temp file. Returns null on success, or a plain-Bulgarian error message.
 */
function org_save_logo(array $upload, string $imagesDir, bool $requireUploaded = true): ?string
{
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'Файлът с логото е твърде голям. Максимумът е 2 MB — намалете размера му и опитайте отново.';
    }
    if ($err !== UPLOAD_ERR_OK) {
        return 'Логото не можа да се качи. Опитайте отново.';
    }
    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp) || ($requireUploaded && !is_uploaded_file($tmp))) {
        return 'Логото не можа да се качи. Опитайте отново.';
    }
    if (filesize($tmp) > ORG_LOGO_MAX_BYTES) {
        return 'Файлът с логото е твърде голям. Максимумът е 2 MB — намалете размера му и опитайте отново.';
    }

    $allowed = ['image/png', 'image/jpeg', 'image/webp'];
    $mime    = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info    = @getimagesize($tmp);
    if (!in_array($mime, $allowed, true) || !$info || ($info['mime'] ?? '') !== $mime) {
        return 'Логото трябва да е картинка във формат PNG, JPG или WebP.';
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > ORG_LOGO_MAX_SIDE || $info[1] > ORG_LOGO_MAX_SIDE) {
        return 'Логото е с твърде големи размери (най-много ' . ORG_LOGO_MAX_SIDE . ' × ' . ORG_LOGO_MAX_SIDE . ' пиксела).';
    }
    if (!function_exists('imagecreatetruecolor')) {
        return 'Сървърът не може да обработва картинки (липсва модулът GD). Помолете хостинг доставчика си да го включи.';
    }

    $im = match ($mime) {
        'image/png'  => @imagecreatefrompng($tmp),
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
    };
    if (!$im) {
        return 'Картинката изглежда повредена и не може да се отвори. Опитайте с друг файл.';
    }
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);

    $logo    = rtrim($imagesDir, '/') . '/logo.png';
    $staging = $logo . '.tmp-' . bin2hex(random_bytes(4));
    $ok = @imagepng($im, $staging);
    imagedestroy($im);
    if (!$ok || !@rename($staging, $logo)) {
        @unlink($staging);
        return 'Логото не можа да се запише на сървъра. Опитайте отново или се свържете с поддръжката.';
    }

    make_favicon($logo, rtrim($imagesDir, '/') . '/favicon.png');
    return null;
}
