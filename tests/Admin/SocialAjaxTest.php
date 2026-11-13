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

    public function testGenerateActionWritesOneSummaryWithTheLinkAdded(): void
    {
        $src = file_get_contents($this->socialAjax);
        // The old "FB пост" variant is gone: one summary, no generate_type switch.
        $this->assertStringNotContainsString("generate_type", $src);
        $this->assertStringContainsString('Без хаштагове', $src, 'The summary must ask for no hashtags');
        $this->assertStringContainsString(
            'article_fb_summary_text($text, $article_url)',
            $src,
            'The article link must be added by the server, not left to the AI'
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

    public function testArticleEditHasOnlyTheSummaryButton(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('socGenerateNewsBtn', $src);
        $this->assertStringContainsString('✦ Обобщение с линк към статията', $src);
        $this->assertStringNotContainsString('socGeneratePostBtn', $src, 'The FB пост button is removed');
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

    // ── Instagram Story tests ────────────────────────────────────────────────

    public function testScheduleActionSupportsStoryPostType(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'post_type'",
            $src,
            "schedule action must read a 'post_type' param to distinguish feed post vs story"
        );
        $this->assertStringContainsString(
            'social_fb_insta_request($data, $channel, $is_story)',
            $src,
            "schedule action must pass the story flag to the shared photo helper"
        );
    }

    public function testScheduleActionDoesNotSendInstagramLink(): void
    {
        // Instagram's Content Publishing API cannot add link stickers to Stories —
        // it's a hard platform limitation, not something Buffer or this app can
        // work around. Sending `link` in the metadata is silently accepted and
        // ignored, which misleads editors into thinking it works. Must not send it.
        $src = file_get_contents($this->socialAjax);
        $this->assertStringNotContainsString(
            "insta_link",
            $src,
            "schedule action must not accept/send an Instagram story link — Instagram's API silently ignores it"
        );
    }

    public function testScheduleActionDoesNotRequireTextForStory(): void
    {
        // Instagram's Content Publishing API has no caption parameter for
        // Stories — text is never displayed, so it must not be required, and
        // it must not be persisted as if it meant something.
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "!\$is_story && \$social_text === ''",
            $src,
            "schedule action must not require social_text when posting an Instagram story"
        );
        $this->assertStringNotContainsString(
            "'insta_story_text'",
            $src,
            "schedule action must not persist a Story caption — Instagram never displays it"
        );
    }

    public function testScheduleActionUsesSeparateStoryStateFields(): void
    {
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString(
            "'insta_story_buffer_post_id'",
            $src,
            "story posts must track their own Buffer post id, separate from the feed post"
        );
    }

    public function testArticleEditHasStoryModeToggle(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('id="igModeStoryBtn"', $src, "must have a Story mode toggle button");
        $this->assertStringNotContainsString(
            'id="igStoryLink"',
            $src,
            "Story link input must not exist — Instagram's API can't attach it to the Story, so the field only misleads editors"
        );
    }

    public function testArticleEditHasSeparateStorySchedule(): void
    {
        $src = file_get_contents($this->articleEdit);
        // Story has no text box of its own (Instagram doesn't support captions on
        // automatically-published Stories) but must still have its own
        // schedule-time input, separate from the Feed Post's instaScheduleAt.
        $this->assertStringContainsString('id="instaStoryScheduleAt"', $src, "Story must have its own schedule-time input, separate from the Feed Post's instaScheduleAt");
        $this->assertStringNotContainsString('id="instaStoryText"', $src, "Story text box must not exist — Instagram doesn't support captions on Stories, so the field only misleads editors");
        $this->assertStringNotContainsString('igDraft', $src, "mode toggle must not swap values between a shared draft object");
    }

    public function testArticlesCarryPreservesStoryFields(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php');
        foreach (['insta_story_buffer_post_id', 'insta_story_scheduled_at', 'insta_story_due_at'] as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $src,
                "article_build_bg_data must carry '{$field}' across saves so scheduled/posted Story state isn't wiped"
            );
        }
    }

    public function testStoryNeverOverwritesTheFeedPostCaption(): void
    {
        // A story is sent with no text; saving that must not blank the feed caption —
        // neither on a fresh post nor on the Buffer edit (reschedule) path.
        $src = file_get_contents($this->socialAjax);
        $this->assertSame(2, substr_count($src, "if (!\$is_story) {"),
            "both the edit and the create path must skip the caption write for a story");
    }

    public function testDashboardCalendarShowsScheduledStories(): void
    {
        // The rules live in article_social_calendar_entries() (SocialCalendarEntriesTest).
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/dashboard.php');
        $this->assertStringContainsString('article_social_calendar_entries($a, $tz)', $src);
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
        $tz = new DateTimeZone('Europe/Sofia');
        $this->assertSame('igs', article_social_calendar_entries(['insta_story_scheduled_at' => '2026-10-20T09:00'], $tz)[0]['ch'] ?? null);
        $this->assertSame('igs', article_social_calendar_entries(['insta_story_scheduled_at' => 'now', 'insta_story_due_at' => '2026-10-20T06:00:00Z'], $tz)[0]['ch'] ?? null);
    }

    public function testEmojiPickerIsReachableFromInlineHandlers(): void
    {
        // The emoji buttons use onclick= attributes, which run in global scope.
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('window._toggleEmojiPicker = _toggleEmojiPicker;', $src);
        $this->assertStringContainsString('window._insertEmoji       = _insertEmoji;', $src);
        $this->assertStringContainsString('data-emoji-target="instaText"', $src);
    }

    // ── LinkedIn tests ────────────────────────────────────────────────────────

    public function testLinkedInHasNoAiText(): void
    {
        // LinkedIn posts the English article as it is: no generate buttons, no AI call.
        $ajax = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/linkedin-ajax.php');
        $this->assertStringNotContainsString("'generate'", $ajax);
        $this->assertStringNotContainsString('api.anthropic.com', $ajax);
        $edit = file_get_contents($this->articleEdit);
        $this->assertStringNotContainsString('liGenerate', $edit);
        $this->assertStringContainsString('id="liText"', $edit);
    }

    // ── „Планирай навсякъде“ ──────────────────────────────────────────────────

    public function testScheduleEverywhereRowHasLabelledControls(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertStringContainsString('<label for="socAllAt"', $src);
        $this->assertStringContainsString('Планирай навсякъде за', $src);
        $this->assertMatchesRegularExpression('/id="socAllAt" value="<\?= h\(\$default_social_at\) \?>"/', $src,
            'The shared time must default to the same time Facebook uses');
        $this->assertStringContainsString('id="socAllBtn"', $src);
        $this->assertStringContainsString('Планирай навсякъде', $src);
        // One real <label for> per network checkbox.
        $this->assertStringContainsString('<label for="socAll_<?= $_net ?>"', $src);
        $this->assertStringContainsString('<input type="checkbox" id="socAll_<?= $_net ?>"', $src);
        foreach (["'fb' => 'Facebook'", "'insta' => 'Instagram'", "'li' => 'LinkedIn'"] as $net) {
            $this->assertStringContainsString($net, $src);
        }
        // Above the Facebook panel.
        $this->assertLessThan(strpos($src, 'id="socialPanel"'), strpos($src, 'id="socAllPanel"'));
    }

    public function testScheduleEverywhereSummaryIsALiveRegion(): void
    {
        $src = file_get_contents($this->articleEdit);
        $this->assertMatchesRegularExpression('/id="socAllSummary"[^>]*role="status"/', $src);
        $this->assertStringContainsString("(ok ? '✓ ' : '✗ ')", $src, 'Success/failure must carry an icon, not colour alone');
        $this->assertStringContainsString("allBtn.textContent = 'Изпраща се…'", $src);
        $this->assertStringContainsString('allBtn.disabled    = true', $src);
    }

    public function testEachNetworkScheduleHandlerIsASharedFunction(): void
    {
        $src = file_get_contents($this->articleEdit);
        foreach (['_fbSchedule', '_instaSchedule', '_liSchedule'] as $fn) {
            $this->assertStringContainsString('function ' . $fn . '()', $src);
        }
        // The network's own button calls the same function the shared button uses.
        $this->assertStringContainsString("fbBtn.addEventListener('click', function() { _fbSchedule(); })", $src);
        $this->assertStringContainsString("instaBtn.addEventListener('click', function() { _instaSchedule(); })", $src);
        $this->assertStringContainsString("scheduleBtn.addEventListener('click', function() { _liSchedule(); })", $src);
        $this->assertStringContainsString('run: _fbSchedule', $src);
        $this->assertStringContainsString('run:   _instaSchedule', $src);
        $this->assertStringContainsString('run: _liSchedule', $src);
        // Both send helpers resolve to {ok, error}.
        $this->assertSame(2, substr_count($src, 'return result;'));
    }

    public function testScheduleEverywhereRunsNetworksOneAfterAnother(): void
    {
        $src = file_get_contents($this->articleEdit);
        $start = strpos($src, "var allBtn = document.getElementById('socAllBtn');");
        $this->assertNotFalse($start);
        $block = substr($src, $start, 3000);
        $this->assertStringContainsString('res = await net.run();', $block, 'Each network must be awaited in turn');
        $this->assertMatchesRegularExpression('/for \(var i = 0; i < nets\.length; i\+\+\)/', $block);
        $this->assertStringNotContainsString('Promise.all', $block, 'Parallel requests can lose writes to the article JSON');
        $this->assertStringContainsString('field.value = when;', $block, 'The shared time must be copied into each network\'s own field');
        $this->assertStringContainsString('catch (e)', $block, 'One failing network must not stop the others');
    }
}
