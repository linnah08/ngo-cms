<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class SocialAjaxTest extends TestCase
{
    private string $socialAjax;
    private string $articleEdit;

    protected function setUp(): void
    {
        $this->socialAjax  = $_SERVER['DOCUMENT_ROOT'] . '/admin/social-ajax.php';
        $this->articleEdit = $_SERVER['DOCUMENT_ROOT'] . '/admin/article-edit.php';
    }

    public function testGenerateActionHandlesGenerateType(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "generate_type",
            $src,
            "social-ajax.php generate action must read a 'generate_type' param"
        );
    }

    public function testGenerateActionHasBrandedPrompt(): void
    {
        $src = file_get_contents($this->socialAjax);
        // Key phrases from the branded FB post skill
        $this->assertStringContainsString(
            'Warm, honest',
            $src,
            "Branded prompt must include voice instructions"
        );
        $this->assertStringContainsString(
            'Hook',
            $src,
            "Branded prompt must include structural instructions"
        );
        $this->assertStringContainsString(
            'устойчивост',
            $src,
            "Branded prompt must include DO NOT jargon list"
        );
    }

    public function testGenerateActionSavesToFbText(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'fb_text'",
            $src,
            "generate action must save to fb_text field"
        );
    }

    public function testScheduleActionSavesChannelSpecificField(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'insta_text'",
            $src,
            "schedule action must save to insta_text for Instagram channel"
        );
    }

    public function testArticleEditPreservesFbText(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString(
            "'fb_text'",
            $src,
            "article-edit.php POST handler must preserve fb_text"
        );
        $this->assertStringContainsString(
            "'insta_text'",
            $src,
            "article-edit.php POST handler must preserve insta_text"
        );
    }

    public function testArticleEditHasTwoGenerateButtons(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString(
            'socGenerateNewsBtn',
            $src,
            "article-edit.php must have a Генерирай FB новина button"
        );
        $this->assertStringContainsString(
            'socGeneratePostBtn',
            $src,
            "article-edit.php must have a Генерирай FB пост button"
        );
    }

    public function testArticleEditHasSplitTextareas(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('id="fbText"',    $src, "FB textarea must have id=fbText");
        $this->assertStringContainsString('id="instaText"', $src, "Instagram textarea must have id=instaText");
        $this->assertStringNotContainsString(
            'id="socText"',
            $src,
            "Old shared id=socText must be removed"
        );
    }

    // ── LinkedIn tests ────────────────────────────────────────────────────────

    public function testLinkedInAjaxHandlesGenerateType(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/linkedin-ajax.php');
        $this->assertStringContainsString(
            "generate_type",
            $src,
            "linkedin-ajax.php generate action must read a 'generate_type' param"
        );
    }

    public function testLinkedInAjaxHasBrandedPrompt(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/linkedin-ajax.php');
        $this->assertStringContainsString(
            'Radically honest',
            $src,
            "LinkedIn branded prompt must include voice instructions"
        );
        $this->assertStringContainsString(
            'em dash',
            $src,
            "LinkedIn branded prompt must include DO NOT em-dash rule"
        );
        $this->assertStringContainsString(
            '/en/news/',
            $src,
            "LinkedIn branded prompt must include the EN article URL"
        );
    }

    public function testArticleEditHasTwoLinkedInGenerateButtons(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString(
            'liGenerateNewsBtn',
            $src,
            "article-edit.php must have a Генерирай LinkedIn новина button"
        );
        $this->assertStringContainsString(
            'liGeneratePostBtn',
            $src,
            "article-edit.php must have a Генерирай LinkedIn пост button"
        );
    }
}
