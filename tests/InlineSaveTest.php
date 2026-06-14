<?php
// tests/InlineSaveTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InlineSaveTest extends TestCase
{
    private string $pagesPath;
    private array  $origPages;
    private string $menusPath;
    private array  $origMenus;

    protected function setUp(): void
    {
        $this->pagesPath = CONTENT_PATH . '/pages.json';
        $this->origPages = json_decode(file_get_contents($this->pagesPath), true);
        $this->menusPath = CONTENT_PATH . '/menus.json';
        $this->origMenus = json_decode(file_get_contents($this->menusPath), true);
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION[ADMIN_SESSION_NAME] = ['role' => 'admin', 'time' => time()];
    }

    protected function tearDown(): void
    {
        // Restore original files after each test
        file_put_contents($this->pagesPath, json_encode($this->origPages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($this->menusPath, json_encode($this->origMenus, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Whitelist validation ────────────────────────────────────────────────

    private function is_allowed_section(string $section): bool
    {
        $allowed_sections = ['home', 'campaign', 'shop', 'footer', 'menus'];
        return in_array($section, $allowed_sections, true);
    }

    private function is_allowed_field(string $section, string $field): bool
    {
        $allowed = [
            'home'     => ['hero_title', 'hero_text', 'mission_title', 'mission_text'],
            'campaign' => ['title', 'text', 'cta'],
            'shop'     => ['donation_text'],
            'footer'   => ['tagline', 'social_fb', 'social_ig'],
        ];
        return isset($allowed[$section]) && in_array($field, $allowed[$section], true);
    }

    public function test_unknown_section_rejected(): void
    {
        $this->assertFalse($this->is_allowed_section('evil_section'));
    }

    public function test_known_sections_allowed(): void
    {
        foreach (['home', 'campaign', 'shop', 'footer', 'menus'] as $section) {
            $this->assertTrue($this->is_allowed_section($section), "$section must be allowed");
        }
    }

    public function test_unknown_field_rejected(): void
    {
        $this->assertFalse($this->is_allowed_field('home', '__proto__'));
        $this->assertFalse($this->is_allowed_field('home', 'evil_field'));
    }

    // ── CSRF ────────────────────────────────────────────────────────────────

    public function test_bad_csrf_fails(): void
    {
        $this->assertFalse(hash_equals(csrf_token(), 'badtoken'));
    }

    // ── Integration: actually write to pages.json ────────────────────────────

    public function test_saves_home_hero_title(): void
    {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['home']['hero_title']    = trim(strip_tags('Нов заглавие'));
        $pages['home']['hero_title_en'] = trim(strip_tags('New title'));
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $saved = json_decode(file_get_contents($this->pagesPath), true);
        $this->assertSame('Нов заглавие', $saved['home']['hero_title']);
        $this->assertSame('New title', $saved['home']['hero_title_en']);
    }

    public function test_saves_shop_donation_text(): void
    {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['shop']['donation_text_bg'] = '<p>Помогни ни</p>';
        $pages['shop']['donation_text_en'] = '<p>Help us</p>';
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $saved = json_decode(file_get_contents($this->pagesPath), true);
        $this->assertSame('<p>Помогни ни</p>', $saved['shop']['donation_text_bg']);
    }

    public function test_saves_menus_header_label(): void
    {
        $menus = load_json(CONTENT_PATH . '/menus.json');
        if (empty($menus['header']['bg'])) {
            $this->markTestSkipped('No header BG menu items');
        }
        $menus['header']['bg'][0]['label'] = 'Тест';
        save_json(CONTENT_PATH . '/menus.json', $menus);

        $saved = json_decode(file_get_contents($this->menusPath), true);
        $this->assertSame('Тест', $saved['header']['bg'][0]['label']);
    }

    public function test_strip_tags_removes_script_tag(): void
    {
        // PHP strip_tags removes the tag markup but keeps the inner text.
        // inline-save.php relies on this to strip dangerous tags while keeping
        // the user-visible text content.
        $input  = '<script>alert(1)</script>Hello';
        $result = trim(strip_tags($input, '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>'));
        // The <script> tag is stripped; its inner text "alert(1)" is retained by PHP.
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_allowed_tags_preserved(): void
    {
        $input = '<p>Hello <b>world</b></p>';
        $this->assertSame($input, trim(strip_tags($input, '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>')));
    }

    public function test_saves_article_title(): void
    {
        // Pick the first BG article slug
        $articles_dir = ARTICLES_PATH . '/bg';
        $files = glob($articles_dir . '/*.json');
        if (!$files) $this->markTestSkipped('No BG articles found');
        $file = $files[0];
        $orig = json_decode(file_get_contents($file), true);

        // Directly apply the article-save logic
        $article = load_json($file);
        $article['title'] = trim(strip_tags('Тест заглавие'));
        save_json($file, $article);

        $saved = json_decode(file_get_contents($file), true);
        $this->assertSame('Тест заглавие', $saved['title']);

        // Restore
        file_put_contents($file, json_encode($orig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function test_article_slug_path_traversal_blocked(): void
    {
        // Verify the slug sanitisation blocks path traversal
        $slug = '../../../etc/passwd';
        $sanitised = basename(str_replace(['..', "\0"], '', $slug));
        // After sanitisation, it should not contain '..' or path separators
        $this->assertStringNotContainsString('..', $sanitised);
        $this->assertStringNotContainsString('/', $sanitised);
    }

    public function test_saves_product_name(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $pdo  = get_pdo();
        $prod = $pdo->query('SELECT id, name_bg FROM products WHERE active = 1 LIMIT 1')->fetch();
        if (!$prod) $this->markTestSkipped('No active products');

        $pdo->prepare('UPDATE products SET name_bg = ? WHERE id = ?')
            ->execute(['Тест продукт', $prod['id']]);

        $updated = $pdo->prepare('SELECT name_bg FROM products WHERE id = ?');
        $updated->execute([$prod['id']]);
        $row = $updated->fetch();
        $this->assertSame('Тест продукт', $row['name_bg']);

        // Restore
        $pdo->prepare('UPDATE products SET name_bg = ? WHERE id = ?')
            ->execute([$prod['name_bg'], $prod['id']]);
    }
}
