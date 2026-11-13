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
            'Статията още е чернова — ще бъде публикувана веднага щом планирате публикацията.',
            'Статията има планирани публикации в социалните мрежи. Връщането в чернова не ги отменя',
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
        $this->assertStringContainsString('data-has-scheduled="0"', $r['body'], 'a new article has no scheduled posts');
        $this->assertSame(3, substr_count($r['body'], 'Статията още е чернова — ще бъде публикувана веднага щом планирате публикацията.'), 'a new article is a draft: every platform says so');
        $this->assertStringContainsString('id="socDraftWarn"', $r['body']);
        $this->assertStringContainsString('/admin/js/social-panel.js?v=', $r['body']);
        $this->assertStringContainsString('id="articleForm"', $r['body']);
    }

    // ── Task 6: scheduling saves and publishes the article first ────────────────

    /** The body of a JS function in social-panel.js, from its name to the next top-level function. */
    private function jsFunction(string $name): string
    {
        $start = strpos($this->js, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' exists');
        $end = strpos($this->js, "\n  function ", $start + 1);
        return substr($this->js, $start, $end === false ? null : $end - $start);
    }

    public function testScheduleUsesSlugFromSaveResponse(): void
    {
        $save = $this->jsFunction('saveArticle');
        $this->assertStringContainsString('tinymce.triggerSave()', $save);
        $this->assertStringContainsString('new FormData(form)', $save);
        $this->assertStringContainsString("fd.append('_ajax', '1')", $save);
        $this->assertStringContainsString("fd.append('publish_now', '1')", $save);
        $this->assertStringContainsString('form.action', $save, 'posted to the form\'s own handler');

        $flow = $this->jsFunction('saveThenSchedule');
        $applied = strpos($flow, 'applySave(saved');
        $slugAt  = strpos($flow, 'slug: saved.slug');
        $this->assertNotFalse($applied, 'the save reply is applied to the page');
        $this->assertNotFalse($slugAt, 'the schedule request uses the slug the save returned');
        foreach (['/admin/social-ajax.php', '/admin/linkedin-ajax.php'] as $url) {
            $at = strpos($flow, "'" . $url . "'");
            $this->assertNotFalse($at, $url);
            $this->assertGreaterThan($applied, $at, $url . ' is called only after the save reply');
        }
        $this->assertStringContainsString('if (!saved || !saved.ok)', $flow, 'a failed save stops before scheduling');
        $this->assertStringContainsString('saveThenSchedule: saveThenSchedule', $this->js, 'exposed on window.SocialPanel');
        // The old "save first" refusal is gone: a new article is saved in the background.
        $this->assertStringNotContainsString('Запазете статията, преди да планирате публикация.', $this->js);
    }

    public function testSaveReplyUpdatesThePage(): void
    {
        $apply = $this->jsFunction('applySave');
        foreach (["getElementById('slug')", "getElementById('slug_en')", "statusSel.value = 'published'",
                  'autoBox.checked = false', 'history.replaceState', 'form.action', 'showWarnings(saved.warnings'] as $step) {
            $this->assertStringContainsString($step, $apply, $step);
        }
    }

    public function testBackgroundSaveClearsAutosaveDraft(): void
    {
        $autosave = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/js/autosave.js');
        $this->assertMatchesRegularExpression('/return \{\s*clear: /', $autosave, 'initAutosave hands back a clear()');
        $this->assertStringContainsString('window._articleAutosave = initAutosave({', $this->edit);
        $apply = $this->jsFunction('applySave');
        $this->assertStringContainsString('window._articleAutosave', $apply);
        $this->assertStringContainsString('.clear(', $apply);
        $this->assertStringContainsString(".setKey('article:' + slug)", $apply, 'later drafts are kept under the new address');
    }

    public function testScheduleBodySendsNoText(): void
    {
        $this->assertStringNotContainsString('social_text', $this->js);
        $this->assertStringNotContainsString('linkedin_text', $this->js);
    }

    public function testDraftNoticeCopy(): void
    {
        $copy = 'Статията още е чернова — ще бъде публикувана веднага щом планирате публикацията.';
        $this->assertStringContainsString($copy, $this->edit);
        $this->assertStringContainsString('<p class="soc-draft-note"', $this->edit);
        $this->assertSame(3, substr_count($this->edit, '<?= $draft_note ?>'), 'next to each platform\'s schedule buttons');
        $this->assertStringContainsString("statusSel.addEventListener('change', updateDraftNotices)", $this->js, 'kept current when the status changes');
    }

    public function testDraftWithScheduledPostsIsWarnedPersistently(): void
    {
        $this->assertMatchesRegularExpression('/data-has-scheduled="<\?= \$has_social_scheduled \? \'1\' : \'0\' \?>"/', $this->edit);
        $this->assertMatchesRegularExpression('/id="socDraftWarn"[^>]*role="status"/', $this->edit);
        $this->assertStringContainsString("panel.dataset.hasScheduled = '1'", $this->js, 'a post scheduled now counts too');
    }

    public function testEmojiInsertsIntoArticleEditor(): void
    {
        // The picker's script, from its heading to where it exposes its handlers.
        $start = strpos($this->edit, '// ── Emoji picker (Socials panel)');
        $end   = strpos($this->edit, 'window._insertEmoji       = _insertEmoji;');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $insert = substr($this->edit, $start, $end - $start);
        $this->assertStringContainsString('tinymce.get(', $insert);
        $this->assertStringContainsString('insertContent(', $insert);
        $this->assertStringContainsString('_expandEnSection()', $insert, 'the EN editor is started first for LinkedIn');
        $this->assertStringContainsString('SocialPanel.refresh()', $insert);
        $this->assertStringContainsString('data-emoji-target="content_en"', $this->edit, 'LinkedIn emoji go into the English text');
        $this->assertDoesNotMatchRegularExpression('/data-emoji-target="[^"]*"[^>]*display:none|display:none[^>]*data-emoji-target/', $this->edit, 'the emoji buttons are shown');
        $this->assertStringNotContainsString('<span onclick="_insertEmoji', $this->edit, 'each emoji is a real button');
        $this->assertStringContainsString("e.key === 'Escape'", $this->edit, 'Escape closes the picker');
    }

    public function testNoWindowConfirm(): void
    {
        $this->assertStringNotContainsString('confirm(', $this->js);
    }

    // ── Task 5 review fixes ──────────────────────────────────────────────────────

    public function testSessionMessageOnlyForLoginRedirect(): void
    {
        $read = $this->jsFunction('readJson');
        $this->assertStringContainsString('r.redirected || r.status === 401 || r.status === 403', $read);
        $this->assertStringContainsString('badReply', $read, 'any other non-JSON reply is a plain failure');
    }

    public function testFailedRefreshClearsTheUpdatedStatus(): void
    {
        $this->assertMatchesRegularExpression("/\.catch\(function \(e\) \{\s*if \(mySeq !== seq\) return;\s*clearStatus\(\);/", $this->js);
    }

    public function testLinkedInStartsBlocked(): void
    {
        $this->assertStringContainsString("setBlocked('linkedin', true);", $this->js);
    }

    public function testInstagramStoryNotBlockedByEmptyText(): void
    {
        $this->assertStringContainsString("igMode === 'post'", $this->jsFunction('needsText'));
    }

    public function testFacebookCounterShowsLengthOnly(): void
    {
        $this->assertStringContainsString("platform === 'fb'", $this->jsFunction('renderPlatform'));
    }

    public function testCopyFallbackChecksTheResult(): void
    {
        $this->assertStringContainsString("ok = document.execCommand('copy')", $this->js);
        $this->assertStringContainsString('Копирането не успя', $this->js);
    }
}
