<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every script a browser can reach must decide, on purpose, who may run it.
 *
 * These walk the webroot instead of listing files, so a script added later is
 * failing this suite until someone either guards it or writes down why it is
 * public. A hardcoded list cannot do that: it silently keeps passing while the
 * new file sits there unprotected.
 *
 * That is not hypothetical. An event check-in page — no login, straight to a
 * query for the names, ticket codes and amounts of everyone who had bought a
 * ticket or donated — sat in this repo's webroot and shipped inside every
 * release package up to v0.9.0. The site it came from had already added the
 * guard; upstream never got it, and nothing here was watching.
 *
 * Static source assertions on purpose: they run without a server or database,
 * so they fail in the suite rather than in production.
 */
#[Group('security')]
final class WebrootGuardsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /** Any of these means the script has decided who may run it. */
    private static function isGuarded(string $src): bool
    {
        return (bool) preg_match(
            '/admin_require_(?:login|admin|editorial|shop)\s*\(' // a role guard
            . '|admin_logged_in\s*\('                            // checked by hand
            . '|admin_bar_token_verify\s*\('                     // signed token
            . '|admin-header\.php/',                             // the header requires login
            $src
        )
        || self::isTokenAuthenticated($src)
        || self::doesNotRunOverHttp($src);
    }

    /**
     * A machine-to-machine endpoint authenticating a bearer token rather than a
     * session. No CSRF partner needed: a hostile page cannot attach an
     * Authorization header to a forged request.
     */
    private static function isTokenAuthenticated(string $src): bool
    {
        return str_contains($src, 'HTTP_AUTHORIZATION');
    }

    /**
     * Nothing happens when a browser asks for this file. Either it refuses
     * outright, or — the shape migrate.php needs, since includes/updater.php
     * requires it in-process during a self-update — it only does its work when
     * it is the script PHP was invoked with directly.
     */
    private static function doesNotRunOverHttp(string $src): bool
    {
        $refuses    = preg_match("/(?:PHP_SAPI|php_sapi_name\(\))\s*!==?\s*'cli'/", $src);
        $onlyDirect = preg_match("/(?:PHP_SAPI|php_sapi_name\(\))\s*===?\s*'cli'/", $src)
                      && str_contains($src, '__FILE__');

        return (bool) ($refuses || $onlyDirect);
    }

    // ── admin/ ────────────────────────────────────────────────────────────────

    /**
     * Reachable without a session by design. Anything not named here has to
     * guard itself — including a file added tomorrow.
     */
    private const ADMIN_PUBLIC = [
        'login.php'        => 'the login form itself',
        'login-forgot.php' => 'password reset request, used when locked out',
        'login-reset.php'  => 'password reset form, reached from an emailed link',
        'install-login.php' => 'one-time login from the install finish screen; needs a single-use 15-minute token',
        'logout.php'       => 'ends the session; nothing to protect',
        'index.php'        => 'redirects to the dashboard or the login form',
        'pin-docroot.php'  => 'auto_prepend helper, sets DOCUMENT_ROOT and stops',
        'translate.php'    => 'redirect stub kept for old bookmarks; reads nothing',
    ];

    public function testEveryAdminScriptDecidesWhoMayRunIt(): void
    {
        $unguarded = [];
        foreach (self::adminScripts() as $rel => $path) {
            $name = basename($rel);
            if (isset(self::ADMIN_PUBLIC[$name])) {
                continue;
            }
            if (!self::isGuarded(self::source($path))) {
                $unguarded[] = $rel;
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "These admin scripts can be opened by anyone who knows the URL:\n  %s\n"
            . "Add admin_require_login()/admin_require_admin() (or the role guard that fits) "
            . "before any output or data access. If the script really is meant to be public, "
            . "add it to ADMIN_PUBLIC with the reason.",
            implode("\n  ", $unguarded)
        ));
    }

    /** @return array<string,string> relative path => absolute path */
    private static function adminScripts(): array
    {
        $found = [];
        foreach (['admin/*.php', 'admin/api/*.php'] as $pattern) {
            foreach (glob(self::root() . '/' . $pattern) ?: [] as $path) {
                $found[substr($path, strlen(self::root()) + 1)] = $path;
            }
        }
        ksort($found);
        return $found;
    }

    public function testAdminScriptsAreActuallyFound(): void
    {
        // A glob that quietly matches nothing would make the test above pass
        // while checking no files at all.
        $this->assertGreaterThan(50, count(self::adminScripts()));
    }

    // ── the repo root ─────────────────────────────────────────────────────────

    /**
     * Root-level scripts a visitor is supposed to be able to open. Everything
     * else at the root must refuse to run over HTTP.
     */
    private const ROOT_PUBLIC = [
        'index.php'   => 'the front page',
        'sitemap.php' => 'sitemap.xml, read by search engines',
        'robots.php'  => 'robots.txt, read by search engines',
        'page.php'    => 'the pages admins create (Admin → Страници); drafts 404 for visitors',
    ];

    public function testRootScriptsArePublicOnPurposeOrCliOnly(): void
    {
        $open = [];
        foreach (glob(self::root() . '/*.php') ?: [] as $path) {
            $name = basename($path);
            if (isset(self::ROOT_PUBLIC[$name])) {
                continue;
            }
            // config.php and the *.config.php files are included, never routed;
            // they define constants and print nothing.
            if ($name === 'config.php' || str_ends_with($name, '.config.php')
                || str_ends_with($name, '.config.example.php')) {
                continue;
            }
            if (!self::doesNotRunOverHttp(self::source($path))) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open, sprintf(
            "These scripts sit in the webroot and run for anyone who requests them:\n  %s\n"
            . "Add a CLI guard (PHP_SAPI !== 'cli') if they are tools, or list them in "
            . "ROOT_PUBLIC with the reason if they are pages.",
            implode("\n  ", $open)
        ));
    }

    // ── cron/ ─────────────────────────────────────────────────────────────────

    public function testCronScriptsRefuseWebRequests(): void
    {
        $paths = glob(self::root() . '/cron/*.php') ?: [];
        $this->assertNotEmpty($paths, 'cron/ scripts should be found');

        foreach ($paths as $path) {
            $this->assertTrue(
                self::doesNotRunOverHttp(self::source($path)),
                basename($path) . ' runs scheduled work and must refuse HTTP requests'
            );
        }
    }

    // ── migrate.php ───────────────────────────────────────────────────────────

    /**
     * migrate.php cannot simply refuse non-CLI requests: includes/updater.php
     * requires it in-process during a self-update, which is a web request. So
     * the property to hold onto is narrower — requesting the file over HTTP
     * defines run_migrations() and does nothing else. Nothing may call it
     * except the direct-invocation block at the bottom.
     */
    public function testMigrateRunsNothingWhenRequestedOverHttp(): void
    {
        $src = self::source(self::root() . '/migrate.php');

        $this->assertTrue(
            self::doesNotRunOverHttp($src),
            'migrate.php applies production migrations and must not act on a web request'
        );

        $guard = strpos($src, "PHP_SAPI === 'cli'");
        $this->assertNotFalse($guard, 'migrate.php must keep its direct-invocation guard');

        // Every call to run_migrations() in this file has to sit after that
        // guard — the definition is the only earlier mention of the name.
        preg_match_all('/run_migrations\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
        $calls = array_filter(
            array_column($m[0], 1),
            static fn(int $pos) => substr_count(substr($src, 0, $pos), 'function run_migrations') === 1
                                   && !str_contains(substr($src, max(0, $pos - 30), 30), 'function ')
        );
        $this->assertNotEmpty($calls, 'migrate.php should still call run_migrations() somewhere');

        foreach ($calls as $pos) {
            $this->assertGreaterThan(
                $guard,
                $pos,
                'run_migrations() must only be called from behind the direct-invocation guard'
            );
        }
    }

    // ── .htaccess: internal files are never served ───────────────────────────

    /**
     * The blocking rules of the root .htaccess (a "-" target that answers 403 or
     * 404), as [PCRE pattern, case-insensitive], in the order Apache runs them.
     * @return list<array{0:string,1:bool}>
     */
    private static function htaccessBlocks(): array
    {
        $ht   = self::source(self::root() . '/.htaccess');
        $head = substr($ht, 0, (int) strpos($ht, '# Force HTTPS'));
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[([^\]]*)\]/m', $head, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $pattern, $flags]) {
            if (!preg_match('/(^|,)(F|R=40[34])(,|$)/', $flags)) continue;
            $out[] = [$pattern, (bool) preg_match('/(^|,)NC(,|$)/', $flags)];
        }
        return $out;
    }

    /** Does a request for $path (as .htaccess sees it: no leading slash) hit a blocking rule? */
    private static function blocked(string $path): bool
    {
        foreach (self::htaccessBlocks() as [$pattern, $nc]) {
            if (preg_match('~' . str_replace('~', '\~', $pattern) . '~' . ($nc ? 'i' : ''), $path)) return true;
        }
        return false;
    }

    /** Never for visitors: the repository, tooling, notes, exports, backups, settings. */
    private const NEVER_SERVED = [
        '.git/config', '.github/workflows/release.yml', '.githooks/pre-commit', '.gitignore', '.user.ini', 'admin/.user.ini',
        '.htaccess', '.phpunit.result.cache', '.env',
        'README.md', 'CLAUDE.md', 'docs/runbooks/x.md', 'docs/notes.html',
        'tests/bootstrap.php', 'scripts/sync-from-prod.sh', 'migrations/001_init.sql', 'cron/unpaid-orders-cron.php',
        'relay/README.md', 'relay/public/ticket.php', 'data/bg-cities.json', 'logs/php_errors.log',
        'node_modules/x/package.json', 'vendor/autoload.php', 'vendor/composer/installed.json',
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'playwright.config.js',
        'VERSION', 'LICENSE', 'db.config.php', 'site.config.php', 'db.config.php.example',
        'orders-export.csv', 'backup.sql', 'dump.SQL', 'pages.json.bak', 'content/pages.json.bak-20261027120000',
        'content/home.json.corrupt-20261027181407', 'backups/site.tar', 'nginx.pid', 'settings.ini', 'config.yml',
        'migrate.php', 'install.php',
    ];

    /** Must stay reachable: pages, assets, search-engine files and certificate checks. */
    private const ALWAYS_SERVED = [
        '', 'index.php', 'page.php', 'robots.txt', 'sitemap.xml', 'robots.php', 'sitemap.php', 'ads.txt',
        '.well-known/acme-challenge/abc123', '.well-known/security.txt',
        'assets/css/main.css', 'assets/js/main.js', 'assets/images/logo.png', 'assets/files/leaflet-3fa9c1.pdf',
        'magazin/', 'en/shop/', 'novini/some-article/', 'install/', 'install/index.php', 'api/cities.php',
        'newsletter/unsubscribe.php', 'donation/', 'cart/design-thumb.php', 'admin/login.php', 'errors/404.php',
    ];

    public function testInternalFilesAreNeverServed(): void
    {
        $this->assertNotEmpty(self::htaccessBlocks(), 'the blocking rules should be found before "# Force HTTPS"');
        $served = array_values(array_filter(self::NEVER_SERVED, static fn(string $p) => !self::blocked($p)));
        $this->assertSame([], $served, 'the web server would hand these out to anyone');
    }

    public function testPagesAssetsAndCertificateChecksStayReachable(): void
    {
        $blocked = array_values(array_filter(self::ALWAYS_SERVED, static fn(string $p) => self::blocked($p)));
        $this->assertSame([], $blocked, 'these must stay reachable');
    }

    /** cron/ and relay/ are blocked from the web: nothing there may need a browser. */
    public function testNothingInBlockedFoldersIsMeantForTheWeb(): void
    {
        foreach (glob(self::root() . '/cron/*.php') ?: [] as $path) {
            $this->assertTrue(self::doesNotRunOverHttp(self::source($path)), basename($path) . ' must be run from the command line');
        }
        // The relay is its own service on its own (sub)domain, with relay/public/ as web root.
        $this->assertStringContainsString('Only `public/` should be web-reachable', self::source(self::root() . '/relay/README.md'));
    }
}
