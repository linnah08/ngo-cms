<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What switching a module off does to the rest of the site (step 2 of the
 * optional modules): its pages are guarded, links to it disappear from menus,
 * the front page and the sitemap, its emails and scheduled jobs stop — and the
 * things that must keep working with it off (unsubscribe links, donations, …)
 * still do.
 *
 * Anything that switches a module off runs in a child PHP process
 * (FEATURE_<NAME> constants cannot be undefined), so this process keeps the
 * site's own values.
 */
final class ModulesGatingTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function src(string $rel): string
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        if (!is_string($src)) throw new RuntimeException("$rel is missing");
        return $src;
    }

    /**
     * Run PHP code in a child process with the test bootstrap loaded and the
     * given modules switched off (FEATURE_<NAME> = false, defined before
     * config.php so the site's own switches do not win). Returns stdout.
     *
     * The worktree's own content/organisation.json (gitignored) would override
     * the constants, so a site with saved switches skips instead.
     */
    private function child(string $code, array $off = [], bool $allowFail = false): string
    {
        if (is_file(self::root() . '/content/organisation.json')) {
            $saved = json_decode((string) file_get_contents(self::root() . '/content/organisation.json'), true);
            foreach ($off as $name) {
                if (isset($saved['feature_' . $name])) $this->markTestSkipped('This site saves its module switches in content/organisation.json.');
            }
        }
        $defs = '';
        foreach ($off as $name) $defs .= "define('FEATURE_" . strtoupper($name) . "', false);\n";
        $boot = var_export(self::root() . '/tests/bootstrap.php', true);
        $file = tempnam(sys_get_temp_dir(), 'om_gating_');
        file_put_contents($file, "<?php\n{$defs}require {$boot};\n" . $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        if (!$allowFail) $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /** Every public entry point of the step-2 modules: [module, file]. */
    public static function publicEntryPoints(): array
    {
        $map = [
            'annual_reports'   => ['finansovi-otcheti/index.php', 'en/financial-reports/index.php'],
            'comments_reviews' => ['api/comment-submit.php', 'api/review-submit.php'],
            'newsletter'       => ['newsletter/subscribe.php', 'newsletter/subscribe-order.php'],
        ];
        $out = [];
        foreach ($map as $module => $files) foreach ($files as $f) $out["$module: $f"] = [$module, $f];
        return $out;
    }

    #[DataProvider('publicEntryPoints')]
    public function test_public_entry_point_is_guarded(string $module, string $rel): void
    {
        $this->assertStringContainsString("module_public_guard('{$module}');", self::src($rel), $rel);
    }

    /** Admin pages call their guard after their own auth check, so visitors never learn which modules a site runs. */
    public function test_admin_guards_come_after_auth(): void
    {
        foreach (modules_registry() as $name => $m) {
            foreach ($m['admin_pages'] as $page) {
                $src   = self::src('admin/' . $page);
                $guard = strpos($src, "module_admin_guard('{$name}');");
                $this->assertNotFalse($guard, "$page has its guard");
                $this->assertSame(1, preg_match('/admin_require_(admin|login|shop|editorial|social)\(\);/', $src, $mm, PREG_OFFSET_CAPTURE), "$page checks who is asking");
                $this->assertLessThan($guard, $mm[0][1], "$page: auth before the module guard");
            }
        }
    }

    public function test_annual_reports_footer_link_and_content_list_follow_the_switch(): void
    {
        $this->assertStringContainsString("module_enabled_with_needs('annual_reports') && reports_any_published()", self::src('templates/footer.php'));
        $this->assertStringContainsString('module_link_visible($r[2])', self::src('admin/pages.php'));
    }

    public function test_annual_reports_page_is_a_404_when_off(): void
    {
        $out = $this->child('$_SERVER["REQUEST_URI"] = "/finansovi-otcheti/"; require ROOT_PATH . "/finansovi-otcheti/index.php"; echo "REACHED";', ['annual_reports']);
        $this->assertStringContainsString('Страницата не е намерена', $out);
        $this->assertStringNotContainsString('REACHED', $out);
    }

    // ── ai_helpers ───────────────────────────────────────────────────────────

    public function test_translate_buttons_absent_when_ai_helpers_off(): void
    {
        $code = 'require ROOT_PATH . "/admin/includes/admin-footer.php";';
        $off = $this->child($code, ['ai_helpers']);
        $this->assertStringNotContainsString('data-translate-from', $off, 'no button is attached to EN fields');
        $this->assertStringNotContainsString('/admin/translate-ajax.php', $off);
        $this->assertStringContainsString('function txField() {}', $off, 'inline handlers on a page never throw');
        $on = $this->child($code);
        if (!module_enabled_with_needs('ai_helpers')) $this->markTestSkipped('ai_helpers is off on this site.');
        $this->assertStringContainsString("querySelectorAll('[data-translate-from]').forEach(attach)", $on);
    }

    public function test_page_level_ai_buttons_follow_the_switch(): void
    {
        foreach (['admin/articles.php', 'admin/campaign.php', 'admin/email-templates.php', 'admin/newsletter-compose.php', 'admin/product-edit.php', 'admin/article-edit.php'] as $rel) {
            $this->assertStringContainsString("module_enabled_with_needs('ai_helpers') && deepl_is_configured()", self::src($rel), $rel);
        }
        $pages = self::src('admin/pages.php');
        $this->assertSame(0, preg_match('/^(?!.*ai_helpers).*<button[^>]*(txField\(|txEl\(|translate-legal-btn)/m', $pages), 'every translate button in pages.php is wrapped');
        $this->assertStringContainsString("\$claude_ready && module_enabled_with_needs('ai_helpers')", self::src('admin/article-edit.php'), 'keyword suggestions');
        $this->assertMatchesRegularExpression("/module_enabled_with_needs\('ai_helpers'\)\): \?>\s*<button type=\"button\" id=\"extractDimsBtn\"/", self::src('admin/product-edit.php'));
        $this->assertStringContainsString("window._aiHelpersOn", self::src('admin/includes/admin-header.php'));
        $this->assertStringContainsString("window._aiHelpersOn !== false", self::src('admin/menus.php'));
    }

    public function test_ai_endpoints_refuse_when_off(): void
    {
        foreach (['translate-ajax.php', 'translate-article-ajax.php', 'suggest-keywords-ajax.php', 'extract-size-dims.php'] as $f) {
            $src = self::src('admin/' . $f);
            $auth = preg_match('/admin_require_\w+\(\);/', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : PHP_INT_MAX;
            $guard = strpos($src, "module_ajax_guard('ai_helpers');");
            $this->assertNotFalse($guard, $f);
            $this->assertLessThan($guard, $auth, "$f: auth first");
        }
        $out = $this->child('module_ajax_guard("ai_helpers"); echo "REACHED";', ['ai_helpers']);
        $this->assertStringNotContainsString('REACHED', $out);
        $r = json_decode($out, true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Помощ от изкуствен интелект', $r['error']);
    }

    public function test_excerpts_fall_back_to_the_plain_cut_without_an_api_call(): void
    {
        $out = $this->child(<<<'PHP'
            require_once ROOT_PATH . '/includes/ai_excerpt.php';
            $html = '<p>' . str_repeat('Дума текст ', 40) . '</p>';
            echo json_encode([article_auto_excerpt('Заглавие', $html, 'bg'), article_excerpt_from_content($html)], JSON_UNESCAPED_UNICODE);
            PHP, ['ai_helpers']);
        [$got, $plain] = json_decode($out, true);
        $this->assertSame($plain, $got);
        $this->assertStringContainsString("module_enabled_with_needs('ai_helpers')", self::src('cron/backfill-article-excerpts.php'));
    }

    // ── newsletter ───────────────────────────────────────────────────────────

    public function test_unsubscribe_and_tracking_are_never_guarded(): void
    {
        foreach (['newsletter/unsubscribe.php', 'newsletter/track.php'] as $rel) {
            $src = self::src($rel);
            $this->assertStringNotContainsString('module_public_guard', $src, "$rel must work in emails already sent");
            $this->assertStringNotContainsString("module_enabled_with_needs('newsletter')", $src, $rel);
            $this->assertNull(module_for_path('/' . $rel), "$rel is no module's page, so no link to it is ever hidden");
        }
    }

    public function test_unsubscribe_still_works_with_the_newsletter_off(): void
    {
        $out = $this->child('$_SERVER["REQUEST_URI"] = "/newsletter/unsubscribe.php?token=x"; $_GET["token"] = str_repeat("0", 32); require ROOT_PATH . "/newsletter/unsubscribe.php";', ['newsletter']);
        $this->assertStringContainsString('Отписани сте', $out);
        $this->assertStringNotContainsString('Страницата не е намерена', $out);
    }

    public function test_sign_up_forms_disappear_when_off(): void
    {
        $this->assertStringContainsString("\$_show_nl_banner = module_enabled_with_needs('newsletter') && ", self::src('templates/footer.php'));
        $this->assertStringContainsString("if (!module_enabled_with_needs('newsletter')) return;", self::src('templates/newsletter-confirm-card.php'));
        $checkout = self::src('checkout/index.php');
        $this->assertStringContainsString("'newsletter'    => module_enabled_with_needs('newsletter') && (", $checkout, 'no opt-in recorded');
        $this->assertMatchesRegularExpression("/<\?php if \(module_enabled_with_needs\('newsletter'\)\): \?>\s*<div[^>]*>\s*<\?php\s*\\\$nl_heading/", $checkout, 'no opt-in shown');
    }

    public function test_confirmation_card_renders_nothing_when_off(): void
    {
        $out = $this->child('$order = ["order_number" => "OM-20260101-ABCD"]; $nl_flow = "order"; require ROOT_PATH . "/templates/newsletter-confirm-card.php"; echo "[END]";', ['newsletter']);
        $this->assertSame('[END]', trim($out));
    }

    public function test_scheduled_newsletters_wait_while_off(): void
    {
        $cron = self::src('cron/newsletter-send-scheduled-cron.php');
        $skip = strpos($cron, "if (!module_enabled_with_needs('newsletter')) {");
        $this->assertNotFalse($skip);
        $this->assertLessThan(strpos($cron, 'newsletter_due_campaigns($pdo)'), $skip, 'skips before sending anything');
        $this->assertStringContainsString("if (!module_enabled_with_needs('newsletter')) return false;", self::src('includes/scheduled_jobs.php'), 'no "cron missing" warning for an off module');

        if (!defined('DB_HOST')) $this->markTestSkipped('No database.');
        $out = $this->child('$argv = ["x"]; require ROOT_PATH . "/cron/newsletter-send-scheduled-cron.php"; echo "NOT STOPPED";', ['newsletter']);
        $this->assertStringContainsString('nothing sent', $out);
        $this->assertStringNotContainsString('NOT STOPPED', $out);
    }

    // ── comments_reviews ─────────────────────────────────────────────────────

    public function test_comments_and_reviews_disappear_from_public_pages(): void
    {
        foreach (['novini/index.php', 'en/news/index.php'] as $rel) {
            $this->assertStringContainsString("if (module_enabled_with_needs('comments_reviews')) require \$_SERVER['DOCUMENT_ROOT'] . '/templates/comments.php';", self::src($rel), $rel);
        }
        $shop = self::src('magazin/index.php');
        $this->assertStringContainsString("\$approved_reviews = \$reviews_on ? product_reviews_fetch_approved(", $shop, 'no rating in the product schema either');
        $this->assertStringContainsString("if (\$reviews_on) require \$_SERVER['DOCUMENT_ROOT'] . '/templates/product-reviews.php';", $shop);
        // Product reviews need the shop as well; comments do not.
        $this->assertStringContainsString("module_public_guard('shop');", self::src('api/review-submit.php'));
        $this->assertStringNotContainsString("'shop'", self::src('api/comment-submit.php'));
        $this->assertSame([], modules_registry()['comments_reviews']['needs'], 'comments work without the shop');
        $rev = self::src('admin/product-reviews.php');
        $this->assertLessThan(strpos($rev, "module_admin_guard('shop');"), strpos($rev, "module_admin_guard('comments_reviews');"));
    }

    public function test_comment_endpoint_is_a_404_when_off(): void
    {
        $out = $this->child('$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REQUEST_URI"] = "/api/comment-submit.php"; require ROOT_PATH . "/api/comment-submit.php"; echo "REACHED";', ['comments_reviews']);
        $this->assertStringContainsString('Страницата не е намерена', $out);
        $this->assertStringNotContainsString('REACHED', $out);
    }

    public function test_contact_messages_stay_when_comments_are_off(): void
    {
        $src = self::src('admin/comments.php');
        $this->assertStringContainsString("if (!\$comments_on) \$source = 'contacts';", $src);
        $this->assertStringContainsString("!module_enabled_with_needs('comments_reviews') && in_array(\$post_action, \$comment_actions, true)", $src, 'comment moderation refused');
        $this->assertNotContains('comments.php', modules_registry()['comments_reviews']['admin_pages'], 'the page also holds contact messages');
        $this->assertStringContainsString("if (\$can_editorial && module_enabled_with_needs('comments_reviews'))", self::src('admin/dashboard.php'));
    }

    // ── social ───────────────────────────────────────────────────────────────

    public function test_social_endpoints_refuse_when_off_and_the_editor_drops_the_tab(): void
    {
        foreach (['social-ajax.php', 'linkedin-ajax.php', 'buffer-setup-ajax.php'] as $f) {
            $src = self::src('admin/' . $f);
            $auth = preg_match('/admin_require_\w+\(\);/', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : PHP_INT_MAX;
            $guard = strpos($src, "module_ajax_guard('social');");
            $this->assertNotFalse($guard, $f);
            $this->assertLessThan($guard, $auth, "$f: auth first");
        }
        $edit = self::src('admin/article-edit.php');
        $this->assertStringContainsString("\$tab      = \$social_on && (\$_GET['tab'] ?? 'content') === 'social' ? 'social' : 'content';", $edit);
        $this->assertMatchesRegularExpression('/<\?php if \(\$social_on\): \?>\s*<nav aria-label="Части на статията"/', $edit);
        $this->assertStringContainsString("if (!module_enabled_with_needs('social')) continue;", self::src('admin/dashboard.php'), 'calendar');
        $this->assertMatchesRegularExpression("/module_enabled_with_needs\('social'\)\): \?>\s*<!-- ── Buffer/u", self::src('admin/payment.php'));
    }

    public function test_social_pending_counts_posts_still_waiting(): void
    {
        $dir = sys_get_temp_dir() . '/om-social-' . bin2hex(random_bytes(3));
        mkdir($dir . '/bg', 0777, true);
        $now = time();
        file_put_contents($dir . '/bg/a.json', json_encode(['fb_scheduled_at' => date('Y-m-d\\TH:i', $now + 86400), 'linkedin_scheduled_at' => date('Y-m-d\\TH:i', $now + 7200)]));
        file_put_contents($dir . '/bg/b.json', json_encode(['fb_scheduled_at' => date('Y-m-d\\TH:i', $now - 86400), 'insta_scheduled_at' => 'now']));
        file_put_contents($dir . '/bg/c.json', 'not json');
        $n = modules_social_scheduled_count($dir, $now);
        exec('rm -rf ' . escapeshellarg($dir));
        $this->assertSame(2, $n);
        $this->assertSame(0, modules_social_scheduled_count($dir, $now), 'a missing folder is nothing to report');
    }

    // ── Links to modules ─────────────────────────────────────────────────────

    public function test_module_for_path_matches_public_paths(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'shop' => ['label' => 'Магазин', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [],
                           'public_paths' => ['/magazin/', '/en/shop/', '/api/review-submit.php']],
                'blog' => ['label' => 'Блог', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [],
                           'public_paths' => ['/magazin/blog/']],
            ]);
            echo json_encode([
                module_for_path('/magazin/'), module_for_path('/magazin'), module_for_path('/magazin/x/?a=1#b'),
                module_for_path('/magazinx/'), module_for_path('/magazin/blog/post/'), module_for_path(rtrim(SITE_URL, '/') . '/en/shop/'),
                module_for_path('/api/review-submit.php'), module_for_path('/api/review-submit.phpx'),
                module_for_path('https://other-site.invalid/magazin/'), module_for_path('mailto:a@b.c'), module_for_path('#top'),
                module_for_path('//evil.example/magazin/'), module_for_path(''),
            ]);
            PHP, ['shop']);
        $this->assertSame(['shop', 'shop', 'shop', null, 'blog', 'shop', 'shop', null, null, null, null, null, null], json_decode($out, true), $out);
    }

    public function test_links_to_an_off_module_are_dropped_with_their_keys_kept(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'shop' => ['label' => 'Магазин', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [], 'public_paths' => ['/magazin/']],
            ]);
            echo json_encode([
                'visible' => [module_link_visible('/magazin/'), module_link_visible('/za-nas/')],
                'menu'    => module_filter_links([['url' => '/za-nas/', 'label' => 'A'], ['url' => '/magazin/', 'label' => 'B'], ['url' => '/kontakti/', 'label' => 'C']]),
            ]);
            PHP, ['shop']);
        $r = json_decode($out, true);
        $this->assertSame([false, true], $r['visible'], $out);
        $this->assertSame(['0', '2'], array_map('strval', array_keys($r['menu'])), 'the on-page editor needs the original indexes');
    }

    public function test_menus_front_page_buttons_cards_and_sitemap_use_the_link_check(): void
    {
        $this->assertStringContainsString("module_filter_links(\$_menus['header'][\$lang] ?? [])", self::src('templates/header.php'));
        $footer = self::src('templates/footer.php');
        $this->assertStringContainsString("module_filter_links(\$_fmenus['footer_nav'][\$lang]   ?? [])", $footer);
        $this->assertStringContainsString("module_filter_links(\$_fmenus['footer_help'][\$lang]  ?? [])", $footer);
        $home = self::src('includes/home_render.php');
        $this->assertStringContainsString('if (!module_link_visible($url)) continue;', $home);
        $this->assertStringContainsString("if (!home_section_module_on((string) \$s['type'])) continue;", $home);
        $this->assertStringContainsString('module_link_visible($link)', self::src('templates/home/cards.php'));
        $this->assertStringContainsString('module_link_visible($u[0])', self::src('sitemap.php'));
        $this->assertStringContainsString('menu-pair-module-off', self::src('admin/menus.php'), 'the menu editor says why an item is hidden');
    }

    public function test_front_page_buttons_to_an_off_module_disappear(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'campaign' => ['label' => 'Кампании', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [], 'public_paths' => ['/campaign/', '/en/campaign/']],
            ]);
            require_once ROOT_PATH . '/includes/home_render.php';
            echo home_buttons('s1', [
                'btn1_label' => ['bg' => 'Кампания', 'en' => ''], 'btn1_url' => ['bg' => '/campaign/', 'en' => ''],
                'btn2_label' => ['bg' => 'За нас', 'en' => ''],   'btn2_url' => ['bg' => '/za-nas/', 'en' => ''],
            ], 'bg', [['btn', ''], ['btn', '']]);
            echo '|', home_section_module_on('campaign') ? 'on' : 'off', '|', home_section_module_on('mission') ? 'on' : 'off';
            PHP, ['campaign']);
        $this->assertStringNotContainsString('/campaign/', $out);
        $this->assertStringContainsString('href="/za-nas/"', $out);
        $this->assertStringEndsWith('|off|on', $out);
    }

    /** [module, an address of it that the sitemap would otherwise list] */
    public static function sitemapModules(): array
    {
        return [
            'donations' => ['donations', '/donation/'],
        ];
    }

    #[DataProvider('sitemapModules')]
    public function test_sitemap_omits_an_off_module(string $module, string $path): void
    {
        if (!defined('DB_HOST')) $this->markTestSkipped('No database.');
        $code = '$_SERVER["REQUEST_URI"] = "/sitemap.xml"; require ROOT_PATH . "/sitemap.php";';
        $off  = $this->child($code, [$module]);
        $this->assertStringContainsString('<urlset', $off);
        $this->assertStringNotContainsString('<loc>' . rtrim(SITE_URL, '/') . $path, $off);
        $this->assertStringContainsString('<loc>' . rtrim(SITE_URL, '/') . '/za-nas/</loc>', $off, 'core pages stay');
    }
}
