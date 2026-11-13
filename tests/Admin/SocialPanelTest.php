<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Socials panel beside the article editor (admin/article-edit.php + admin/js/social-panel.js).
 * Source-level guards; behaviour is checked by hand in the browser.
 */
#[Group('admin')]
final class SocialPanelTest extends TestCase
{
    private string $edit;
    private string $js;

    protected function setUp(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $this->edit = (string) file_get_contents($root . '/admin/article-edit.php');
        $this->js   = (string) @file_get_contents($root . '/admin/js/social-panel.js');
    }

    public function testNoTabsLeft(): void
    {
        $this->assertStringNotContainsString('tab=social', $this->edit);
        $this->assertStringNotContainsString("\$tab === 'social'", $this->edit);
        $this->assertStringNotContainsString('aria-label="Части на статията"', $this->edit);
    }

    public function testToggleIsAccessibleButton(): void
    {
        $this->assertMatchesRegularExpression('/<button type="button" id="socialToggle"[^>]*aria-expanded=[^>]*aria-controls="socialPanel"/', $this->edit);
        $this->assertStringContainsString("'socialPanelOpen'", $this->js, 'the open state is remembered');
        $this->assertStringContainsString('toggle.focus()', $this->js, 'closing hands focus back to the toggle');
    }

    public function testPanelNotGatedOnClaude(): void
    {
        $this->assertStringNotContainsString("\$tab === 'social' && !\$is_new && \$claude_ready", $this->edit);
        $this->assertStringNotContainsString('Социални мрежи: конфигурирайте Claude API ключ', $this->edit);
        // The panel exists while the social module is on, for new and saved articles alike.
        $this->assertMatchesRegularExpression('/<\?php if \(\$social_on\): \?>\s*<aside id="socialPanel"/', $this->edit);
    }

    public function testPanelRendersForNewArticle(): void
    {
        $this->assertStringNotContainsString('Запазете статията, за да публикувате', $this->edit);
    }

    public function testPanelIdsAndRoles(): void
    {
        foreach (['socPreview-fb', 'socPreview-insta', 'socPreview-linkedin', 'socError'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $this->edit, $id);
        }
        $this->assertMatchesRegularExpression('/id="socStatus"[^>]*role="status"/', $this->edit);
        $this->assertStringContainsString('role="tablist"', $this->edit);
        $this->assertMatchesRegularExpression(
            "/foreach \(\['fb' => 'Facebook', 'insta' => 'Instagram', 'linkedin' => 'LinkedIn'\] as[^\n]*\n\s*<button type=\"button\" role=\"tab\"/",
            $this->edit, 'one tab per platform');
        $this->assertSame(3, substr_count($this->edit, '<div role="tabpanel"'));
        $this->assertStringContainsString("'ArrowRight'", $this->js, 'tabs move with the arrow keys');
        $this->assertStringContainsString('window.SocialPanel', $this->js);
        $this->assertStringContainsString("versioned_asset('/admin/js/social-panel.js')", $this->edit);
    }

    public function testPanelIgnoresStaleResponses(): void
    {
        $this->assertStringContainsString('var mySeq = ++seq;', $this->js);
        $this->assertStringContainsString('if (mySeq !== seq) return;', $this->js);
    }

    public function testRefreshIsDebouncedAndOnlyWhileOpen(): void
    {
        $this->assertStringContainsString('1000', $this->js);
        $this->assertStringContainsString("'input change'", $this->js, 'TinyMCE typing and formatting changes refresh it');
        $this->assertStringContainsString('/admin/social-preview-ajax.php', $this->js);
        $this->assertMatchesRegularExpression('/function schedule\(\) \{\s*if \(!isOpen\(\)\) return;/', $this->js);
    }

    public function testSessionExpiryIsShownNotSilent(): void
    {
        $this->assertStringContainsString('r.redirected', $this->js, 'the login redirect is an expired session');
        $this->assertStringContainsString('Сесията е изтекла — презаредете страницата.', $this->js);
    }

    public function testCopyStrings(): void
    {
        $all = $this->edit . $this->js;
        foreach ([
            'Текстът е съкратен до последното цяло изречение',
            'Няма английски текст — преведете статията',
            'Започнете да пишете — тук ще видите публикациите.',
            'Прегледът не е обновен — опитайте отново',
            'Прегледът е обновен',
        ] as $copy) {
            $this->assertStringContainsString($copy, $all, $copy);
        }
    }

    public function testOldTextBoxesAndGenerateButtonsAreGone(): void
    {
        $all = $this->edit . $this->js;
        foreach (['fbText', 'instaText', 'liText', 'socGenerateNewsBtn', 'liGenerateNewsBtn', "action: 'generate'", 'social_text', 'linkedin_text', 'socCopyFromFbBtn'] as $gone) {
            $this->assertStringNotContainsString($gone, $all, $gone);
        }
        $this->assertStringNotContainsString('confirm(', $this->js);
    }

    public function testScheduleControlsStay(): void
    {
        foreach (['socFbBtn', 'socFbNowBtn', 'socInstaBtn', 'socInstaNowBtn', 'liScheduleBtn', 'liNowBtn', 'fbScheduleAt', 'instaScheduleAt', 'instaStoryScheduleAt', 'liScheduleAt', 'publish.buffer.com'] as $id) {
            $this->assertStringContainsString($id, $this->edit, $id);
        }
        foreach (["action: 'save_crop'", "action: 'save_fit'", "action: 'save_shape'", "action: 'schedule'"] as $action) {
            $this->assertStringContainsString($action, $this->js, $action);
        }
    }

    public function testCopyButtonsCopyThePreview(): void
    {
        $this->assertSame(3, substr_count($this->edit, 'class="btn btn--outline soc-copy"'));
        $this->assertStringContainsString('navigator.clipboard.writeText', $this->js);
    }

    public function testNewArticlePageRendersThePanel(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modules.php';
        if (!module_enabled_with_needs('social')) $this->markTestSkipped('The social module is off in this database.');
        $r = run_admin_page('admin/article-edit.php', [], ['method' => 'GET']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error):/', $r['body']);
        $this->assertStringContainsString('id="socialToggle"', $r['body']);
        $this->assertStringContainsString('<aside id="socialPanel"', $r['body']);
        $this->assertStringContainsString('data-slug=""', $r['body'], 'a new article has no address yet');
        $this->assertStringContainsString('/admin/js/social-panel.js?v=', $r['body']);
        $this->assertStringContainsString('id="articleForm"', $r['body']);
    }
}
