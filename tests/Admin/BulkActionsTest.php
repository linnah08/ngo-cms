<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs the real bulk-action handlers on the admin list pages (via
 * run_admin_page(), one PHP process per request) against throwaway rows, and
 * checks the outcome in the DB / content files: the smart toggles flip the
 * right way, only the selected rows change, junk ids are dropped, and a
 * missing CSRF token or the wrong role changes nothing.
 */
#[Group('admin')]
#[Group('bulk')]
final class BulkActionsTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private string $tag;
    private array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        if (test_db_available()) self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        $this->tag = 'phpunit-bulk-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $fn) $fn();
        $this->cleanup = [];
    }

    private function db(): \PDO
    {
        if (!self::$pdo) $this->markTestSkipped('No DB available.');
        return self::$pdo;
    }

    private function insert(string $table, array $row): int
    {
        $pdo  = $this->db();
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $qs   = implode(',', array_fill(0, count($row), '?'));
        $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($qs)")->execute(array_values($row));
        $id = (int) $pdo->lastInsertId();
        $this->cleanup[] = fn() => $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function col(string $table, string $col, int $id): mixed
    {
        $st = $this->db()->prepare("SELECT `$col` FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        return $st->fetchColumn();
    }

    // ── Newsletter subscribers ───────────────────────────────────────────────

    private function subscriber(string $status): int
    {
        return $this->insert('newsletter_subscribers', [
            'email'  => $this->tag . '-' . bin2hex(random_bytes(3)) . '@test.invalid',
            'token'  => bin2hex(random_bytes(32)),
            'status' => $status,
        ]);
    }

    public function testSubscriberToggleActivatesAMixedSelection(): void
    {
        $a = $this->subscriber('active');
        $b = $this->subscriber('unsubscribed');
        $r = run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_toggle_status', 'ids' => [$a, $b]]);
        $this->assertSame(302, $r['status'], $r['body']);
        $this->assertSame('active', $this->col('newsletter_subscribers', 'status', $a));
        $this->assertSame('active', $this->col('newsletter_subscribers', 'status', $b));
    }

    public function testSubscriberToggleUnsubscribesAnAllActiveSelection(): void
    {
        $a = $this->subscriber('active');
        $b = $this->subscriber('active');
        run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_toggle_status', 'ids' => [$a, $b]]);
        $this->assertSame('unsubscribed', $this->col('newsletter_subscribers', 'status', $a));
        $this->assertSame('unsubscribed', $this->col('newsletter_subscribers', 'status', $b));
    }

    public function testSubscriberDeleteOnlyTouchesSelectedAndIgnoresJunkIds(): void
    {
        $keep = $this->subscriber('active');
        $drop = $this->subscriber('active');
        run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_delete', 'ids' => [$drop, 'abc', '0', '-5', ['nested']]]);
        $this->assertFalse($this->col('newsletter_subscribers', 'status', $drop));
        $this->assertSame('active', $this->col('newsletter_subscribers', 'status', $keep));
    }

    public function testSubscriberBulkDeleteNeedsCsrfAndAdmin(): void
    {
        $id = $this->subscriber('active');
        $r = run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_delete', 'ids' => [$id]], ['csrf' => false]);
        $this->assertSame(400, $r['status']);
        $r = run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_delete', 'ids' => [$id]], ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $r = run_admin_page('admin/newsletter-subscribers.php', ['action' => 'bulk_delete', 'ids' => [$id]], ['role' => null]);
        $this->assertSame(302, $r['status']);
        $this->assertSame('active', $this->col('newsletter_subscribers', 'status', $id));
    }

    // ── Comments + contact submissions ───────────────────────────────────────

    private function comment(string $status = 'pending'): int
    {
        return $this->insert('comments', [
            'article_slug' => $this->tag, 'author_name' => 'PHPUnit', 'lang' => 'bg',
            'author_email' => 'x@test.invalid', 'content' => 'test', 'status' => $status,
        ]);
    }

    private function contact(string $status = 'new'): int
    {
        return $this->insert('contact_submissions', [
            'name' => 'PHPUnit', 'email' => 'x@test.invalid', 'message' => $this->tag, 'status' => $status,
        ]);
    }

    public function testCommentBulkStatusActions(): void
    {
        foreach (['bulk_approve_comments' => 'approved', 'bulk_reject_comments' => 'rejected', 'bulk_spam_comments' => 'spam'] as $action => $expected) {
            $a = $this->comment();
            $b = $this->comment();
            $other = $this->comment();
            $r = run_admin_page('admin/comments.php', ['action' => $action, 'ids' => [$a, $b]], ['role' => 'author']);
            $this->assertSame(302, $r['status'], "$action: {$r['body']}");
            $this->assertSame($expected, $this->col('comments', 'status', $a), $action);
            $this->assertSame($expected, $this->col('comments', 'status', $b), $action);
            $this->assertSame('pending', $this->col('comments', 'status', $other), "$action touched an unselected row");
        }
    }

    public function testCommentBulkDelete(): void
    {
        $a = $this->comment();
        $other = $this->comment();
        run_admin_page('admin/comments.php', ['action' => 'bulk_delete_comments', 'ids' => [$a, 'x']], ['role' => 'author']);
        $this->assertFalse($this->col('comments', 'status', $a));
        $this->assertSame('pending', $this->col('comments', 'status', $other));
    }

    public function testContactBulkStatusActionsAndDelete(): void
    {
        foreach (['bulk_read_contacts' => 'read', 'bulk_archive_contacts' => 'archived', 'bulk_spam_contacts' => 'spam'] as $action => $expected) {
            $a = $this->contact();
            $other = $this->contact();
            run_admin_page('admin/comments.php', ['action' => $action, 'ids' => [$a]]);
            $this->assertSame($expected, $this->col('contact_submissions', 'status', $a), $action);
            $this->assertSame('new', $this->col('contact_submissions', 'status', $other), "$action touched an unselected row");
        }
        $a = $this->contact();
        run_admin_page('admin/comments.php', ['action' => 'bulk_delete_contacts', 'ids' => [$a]]);
        $this->assertFalse($this->col('contact_submissions', 'status', $a));
    }

    public function testCommentBulkActionsNeedCsrfAndEditorialRole(): void
    {
        $id = $this->comment();
        $this->assertSame(400, run_admin_page('admin/comments.php', ['action' => 'bulk_delete_comments', 'ids' => [$id]], ['csrf' => false])['status']);
        $this->assertSame(403, run_admin_page('admin/comments.php', ['action' => 'bulk_delete_comments', 'ids' => [$id]], ['role' => 'shop_admin'])['status']);
        $this->assertSame('pending', $this->col('comments', 'status', $id));
    }

    // ── Product reviews ──────────────────────────────────────────────────────

    private function review(): int
    {
        $pid = (int) $this->db()->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        if (!$pid) $this->markTestSkipped('No product to attach a review to.');
        return $this->insert('product_reviews', [
            'product_id' => $pid, 'author_name' => $this->tag, 'rating' => 5, 'content' => 'test', 'status' => 'pending',
        ]);
    }

    public function testReviewBulkApproveRejectDelete(): void
    {
        foreach (['bulk_approve' => 'approved', 'bulk_reject' => 'rejected'] as $action => $expected) {
            $a = $this->review();
            $other = $this->review();
            $r = run_admin_page('admin/product-reviews.php', ['action' => $action, 'ids' => [$a]], ['role' => 'shop_admin']);
            $this->assertSame(302, $r['status'], $r['body']);
            $this->assertSame($expected, $this->col('product_reviews', 'status', $a));
            $this->assertSame('pending', $this->col('product_reviews', 'status', $other));
        }
        $a = $this->review();
        run_admin_page('admin/product-reviews.php', ['action' => 'bulk_delete', 'ids' => [$a, '-1']]);
        $this->assertFalse($this->col('product_reviews', 'status', $a));
    }

    public function testReviewBulkNeedsCsrfAndShopRole(): void
    {
        $id = $this->review();
        $this->assertSame(400, run_admin_page('admin/product-reviews.php', ['action' => 'bulk_delete', 'ids' => [$id]], ['csrf' => false])['status']);
        $this->assertSame(403, run_admin_page('admin/product-reviews.php', ['action' => 'bulk_delete', 'ids' => [$id]], ['role' => 'author'])['status']);
        $this->assertSame('pending', $this->col('product_reviews', 'status', $id));
    }

    // ── Campaign backers ─────────────────────────────────────────────────────

    private function pledge(?int $rewardId, int $shipped): int
    {
        return $this->insert('campaign_pledges', [
            'pledge_number' => substr($this->tag . bin2hex(random_bytes(3)), 0, 30),
            'name' => 'PHPUnit', 'email' => 'x@test.invalid', 'amount_eur' => 10,
            'reward_id' => $rewardId, 'reward_shipped' => $shipped,
        ]);
    }

    public function testBackerShippedToggleIsSmartAndSkipsPledgesWithoutAReward(): void
    {
        if (function_exists('feature_enabled') && !feature_enabled('campaign')) {
            $this->markTestSkipped('The campaign module is switched off on this site.');
        }
        $rid = (int) $this->db()->query('SELECT id FROM campaign_rewards ORDER BY id LIMIT 1')->fetchColumn();
        if (!$rid) $this->markTestSkipped('No campaign reward in the DB.');

        $a = $this->pledge($rid, 1);
        $b = $this->pledge($rid, 0);
        $none = $this->pledge(null, 0);
        run_admin_page('admin/campaign-backers.php', ['action' => 'bulk_toggle_shipped', 'ids' => [$a, $b, $none]]);
        $this->assertSame(1, (int) $this->col('campaign_pledges', 'reward_shipped', $a));
        $this->assertSame(1, (int) $this->col('campaign_pledges', 'reward_shipped', $b));
        $this->assertSame(0, (int) $this->col('campaign_pledges', 'reward_shipped', $none), 'a pledge with no reward has nothing to ship');

        run_admin_page('admin/campaign-backers.php', ['action' => 'bulk_toggle_shipped', 'ids' => [$a, $b]]);
        $this->assertSame(0, (int) $this->col('campaign_pledges', 'reward_shipped', $a));
        $this->assertSame(0, (int) $this->col('campaign_pledges', 'reward_shipped', $b));
    }

    // ── Products: show-on-homepage toggle ────────────────────────────────────

    private function product(int $featured): int
    {
        return $this->insert('products', [
            'slug' => $this->tag . '-' . bin2hex(random_bytes(3)), 'name_bg' => 'PHPUnit', 'name_en' => 'PHPUnit',
            'price_eur' => 1, 'stock' => 0, 'active' => 0, 'featured' => $featured,
        ]);
    }

    public function testProductFeatureToggleIsSmart(): void
    {
        $a = $this->product(1);
        $b = $this->product(0);
        run_admin_page('admin/products.php', ['action' => 'bulk_feature', 'ids' => [$a, $b]], ['role' => 'shop_admin']);
        $this->assertSame(1, (int) $this->col('products', 'featured', $a));
        $this->assertSame(1, (int) $this->col('products', 'featured', $b));

        run_admin_page('admin/products.php', ['action' => 'bulk_feature', 'ids' => [$a, $b]], ['role' => 'shop_admin']);
        $this->assertSame(0, (int) $this->col('products', 'featured', $a));
        $this->assertSame(0, (int) $this->col('products', 'featured', $b));
    }

    // ── Partners (content/partners.json) ─────────────────────────────────────

    private function withTestPartners(array $partners): void
    {
        $original = file_get_contents(PARTNERS_FILE);
        $this->cleanup[] = fn() => file_put_contents(PARTNERS_FILE, $original);
        save_json(PARTNERS_FILE, array_merge(load_json(PARTNERS_FILE), $partners));
    }

    private function partner(string $id): ?array
    {
        foreach (load_json(PARTNERS_FILE) as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    public function testPartnerBulkToggleAndDelete(): void
    {
        $a = $this->tag . '-a';
        $b = $this->tag . '-b';
        $this->withTestPartners([
            ['id' => $a, 'name' => 'A', 'logo' => '', 'url' => '', 'active' => true,  'order' => 999],
            ['id' => $b, 'name' => 'B', 'logo' => '', 'url' => '', 'active' => false, 'order' => 999],
        ]);
        $before = count(load_json(PARTNERS_FILE));

        run_admin_page('admin/partners.php', ['action' => 'bulk_toggle', 'ids' => [$a, $b]]);
        $this->assertTrue($this->partner($a)['active']);
        $this->assertTrue($this->partner($b)['active']);

        run_admin_page('admin/partners.php', ['action' => 'bulk_toggle', 'ids' => [$a, $b]]);
        $this->assertFalse($this->partner($a)['active']);
        $this->assertFalse($this->partner($b)['active']);

        run_admin_page('admin/partners.php', ['action' => 'bulk_delete', 'ids' => [$a]]);
        $this->assertNull($this->partner($a));
        $this->assertNotNull($this->partner($b));
        $this->assertCount($before - 1, load_json(PARTNERS_FILE), 'bulk delete removed more than the selected partner');

        $this->assertSame(400, run_admin_page('admin/partners.php', ['action' => 'bulk_delete', 'ids' => [$b]], ['csrf' => false])['status']);
        $this->assertNotNull($this->partner($b));
    }

    // ── Articles (content/articles/{bg,en}/*.json) ───────────────────────────

    private function article(string $status): string
    {
        $slug = $this->tag . '-' . bin2hex(random_bytes(3));
        $bg = ARTICLES_PATH . "/bg/$slug.json";
        $en = ARTICLES_PATH . "/en/$slug-en.json";
        save_json($bg, ['title' => 'PHPUnit', 'slug' => $slug, 'slug_en' => "$slug-en", 'status' => $status, 'scheduled' => true]);
        save_json($en, ['title' => 'PHPUnit', 'slug' => "$slug-en", 'status' => $status, 'scheduled' => true]);
        $this->cleanup[] = function () use ($bg, $en) { @unlink($bg); @unlink($en); };
        return $slug;
    }

    public function testArticleBulkPublishIsSmartAndKeepsEnInSync(): void
    {
        $a = $this->article('published');
        $b = $this->article('draft');

        run_admin_page('admin/articles.php', ['ids' => [$a, $b]], ['get' => ['action' => 'bulk_publish'], 'role' => 'author']);
        foreach ([$a, $b] as $slug) {
            $this->assertSame('published', load_json(ARTICLES_PATH . "/bg/$slug.json")['status']);
            $this->assertSame('published', load_json(ARTICLES_PATH . "/en/$slug-en.json")['status'], 'EN must follow BG');
            $this->assertFalse(load_json(ARTICLES_PATH . "/bg/$slug.json")['scheduled'], 'a manual bulk change clears the schedule');
        }

        run_admin_page('admin/articles.php', ['ids' => [$a, $b]], ['get' => ['action' => 'bulk_publish'], 'role' => 'author']);
        $this->assertSame('draft', load_json(ARTICLES_PATH . "/bg/$a.json")['status']);
        $this->assertSame('draft', load_json(ARTICLES_PATH . "/en/$b-en.json")['status']);
    }

    public function testArticleBulkDeleteRemovesBothLanguagesAndCannotEscapeTheFolder(): void
    {
        $a = $this->article('draft');
        $keep = $this->article('draft');

        run_admin_page('admin/articles.php', ['ids' => [$a, '../../partners', '../pages']], ['get' => ['action' => 'bulk_delete']]);
        $this->assertFileDoesNotExist(ARTICLES_PATH . "/bg/$a.json");
        $this->assertFileDoesNotExist(ARTICLES_PATH . "/en/$a-en.json");
        $this->assertFileExists(ARTICLES_PATH . "/bg/$keep.json");
        $this->assertFileExists(PARTNERS_FILE, 'a ../ id must not reach files outside content/articles');

        $this->assertSame(400, run_admin_page('admin/articles.php', ['ids' => [$keep]], ['get' => ['action' => 'bulk_delete'], 'csrf' => false])['status']);
        $this->assertFileExists(ARTICLES_PATH . "/bg/$keep.json");
    }
}
