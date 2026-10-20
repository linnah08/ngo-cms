<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scheduled article publishing (admin/publish-scheduled.php): only drafts that
 * were deliberately scheduled are published — never an ordinary draft just
 * because its date (which the editor pre-fills with today) has passed.
 */
final class ArticleSchedulingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
    }

    private function draft(array $over = []): array
    {
        return array_merge(['status' => 'draft', 'date' => '2026-10-01'], $over);
    }

    public function test_explicitly_scheduled_draft(): void
    {
        $this->assertTrue(article_is_scheduled($this->draft(['scheduled' => true])));
        $this->assertFalse(article_is_scheduled($this->draft(['scheduled' => false])));
    }

    public function test_explicit_flag_wins_over_legacy_date_rule(): void
    {
        $savedLongAgo = strtotime('2026-01-01');
        $this->assertFalse(article_is_scheduled($this->draft(['scheduled' => false]), $savedLongAgo));
    }

    public function test_published_or_undated_is_never_scheduled(): void
    {
        $this->assertFalse(article_is_scheduled(['status' => 'published', 'date' => '2026-10-01', 'scheduled' => true]));
        $this->assertFalse(article_is_scheduled(['status' => 'draft', 'date' => '', 'scheduled' => true]));
        $this->assertFalse(article_is_scheduled(['status' => 'draft', 'scheduled' => true]));
    }

    public function test_legacy_draft_dated_after_its_last_save_is_scheduled(): void
    {
        // Saved on 15 Sep with a 1 Oct date → someone picked that date on purpose.
        $this->assertTrue(article_is_scheduled($this->draft(), strtotime('2026-09-15 10:00')));
    }

    public function test_legacy_draft_dated_on_or_before_its_last_save_is_an_ordinary_draft(): void
    {
        // The editor pre-filled the save day — this is the bug being fixed.
        $this->assertFalse(article_is_scheduled($this->draft(['date' => '2026-09-15']), strtotime('2026-09-15 18:00')));
        $this->assertFalse(article_is_scheduled($this->draft(['date' => '2026-03-01']), strtotime('2026-09-15')));
        $this->assertFalse(article_is_scheduled($this->draft(), null), 'no mtime → not scheduled');
    }

    public function test_due_only_once_the_date_arrives(): void
    {
        $a = $this->draft(['scheduled' => true]);
        $this->assertFalse(article_due_for_publish($a, null, '2026-09-30'));
        $this->assertTrue(article_due_for_publish($a, null, '2026-10-01'));
        $this->assertTrue(article_due_for_publish($a, null, '2026-10-05'), 'catches up after a missed run');
        $this->assertFalse(article_due_for_publish($this->draft(['date' => '2026-09-01']), strtotime('2026-09-15'), '2026-10-01'));
    }

    public function test_builders_store_the_flag_only_for_drafts(): void
    {
        $f = ['title' => 'T', 'slug' => 't', 'slug_en' => 't', 'date' => '2026-10-01', 'author' => 'A',
              'status' => 'draft', 'excerpt' => '', 'image' => '', 'tags' => [], 'content' => '', 'scheduled' => true];
        $this->assertTrue(article_build_bg_data($f)['scheduled']);
        $this->assertTrue(article_build_en_data($f)['scheduled']);

        $f['status'] = 'published';
        $this->assertFalse(article_build_bg_data($f)['scheduled']);
        $this->assertFalse(article_build_en_data($f)['scheduled']);

        $f['status'] = 'draft';
        unset($f['scheduled']);
        $this->assertFalse(article_build_bg_data($f)['scheduled'], 'unticked checkbox');
    }

    // ── A draft goes live with its social post ─────────────────────────────────

    public function test_social_post_going_out_today_publishes_the_draft_now(): void
    {
        $r = article_go_live_with_social($this->draft(['date' => '2026-10-25']), strtotime('2026-10-20 15:00'), '2026-10-20');
        $this->assertSame('now', $r['when']);
        $this->assertSame('published', $r['article']['status']);
        $this->assertFalse($r['article']['scheduled']);
        $this->assertSame('2026-10-20', $r['article']['date'], 'a future date would hide it');
    }

    public function test_social_post_before_the_morning_run_publishes_now(): void
    {
        // Tomorrow 05:00 comes before tomorrow's 06:00 run — publish today, not too late.
        $r = article_go_live_with_social($this->draft(), strtotime('2026-10-21 05:00'), '2026-10-20');
        $this->assertSame('now', $r['when']);
    }

    public function test_later_social_post_schedules_the_draft_for_that_morning(): void
    {
        $r = article_go_live_with_social($this->draft(), strtotime('2026-10-24 11:00'), '2026-10-20');
        $this->assertSame('2026-10-24', $r['when']);
        $this->assertSame('draft', $r['article']['status']);
        $this->assertTrue($r['article']['scheduled']);
        $this->assertTrue(article_due_for_publish($r['article'], null, '2026-10-24'));
    }

    public function test_social_post_at_dawn_schedules_the_day_before(): void
    {
        $r = article_go_live_with_social($this->draft(), strtotime('2026-10-24 06:30'), '2026-10-20');
        $this->assertSame('2026-10-23', $r['when']);
    }

    public function test_an_earlier_planned_publish_date_is_kept(): void
    {
        $a = $this->draft(['date' => '2026-10-22', 'scheduled' => true]);
        $this->assertSame('2026-10-22', article_go_live_with_social($a, strtotime('2026-10-24 11:00'), '2026-10-20')['when']);
    }

    public function test_a_later_planned_publish_date_is_brought_forward(): void
    {
        $a = $this->draft(['date' => '2026-10-30', 'scheduled' => true]);
        $this->assertSame('2026-10-24', article_go_live_with_social($a, strtotime('2026-10-24 11:00'), '2026-10-20')['when']);
    }

    public function test_published_post_is_left_alone(): void
    {
        $a = ['status' => 'published', 'date' => '2026-10-01'];
        $r = article_go_live_with_social($a, strtotime('2026-10-24 11:00'), '2026-10-20');
        $this->assertNull($r['when']);
        $this->assertSame($a, $r['article']);
    }

    public function test_publish_with_social_updates_bg_and_en_and_says_so(): void
    {
        $dir = sys_get_temp_dir() . '/art-' . bin2hex(random_bytes(4));
        mkdir($dir . '/bg', 0777, true);
        mkdir($dir . '/en', 0777, true);
        file_put_contents($dir . '/bg/novina.json', json_encode(['status' => 'draft', 'date' => '2026-10-01', 'slug_en' => 'news']));
        file_put_contents($dir . '/en/news.json', json_encode(['status' => 'draft', 'date' => '2026-10-01', 'title' => 'News']));

        $msg = article_publish_with_social('novina', time() + 120, 'Facebook', $dir);
        $this->assertStringContainsString('публикувана на сайта', (string) $msg);
        $this->assertStringContainsString('Facebook', (string) $msg);
        $this->assertSame('published', json_decode(file_get_contents($dir . '/bg/novina.json'), true)['status']);
        $en = json_decode(file_get_contents($dir . '/en/news.json'), true);
        $this->assertSame('published', $en['status']);
        $this->assertSame('News', $en['title']);

        $this->assertNull(article_publish_with_social('novina', time() + 120, 'Facebook', $dir), 'already live → no message');

        array_map('unlink', glob($dir . '/*/*.json'));
        rmdir($dir . '/bg'); rmdir($dir . '/en'); rmdir($dir);
    }
}
