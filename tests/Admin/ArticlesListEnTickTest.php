<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The „EN ✓“ tick in Админ → Статии finds the English version at the article's
 * own English address (slug_en), and an automatic translation is saved there.
 */
#[Group('admin')]
final class ArticlesListEnTickTest extends TestCase
{
    private string $tag = '';
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        $this->tag = 'phpunit-entick-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
    }

    private function put(string $lang, string $slug, array $data): void
    {
        $dir = ARTICLES_PATH . '/' . $lang;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->files[] = $file = $dir . '/' . $slug . '.json';
        file_put_contents($file, json_encode($data + ['title' => 'T', 'date' => '2026-01-01', 'status' => 'draft']));
    }

    private function tickShown(string $slug, string $body): bool
    {
        return str_contains($body, 'article-edit.php?slug=' . urlencode($slug) . '&lang=en');
    }

    public function testTickFollowsTheEnglishAddress(): void
    {
        $this->put('bg', $this->tag, ['slug_en' => $this->tag . '-en']);
        $this->put('en', $this->tag . '-en', []);
        $r = run_admin_page('admin/articles.php', [], ['method' => 'GET']);
        $this->assertTrue($this->tickShown($this->tag, $r['body']), 'translated under its own EN address');
    }

    public function testOlderTranslationAtTheBulgarianAddressStillCounts(): void
    {
        $this->put('bg', $this->tag, ['slug_en' => $this->tag . '-en']);
        $this->put('en', $this->tag, []);
        $r = run_admin_page('admin/articles.php', [], ['method' => 'GET']);
        $this->assertTrue($this->tickShown($this->tag, $r['body']));
    }

    public function testNoEnglishVersionNoTick(): void
    {
        $this->put('bg', $this->tag, ['slug_en' => $this->tag . '-en']);
        $r = run_admin_page('admin/articles.php', [], ['method' => 'GET']);
        $this->assertStringContainsString($this->tag, $r['body'], 'the article is listed');
        $this->assertFalse($this->tickShown($this->tag, $r['body']));
    }

    public function testAutomaticTranslationIsSavedAtTheEnglishAddress(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/translate-article-ajax.php');
        $this->assertStringContainsString("save_json(\$en_dir . '/' . \$slug_en . '.json'", $src);
        $this->assertStringContainsString("\$article['slug_en']", $src);
    }
}
