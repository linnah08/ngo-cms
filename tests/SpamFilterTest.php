<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for includes/spam_filter.php.
 * Link-threshold and extraction tests need no DB. Blocklist-match and
 * blocklist_add tests need a real settings table, so they self-skip
 * via test_db_available() (see tests/bootstrap.php), same convention
 * as tests/TranslatorTest.php.
 */
final class SpamFilterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/spam_filter.php';
    }

    // ── spam_content_is_blocked: link threshold (no DB needed) ───────────────

    public function test_three_or_more_links_is_blocked(): void
    {
        $text = 'Check these out: http://a.com http://b.com http://c.com';
        $this->assertTrue(spam_content_is_blocked($text));
    }

    public function test_two_links_is_not_blocked_by_link_rule(): void
    {
        $text = 'Check these out: http://a.com http://b.com';
        // Only meaningful if the blocklist itself is empty
        if (test_db_available()) {
            spam_blocklist_add([]); // no-op, just ensures function is loaded
            $original = spam_blocklist_terms();
            if ($original !== []) {
                $this->markTestSkipped('Blocklist is not empty — cannot isolate the link-count rule.');
            }
        }
        $this->assertFalse(spam_content_is_blocked($text));
    }

    public function test_www_prefixed_links_count_toward_threshold(): void
    {
        $text = 'www.a.com www.b.com www.c.com';
        $this->assertTrue(spam_content_is_blocked($text));
    }

    public function test_two_http_www_links_is_not_blocked_by_link_rule(): void
    {
        // Regression: 'http://www.example.com' must count as ONE link, not two
        // (the old regex matched 'https?://' and 'www.' separately, so this
        // 2-link message was miscounted as 4 and silently blocked).
        $text = "Here's my site http://www.mysite.com and my page http://www.facebook.com/us";
        // Only meaningful if the blocklist itself is empty.
        if (test_db_available()) {
            $original = spam_blocklist_terms();
            if ($original !== []) {
                $this->markTestSkipped('Blocklist is not empty — cannot isolate the link-count rule.');
            }
        }
        $this->assertFalse(spam_content_is_blocked($text));
    }

    public function test_three_http_www_links_is_blocked(): void
    {
        $text = 'http://www.a.com http://www.b.com http://www.c.com';
        $this->assertTrue(spam_content_is_blocked($text));
    }

    // ── spam_content_is_blocked: blocklist match (DB-gated) ───────────────────

    public function test_blocklist_term_match_is_case_insensitive(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }
        $original = setting_get('spam_blocklist');

        spam_blocklist_add(['casino']);
        $this->assertTrue(spam_content_is_blocked('Best CASINO in town'));
        $this->assertFalse(spam_content_is_blocked('hello world, nothing spammy here'));

        setting_set('spam_blocklist', $original);
    }

    // ── spam_extract_domains ───────────────────────────────────────────────────

    public function test_extracts_domain_from_http_link(): void
    {
        $domains = spam_extract_domains(['Check out http://cheap-pills-shop.ru/buy now!']);
        $this->assertSame(['cheap-pills-shop.ru'], $domains);
    }

    public function test_extracts_and_dedupes_www_links_case_insensitively(): void
    {
        $domains = spam_extract_domains(['Visit www.spam-site.com and www.SPAM-site.com too']);
        $this->assertSame(['spam-site.com'], $domains);
    }

    public function test_no_links_returns_empty_array(): void
    {
        $this->assertSame([], spam_extract_domains(['no links here at all']));
    }

    public function test_dedupes_across_multiple_texts(): void
    {
        $domains = spam_extract_domains([
            'first http://foo.com message',
            'second http://foo.com http://bar.com message',
        ]);
        sort($domains);
        $this->assertSame(['bar.com', 'foo.com'], $domains);
    }

    public function test_strips_trailing_period_from_domain(): void
    {
        $domains = spam_extract_domains(['visit http://spam.com. Thanks!']);
        $this->assertSame(['spam.com'], $domains);
    }

    public function test_strips_trailing_parenthesis_from_domain(): void
    {
        $domains = spam_extract_domains(['see (http://other-spam.net) for info']);
        $this->assertSame(['other-spam.net'], $domains);
    }

    public function test_strips_multiple_trailing_punctuation_characters(): void
    {
        $domains = spam_extract_domains([
            'visit http://test1.com. and',
            'view http://test2.com; also',
            'check http://test3.com: here',
            'see http://test4.com) now',
        ]);
        sort($domains);
        $this->assertSame(['test1.com', 'test2.com', 'test3.com', 'test4.com'], $domains);
    }

    public function test_strips_quotes_from_domain(): void
    {
        $domains = spam_extract_domains(['check "http://quoted.com" today']);
        $this->assertSame(['quoted.com'], $domains);
    }

    // ── spam_blocklist_add (DB-gated) ─────────────────────────────────────────

    public function test_blocklist_add_appends_and_dedupes(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }
        $original = setting_get('spam_blocklist');

        setting_set('spam_blocklist', "existing-term");
        spam_blocklist_add(['New-Term', 'existing-term']);

        $terms = spam_blocklist_terms();
        sort($terms);
        $this->assertSame(['existing-term', 'new-term'], $terms);

        setting_set('spam_blocklist', $original);
    }

    // ── turnstile_is_configured / turnstile_verify ────────────────────────────

    public function test_is_configured_returns_bool(): void
    {
        $this->assertIsBool(turnstile_is_configured());
    }

    public function test_verify_returns_false_for_empty_token_without_network(): void
    {
        // Must short-circuit before any network call.
        $this->assertFalse(turnstile_verify('', '127.0.0.1'));
    }

    public function test_verify_returns_false_when_not_configured(): void
    {
        if (test_db_available()) {
            $original = setting_get('turnstile_secret_key');
            setting_set('turnstile_secret_key', '');
            $this->assertFalse(turnstile_verify('some-token', '127.0.0.1'));
            setting_set('turnstile_secret_key', $original);
        } else {
            // Without DB, setting_get() always returns '' — still no network call.
            $this->assertFalse(turnstile_verify('some-token', '127.0.0.1'));
        }
    }

    // ── spam_mark_comments_as_spam / spam_mark_contacts_as_spam (DB-gated) ────

    public function test_mark_comments_as_spam_updates_status_and_extracts_domains(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }
        $pdo = get_pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                article_slug VARCHAR(255) NOT NULL, lang VARCHAR(5) NOT NULL DEFAULT 'bg',
                author_name VARCHAR(100) NOT NULL, author_email VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending',
                ip VARCHAR(45) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->prepare("INSERT INTO comments (article_slug, author_name, author_email, content, status) VALUES ('t','Test','t@example.com', ?, 'pending')")
            ->execute(['Buy now at http://spammy-domain.ru/deal']);
        $id = (int)$pdo->lastInsertId();

        $domains = spam_mark_comments_as_spam($pdo, [$id]);

        $status = $pdo->query("SELECT status FROM comments WHERE id = $id")->fetchColumn();
        $this->assertSame('spam', $status);
        $this->assertSame(['spammy-domain.ru'], $domains);

        $pdo->exec("DELETE FROM comments WHERE id = $id");
    }

    public function test_mark_contacts_as_spam_updates_status_and_extracts_domains(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }
        $pdo = get_pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contact_submissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL, email VARCHAR(255) NOT NULL,
                topic VARCHAR(200) NOT NULL DEFAULT '', message TEXT NOT NULL,
                status ENUM('new','read','archived','spam') NOT NULL DEFAULT 'new',
                ip VARCHAR(45) NOT NULL DEFAULT '', lang VARCHAR(5) NOT NULL DEFAULT 'bg',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->prepare("INSERT INTO contact_submissions (name, email, topic, message, status) VALUES ('Test','t@example.com','Other', ?, 'new')")
            ->execute(['See www.other-spam.com for details']);
        $id = (int)$pdo->lastInsertId();

        $domains = spam_mark_contacts_as_spam($pdo, [$id]);

        $status = $pdo->query("SELECT status FROM contact_submissions WHERE id = $id")->fetchColumn();
        $this->assertSame('spam', $status);
        $this->assertSame(['other-spam.com'], $domains);

        $pdo->exec("DELETE FROM contact_submissions WHERE id = $id");
    }

    public function test_mark_as_spam_with_empty_ids_is_a_noop(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB available.');
        }
        $this->assertSame([], spam_mark_comments_as_spam(get_pdo(), []));
        $this->assertSame([], spam_mark_contacts_as_spam(get_pdo(), []));
    }
}
