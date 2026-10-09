<?php
/**
 * Going live.
 *
 * A site set up by the install wizard starts closed to visitors (SITE_LAUNCHED
 * false in site.config.php): they see a "Скоро отваряме" page while the admin
 * works through launch_checklist() — what the law and the chosen modules need
 * (legal pages, ЕИК, address…) — and presses „Пусни сайта“ on the dashboard
 * (admin/launch.php). Admins always see the real site.
 *
 * Sites from before this existed have no SITE_LAUNCHED and are live.
 */

function site_launched(): bool
{
    if (!defined('SITE_LAUNCHED')) return true;
    $v = constant('SITE_LAUNCHED');
    return is_string($v) ? filter_var(trim($v), FILTER_VALIDATE_BOOLEAN) : (bool) $v;
}

/** "The site's name is also the registered name" — ticked in Админ → Организация. */
function site_legal_same(): bool
{
    if (!defined('SITE_LEGAL_SAME')) return false;
    $v = constant('SITE_LEGAL_SAME');
    return is_string($v) ? filter_var(trim($v), FILTER_VALIDATE_BOOLEAN) : (bool) $v;
}

function launch_text_filled(array $legal, string $key): bool
{
    // "<p>&nbsp;</p>" from an emptied editor is still empty.
    $text = html_entity_decode(strip_tags((string) ($legal[$key] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_replace('/[\s\x{00A0}]+/u', '', $text) !== '';
}

/**
 * What the site needs before (and after) it opens, for the modules switched on.
 * Each item: key, label, why (one plain sentence), done, href (where to fix it),
 * required (blocks „Пусни сайта“; the rest are strongly recommended).
 *
 * $only_required skips the recommended checks, which touch the database — the
 * one-line bar on every admin page needs only the count of required ones.
 */
function launch_checklist(?array $pages = null, bool $only_required = false): array
{
    $pages ??= load_json(CONTENT_PATH . '/pages.json');
    $legal = is_array($pages['legal'] ?? null) ? $pages['legal'] : [];
    $const = static fn(string $c): string => defined($c) ? trim((string) constant($c)) : '';
    $takes_money = module_any_enabled('shop', 'donations', 'campaign', 'events');
    $org = '/admin/organisation.php';

    $items = [];
    $add = static function (string $key, string $label, string $why, bool $done, string $href, bool $required) use (&$items): void {
        $items[] = compact('key', 'label', 'why', 'done', 'href', 'required');
    };

    $add('legal_name', 'Юридическо име', 'Името, с което организацията е регистрирана. Печата се на фактурите, сертификатите и в правната информация. Ако е същото като името на сайта, само го потвърдете.',
        $const('SITE_LEGAL_NAME_BG') !== '' || site_legal_same(), $org . '#f-site_legal_name_bg', true);
    $add('eik', 'ЕИК / БУЛСТАТ', 'Законът изисква сайтът да посочва кой стои зад него; без ЕИК фактурите не са редовни.',
        $const('SITE_EIK') !== '', $org . '#f-site_eik', true);
    $add('address', 'Адрес на управление', 'Адресът от регистрацията на организацията. Показва се в правната информация и на документите.',
        $const('SITE_ADDRESS') !== '', $org . '#f-site_address', true);
    $add('privacy', 'Политика за поверителност', 'Задължителна по закон (GDPR): как събирате и пазите личните данни на посетителите.',
        launch_text_filled($legal, 'privacy'), '/admin/pages.php?page=legal_privacy', true);
    $add('cookies', 'Политика за бисквитки', 'Обяснява на посетителите какви бисквитки ползва сайтът. Банерът за бисквитки води към нея.',
        launch_text_filled($legal, 'cookie_policy_bg'), '/admin/pages.php?page=legal_cookies', true);
    $add('legal_info', 'Правна информация', 'Кой стои зад сайта и как да се свържат с вас — изисква се от Закона за електронната търговия.',
        launch_text_filled($legal, 'legal_info'), '/admin/pages.php?page=legal_info', true);
    if ($takes_money) {
        $add('terms', 'Условия за ползване', 'Правилата за поръчки, плащане, доставка и връщане. Купувачите се съгласяват с тях при плащане.',
            launch_text_filled($legal, 'terms'), '/admin/pages.php?page=legal_terms', true);
    }
    if ($only_required) return $items;

    if ($takes_money) {
        require_once dirname(__DIR__) . '/includes/settings.php';
        require_once dirname(__DIR__) . '/includes/payment/DSKBankPayment.php';
        require_once dirname(__DIR__) . '/includes/payment/IRISPayment.php';
        $cards = false;
        try { $cards = DSKBankPayment::isEnabled() || IRISPayment::isEnabled(); } catch (Throwable) {}
        $add('payments', 'Онлайн плащане', 'Без него посетителите не могат да платят онлайн.',
            $cards, '/admin/payment.php', false);
    }
    require_once dirname(__DIR__) . '/includes/mailer.php';
    $add('email', 'Изпращане на имейли', 'Без него сайтът не може да праща потвърждения на поръчки, дарения и абонаменти.',
        mail_is_configured(), '/admin/email-settings.php', false);
    if (module_enabled_with_needs('donations')) {
        $add('bank', 'Банкова сметка', 'За хората, които предпочитат да дарят по банков път.',
            $const('SITE_IBAN') !== '', $org . '#f-site_iban', false);
    }
    $add('mol', 'Представляващ (МОЛ)', 'Името на човека, който представлява организацията. Печата се на документите.',
        $const('SITE_MOL') !== '', $org . '#f-site_mol', false);
    return $items;
}

/** How many required items are still open (cheap: no database). */
function launch_required_open(?array $pages = null): int
{
    return count(array_filter(launch_checklist($pages, true), static fn($i) => !$i['done']));
}

/** Paths a closed site still serves to everyone: the admin, the wizard, callbacks and files. */
function launch_path_is_open(string $path): bool
{
    foreach (['/admin', '/install', '/api/', '/assets/', '/uploads/', '/relay/', '/cron/', '/documents/'] as $p) {
        if (str_starts_with($path, $p)) return true;
    }
    return in_array($path, ['/robots.txt', '/favicon.ico'], true);
}

/** Called from config.php on every web request: visitors of a closed site get the holding page. */
function launch_holding_guard(): void
{
    if (PHP_SAPI === 'cli' || site_launched()) return;
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (launch_path_is_open($path) || admin_logged_in() || admin_bar_token_verify()) return;
    launch_render_holding(str_starts_with($path, '/en/') || $path === '/en' ? 'en' : 'bg');
    exit;
}

/**
 * The holding page. Deliberately not the site's header/footer: there is no
 * site to navigate yet, and every link would lead back here.
 */
function launch_render_holding(string $lang): void
{
    http_response_code(503);
    header('Retry-After: 86400');
    header('X-Robots-Tag: noindex');
    header('Content-Type: text/html; charset=UTF-8');
    $en    = $lang === 'en';
    $name  = $en && defined('SITE_NAME_EN') ? SITE_NAME_EN : (defined('SITE_NAME_BG') ? SITE_NAME_BG : '');
    $email = defined('SITE_EMAIL') ? (string) SITE_EMAIL : '';
    $teal  = (defined('BRAND_PRIMARY') && preg_match('/^#[0-9a-fA-F]{6}$/', (string) BRAND_PRIMARY)) ? BRAND_PRIMARY : '#03758f';
    $logo  = function_exists('logo_url') ? logo_url() : '/assets/images/logo.png';
    ?>
<!DOCTYPE html>
<html lang="<?= $en ? 'en' : 'bg' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= h(($en ? 'Coming soon' : 'Скоро отваряме') . ($name !== '' ? ' — ' . $name : '')) ?></title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8f6f2;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1a1916;padding:1.5rem 1rem;box-sizing:border-box;">
  <main style="max-width:520px;width:100%;text-align:center;background:#fff;border:1px solid #e2e0db;border-radius:14px;padding:2.5rem 1.5rem;box-sizing:border-box;">
    <img src="<?= h($logo) ?>" alt="<?= h($name) ?>" style="max-width:180px;max-height:90px;width:auto;height:auto;display:block;margin:0 auto 1.5rem;">
    <h1 style="font-size:1.6rem;margin:0 0 .75rem;line-height:1.25;"><?= $en ? 'Coming soon' : 'Скоро отваряме' ?></h1>
    <p style="margin:0;line-height:1.6;color:#5c5752;">
      <?= $en ? 'We are getting our website ready. Please come back soon.' : 'Подготвяме сайта си. Заповядайте отново съвсем скоро.' ?>
    </p>
<?php if ($email !== ''): ?>
    <p style="margin:1.25rem 0 0;line-height:1.6;color:#5c5752;">
      <?= $en ? 'Write to us:' : 'Пишете ни:' ?> <a href="mailto:<?= h($email) ?>" style="color:<?= h($teal) ?>;font-weight:600;"><?= h($email) ?></a>
    </p>
<?php endif; ?>
    <p style="margin:1.5rem 0 0;font-size:.9rem;">
      <a href="<?= $en ? '/' : '/en/' ?>" lang="<?= $en ? 'bg' : 'en' ?>" style="color:<?= h($teal) ?>;"><?= $en ? 'На български' : 'In English' ?></a>
    </p>
  </main>
</body>
</html>
<?php
}
