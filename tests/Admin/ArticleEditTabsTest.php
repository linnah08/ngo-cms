<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The post editor's layout (no Content / Social tabs any more) and the folded English section.
 * Behaviour is covered end-to-end in tests/Browser/article-tabs.spec.js; these
 * guard the server-side pieces that a browser test can't see go wrong quietly.
 */
final class ArticleEditTabsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/article-edit.php');
    }

    public function testThereAreNoTabsAndTheFormAlwaysRenders(): void
    {
        // The Content / Social tabs are gone: the Socials panel opens beside the form.
        $this->assertStringNotContainsString("\$_GET['tab']", $this->src);
        $this->assertMatchesRegularExpression('/<div id="editorLayout"[^>]*>\s*<form method="POST"/', $this->src);
    }

    public function testEnglishEditorStartsOnlyWhenTheSectionOpens(): void
    {
        // The shared selector must not pick up the English editor at load …
        $this->assertStringContainsString('id="content_en" name="content_en" class="rich-editor-en"', $this->src);
        // … it is started by the section toggle instead.
        $this->assertStringContainsString("selector: '#content_en'", $this->src);
        $this->assertStringContainsString('aria-controls="enFieldsWrap"', $this->src);
    }
}
