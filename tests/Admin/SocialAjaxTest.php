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
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/dashboard.php');
        $this->assertStringContainsString("'insta_story_scheduled_at' => 'igs'", $src);
        $this->assertStringContainsString("'insta_story_scheduled_at' => ['insta_story_due_at', 'igs']", $src);
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
