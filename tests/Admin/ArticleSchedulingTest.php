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
}
