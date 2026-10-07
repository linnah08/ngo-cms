<?php
/**
 * Optional modules — the parts of the CMS an organisation can switch on and off
 * in Admin → Модули (admin/modules.php) and choose in the install wizard.
 *
 * When a module is off: its admin menu items are hidden, its admin pages show
 * "Този модул е изключен" (module_admin_guard()), its public pages answer with
 * the site's 404 (module_public_guard()), and its emails / scheduled jobs stop
 * (each checks module_enabled_with_needs()). Nothing is ever deleted —
 * switching it back on restores everything.
 *
 * The switch itself is still feature_enabled('<name>') → FEATURE_<NAME>
 * (includes/organisation.php): saved by Admin → Модули into
 * content/organisation.json as feature_<name> = '1' / '0', else the constant in
 * site.config.php, else on. So a site that updates keeps everything it has
 * today, and a new install gets an explicit value for every module from the
 * wizard.
 *
 * Adding a module = adding one entry to modules_registry(). Nothing else here
 * needs to change: org_fields(), the Модули page, the admin menu and the
 * wizard all read the registry.
 *
 * Standalone on purpose — loaded by includes/organisation.php, which config.php
 * and the install wizard (no config.php yet) both load first.
 */

/**
 * Every optional module, keyed by its name (as passed to feature_enabled()).
 *
 * Entry format:
 *   label        string    Bulgarian name shown on cards and in messages.
 *   description  string    One or two plain sentences: what it gives the NGO.
 *   off_warning  string    Shown in the confirmation when an admin switches it off.
 *   needs        string[]  Other modules it cannot work without. While one of
 *                          them is off, this module counts as off too.
 *   admin_pages  string[]  admin/*.php basenames that belong to it: hidden from
 *                          the menu when it is off. Each such page also calls
 *                          module_admin_guard('<name>').
 *   public_paths string[]  URL prefixes of its public pages, BG and EN. Each
 *                          entry point calls module_public_guard('<name>').
 *   pending      ?callable (PDO $pdo): ?string — unfinished work to mention
 *                          before switching off, e.g. "3 награди още не са
 *                          изпратени", or null when there is none. Optional.
 *
 * @param ?array $replace tests only — see below.
 * @return array<string, array{label:string, description:string, off_warning:string,
 *               needs:string[], admin_pages:string[], public_paths:string[], pending?:callable}>
 */
function modules_registry(?array $replace = null): array
{
    static $registry = null;
    // Tests only: swap in a made-up registry (e.g. modules with `needs`) for the
    // rest of this PHP process. Never called by the site itself.
    if ($replace !== null) $registry = $replace;
    if ($registry !== null) return $registry;

    $registry = [
        'donations' => [
            'label'       => 'Дарения',
            'description' => 'Страница за онлайн дарение, форма за дарение в магазина и бутони „Дари сега“ под новините.',
            'off_warning' => 'Изключвате даренията. Страницата за дарение, формата за дарение и бутоните „Дари сега“ ще изчезнат от сайта и посетителите няма да могат да даряват онлайн.',
            'needs'        => [],
            // Past donations stay in Поръчки, and certificates for donations made
            // outside the site (manual-cert.php) are still needed — no page of its own.
            'admin_pages'  => [],
            'public_paths' => ['/donation/', '/en/donation/'],
            'pending'      => static function (PDO $pdo): ?string {
                $n = (int) $pdo->query(
                    "SELECT COUNT(*) FROM orders
                      WHERE type = 'donation' AND payment_status = 'pending'
                        AND status <> 'cancelled'"
                )->fetchColumn();
                if ($n < 1) return null;
                return $n === 1
                    ? '1 дарение още чака плащане.'
                    : $n . ' дарения още чакат плащане.';
            },
        ],
        'campaign' => [
            'label'       => 'Кампании',
            'description' => 'Кампания за набиране на средства: страница с цел и напредък, награди за дарителите и билети за събития.',
            'off_warning' => 'Изключвате кампаниите. Страниците на кампанията ще изчезнат от сайта и посетителите няма да могат да ги отварят.',
            'needs'        => [],
            'admin_pages'  => ['campaign.php', 'campaign-backers.php', 'pledge-view.php', 'ticket-checklist.php'],
            'public_paths' => ['/campaign/', '/en/campaign/', '/tickets/', '/api/campaign-payment-return.php'],
            'pending'      => static function (PDO $pdo): ?string {
                $n = (int) $pdo->query(
                    "SELECT COUNT(*) FROM campaign_pledges
                      WHERE payment_status = 'paid' AND reward_id IS NOT NULL AND reward_shipped = 0"
                )->fetchColumn();
                if ($n < 1) return null;
                return $n === 1
                    ? '1 награда за дарител още не е изпратена.'
                    : $n . ' награди за дарители още не са изпратени.';
            },
        ],
        'annual_reports' => [
            'label'       => 'Годишни отчети',
            'description' => 'Страница „Годишни отчети“, на която публикувате финансовите отчети и докладите за дейността си, с връзка към нея във футъра.',
            'off_warning' => 'Изключвате годишните отчети. Страницата с отчетите и връзката към нея във футъра ще изчезнат от сайта. Качените документи остават запазени.',
            'needs'        => [],
            'admin_pages'  => ['financial-reports.php'],
            'public_paths' => ['/finansovi-otcheti/', '/en/financial-reports/'],
        ],
        'comments_reviews' => [
            'label'       => 'Коментари и отзиви',
            'description' => 'Коментари под новините и отзиви със звезди за продуктите в магазина, които публикувате, след като ги прегледате.',
            'off_warning' => 'Изключвате коментарите и отзивите. Формите за коментар и отзив, публикуваните коментари и отзиви и оценките със звезди ще изчезнат от сайта. Съобщенията от контактната форма остават. Нищо не се изтрива.',
            'needs'        => [],
            // Comments work without the shop; the product-review parts also check
            // module_enabled_with_needs('shop'). admin/comments.php is not listed:
            // it also holds the contact-form messages, so with this module off it
            // stays as „Контакти“ only.
            'admin_pages'  => ['product-reviews.php'],
            'public_paths' => ['/api/comment-submit.php', '/api/review-submit.php'],
            'pending'      => static function (PDO $pdo): ?string {
                $c = (int) $pdo->query("SELECT COUNT(*) FROM comments WHERE status = 'pending'")->fetchColumn();
                $r = 0;
                try {
                    $r = (int) $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetchColumn();
                } catch (Throwable $e) { /* no reviews table yet */ }
                $n = $c + $r;
                if ($n < 1) return null;
                return $n === 1
                    ? '1 коментар или отзив още чака преглед.'
                    : $n . ' коментара и отзива още чакат преглед.';
            },
        ],
        'social' => [
            'label'       => 'Социални мрежи',
            'description' => 'Раздел „Социални мрежи“ в редактора на статии: публикации за Facebook, Instagram и LinkedIn, написани с помощта на изкуствен интелект и планирани през Buffer.',
            'off_warning' => 'Изключвате социалните мрежи. Разделът „Социални мрежи“ ще изчезне от редактора на статии, а настройките за Buffer — от „Плащания“. Публикации, които вече са планирани в Buffer, ще излязат по график, освен ако не ги изтриете в Buffer. Написаните текстове остават запазени.',
            'needs'        => [],
            // No page of its own: the Social tab lives in article-edit.php, Buffer
            // settings in payment.php. Its JSON endpoints (social-ajax.php,
            // linkedin-ajax.php, buffer-setup-ajax.php) call module_ajax_guard('social').
            'admin_pages'  => [],
            'public_paths' => [],
            'pending'      => static function (PDO $pdo): ?string {
                // Posts still waiting in Buffer — read from the articles, not the database.
                $n = modules_social_scheduled_count(defined('ARTICLES_PATH') ? ARTICLES_PATH : dirname(__DIR__) . '/content/articles', time());
                if ($n < 1) return null;
                return $n === 1
                    ? '1 публикация в социалните мрежи е планирана и още не е излязла.'
                    : $n . ' публикации в социалните мрежи са планирани и още не са излезли.';
            },
        ],
        'ai_helpers' => [
            'label'       => 'Помощ от изкуствен интелект',
            'description' => 'Бутони „✦ Translate“, които превеждат текста от български на английски, предложения за ключови думи, автоматично кратко описание на статиите и разчитане на таблици с размери.',
            'off_warning' => 'Изключвате помощта от изкуствен интелект. Бутоните за превод и предложенията ще изчезнат от админ панела и английските текстове ще се попълват на ръка. Краткото описание на статия ще се взима от началото на текста. Ключовете за DeepL и Claude остават запазени.',
            'needs'        => [],
            // No page of its own: its buttons live on other pages, and its JSON
            // endpoints (translate-ajax.php, translate-article-ajax.php,
            // suggest-keywords-ajax.php, extract-size-dims.php) call
            // module_ajax_guard('ai_helpers').
            'admin_pages'  => [],
            'public_paths' => [],
        ],
    ];
    return $registry;
}

/**
 * Social posts (Facebook, Instagram, Instagram story, LinkedIn) scheduled for
 * after $now, counted over the BG articles in $articles_dir.
 */
function modules_social_scheduled_count(string $articles_dir, int $now): int
{
    $n = 0;
    foreach (glob($articles_dir . '/bg/*.json') ?: [] as $file) {
        $a = json_decode((string) @file_get_contents($file), true);
        if (!is_array($a)) continue;
        foreach (['fb_scheduled_at', 'insta_scheduled_at', 'insta_story_scheduled_at', 'linkedin_scheduled_at'] as $k) {
            $t = !empty($a[$k]) && $a[$k] !== 'now' ? strtotime((string) $a[$k]) : false;
            if ($t !== false && $t > $now) $n++;
        }
    }
    return $n;
}

/** The content/organisation.json key a module's switch is saved under. */
function module_field(string $name): string
{
    return 'feature_' . strtolower($name);
}

/**
 * Is the module on, counting what it needs? A module whose `needs` are off
 * counts as off, however its own switch is set. An unregistered name is just
 * feature_enabled($name). Safe on every request: no database, no file reads.
 */
function module_enabled_with_needs(string $name, array $seen = []): bool
{
    $name = strtolower($name);
    if (!feature_enabled($name)) return false;
    if (isset($seen[$name])) return true;   // a cycle cannot make anything off
    $seen[$name] = true;
    foreach (modules_registry()[$name]['needs'] ?? [] as $need) {
        if (!module_enabled_with_needs($need, $seen)) return false;
    }
    return true;
}

/** The modules this one needs that are off right now (label-ready names). */
function module_missing_needs(string $name): array
{
    $missing = [];
    foreach (modules_registry()[strtolower($name)]['needs'] ?? [] as $need) {
        if (!module_enabled_with_needs($need)) $missing[] = $need;
    }
    return $missing;
}

/** Which module an admin page belongs to, or null for a core page. */
function module_for_admin_page(string $page): ?string
{
    $page = basename($page);
    foreach (modules_registry() as $name => $m) {
        if (in_array($page, $m['admin_pages'], true)) return $name;
    }
    return null;
}

/** Should the admin menu show a link to this admin/*.php page? */
function module_admin_page_visible(string $page): bool
{
    $module = module_for_admin_page($page);
    return $module === null || module_enabled_with_needs($module);
}

/**
 * Which module a site address belongs to, from the modules' public_paths, or
 * null for core pages, other sites, mailto:/tel: and #anchors. Accepts a bare
 * path (/magazin/x/?a=1#b) or an absolute address on this site (SITE_URL…).
 * The longest matching prefix wins.
 */
function module_for_path(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (defined('SITE_URL')) {
        $base = rtrim((string) SITE_URL, '/');
        if ($base !== '' && stripos($url, $base . '/') === 0) $url = substr($url, strlen($base));
        elseif ($base !== '' && strcasecmp($url, $base) === 0) $url = '/';
    }
    if ($url[0] !== '/' || str_starts_with($url, '//')) return null;
    $path = rawurldecode(substr($url, 0, strcspn($url, '?#')));

    $best = null; $best_len = 0;
    foreach (modules_registry() as $name => $m) {
        foreach ($m['public_paths'] as $prefix) {
            $p = rtrim($prefix, '/');
            // '/x.php' matches only itself; '/dir/' matches /dir and everything under it.
            $hit = str_ends_with($prefix, '/')
                ? ($path === $p || str_starts_with($path, $p . '/'))
                : $path === $prefix;
            if ($hit && strlen($p) > $best_len) { $best = $name; $best_len = strlen($p); }
        }
    }
    return $best;
}

/**
 * Should a link to this address be shown? False only when it points at a page
 * of a module that is off — so menus, front-page buttons and the sitemap drop
 * it quietly instead of sending visitors to a 404.
 */
function module_link_visible(string $url): bool
{
    $module = module_for_path($url);
    return $module === null || module_enabled_with_needs($module);
}

/**
 * A menu list ([i => ['url' => …, 'label' => …]]) without the items that point
 * at an off module. Keys are kept: the on-page editor addresses items by their
 * index in content/menus.json.
 */
function module_filter_links(array $items): array
{
    return array_filter($items, static fn($it) => !is_array($it) || module_link_visible((string) ($it['url'] ?? '')));
}

/** Label of a module, or the name itself if it is not registered. */
function module_label(string $name): string
{
    return modules_registry()[$name]['label'] ?? $name;
}

/**
 * Unfinished work in a module, in plain words, or null. Never throws — a
 * missing table (module never used) or a database hiccup simply means nothing
 * to report.
 */
function module_pending(string $name, ?PDO $pdo): ?string
{
    $fn = modules_registry()[$name]['pending'] ?? null;
    if ($pdo === null || !is_callable($fn)) return null;
    try {
        $text = $fn($pdo);
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Call at the top of every admin page of a module, after the page's own auth
 * call (so an anonymous visitor still gets the login redirect and never learns
 * which modules a site runs). If the module is off, renders "Този модул е
 * изключен" inside the admin, with a button to Admin → Модули for admins, and
 * stops with HTTP 404.
 */
function module_admin_guard(string $module): void
{
    if (module_enabled_with_needs($module)) return;

    admin_require_login();
    $root = dirname(__DIR__);
    require_once $root . '/admin/includes/db.php';

    http_response_code(404);
    $page_title_admin = 'Модулът е изключен';
    $active_nav       = 'modules';
    $label   = module_label($module);
    $missing = array_map('module_label', module_missing_needs($module));
    $is_admin = admin_is_admin();

    require $root . '/admin/includes/admin-header.php';
    ?>
    <section class="admin-card" style="max-width:640px;padding:1.75rem;" aria-labelledby="module-off-title">
      <h1 id="module-off-title" style="font-size:1.35rem;margin:0 0 .75rem;">Този модул е изключен</h1>
      <p style="margin:0 0 .75rem;line-height:1.6;">
        Модулът <strong>„<?= h($label) ?>“</strong> е изключен за този сайт, затова тази страница не се отваря.
        <?php if ($missing): ?>
          Той има нужда от модул <?= h(implode(', ', array_map(static fn($l) => '„' . $l . '“', $missing))) ?>, който в момента е изключен.
        <?php endif; ?>
      </p>
      <p style="margin:0 0 1.25rem;line-height:1.6;">Нищо не е изтрито — когато модулът бъде включен отново, всичко ще си е на мястото.</p>
      <?php if ($is_admin): ?>
        <a href="/admin/modules.php" class="btn btn--primary" style="display:inline-block;min-height:44px;line-height:1.5;">Към „Модули“</a>
      <?php else: ?>
        <p style="margin:0;line-height:1.6;color:#4b5563;">Ако ви трябва, помолете администратор на сайта да го включи.</p>
      <?php endif; ?>
    </section>
    <?php
    require $root . '/admin/includes/admin-footer.php';
    exit;
}

/**
 * For an admin AJAX/JSON endpoint of a module, after its own auth call: if the
 * module is off, answer HTTP 404 with {"ok":false,"error":"…"} in plain words
 * and stop. Admin pages that render HTML use module_admin_guard() instead.
 */
function module_ajax_guard(string $module): void
{
    if (module_enabled_with_needs($module)) return;
    http_response_code(404);
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'Модулът „' . module_label($module) . '“ е изключен. Може да бъде включен отново от „Модули“ в админ панела.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Call at the top of every public entry point of a module. If the module is
 * off, the page does not exist: the site's own 404 page is shown and the
 * request stops.
 */
function module_public_guard(string $module): void
{
    if (module_enabled_with_needs($module)) return;
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

/**
 * Turn the Модули form into the values to save: [feature_<name> => '1'|'0'].
 *
 * $in      the POSTed form; a ticked switch sends '1', an unticked one nothing.
 * $current the switches as they are now, [name => bool].
 *
 * A module whose `needs` are off after this save keeps its current value: its
 * switch is greyed out on the page (so the browser does not send it), and
 * switching the needed module back on must bring it back as it was.
 *
 * @return array{values: array<string,string>, errors: array<string,string>}
 */
function modules_validate_switches(array $in, array $current): array
{
    $values = $errors = [];
    $wanted = [];
    foreach (modules_registry() as $name => $m) {
        $raw = $in[module_field($name)] ?? null;
        if ($raw === null) {
            $wanted[$name] = false;
        } elseif ($raw === '1') {
            $wanted[$name] = true;
        } else {
            $wanted[$name] = (bool) ($current[$name] ?? true);
            $errors[module_field($name)] = 'Невалидна стойност за „' . $m['label'] . '“. Презаредете страницата и опитайте отново.';
        }
    }
    foreach (modules_registry() as $name => $m) {
        $on = $wanted[$name];
        if (!modules_needs_met($name, $wanted)) $on = (bool) ($current[$name] ?? true);
        $values[module_field($name)] = $on ? '1' : '0';
    }
    return ['values' => $values, 'errors' => $errors];
}

/** Are all of a module's needs (and theirs) on in this [name => bool] set? */
function modules_needs_met(string $name, array $on, array $seen = []): bool
{
    if (isset($seen[$name])) return true;
    $seen[$name] = true;
    foreach (modules_registry()[$name]['needs'] ?? [] as $need) {
        if (empty($on[$need]) || !modules_needs_met($need, $on, $seen)) return false;
    }
    return true;
}

/**
 * For the install wizard: FEATURE_<NAME> => bool for EVERY registered module,
 * from the names the person ticked. Unticked = off (written explicitly, so a
 * new site starts with only what it chose). Unknown names are ignored.
 */
function modules_install_flags(array $chosen): array
{
    $chosen = array_flip(array_filter($chosen, 'is_string'));
    $flags  = [];
    foreach (array_keys(modules_registry()) as $name) {
        $flags['FEATURE_' . strtoupper($name)] = isset($chosen[$name]);
    }
    return $flags;
}

/**
 * Plain-language problems with a set of choices: a module ticked without a
 * module it needs. [] when everything fits.
 */
function modules_needs_problems(array $on): array
{
    $problems = [];
    foreach (modules_registry() as $name => $m) {
        if (empty($on[$name])) continue;
        foreach ($m['needs'] as $need) {
            if (empty($on[$need])) {
                $problems[] = 'Модулът „' . $m['label'] . '“ има нужда от „' . module_label($need)
                            . '“. Отбележете и двата или махнете отметката от „' . $m['label'] . '“.';
            }
        }
    }
    return $problems;
}

/**
 * Compatibility: the shape admin/organisation.php used before Admin → Модули
 * existed. [name => [field, label, hint, off_warning]].
 */
// Guarded: before this file existed org_modules() lived in
// includes/organisation.php, and the updater keeps a site's own customised copy
// of that file — declaring it again here would take such a site down with
// "Cannot redeclare".
if (!function_exists('org_modules')) {
function org_modules(): array
{
    $out = [];
    foreach (modules_registry() as $name => $m) {
        $out[$name] = [
            'field'       => module_field($name),
            'label'       => $m['label'],
            'hint'        => $m['description'],
            'off_warning' => $m['off_warning'],
        ];
    }
    return $out;
}
}
