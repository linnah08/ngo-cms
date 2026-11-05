<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Edit mode on the public site saves through admin/inline-*.php. Each endpoint must refuse a
 * role that the matching admin page refuses — an author could otherwise rewrite page content
 * (and plant a link that runs script) through admin/inline-save.php, which checked only that
 * someone was logged in. Formatted text must also lose every attribute but a safe href.
 */
#[Group('admin')]
final class InlineEditRolesTest extends TestCase
{
    /** Raw bytes of each file this test writes, or null if it did not exist. */
    private array $backup = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/inline_html.php';
        foreach ([CONTENT_PATH . '/pages.json', IMPACT_FILE] as $path) {
            $this->backup[$path] = is_file($path) ? file_get_contents($path) : null;
        }
        save_json(CONTENT_PATH . '/pages.json', ['footer' => ['tagline' => 'Старо', 'tagline_en' => 'Old']]);
        save_json(IMPACT_FILE, [['number' => '10', 'label_bg' => 'деца', 'label_en' => 'children']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $path => $bytes) {
            if ($bytes !== null) file_put_contents($path, $bytes);
            elseif (is_file($path)) unlink($path);
        }
    }

    private function json(string $rel, array $body, array $opts = []): array
    {
        $r = run_admin_page($rel, [], $opts + ['json' => $body]);
        $r['json'] = json_decode(trim($r['body']), true) ?? [];
        return $r;
    }

    private function saveFooter(string $bg, array $opts = []): array
    {
        return $this->json('admin/inline-save.php',
            ['section' => 'footer', 'fields' => ['tagline' => ['bg' => $bg, 'en' => 'New']]], $opts);
    }

    private function tagline(): string
    {
        return (string) (load_json(CONTENT_PATH . '/pages.json')['footer']['tagline'] ?? '');
    }

    // ── inline-save.php: page content is admin-only ──────────────────────────

    public function test_admin_can_save_page_text(): void
    {
        $this->assertSame(['ok' => true], $this->saveFooter('Ново')['json']);
        $this->assertSame('Ново', $this->tagline());
    }

    public static function nonAdminRoles(): array
    {
        return ['author' => ['author'], 'shop manager' => ['shop_admin']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_save_page_text(string $role): void
    {
        $r = $this->saveFooter('<a href="https://evil.example">x</a>', ['role' => $role]);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['error'] ?? null);
        $this->assertSame('Старо', $this->tagline(), 'nothing may be written');
    }

    public function test_logged_out_request_without_cookie_is_refused(): void
    {
        $r = $this->saveFooter('Ново', ['role' => null, 'csrf' => false]);
        $this->assertSame('unauthorized', $r['json']['error'] ?? null);
        $this->assertSame('Старо', $this->tagline());
    }

    /** The admin-bar cookie a subdomain request carries instead of a session. */
    private function cookie(string $role): array
    {
        $exp = (string) (time() + 600);
        return ['_COOKIE' => [ADMIN_BAR_COOKIE => $exp . '|' . $role . '|' . hash_hmac('sha256', "v2|$exp|$role", (string) _admin_bar_secret())]];
    }

    public function test_admin_bar_cookie_works_only_for_an_admin(): void
    {
        if (_admin_bar_secret() === null) $this->markTestSkipped('SETTINGS_ENCRYPTION_KEY not configured');

        $r = $this->saveFooter('От автор', ['role' => null, 'csrf' => false, 'globals' => $this->cookie('author')]);
        $this->assertSame('forbidden', $r['json']['error'] ?? null);
        $this->assertSame('Старо', $this->tagline());

        $r = $this->saveFooter('От админ', ['role' => null, 'csrf' => false, 'globals' => $this->cookie('admin')]);
        $this->assertSame(['ok' => true], $r['json']);
        $this->assertSame('От админ', $this->tagline());
    }

    public function test_script_link_from_the_report_is_stored_without_its_address(): void
    {
        $payload = '<a href="javascript:alert(document.domain)" onclick="x()">Read more -&gt;</a>';
        $this->assertSame(['ok' => true], $this->saveFooter($payload)['json']);
        $saved = $this->tagline();
        $this->assertStringNotContainsStringIgnoringCase('javascript', $saved);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $saved);
        $this->assertStringContainsString('Read more', $saved);
    }

    // ── The other inline endpoints ───────────────────────────────────────────

    public function test_author_cannot_add_or_remove_page_items(): void
    {
        $before = file_get_contents(IMPACT_FILE);
        $r = run_admin_page('admin/inline-add.php', ['type' => 'impact', 'number' => '1'], ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', json_decode(trim($r['body']), true)['error'] ?? null);
        $r = $this->json('admin/inline-remove.php', ['type' => 'impact', 'id' => 0], ['role' => 'author']);
        $this->assertSame('forbidden', $r['json']['error'] ?? null);
        $this->assertSame($before, file_get_contents(IMPACT_FILE));
    }

    public function test_author_cannot_save_a_product(): void
    {
        $r = $this->json('admin/inline-save-product.php',
            ['fields' => ['id' => ['bg' => '1'], 'description' => ['bg' => '<img src=x onerror=alert(1)>', 'en' => '']]],
            ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['error'] ?? null);
    }

    public function test_shop_manager_cannot_save_an_article(): void
    {
        $r = $this->json('admin/inline-save-article.php',
            ['fields' => ['slug' => ['bg' => 'x'], 'content' => ['bg' => '<p>x</p>']]], ['role' => 'shop_admin']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['error'] ?? null);
    }

    // ── inline_clean_html() ──────────────────────────────────────────────────

    public static function cleaned(): array
    {
        return [
            'script link'            => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'entity-hidden scheme'   => ['<a href="JaVa&#115;cript:alert(1)">x</a>', '<a>x</a>'],
            'tab inside scheme'      => ["<a href=\"java\tscript:1\">x</a>", '<a>x</a>'],
            'data and vbscript'      => ['<a href="data:text/html,x">d</a><a href="vbscript:x">v</a>', '<a>d</a><a>v</a>'],
            'protocol-relative'      => ['<a href="//evil.example">p</a>', '<a>p</a>'],
            'event handlers, style'  => ['<p onmouseover="x()" style="color:red">Hi <b onclick="y()">you</b></p>', '<p>Hi <b>you</b></p>'],
            'img and script dropped' => ['a<img src=x onerror=alert(1)><script>alert(1)</script>b', 'ab'],
            'svg dropped whole'      => ['<svg onload="alert(1)"><a>q</a></svg>ok', 'ok'],
            'unknown tag unwrapped'  => ['<div><span>keep</span></div>', 'keep'],
            'comment dropped'        => ['a<!-- <script> -->b', 'ab'],
            'safe links kept'        => ['<a href="/kontakti">k</a> <a href="#faq">f</a> <a href="mailto:info@x.bg">m</a> <a href="tel:+359 88 123">t</a>',
                                         '<a href="/kontakti">k</a> <a href="#faq">f</a> <a href="mailto:info@x.bg">m</a> <a href="tel:+35988123">t</a>'],
            'web link opens new tab' => ['<a href="https://example.org">e</a>', '<a href="https://example.org" target="_blank" rel="noopener noreferrer">e</a>'],
            'formatting kept'        => ['<h2>T</h2><ul><li><em>a</em></li></ul><p>x<br>y</p>', '<h2>T</h2><ul><li><em>a</em></li></ul><p>x<br>y</p>'],
            'Cyrillic text kept'     => ['<p>Здравей &amp; добре дошли</p>', '<p>Здравей &amp; добре дошли</p>'],
            'plain text untouched'   => ['Tom & Jerry "quoted"', 'Tom & Jerry "quoted"'],
            'not a string'           => [['x'], ''],
        ];
    }

    #[DataProvider('cleaned')]
    public function test_inline_clean_html(mixed $in, string $out): void
    {
        $this->assertSame($out, inline_clean_html($in));
    }
}
