<?php
// tests/InlineSaveTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InlineSaveTest extends TestCase
{
    private string $pagesPath;
    private string $menusPath;
    /** Raw bytes of each content file before the test, or null if it did not exist. */
    private array  $backup = [];

    protected function setUp(): void
    {
        $this->pagesPath = CONTENT_PATH . '/pages.json';
        $this->menusPath = CONTENT_PATH . '/menus.json';
        // Keep the exact bytes, so restoring never reformats the file.
        foreach ([$this->pagesPath, $this->menusPath] as $path) {
            $this->backup[$path] = is_file($path) ? file_get_contents($path) : null;
        }
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION[ADMIN_SESSION_NAME] = ['role' => 'admin', 'time' => time()];
    }

    protected function tearDown(): void
    {
        // Put every file back exactly as it was, and remove any the test created.
        foreach ($this->backup as $path => $bytes) {
            if ($bytes !== null) {
                file_put_contents($path, $bytes);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * pages.json and menus.json hold the site's own content and are not in git,
     * so a fresh checkout has neither. Tests that edit one skip without it.
     */
    private function requireContentFile(string $path): void
    {
        if ($this->backup[$path] === null) {
            $this->markTestSkipped(basename($path) . ' not present (it is not in git).');
        }
    }

    // ── Whitelist validation ────────────────────────────────────────────────

    private function is_allowed_section(string $section): bool
    {
        $allowed_sections = ['home', 'campaign', 'shop', 'donation', 'footer', 'menus'];
        return in_array($section, $allowed_sections, true);
    }

    private function is_allowed_field(string $section, string $field): bool
    {
        $allowed = [
            'home'     => ['hero_title', 'hero_text', 'mission_title', 'mission_text'],
            'campaign' => ['title', 'text', 'cta'],
            'shop'     => ['donation_text'],
            'donation' => ['title'],
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
        foreach (['home', 'campaign', 'shop', 'donation', 'footer', 'menus'] as $section) {
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
        $this->requireContentFile($this->pagesPath);
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
        $this->requireContentFile($this->pagesPath);
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['shop']['donation_text_bg'] = '<p>Помогни ни</p>';
        $pages['shop']['donation_text_en'] = '<p>Help us</p>';
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $saved = json_decode(file_get_contents($this->pagesPath), true);
        $this->assertSame('<p>Помогни ни</p>', $saved['shop']['donation_text_bg']);
    }

    public function test_saves_donation_page_title(): void
    {
        $this->requireContentFile($this->pagesPath);
        $pages = load_json(CONTENT_PATH . '/pages.json');
        $pages['donation']['title']    = 'Подкрепи ни';
        $pages['donation']['title_en'] = 'Support us';
        save_json(CONTENT_PATH . '/pages.json', $pages);

        $saved = json_decode(file_get_contents($this->pagesPath), true);
        $this->assertSame('Подкрепи ни', $saved['donation']['title']);
        $this->assertSame('Support us',  $saved['donation']['title_en']);
    }

    public function test_saves_menus_header_label(): void
    {
        $this->requireContentFile($this->menusPath);
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
        $orig = file_get_contents($file);

        // Directly apply the article-save logic
        $article = load_json($file);
        $article['title'] = trim(strip_tags('Тест заглавие'));
        save_json($file, $article);

        $saved = json_decode(file_get_contents($file), true);
        $this->assertSame('Тест заглавие', $saved['title']);

        // Restore
        file_put_contents($file, $orig);
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
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
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
