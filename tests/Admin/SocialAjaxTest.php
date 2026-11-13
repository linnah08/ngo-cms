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

    // ── The post is built on the server ──────────────────────────────────────

    /** @return array<string,string> */
    private function ajaxSources(): array
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        return ['social-ajax' => (string) file_get_contents($root . '/admin/social-ajax.php'),
                'linkedin-ajax' => (string) file_get_contents($root . '/admin/linkedin-ajax.php')];
    }

    public function testScheduleIgnoresClientText(): void
    {
        foreach ($this->ajaxSources() as $name => $src) {
            $this->assertStringNotContainsString('$body[\'social_text\']', $src, $name);
            $this->assertStringNotContainsString('$body[\'linkedin_text\']', $src, $name);
            $this->assertStringContainsString('social_schedule_text(', $src, $name);
        }
    }

    public function testNoGenerateAction(): void
    {
        foreach ($this->ajaxSources() as $name => $src) {
            $this->assertStringNotContainsString("=== 'generate'", $src, $name);
            $this->assertStringNotContainsString('api.anthropic.com', $src, $name);
            $this->assertStringNotContainsString('org_ai_context', $src, $name);
        }
    }

    public function testScheduleStopsWritingTextFields(): void
    {
        foreach ($this->ajaxSources() as $name => $src) {
            $this->assertDoesNotMatchRegularExpression(
                '/\[\s*\'(fb_text|insta_text|social_text|linkedin_text)\'\s*\]\s*=[^=]/', $src, $name);
            $this->assertDoesNotMatchRegularExpression('/\$text_key\b/', $src, $name);
        }
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
        // The rule lives in social_schedule_check() (SocialScheduleCheckTest::testTextIsNeededExceptForAStory).
        $src = file_get_contents($this->socialAjax);
        $this->assertStringContainsString('social_schedule_check($channel, $is_story,', $src,
            "schedule action must tell the shared check that a story needs no text");
        $this->assertStringContainsString("!\$is_story && social_schedule_text(",
            (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/social_images.php'));
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
        $this->assertStringContainsString('data-emoji-target="content"', $src);
    }

    // ── LinkedIn tests ────────────────────────────────────────────────────────
}
