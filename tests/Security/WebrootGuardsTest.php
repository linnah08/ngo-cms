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
}
