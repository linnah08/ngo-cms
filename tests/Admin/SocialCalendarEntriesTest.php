<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/** The social posts the dashboard calendar shows for one article. */
#[Group('admin')]
final class SocialCalendarEntriesTest extends TestCase
{
    private DateTimeZone $tz;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/articles.php';
    }

    protected function setUp(): void
    {
        $this->tz = new DateTimeZone('Europe/Sofia');
    }

    public function testScheduledPostsUseTheLocalTime(): void
    {
        $e = article_social_calendar_entries(['fb_scheduled_at' => '2026-10-20T09:30', 'linkedin_scheduled_at' => '2026-10-21T18:00'], $this->tz);
        $this->assertSame([
            ['date' => '2026-10-20', 'time' => '09:30', 'ch' => 'fb'],
            ['date' => '2026-10-21', 'time' => '18:00', 'ch' => 'li'],
        ], $e);
    }

    public function testLinkedInPostPublishedNowIsShown(): void
    {
        // Published "now" through Buffer: no linkedin_posted_at, the time is in linkedin_due_at (UTC).
        $e = article_social_calendar_entries(['linkedin_scheduled_at' => 'now', 'linkedin_due_at' => '2026-10-15T07:05:00Z'], $this->tz);
        $this->assertSame([['date' => '2026-10-15', 'time' => '10:05', 'ch' => 'li']], $e);
    }

    public function testFacebookAndInstagramPublishedNowAreShown(): void
    {
        $e = article_social_calendar_entries([
            'fb_scheduled_at' => 'now', 'fb_due_at' => '2026-10-15T21:30:00Z',
            'insta_story_scheduled_at' => 'now', 'insta_story_due_at' => '2026-10-15T08:00:00Z',
        ], $this->tz);
        $this->assertSame([
            ['date' => '2026-10-16', 'time' => '00:30', 'ch' => 'fb'],
            ['date' => '2026-10-15', 'time' => '11:00', 'ch' => 'igs'],
        ], $e);
    }

    public function testLinkedInPublishedNowIsNotShownTwice(): void
    {
        $e = article_social_calendar_entries([
            'linkedin_scheduled_at' => 'now', 'linkedin_due_at' => '2026-10-15T07:05:00Z',
            'linkedin_posted_at' => '2026-10-15 10:05:00',
        ], $this->tz);
        $this->assertCount(1, $e);
    }

    public function testLinkedInMarkedPublishedByHandIsShown(): void
    {
        $e = article_social_calendar_entries(['linkedin_posted_at' => '2026-10-14 12:00:00'], $this->tz);
        $this->assertSame([['date' => '2026-10-14', 'time' => '12:00', 'ch' => 'li']], $e);
    }

    public function testNowWithoutATimeAndMalformedDatesAreSkipped(): void
    {
        $this->assertSame([], article_social_calendar_entries([
            'linkedin_scheduled_at' => 'now', 'fb_scheduled_at' => 'not a date', 'insta_scheduled_at' => '',
        ], $this->tz));
    }

    public function testTheDashboardUsesIt(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/dashboard.php');
        $this->assertStringContainsString('article_social_calendar_entries($a, $tz)', $src);
    }
}
