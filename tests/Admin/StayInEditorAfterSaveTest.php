<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Запази“ on the article, product and newsletter editors keeps the editor open
 * with a visible success message, instead of returning to the list (where the
 * next steps — social posting, reviews, preview and send — were out of reach).
 * The autosave draft is still cleared through ?_asclear, and the back link stays.
 */
#[Group('admin')]
final class StayInEditorAfterSaveTest extends TestCase
{
    private const SLUG = 'phpunit-stay-in-editor';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    protected function tearDown(): void
    {
        foreach (['bg', 'en'] as $lang) @unlink(ARTICLES_PATH . "/$lang/" . self::SLUG . '.json');
    }

    public function testArticleEditorUrlAfterSaveCarriesTheSlugAndTheAutosaveKey(): void
    {
        $this->assertSame(
            '/admin/article-edit.php?slug=nova-statiya&_asclear=article%3Anew',
            article_editor_url_after_save('nova-statiya', 'article:new')
        );
        // A Cyrillic address is encoded, not passed raw into the Location header.
        $this->assertStringContainsString('slug=%D0%BD', article_editor_url_after_save('нова', 'article:x'));
    }

    public function testSavingAnArticleStoresItAndLeavesASuccessMessage(): void
    {
        $r = run_admin_page('admin/article-edit.php', [
            'title' => 'Stay in editor', 'slug' => self::SLUG, 'status' => 'draft', 'content' => '',
        ]);
        $this->assertFileExists(ARTICLES_PATH . '/bg/' . self::SLUG . '.json', $r['body']);
        $messages = array_column($r['flash'], 'message');
        $this->assertContains('Статията е запазена.', $messages, $r['body']);
    }

    public function testArticleSaveReturnsToTheEditor(): void
    {
        $src = self::src('admin/article-edit.php');
        $this->assertStringContainsString("header('Location: ' . article_editor_url_after_save(\$new_slug, \$as_key));", $src);
        $this->assertStringNotContainsString('/admin/articles.php?saved=1', $src);
        // An image problem is still shown — now as a message in the editor.
        $this->assertStringContainsString("flash_set('error', 'Снимката не бе запазена: ' . \$image_error);", $src);
    }

    public function testProductSaveReturnsToTheEditor(): void
    {
        $src = self::src('admin/product-edit.php');
        $this->assertStringContainsString("header('Location: /admin/product-edit.php?id=' . (int) \$saved_id . '&_asclear='", $src);
        $this->assertStringNotContainsString("header('Location: /admin/products.php?_asclear=", $src);
    }

    public function testNewsletterSaveReturnsToTheEditor(): void
    {
        $src = self::src('admin/newsletter-compose.php');
        $this->assertStringContainsString("header('Location: /admin/newsletter-compose.php?id=' . (int) \$id . '&_asclear='", $src);
        $this->assertStringNotContainsString("header('Location: /admin/newsletter.php?_asclear=", $src);
    }

    public function testEveryEditorShowsTheMessageAndKeepsItsBackLink(): void
    {
        $back = [
            'admin/article-edit.php'       => 'href="/admin/articles.php"',
            'admin/product-edit.php'       => 'href="/admin/products.php"',
            'admin/newsletter-compose.php' => 'href="/admin/newsletter.php"',
        ];
        foreach ($back as $rel => $link) {
            $src = self::src($rel);
            $this->assertStringContainsString('flash_get()', $src, "$rel shows the saved message");
            // Announced to screen readers, not only shown in green.
            $this->assertStringContainsString("'alert' : 'status'", $src, "$rel announces the message");
            $this->assertStringContainsString($link, $src, "$rel keeps its link back to the list");
        }
    }

    public function testAutosaveDropsTheClearMarkerFromTheAddress(): void
    {
        // Otherwise reloading the editor later would wipe a newer draft.
        $js = self::src('admin/js/autosave.js');
        $this->assertStringContainsString("searchParams.delete('_asclear')", $js);
        $this->assertStringContainsString('history.replaceState', $js);
    }
}
