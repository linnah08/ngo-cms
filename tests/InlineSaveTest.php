<?php
// tests/InlineSaveTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The on-page ("inline") editors: admin/inline-save.php (page text and menus),
 * inline-save-article.php and inline-save-product.php, each run for real via
 * run_admin_page(). The content they write is gitignored site content, so each
 * test writes its own pages.json / menus.json / article and puts back whatever
 * was there before, byte for byte.
 */
#[Group('admin')]
final class InlineSaveTest extends TestCase
{
    private const SLUG = 'pw-inline-save';

    /** Raw bytes of each file before the test, or null if it did not exist. */
    private array $backup = [];
    private ?int $productId = null;

    private function pagesPath(): string { return CONTENT_PATH . '/pages.json'; }
    private function menusPath(): string { return CONTENT_PATH . '/menus.json'; }
    private function articlePath(string $lang): string { return ARTICLES_PATH . "/$lang/" . self::SLUG . '.json'; }

    protected function setUp(): void
    {
        $files = [$this->pagesPath(), $this->menusPath(), $this->articlePath('bg'), $this->articlePath('en')];
        foreach ($files as $path) $this->backup[$path] = is_file($path) ? file_get_contents($path) : null;

        save_json($this->pagesPath(), [
            'home'     => ['hero_title' => 'Старо', 'hero_title_en' => 'Old', 'hero_text' => '', 'hero_text_en' => ''],
            'shop'     => ['donation_text_bg' => '', 'donation_text_en' => ''],
            'donation' => ['title' => 'Дарение', 'title_en' => 'Donation'],
        ]);
        save_json($this->menusPath(), [
            'header' => [
                'bg' => [['label' => 'Начало', 'url' => '/'], ['label' => 'Новини', 'url' => '/novini/']],
                'en' => [['label' => 'Home', 'url' => '/en/'], ['label' => 'News', 'url' => '/en/news/']],
            ],
        ]);
        foreach (['bg' => 'Статия', 'en' => 'Article'] as $lang => $title) {
            if (!is_dir(dirname($this->articlePath($lang)))) mkdir(dirname($this->articlePath($lang)), 0755, true);
            save_json($this->articlePath($lang), ['title' => $title, 'slug' => self::SLUG, 'status' => 'draft',
                'date' => '2026-10-07', 'excerpt' => '', 'content' => '<p>x</p>', 'image' => '', 'tags' => []]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $path => $bytes) {
            if ($bytes !== null) file_put_contents($path, $bytes);
            elseif (is_file($path)) unlink($path);
        }
        if ($this->productId !== null) {
            get_pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([$this->productId]);
        }
    }

    /** POST $body as JSON to an inline endpoint; returns the decoded reply. */
    private function save(string $endpoint, array $body, array $opts = []): array
    {
        $r = run_admin_page('admin/' . $endpoint, [], $opts + ['json' => $body]);
        $r['json'] = json_decode(trim($r['body']), true) ?? [];
        return $r;
    }

    private function pages(): array { return load_json($this->pagesPath()); }

    // ── inline-save.php: who may save ────────────────────────────────────────

    public function test_logged_out_visitor_cannot_save(): void
    {
        $before = file_get_contents($this->pagesPath());
        $r = $this->save('inline-save.php', ['section' => 'home', 'fields' => ['hero_title' => ['bg' => 'X', 'en' => 'X']]], ['role' => null]);
        $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $r['json']);
        $this->assertSame($before, file_get_contents($this->pagesPath()));
    }

    public function test_missing_csrf_token_saves_nothing(): void
    {
        $before = file_get_contents($this->pagesPath());
        $r = $this->save('inline-save.php', ['section' => 'home', 'fields' => ['hero_title' => ['bg' => 'X', 'en' => 'X']]], ['csrf' => false]);
        $this->assertSame('csrf', $r['json']['error'] ?? null);
        $this->assertSame($before, file_get_contents($this->pagesPath()));
    }

    public function test_get_is_refused(): void
    {
        $r = $this->save('inline-save.php', [], ['method' => 'GET']);
        $this->assertSame('method', $r['json']['error'] ?? null);
    }

    public function test_unknown_section_is_refused(): void
    {
        $before = file_get_contents($this->pagesPath());
        $r = $this->save('inline-save.php', ['section' => 'evil_section', 'fields' => ['x' => ['bg' => 'y']]]);
        $this->assertSame('unknown section', $r['json']['error'] ?? null);
        $this->assertSame($before, file_get_contents($this->pagesPath()));
    }

    // ── inline-save.php: what it saves ───────────────────────────────────────

    public function test_saves_home_hero_in_both_languages_and_ignores_unknown_fields(): void
    {
        $r = $this->save('inline-save.php', ['section' => 'home', 'fields' => [
            'hero_title' => ['bg' => ' Ново заглавие ', 'en' => 'New title'],
            '__proto__'  => ['bg' => 'x', 'en' => 'x'],
        ]]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $home = $this->pages()['home'];
        $this->assertSame('Ново заглавие', $home['hero_title']);
        $this->assertSame('New title', $home['hero_title_en']);
        $this->assertArrayNotHasKey('__proto__', $home);
    }

    public function test_strips_scripts_but_keeps_basic_formatting(): void
    {
        $this->save('inline-save.php', ['section' => 'home', 'fields' => [
            'hero_text' => ['bg' => '<p>Здравей <b>свят</b></p><script>alert(1)</script>', 'en' => '<p>Hi</p>'],
        ]]);
        $text = $this->pages()['home']['hero_text'];
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringStartsWith('<p>Здравей <b>свят</b></p>', $text);
    }

    public function test_saves_shop_donation_text_and_donation_page_title(): void
    {
        $this->save('inline-save.php', ['section' => 'shop', 'fields' => ['donation_text' => ['bg' => '<p>Помогни ни</p>', 'en' => '<p>Help us</p>']]]);
        $this->save('inline-save.php', ['section' => 'donation', 'fields' => ['title' => ['bg' => 'Подкрепи ни', 'en' => 'Support us']]]);

        $pages = $this->pages();
        $this->assertSame('<p>Помогни ни</p>', $pages['shop']['donation_text_bg']);
        $this->assertSame('<p>Help us</p>', $pages['shop']['donation_text_en']);
        $this->assertSame(['Подкрепи ни', 'Support us'], [$pages['donation']['title'], $pages['donation']['title_en']]);
    }

    public function test_menu_labels_change_but_items_cannot_be_added_or_retargeted(): void
    {
        $r = $this->save('inline-save.php', ['section' => 'menus', 'fields' => [
            'header'   => ['bg' => [['label' => '<i>Тест</i>', 'url' => 'https://evil.example'], 5 => ['label' => 'Нов']]],
            'not_menu' => ['bg' => [['label' => 'x']]],
        ]]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $menus = load_json($this->menusPath());
        $this->assertSame('Тест', $menus['header']['bg'][0]['label']);
        $this->assertSame('/', $menus['header']['bg'][0]['url'], 'the inline editor only changes labels');
        $this->assertCount(2, $menus['header']['bg'], 'an index that does not exist is not added');
        $this->assertArrayNotHasKey('not_menu', $menus);
        $this->assertSame('Home', $menus['header']['en'][0]['label'], 'EN not sent, so untouched');
    }

    // ── inline-save-article.php ──────────────────────────────────────────────

    public function test_article_title_saves_to_each_language(): void
    {
        $r = $this->save('inline-save-article.php', ['fields' => [
            'slug'  => ['bg' => self::SLUG, 'en' => self::SLUG],
            'title' => ['bg' => '<b>Ново</b> заглавие', 'en' => 'New title'],
        ]]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);
        $this->assertSame('Ново заглавие', load_json($this->articlePath('bg'))['title']);
        $this->assertSame('New title', load_json($this->articlePath('en'))['title']);
    }

    public function test_article_slug_cannot_reach_outside_the_articles_folder(): void
    {
        $before = file_get_contents($this->pagesPath());
        $r = $this->save('inline-save-article.php', ['fields' => [
            'slug'  => ['bg' => '../../pages'],
            'title' => ['bg' => 'Hacked'],
        ]]);
        $this->assertSame(['ok' => true], $r['json'], 'an unknown slug is skipped, not an error');
        $this->assertSame($before, file_get_contents($this->pagesPath()));
    }

    public function test_article_save_needs_a_login_and_a_token(): void
    {
        $body = ['fields' => ['slug' => ['bg' => self::SLUG], 'title' => ['bg' => 'Hacked']]];
        $this->assertSame('csrf', $this->save('inline-save-article.php', $body, ['csrf' => false])['json']['error'] ?? null);
        $this->assertSame(302, $this->save('inline-save-article.php', $body, ['role' => null])['status']);
        $this->assertSame('Статия', load_json($this->articlePath('bg'))['title']);
    }

    // ── inline-save-product.php ──────────────────────────────────────────────

    public function test_product_name_saves_without_tags(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo = get_pdo();
        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?,?,?,?,?,?)')
            ->execute(['pw-inline-save-' . bin2hex(random_bytes(3)), 'Старо', 'Old', 5, 1, 1]);
        $this->productId = (int) $pdo->lastInsertId();

        $r = $this->save('inline-save-product.php', ['fields' => [
            'id'   => ['bg' => (string) $this->productId],
            'name' => ['bg' => '<script>x</script>Нов продукт', 'en' => 'New product'],
        ]]);
        $this->assertSame(['ok' => true], $r['json'], $r['body']);

        $row = $pdo->prepare('SELECT name_bg, name_en FROM products WHERE id = ?');
        $row->execute([$this->productId]);
        $this->assertSame(['name_bg' => 'xНов продукт', 'name_en' => 'New product'], $row->fetch(PDO::FETCH_ASSOC));
    }

    public function test_product_save_without_an_id_is_refused(): void
    {
        $r = $this->save('inline-save-product.php', ['fields' => ['name' => ['bg' => 'X', 'en' => 'X']]]);
        $this->assertSame('missing id', $r['json']['error'] ?? null);
    }
}
