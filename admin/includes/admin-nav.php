<?php
/**
 * The admin menu, defined once.
 *
 * Every link lives in admin_nav_items(): its label, address, icon, where it
 * shows (the side menu or the Настройки panel in the top bar), who sees it and
 * any count of things waiting. admin/includes/admin-header.php only draws what
 * comes back, so adding a page to the menu is one entry here.
 *
 * Each link to a page that belongs to an optional module is hidden while that
 * module is off — module_admin_page_visible() reads admin_pages in
 * modules_registry() (includes/modules.php).
 */

require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';   // also for a site that kept an older config.php

/** Side-menu groups, in order. '' is the ungrouped top (Начало). */
const ADMIN_NAV_SIDE_GROUPS = ['', 'Съдържание', 'Магазин', 'Хора и кампании', 'Още'];

/** Настройки-panel groups, in order. */
const ADMIN_NAV_SETTINGS_GROUPS = ['Сайт', 'Плащане и документи', 'Имейли', 'Система'];

/**
 * The links this user sees, in menu order. Each one:
 *   key, label, href, icon, area ('side'|'settings'), group,
 *   nav         the $active_nav values that light it up,
 *   count       things waiting (0 = no badge), count_label what they are,
 *   count_href  where the badge goes, when that is not the page itself,
 *   tag         a short word shown next to it (Обновления: „ново“).
 */
function admin_nav_items(?string $active_nav = null): array
{
    $admin    = admin_is_admin();
    $editor   = admin_can_editorial();
    $shop     = admin_can_manage_shop();
    $items    = [];
    $add      = static function (bool $show, array $item) use (&$items): void {
        if ($show) $items[] = $item + ['nav' => [$item['key']], 'count' => 0, 'count_label' => '', 'count_href' => null, 'tag' => null];
    };

    $add(true, ['key' => 'dashboard', 'label' => 'Начало', 'href' => '/admin/dashboard.php', 'icon' => 'home', 'area' => 'side', 'group' => '']);

    // ── Съдържание ──
    $add($admin, ['key' => 'home', 'label' => 'Начална страница', 'href' => '/admin/home-sections.php', 'icon' => 'home-blocks', 'area' => 'side', 'group' => 'Съдържание']);
    $add($editor && module_admin_page_visible('articles.php'), ['key' => 'articles', 'label' => 'Статии', 'href' => '/admin/articles.php', 'icon' => 'article', 'area' => 'side', 'group' => 'Съдържание']);
    $add($admin && module_admin_page_visible('pages.php'), ['key' => 'pages', 'label' => 'Страници', 'href' => '/admin/pages.php', 'icon' => 'pages', 'area' => 'side', 'group' => 'Съдържание']);

    // Partners only ever appear in the front page's Партньори section, so the menu
    // item follows that section: switch it on in Начална страница and it is back.
    // On the partners page itself it stays, so the admin is never on a page the
    // menu does not show. The page itself always works at /admin/partners.php.
    $nav_partners = $active_nav === 'partners';
    if ($admin && !$nav_partners) {
        try {
            require_once dirname(__DIR__, 2) . '/includes/home.php';
            $nav_partners = home_section_on('partners');
        } catch (Throwable $e) {
            $nav_partners = true;
        }
    }
    $add($admin && $nav_partners, ['key' => 'partners', 'label' => 'Партньори', 'href' => '/admin/partners.php', 'icon' => 'heart', 'area' => 'side', 'group' => 'Съдържание']);

    // The page holds comments and contact messages: it stays while either
    // module is on. With „Коментари и отзиви“ off it is just „Контакти“ and the
    // waiting-comments badge goes; with „Контактна форма“ off, just „Коментари“.
    $comments_on = module_enabled_with_needs('comments_reviews');
    if ($editor && module_any_enabled('comments_reviews', 'contact_form')) {
        $add(true, ['key' => 'comments', 'label' => $comments_on ? 'Коментари' : 'Контакти', 'href' => '/admin/comments.php', 'icon' => 'comment', 'area' => 'side', 'group' => 'Съдържание',
                    'count' => $comments_on ? admin_nav_count("SELECT COUNT(*) FROM comments WHERE status='pending'") : 0,
                    'count_label' => 'чакащи коментара']);
    }

    // ── Магазин ──
    $add($shop && module_admin_page_visible('products.php'), ['key' => 'products', 'label' => 'Продукти', 'href' => '/admin/products.php', 'icon' => 'package', 'area' => 'side', 'group' => 'Магазин',
        'count' => $shop && module_admin_page_visible('product-reviews.php') ? admin_nav_count("SELECT COUNT(*) FROM product_reviews WHERE status='pending'") : 0,
        'count_label' => 'чакащи отзива', 'count_href' => '/admin/product-reviews.php']);
    $add($shop && module_admin_page_visible('orders.php'), ['key' => 'orders', 'label' => 'Поръчки', 'href' => '/admin/orders.php', 'icon' => 'cart', 'area' => 'side', 'group' => 'Магазин']);
    $add($shop && module_admin_page_visible('distribution.php'), ['key' => 'distribution', 'label' => 'Разпространение', 'href' => '/admin/distribution.php', 'icon' => 'share', 'area' => 'side', 'group' => 'Магазин']);
    $add($shop && module_admin_page_visible('monthly-report.php'), ['key' => 'monthly-report', 'label' => 'Месечен отчет', 'href' => '/admin/monthly-report.php', 'icon' => 'chart', 'area' => 'side', 'group' => 'Магазин']);

    // ── Хора и кампании ──
    $add($shop && module_admin_page_visible('events.php'), ['key' => 'events', 'label' => 'Събития', 'href' => '/admin/events.php', 'icon' => 'calendar', 'area' => 'side', 'group' => 'Хора и кампании']);
    $add($admin && module_admin_page_visible('campaign.php'), ['key' => 'campaign', 'label' => 'Кампания', 'href' => '/admin/campaign.php', 'icon' => 'megaphone', 'area' => 'side', 'group' => 'Хора и кампании']);
    $add($admin && module_admin_page_visible('newsletter.php'), ['key' => 'newsletter', 'label' => 'Бюлетин', 'href' => '/admin/newsletter.php', 'icon' => 'mail', 'area' => 'side', 'group' => 'Хора и кампании',
        'nav' => ['newsletter', 'newsletter-compose', 'newsletter-send', 'newsletter-subscribers', 'newsletter-topics']]);

    // ── The site's own links (includes/site-extensions.php 'admin_nav'), for the roles it names ──
    foreach (site_admin_nav((string) (admin_user()['role'] ?? '')) as $key => $site_nav) {
        $add(true, ['key' => 'site-' . $key, 'label' => $site_nav['label'], 'href' => $site_nav['href'], 'icon' => 'link', 'area' => 'side', 'group' => 'Още',
                    'nav' => [$site_nav['active_nav']]]);
    }

    // ── Настройки: Сайт ──
    $add($admin && module_admin_page_visible('organisation.php'), ['key' => 'organisation', 'label' => 'Организация', 'href' => '/admin/organisation.php', 'icon' => 'building', 'area' => 'settings', 'group' => 'Сайт']);
    $add($admin, ['key' => 'modules', 'label' => 'Модули', 'href' => '/admin/modules.php', 'icon' => 'modules', 'area' => 'settings', 'group' => 'Сайт']);
    $add($admin && module_admin_page_visible('menus.php'), ['key' => 'menus', 'label' => 'Менюта на сайта', 'href' => '/admin/menus.php', 'icon' => 'menu', 'area' => 'settings', 'group' => 'Сайт']);
    $add($admin && module_admin_page_visible('users.php'), ['key' => 'users', 'label' => 'Потребители', 'href' => '/admin/users.php', 'icon' => 'users', 'area' => 'settings', 'group' => 'Сайт']);

    // ── Настройки: Плащане и документи ──
    $add($admin && module_admin_page_visible('payment.php'), ['key' => 'payment', 'label' => 'Плащания', 'href' => '/admin/payment.php', 'icon' => 'card', 'area' => 'settings', 'group' => 'Плащане и документи']);
    if ($shop && module_admin_page_visible('sumup.php')) {
        require_once dirname(__DIR__, 2) . '/includes/sumup.php';
        $sumup_waiting = 0;
        try { $sumup_waiting = sumup_attention_count(get_pdo()); } catch (Throwable $e) {}
        $add(true, ['key' => 'sumup', 'label' => 'SumUp', 'href' => '/admin/sumup.php', 'icon' => 'terminal', 'area' => 'settings', 'group' => 'Плащане и документи',
                    'count' => $sumup_waiting, 'count_label' => 'продажби чакат избор на продукт']);
    }
    $add($admin && module_admin_page_visible('couriers.php'), ['key' => 'couriers', 'label' => 'Куриери', 'href' => '/admin/couriers.php', 'icon' => 'truck', 'area' => 'settings', 'group' => 'Плащане и документи']);
    $add($admin && admin_can_sign() && module_certificates_enabled(), ['key' => 'signature', 'label' => 'Подпис', 'href' => '/admin/signature.php', 'icon' => 'pen', 'area' => 'settings', 'group' => 'Плащане и документи']);

    // ── Настройки: Имейли ──
    $add($admin && module_admin_page_visible('email-settings.php'), ['key' => 'email-settings', 'label' => 'Имейл настройки', 'href' => '/admin/email-settings.php', 'icon' => 'at', 'area' => 'settings', 'group' => 'Имейли']);
    $add($admin && module_admin_page_visible('email-templates.php'), ['key' => 'email-templates', 'label' => 'Имейл шаблони', 'href' => '/admin/email-templates.php', 'icon' => 'template', 'area' => 'settings', 'group' => 'Имейли']);

    // ── Настройки: Система ──
    $add($admin && module_admin_page_visible('error-alerts.php'), ['key' => 'error-alerts', 'label' => 'Известия за грешки', 'href' => '/admin/error-alerts.php', 'icon' => 'alert', 'area' => 'settings', 'group' => 'Система']);
    $add($admin && module_admin_page_visible('scheduled-jobs.php'), ['key' => 'scheduled-jobs', 'label' => 'Автоматични задачи', 'href' => '/admin/scheduled-jobs.php', 'icon' => 'clock', 'area' => 'settings', 'group' => 'Система']);
    $add($admin && module_admin_page_visible('updates.php'), ['key' => 'updates', 'label' => 'Обновления', 'href' => '/admin/updates.php', 'icon' => 'refresh', 'area' => 'settings', 'group' => 'Система',
        'tag' => admin_nav_update_version() !== null ? 'ново' : null]);

    return $items;
}

/** A COUNT(*) for a badge; 0 when the table is missing or the query fails. */
function admin_nav_count(string $sql): int
{
    try {
        return (int) get_pdo()->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // table may not exist yet
    }
}

/** The items of one area ('side'|'settings'), grouped in menu order: group => items. */
function admin_nav_grouped(array $items, string $area): array
{
    $order = $area === 'side' ? ADMIN_NAV_SIDE_GROUPS : ADMIN_NAV_SETTINGS_GROUPS;
    $out   = array_fill_keys($order, []);
    foreach ($items as $item) {
        if ($item['area'] === $area) $out[$item['group']][] = $item;
    }
    return array_filter($out);
}

/** The key of the link that is the current page, or null. */
function admin_nav_current(array $items, ?string $active_nav): ?string
{
    if ($active_nav === null || $active_nav === '') return null;
    foreach ($items as $item) {
        if (in_array($active_nav, $item['nav'], true)) return $item['key'];
    }
    return null;
}

/** Everything waiting behind the Настройки button, for its badge. */
function admin_nav_settings_count(array $items): int
{
    $n = 0;
    foreach ($items as $item) {
        if ($item['area'] === 'settings') $n += $item['count'];
    }
    return $n;
}

/** Two letters for the account button: „Мария Иванова“ → „МИ“. */
function admin_nav_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out   = '';
    foreach (array_slice($words, 0, 2) as $w) $out .= mb_substr($w, 0, 1);
    return $out === '' ? '?' : mb_strtoupper($out);
}

/**
 * The newer version waiting to be installed, or null. Only for administrators
 * on a site that updates itself (updater_self_update_allowed()), and only from
 * the saved result of the last check — drawing a page never asks GitHub.
 * admin/update-check-ajax.php refreshes that result in the background.
 */
function admin_nav_update_version(): ?string
{
    if (!admin_is_admin() || !module_admin_page_visible('updates.php')) return null;
    try {
        require_once dirname(__DIR__, 2) . '/includes/updater.php';
        if (!updater_self_update_allowed() || updater_is_maintenance_mode()) return null;
        return updater_cached_update();
    } catch (Throwable $e) {
        return null;
    }
}

/** Should the page ask admin/update-check-ajax.php to check for a new version? */
function admin_nav_update_check_due(): bool
{
    if (!admin_is_admin() || !module_admin_page_visible('updates.php')) return false;
    try {
        require_once dirname(__DIR__, 2) . '/includes/updater.php';
        return updater_self_update_allowed() && updater_cache_is_stale();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * A line icon (24×24, drawn with the text colour). Decorative: the link's own
 * text says what it is, so screen readers skip it.
 */
function admin_nav_icon(string $name, int $size = 20): string
{
    static $paths = [
        'home'        => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'home-blocks' => '<rect x="3" y="3" width="18" height="6" rx="1"/><rect x="3" y="12" width="8" height="9" rx="1"/><path d="M14 13h7M14 17h7M14 21h4"/>',
        'article'     => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/><path d="M9 13h6M9 17h6"/>',
        'pages'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
        'heart'       => '<path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z"/>',
        'comment'     => '<path d="M4 5h16v11H9l-5 4z"/>',
        'package'     => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
        'cart'        => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h12L22 7H6"/>',
        'share'       => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
        'chart'       => '<path d="M5 20V11M11 20V5M17 20v-7M3 20h18"/>',
        'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'megaphone'   => '<path d="M3 10v4h4l8 5V5l-8 5z"/><path d="M19 9a4 4 0 0 1 0 6"/>',
        'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'link'        => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'building'    => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>',
        'modules'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M17.5 14v7M14 17.5h7"/>',
        'menu'        => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20a7 7 0 0 1 14 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a7 7 0 0 1 4 6"/>',
        'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'terminal'    => '<rect x="6" y="2" width="12" height="20" rx="2"/><rect x="9" y="5" width="6" height="4" rx=".5"/><path d="M9 13h.01M12 13h.01M15 13h.01M9 16h.01M12 16h.01M15 16h.01"/>',
        'truck'       => '<path d="M2 6h12v10H2zM14 10h4l4 3v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'pen'         => '<path d="M16 3l5 5L8 21H3v-5z"/>',
        'at'          => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
        'template'    => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="8" height="7" rx="1"/><rect x="15" y="14" width="6" height="7" rx="1"/>',
        'alert'       => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'refresh'     => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
        'external'    => '<path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v6H4V5h6"/>',
        'help'        => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
        'gear'        => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'chevron'     => '<path d="m6 9 6 6 6-6"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'logout'      => '<path d="M9 21H4V3h5"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'close'       => '<path d="M6 6l12 12M18 6 6 18"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'
         . ' style="flex:none;">' . ($paths[$name] ?? $paths['link']) . '</svg>';
}

/** Text only screen readers hear. */
function admin_nav_sr(string $text): string
{
    return '<span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">' . h($text) . '</span>';
}
