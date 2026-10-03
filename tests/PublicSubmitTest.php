<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two public write endpoints — api/comment-submit.php (article comments)
 * and api/review-submit.php (product reviews) — run for real as an anonymous
 * visitor via run_admin_page(): validation, honeypot, CSRF, rate limit, and
 * that only well-formed submissions reach the moderation queue.
 */
#[Group('spam')]
final class PublicSubmitTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private string $tag;
    private ?string $turnstile = null;
    private array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        if (test_db_available()) self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB available.');
        $this->tag = 'phpunit-pub-' . bin2hex(random_bytes(4));
        // Turnstile can't be solved from a test; switch it off for the run.
        $this->turnstile = setting_get('turnstile_secret_key');
        setting_set('turnstile_secret_key', '');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $fn) $fn();
        $this->cleanup = [];
        if ($this->turnstile !== null) setting_set('turnstile_secret_key', $this->turnstile);
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    private function comment(array $post = [], array $opts = []): array
    {
        $slug = $post['article_slug'] ?? $this->tag;
        $this->cleanup[] = fn() => self::$pdo->prepare('DELETE FROM comments WHERE article_slug = ?')->execute([$slug]);
        return run_admin_page('api/comment-submit.php', $post + [
            'article_slug' => $this->tag, 'lang' => 'bg', 'author_name' => 'Мария',
            'author_email' => 'maria@test.invalid', 'content' => 'Много полезна статия, благодаря.', 'website' => '',
        ], $opts + ['role' => null]);
    }

    private function comments(string $slug = ''): array
    {
        $st = self::$pdo->prepare('SELECT * FROM comments WHERE article_slug = ?');
        $st->execute([$slug ?: $this->tag]);
        return $st->fetchAll();
    }

    private function flashTypes(array $r): array
    {
        return array_column($r['flash'], 'type');
    }

    public function testValidCommentIsQueuedForModeration(): void
    {
        $r = $this->comment();
        $this->assertSame(302, $r['status'], $r['body']);
        $this->assertSame(['comment_success'], $this->flashTypes($r));
        $rows = $this->comments();
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status'], 'comments must never go live without moderation');
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
    }

    public function testCommentWithoutCsrfIsRejected(): void
    {
        $this->assertSame(400, $this->comment([], ['csrf' => false])['status']);
        $this->assertSame([], $this->comments());
    }

    public function testCommentHoneypotPretendsSuccessButStoresNothing(): void
    {
        $r = $this->comment(['website' => 'http://spam.example']);
        $this->assertSame(['comment_success'], $this->flashTypes($r));
        $this->assertSame([], $this->comments());
    }

    public function testInvalidCommentsShowAnErrorAndStoreNothing(): void
    {
        foreach ([
            'bad email'    => ['author_email' => 'not-an-email'],
            'no name'      => ['author_name' => '  '],
            'too short'    => ['content' => 'хм'],
            'too long'     => ['content' => str_repeat('а', 2001)],
            'name too long'=> ['author_name' => str_repeat('я', 101)],
        ] as $case => $post) {
            $r = $this->comment($post);
            $this->assertSame(302, $r['status'], "$case: must show a message, not crash — {$r['body']}");
            $this->assertSame(['comment_error'], $this->flashTypes($r), $case);
            $this->assertSame([], $this->comments(), $case);
        }
    }

    public function testCommentRateLimitIsPerIpAndArticle(): void
    {
        $this->comment();
        $r = $this->comment(['content' => 'Още един коментар веднага след първия.']);
        $this->assertSame(['comment_error'], $this->flashTypes($r));
        $this->assertCount(1, $this->comments());

        $this->comment(['content' => 'От друг посетител, друг IP адрес.'], ['ip' => '10.0.0.9']);
        $this->assertCount(2, $this->comments());
    }

    public function testCommentSlugCannotCarryAPath(): void
    {
        $this->comment(['article_slug' => '../../' . $this->tag]);
        $this->assertCount(1, $this->comments($this->tag), 'path segments must be stripped from the slug');
    }

    public function testCommentGetRequestDoesNothing(): void
    {
        $this->assertSame(302, $this->comment([], ['method' => 'GET'])['status']);
        $this->assertSame([], $this->comments());
    }

    // ── Product reviews ──────────────────────────────────────────────────────

    private function product(int $active = 1): string
    {
        $slug = $this->tag . '-p' . $active;
        self::$pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?,?,?,?,?,?)')
            ->execute([$slug, 'PHPUnit', 'PHPUnit', 1, 0, $active]);
        $id = (int) self::$pdo->lastInsertId();
        $this->cleanup[] = function () use ($id) {
            self::$pdo->prepare('DELETE FROM product_reviews WHERE product_id = ?')->execute([$id]);
            self::$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        };
        return $slug;
    }

    private function review(string $slug, array $post = [], array $opts = []): array
    {
        return run_admin_page('api/review-submit.php', $post + [
            'product_slug' => $slug, 'lang' => 'bg', 'author_name' => 'Иван', 'author_email' => 'ivan@test.invalid',
            'rating' => '5', 'content' => 'Чудесен продукт, препоръчвам.', 'website' => '',
        ], $opts + ['role' => null]);
    }

    private function reviews(string $slug): array
    {
        $st = self::$pdo->prepare('SELECT r.* FROM product_reviews r JOIN products p ON p.id = r.product_id WHERE p.slug = ?');
        $st->execute([$slug]);
        return $st->fetchAll();
    }

    private function reviewFlash(array $r): ?string
    {
        return $r['session']['product_review_flash']['type'] ?? null;
    }

    public function testValidReviewIsQueuedUnverified(): void
    {
        $slug = $this->product();
        $r = $this->review($slug);
        $this->assertSame(302, $r['status'], $r['body']);
        $this->assertSame('success', $this->reviewFlash($r));
        $rows = $this->reviews($slug);
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(0, (int) $rows[0]['verified_purchase'], 'an email with no paid order is not a verified purchase');
    }

    public function testInvalidReviewsShowAnErrorAndStoreNothing(): void
    {
        $slug = $this->product();
        foreach ([
            'rating 0'      => ['rating' => '0'],
            'rating 6'      => ['rating' => '6'],
            'bad email'     => ['author_email' => 'x'],
            'too short'     => ['content' => 'ок'],
            'name too long' => ['author_name' => str_repeat('я', 101)],
        ] as $case => $post) {
            $r = $this->review($slug, $post);
            $this->assertSame('error', $this->reviewFlash($r), $case);
        }
        $this->assertSame([], $this->reviews($slug));
    }

    public function testReviewForHiddenOrUnknownProductIsRejected(): void
    {
        $hidden = $this->product(0);
        $this->assertSame('error', $this->reviewFlash($this->review($hidden)));
        $this->assertSame([], $this->reviews($hidden));
        $this->assertSame('error', $this->reviewFlash($this->review($this->tag . '-nope')));
    }

    public function testReviewHoneypotCsrfAndRateLimit(): void
    {
        $slug = $this->product();
        $this->assertSame('success', $this->reviewFlash($this->review($slug, ['website' => 'x'])));
        $this->assertSame([], $this->reviews($slug), 'honeypot must store nothing');

        $this->assertSame(400, $this->review($slug, [], ['csrf' => false])['status']);
        $this->assertSame([], $this->reviews($slug));

        $this->review($slug);
        $this->assertSame('error', $this->reviewFlash($this->review($slug, ['content' => 'Втори отзив веднага.'])));
        $this->assertCount(1, $this->reviews($slug));
    }

    // ── Contact forms (BG + EN) ──────────────────────────────────────────────

    public function testContactFormsRejectAnOverlongNameWithAMessage(): void
    {
        $email = $this->tag . '@test.invalid';
        $this->cleanup[] = fn() => self::$pdo->prepare('DELETE FROM contact_submissions WHERE email = ?')->execute([$email]);

        foreach (['kontakti/index.php' => ['Друго', 'Името е твърде дълго'], 'en/contacts/index.php' => ['Other', 'name is too long']] as $page => [$topic, $msg]) {
            $r = run_admin_page($page, ['name' => str_repeat('я', 201), 'email' => $email, 'topic' => $topic, 'message' => 'Здравейте, имам въпрос.', 'website' => ''],
                ['role' => null, 'ip' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254)]);
            $this->assertSame(200, $r['status'], "$page must show the form again, not crash");
            $this->assertStringContainsString($msg, $r['body'], $page);
        }
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM contact_submissions WHERE email = ?');
        $st->execute([$email]);
        $this->assertSame(0, (int) $st->fetchColumn());
    }
}
