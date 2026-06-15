<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/newsletter.php.
 * DB-dependent tests self-skip when no database is available.
 */
final class NewsletterTest extends TestCase
{
    private static \PDO $pdo;
    private static bool $dbAvailable = false;

    public static function setUpBeforeClass(): void
    {
        self::$dbAvailable = test_db_available();
        if (self::$dbAvailable) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
            self::$pdo = get_pdo();
        }
    }

    private function skipWithoutDb(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('No DB available.');
        }
    }

    /** Insert a subscriber directly and return its token. */
    private function insertSubscriber(string $email, string $status = 'active'): string
    {
        $token = bin2hex(random_bytes(32));
        self::$pdo->prepare("
            INSERT INTO newsletter_subscribers (email, name, lang, source, token, status)
            VALUES (?, NULL, 'bg', 'manual', ?, ?)
            ON DUPLICATE KEY UPDATE token=VALUES(token), status=VALUES(status)
        ")->execute([$email, $token, $status]);
        return $token;
    }

    /** Remove a subscriber by email (test cleanup). */
    private function deleteSubscriber(string $email): void
    {
        self::$pdo->prepare("DELETE FROM newsletter_subscribers WHERE email=?")->execute([$email]);
    }

    // ── newsletter_subscribe ──────────────────────────────────────────────────

    public function test_subscribe_returns_ok_true(): void
    {
        $this->skipWithoutDb();
        $email = 'test_subscribe_ok_' . time() . '@example-test.invalid';
        try {
            $result = newsletter_subscribe($email, 'Test', 'bg', 'manual');
            $this->assertTrue($result['ok']);
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_normalises_email_to_lowercase(): void
    {
        $this->skipWithoutDb();
        $email = 'TEST_LOWER_' . time() . '@Example-TEST.invalid';
        try {
            newsletter_subscribe($email, '', 'bg', 'manual');
            $stmt = self::$pdo->prepare("SELECT email FROM newsletter_subscribers WHERE email=?");
            $stmt->execute([strtolower(trim($email))]);
            $this->assertNotFalse($stmt->fetchColumn());
        } finally {
            $this->deleteSubscriber(strtolower(trim($email)));
        }
    }

    public function test_subscribe_duplicate_sets_duplicate_flag(): void
    {
        $this->skipWithoutDb();
        $email = 'test_dup_' . time() . '@example-test.invalid';
        try {
            newsletter_subscribe($email, '', 'bg', 'manual');
            $result = newsletter_subscribe($email, '', 'bg', 'manual');
            $this->assertTrue($result['duplicate']);
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_rejects_invalid_lang_and_defaults_to_bg(): void
    {
        $this->skipWithoutDb();
        $email = 'test_lang_' . time() . '@example-test.invalid';
        try {
            newsletter_subscribe($email, '', 'fr', 'manual');
            $stmt = self::$pdo->prepare("SELECT lang FROM newsletter_subscribers WHERE email=?");
            $stmt->execute([$email]);
            $this->assertSame('bg', $stmt->fetchColumn());
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_rejects_invalid_source_and_defaults_to_web_banner(): void
    {
        $this->skipWithoutDb();
        $email = 'test_source_' . time() . '@example-test.invalid';
        try {
            newsletter_subscribe($email, '', 'bg', 'hacker_input');
            $stmt = self::$pdo->prepare("SELECT source FROM newsletter_subscribers WHERE email=?");
            $stmt->execute([$email]);
            $this->assertSame('web_banner', $stmt->fetchColumn());
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    // ── newsletter_unsubscribe ────────────────────────────────────────────────

    public function test_unsubscribe_with_bogus_token_returns_false(): void
    {
        // No DB needed — token validation happens before the DB query
        $this->assertFalse(newsletter_unsubscribe('not-a-valid-token'));
    }

    public function test_unsubscribe_rejects_token_wrong_length(): void
    {
        $this->assertFalse(newsletter_unsubscribe(str_repeat('a', 63)));
        $this->assertFalse(newsletter_unsubscribe(str_repeat('a', 65)));
    }

    public function test_unsubscribe_rejects_non_hex_token(): void
    {
        // 64 chars but contains non-hex character 'z'
        $this->assertFalse(newsletter_unsubscribe(str_repeat('z', 64)));
    }

    public function test_unsubscribe_valid_token_sets_status_unsubscribed(): void
    {
        $this->skipWithoutDb();
        $email = 'test_unsub_' . time() . '@example-test.invalid';
        $token = $this->insertSubscriber($email, 'active');
        try {
            $result = newsletter_unsubscribe($token);
            $this->assertTrue($result);

            $stmt = self::$pdo->prepare("SELECT status FROM newsletter_subscribers WHERE token=?");
            $stmt->execute([$token]);
            $this->assertSame('unsubscribed', $stmt->fetchColumn());
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_unsubscribe_unknown_token_returns_false(): void
    {
        $this->skipWithoutDb();
        $fakeToken = str_repeat('0', 64);
        // Ensure it doesn't exist
        self::$pdo->prepare("DELETE FROM newsletter_subscribers WHERE token=?")->execute([$fakeToken]);
        $this->assertFalse(newsletter_unsubscribe($fakeToken));
    }

    public function test_unsubscribe_is_idempotent(): void
    {
        $this->skipWithoutDb();
        $email = 'test_idem_' . time() . '@example-test.invalid';
        $token = $this->insertSubscriber($email, 'active');
        try {
            newsletter_unsubscribe($token);
            // Second call should still return true (token exists)
            $this->assertTrue(newsletter_unsubscribe($token));
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    // ── newsletter_active_count ───────────────────────────────────────────────

    public function test_active_count_returns_expected_shape(): void
    {
        $this->skipWithoutDb();
        $counts = newsletter_active_count();
        $this->assertArrayHasKey('total', $counts);
        $this->assertArrayHasKey('bg',    $counts);
        $this->assertArrayHasKey('en',    $counts);
        $this->assertSame($counts['total'], $counts['bg'] + $counts['en']);
    }
}
