<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Newsletter topics as a list the admin manages (migration 045,
 * includes/newsletter.php "Topics", admin/newsletter-topics.php): adding,
 * renaming, ordering, hiding without losing anyone's choice, and what the
 * sign-up forms do with two or more, one, or no topics shown.
 *
 * Works on the dev database; every test puts the topics back as it found them.
 */
final class NewsletterTopicsTest extends TestCase
{
    private static bool $db = false;
    private \PDO $pdo;
    private array $savedTopics = [];
    private array $made = [];

    public static function setUpBeforeClass(): void
    {
        self::$db = test_db_available();
        if (self::$db) require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
    }

    protected function setUp(): void
    {
        if (!self::$db) $this->markTestSkipped('No DB available.');
        $this->pdo = get_pdo();
        $this->savedTopics = $this->pdo->query('SELECT id, name_bg, name_en, sort_order, is_active FROM newsletter_topics')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        if (!self::$db) return;
        foreach ($this->made as $id) $this->pdo->prepare('DELETE FROM newsletter_topics WHERE id = ?')->execute([$id]);
        $put = $this->pdo->prepare('UPDATE newsletter_topics SET name_bg = ?, name_en = ?, sort_order = ?, is_active = ? WHERE id = ?');
        foreach ($this->savedTopics as $t) $put->execute([$t['name_bg'], $t['name_en'], $t['sort_order'], $t['is_active'], $t['id']]);
        $this->pdo->exec("DELETE FROM newsletter_subscribers WHERE email LIKE 'test_nltopics_%'");
    }

    private function add(string $bg, string $en = ''): int
    {
        return $this->made[] = newsletter_topic_add($bg, $en);
    }

    /** Show exactly these topics (by id), hiding every other one. */
    private function showOnly(array $ids): void
    {
        $this->pdo->exec('UPDATE newsletter_topics SET is_active = 0');
        foreach ($ids as $id) newsletter_topic_set_shown($id, true);
    }

    private function shownIds(): array
    {
        return array_map(fn($t) => (int) $t['id'], newsletter_topic_list());
    }

    private function render(array $vars = []): string
    {
        ob_start();
        (static function (array $vars): void {
            extract($vars);
            require $_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-topics.php';
        })($vars);
        return (string) ob_get_clean();
    }

    // ── Managing the list ─────────────────────────────────────────────────────

    public function test_added_topic_goes_last_and_can_be_renamed(): void
    {
        $id = $this->add('  <b>Събития</b> ', 'Events');
        $this->assertSame($id, end($this->shownIds()) ?: null);
        $t = newsletter_topic_get($id);
        $this->assertSame('Събития', $t['name_bg'], 'plain text, trimmed');
        $this->assertSame('Events', newsletter_topic_name($t, 'en'));

        newsletter_topic_rename($id, 'Покани', str_repeat('x', 200));
        $t = newsletter_topic_get($id);
        $this->assertSame('Покани', $t['name_bg']);
        $this->assertSame(150, mb_strlen($t['name_en']), 'capped to the column');
    }

    public function test_move_reorders_shown_topics_and_stops_at_the_ends(): void
    {
        $a = $this->add('А');
        $b = $this->add('Б');
        $this->showOnly([$a, $b]);
        $this->assertSame([$a, $b], $this->shownIds());

        newsletter_topic_move($b, -1);
        $this->assertSame([$b, $a], $this->shownIds());
        newsletter_topic_move($b, -1);
        $this->assertSame([$b, $a], $this->shownIds(), 'already first');
        newsletter_topic_move($b, 1);
        $this->assertSame([$a, $b], $this->shownIds());
    }

    public function test_hiding_keeps_choices_and_campaign_audience(): void
    {
        $id    = $this->add('Временна');
        $email = 'test_nltopics_hide_' . uniqid() . '@example-test.invalid';
        newsletter_subscribe($email, '', 'bg', 'web_banner', [$id]);
        $this->assertSame(1, newsletter_topic_subscriber_counts()[$id] ?? 0);

        newsletter_topic_set_shown($id, false);
        $this->assertNotContains($id, $this->shownIds(), 'no longer offered');
        $this->assertSame(1, newsletter_topic_subscriber_counts()[$id] ?? 0, 'nobody loses their choice');
        $this->assertSame((string) $id, newsletter_clean_topic((string) $id), 'a campaign aimed at it keeps its topic');
        $to = $this->pdo->query('SELECT email FROM newsletter_subscribers WHERE ' . newsletter_topic_where((string) $id))
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains($email, $to);
        $this->assertSame([], newsletter_topics_from_post(['nl_topics' => [(string) $id]]), 'a hidden topic cannot be ticked');

        newsletter_topic_set_shown($id, true);
        $this->assertSame($id, end($this->shownIds()) ?: null, 'shown again, at the end');
    }

    // ── Sign-up forms with 2+, 1 or 0 topics ──────────────────────────────────

    public function test_two_or_more_topics_render_unticked_checkboxes_and_need_a_choice(): void
    {
        $a = $this->add('Новини', 'News');
        $b = $this->add('Събития');
        $this->showOnly([$a, $b]);

        $html = $this->render();
        $this->assertStringContainsString('<legend', $html);
        $this->assertSame(2, substr_count($html, 'name="nl_topics[]"'));
        $this->assertStringNotContainsString(' checked', $html, 'consent is never pre-ticked');
        $this->assertStringContainsString('data-cms-field="topic_' . $a . '_name"', $html);

        $this->assertStringContainsString(' checked', $this->render(['nl_checked' => [$b]]), 'ticks survive a form error');

        $this->assertNull(newsletter_signup_topics([]), 'none ticked: ask for one');
        $this->assertSame([$b], newsletter_signup_topics(['nl_topics' => [(string) $b]]));
        $this->assertFalse(newsletter_post_opted_in([]));
        $this->assertTrue(newsletter_post_opted_in(['nl_topics' => [(string) $a]]));
    }

    public function test_one_topic_shows_no_checkbox_and_signs_up_for_it(): void
    {
        $a = $this->add('Само това');
        $this->showOnly([$a]);

        $html = $this->render(['nl_on_dark' => true]);
        $this->assertStringNotContainsString('type="checkbox"', $html, 'nothing to choose');
        $this->assertSame([$a], newsletter_signup_topics([]));

        // At checkout ticking is the consent: one opt-in box of its own.
        $html = $this->render(['nl_heading' => 'Искам новини.', 'nl_optin' => false]);
        $this->assertStringContainsString('name="nl_optin"', $html);
        $this->assertStringContainsString('Искам новини.', $html);
        $this->assertStringNotContainsString(' checked', $html);
        $this->assertFalse(newsletter_post_opted_in([]));
        $this->assertTrue(newsletter_post_opted_in(['nl_optin' => '1']));
    }

    public function test_no_topics_shows_no_checkbox_and_signs_up_for_everything(): void
    {
        $this->showOnly([]);
        $this->assertSame('', trim($this->render()));
        $this->assertSame([], newsletter_signup_topics([]));
        $this->assertStringContainsString('name="nl_optin"', $this->render(['nl_optin' => true]));
    }

    // ── Editing a name in place on the page ───────────────────────────────────

    public function test_inline_save_writes_topic_names_to_the_table(): void
    {
        $id   = $this->add('Старо име', 'Old name');
        $body = fn(string $bg, string $en) => ['section' => 'newsletter', 'fields' => ["topic_{$id}_name" => ['bg' => $bg, 'en' => $en]]];

        $r = run_admin_page('admin/inline-save.php', [], ['json' => $body('<i>Ново</i> име', 'New name')]);
        $this->assertSame(['ok' => true], json_decode(trim($r['body']), true));
        $t = newsletter_topic_get($id);
        $this->assertSame(['Ново име', 'New name'], [$t['name_bg'], $t['name_en']]);

        run_admin_page('admin/inline-save.php', [], ['json' => $body('  ', 'Blank')]);
        $this->assertSame('Ново име', newsletter_topic_get($id)['name_bg'], 'a topic is never left without a name');

        $r = run_admin_page('admin/inline-save.php', [], ['json' => $body('Хакер', 'x'), 'role' => null]);
        $this->assertSame('unauthorized', json_decode(trim($r['body']), true)['error'] ?? null);
        $this->assertSame('Ново име', newsletter_topic_get($id)['name_bg']);
    }

    // ── Admin → Бюлетин → Теми ────────────────────────────────────────────────

    public function test_topics_page_is_admin_only_and_needs_csrf(): void
    {
        $count = fn() => (int) $this->pdo->query('SELECT COUNT(*) FROM newsletter_topics')->fetchColumn();
        $before = $count();

        $r = run_admin_page('admin/newsletter-topics.php', ['action' => 'add', 'name_bg' => 'Х'], ['role' => null]);
        $this->assertContains($r['status'], [302, 303], 'logged out: sent to the login page');
        $r = run_admin_page('admin/newsletter-topics.php', ['action' => 'add', 'name_bg' => 'Х'], ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $r = run_admin_page('admin/newsletter-topics.php', ['action' => 'add', 'name_bg' => 'Х'], ['csrf' => 'deadbeef']);
        $this->assertSame(400, $r['status']);
        $this->assertSame($before, $count(), 'nothing was added');
    }

    public function test_topics_page_adds_hides_and_shows_again(): void
    {
        $r = run_admin_page('admin/newsletter-topics.php', ['action' => 'add', 'name_bg' => '', 'name_en' => 'Only English']);
        $this->assertSame('error', $r['flash'][0]['type'] ?? null, 'a topic needs a Bulgarian name');

        run_admin_page('admin/newsletter-topics.php', ['action' => 'add', 'name_bg' => 'Доброволчество', 'name_en' => 'Volunteering']);
        $id = (int) $this->pdo->query("SELECT MAX(id) FROM newsletter_topics WHERE name_bg = 'Доброволчество'")->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $this->made[] = $id;
        $this->assertContains($id, $this->shownIds());

        $r = run_admin_page('admin/newsletter-topics.php', ['action' => 'hide', 'id' => (string) $id]);
        $this->assertNotContains($id, $this->shownIds());
        $this->assertStringContainsString('не губят нищо', $r['flash'][0]['message'] ?? '');

        run_admin_page('admin/newsletter-topics.php', ['action' => 'show', 'id' => (string) $id]);
        $this->assertContains($id, $this->shownIds());

        $page = run_admin_page('admin/newsletter-topics.php', [], ['method' => 'GET']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('value="Доброволчество"', $page['body']);
        $this->assertMatchesRegularExpression('#<label for="topic-en-' . $id . '"[^>]*>Име на английски</label>#u', $page['body']);
        $this->assertStringContainsString('data-translate-from="name_bg"', $page['body']);
    }
}
