<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';
require_once dirname(__DIR__, 2) . '/includes/menus.php';

/**
 * Every created-pages test works on its own empty content/pages/ in a temp
 * folder (cpage_dir() reads $GLOBALS['_om_pages_dir']), never on the site's.
 */
abstract class CreatedPagesTestCase extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/om-cpages-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0755, true);
        $GLOBALS['_om_pages_dir'] = $this->dir;
        // Page images and menus too: never the site's own.
        $GLOBALS['_om_pages_img_dir'] = $this->dir . '-img';
        $GLOBALS['_om_menus_file']    = $this->dir . '-menus.json';
        cpage_cache_reset();
        home_target_reset();
    }

    /** Remove a folder this test made, with everything in it (links removed, never followed). */
    protected function rmTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') $this->rmTree($dir . '/' . $e);
        }
        @rmdir($dir);
    }

    protected function tearDown(): void
    {
        home_target_reset();
        unset($GLOBALS['_om_pages_dir'], $GLOBALS['_om_pages_img_dir'], $GLOBALS['_om_menus_file']);
        cpage_cache_reset();
        $this->rmTree($this->dir . '-img');
        @unlink($this->dir . '-menus.json');
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Write a page file directly, as the site would have it on disk. */
    protected function putPage(array $page): array
    {
        $page += ['version' => 1, 'rev' => 0, 'status' => 'draft', 'title_bg' => 'Т', 'title_en' => 'T',
                  'slug_bg' => '', 'slug_en' => '', 'sections' => [], 'created' => '', 'updated' => ''];
        file_put_contents($this->dir . '/' . $page['id'] . '.json', json_encode($page, JSON_UNESCAPED_UNICODE));
        cpage_cache_reset();
        return $page;
    }

    protected function storyPage(string $status = 'published'): array
    {
        return $this->putPage([
            'id' => 'p_abcd1234', 'status' => $status,
            'title_bg' => 'Нашата история', 'title_en' => 'Our story',
            'slug_bg' => 'nashata-istoriya', 'slug_en' => 'our-story',
            'sections' => [[
                'id' => 's_t1', 'type' => 'richtext', 'visible' => true,
                'fields' => ['heading' => ['bg' => 'Как започнахме', 'en' => 'How we began'],
                             'body'    => ['bg' => '<p>Тяло на страницата</p>', 'en' => '<p>Body of the page</p>']],
            ]],
        ]);
    }

    /**
     * Run $script (page.php, sitemap.php…) in its own PHP process, as a request
     * for $uri. Returns [output, response code].
     */
    protected function request(string $script, string $uri, ?string $role = null, array $server = []): array
    {
        $root = dirname(__DIR__, 2);
        $code = sprintf(
            '$_SERVER["DOCUMENT_ROOT"] = %1$s; $_SERVER["REQUEST_URI"] = %2$s; $_SERVER["HTTP_HOST"] = "localhost";'
            . '$_SERVER = %6$s + $_SERVER;'
            . '$GLOBALS["_om_pages_dir"] = %3$s;'
            . 'register_shutdown_function(function () { echo "\n@@CODE=" . (int) (http_response_code() ?: 200); });'
            . 'if (%4$s !== null) { require_once %1$s . "/config.php"; session_start();'
            . ' $_SESSION[ADMIN_SESSION_NAME] = ["role" => %4$s, "time" => time(), "id" => 1, "username" => "t"]; }'
            . 'require %5$s;',
            var_export($root, true), var_export($uri, true), var_export($this->dir, true),
            var_export($role, true), var_export($root . '/' . $script, true), var_export($server, true)
        );
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg(sys_get_temp_dir())
            . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertMatchesRegularExpression('/@@CODE=(\d+)$/', $out, "No response from $script for $uri:\n$out");
        preg_match('/\n@@CODE=(\d+)$/', $out, $m);
        return [substr($out, 0, -strlen($m[0])), (int) $m[1]];
    }
}
