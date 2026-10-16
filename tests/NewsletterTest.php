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

    // ── Mobile-safe article cards ──────────────────────────────────────────────

    public function test_secondary_article_card_uses_percentage_width_not_fixed_pixels(): void
    {
        if (!function_exists('newsletter_format_articles')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
        }
        $articles = [
            ['slug' => 'first',  'title' => 'First',  'excerpt' => 'E1', 'image' => '/img/a.jpg'],
            ['slug' => 'second', 'title' => 'Second', 'excerpt' => 'E2', 'image' => '/img/b.jpg'],
        ];
        $html = newsletter_format_articles($articles, 'bg');

        // The image column must scale with the container (percentage width), never
        // a hard pixel width — a fixed px column can't shrink on a narrow phone
        // screen and overflows the email wrapper's overflow:hidden, clipping the
        // rest of the card.
        $this->assertStringNotContainsString('width="120"', $html);
        $this->assertStringContainsString('nl-card-img', $html);
        $this->assertStringContainsString('nl-card-body', $html);
    }

    public function test_email_wrap_defines_mobile_stacking_media_query(): void
    {
        if (!function_exists('email_wrap')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
        }
        $html = email_wrap('<p>x</p>');
        $this->assertStringContainsString('@media only screen and (max-width:480px)', $html);
        $this->assertStringContainsString('.nl-card-img', $html);
    }

    // ── Scheduled sending ──────────────────────────────────────────────────────

    /** Insert a campaign directly and return its id. */
    private function insertCampaign(string $status = 'draft', ?string $sendDate = null): int
    {
        self::$pdo->prepare("
            INSERT INTO newsletter_campaigns (subject_bg, subject_en, body_bg, body_en, status, send_date)
            VALUES ('Test subject', '', '<p>Body</p>', '', ?, ?)
        ")->execute([$status, $sendDate]);
        return (int)self::$pdo->lastInsertId();
    }

    private function deleteCampaign(int $id): void
    {
        self::$pdo->prepare("DELETE FROM newsletter_campaigns WHERE id=?")->execute([$id]);
    }

    public function test_due_campaigns_includes_past_send_date(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('draft', date('Y-m-d', strtotime('-1 day')));
        try {
            $ids = array_column(newsletter_due_campaigns(self::$pdo), 'id');
            $this->assertContains($id, $ids);
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_due_campaigns_includes_todays_send_date(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('draft', date('Y-m-d'));
        try {
            $ids = array_column(newsletter_due_campaigns(self::$pdo), 'id');
            $this->assertContains($id, $ids);
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_due_campaigns_excludes_future_send_date(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('draft', date('Y-m-d', strtotime('+1 day')));
        try {
            $ids = array_column(newsletter_due_campaigns(self::$pdo), 'id');
            $this->assertNotContains($id, $ids);
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_due_campaigns_excludes_draft_with_no_send_date(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('draft', null);
        try {
            $ids = array_column(newsletter_due_campaigns(self::$pdo), 'id');
            $this->assertNotContains($id, $ids);
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_due_campaigns_excludes_already_sent(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('sent', date('Y-m-d', strtotime('-1 day')));
        try {
            $ids = array_column(newsletter_due_campaigns(self::$pdo), 'id');
            $this->assertNotContains($id, $ids);
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_claim_for_sending_succeeds_on_draft(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('draft', date('Y-m-d'));
        try {
            $this->assertTrue(newsletter_claim_for_sending(self::$pdo, $id));
            $stmt = self::$pdo->prepare("SELECT status FROM newsletter_campaigns WHERE id=?");
            $stmt->execute([$id]);
            $this->assertSame('sending', $stmt->fetchColumn());
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_claim_for_sending_fails_on_already_sending(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('sending', date('Y-m-d'));
        try {
            // Simulates the cron and a manual click racing for the same campaign.
            $this->assertFalse(newsletter_claim_for_sending(self::$pdo, $id));
        } finally {
            $this->deleteCampaign($id);
        }
    }

    public function test_claim_for_sending_fails_on_sent(): void
    {
        $this->skipWithoutDb();
        $id = $this->insertCampaign('sent', null);
        try {
            $this->assertFalse(newsletter_claim_for_sending(self::$pdo, $id));
        } finally {
            $this->deleteCampaign($id);
        }
    }

    // ── newsletter_apply_excerpt_override ─────────────────────────────────────

    private function loadNewsletter(): void
    {
        if (!function_exists('newsletter_apply_excerpt_override')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
        }
    }

    public function test_excerpt_override_replaces_saved_excerpt(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'T', 'excerpt' => 'Original excerpt'];
        $result  = newsletter_apply_excerpt_override($article, ['my-article' => 'Edited for this send']);
        $this->assertSame('Edited for this send', $result['excerpt']);
    }

    public function test_excerpt_override_blank_keeps_saved_excerpt(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'T', 'excerpt' => 'Original excerpt'];
        $result  = newsletter_apply_excerpt_override($article, ['my-article' => '   ']);
        $this->assertSame('Original excerpt', $result['excerpt']);
    }

    public function test_excerpt_override_ignores_other_slugs(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'T', 'excerpt' => 'Original excerpt'];
        $result  = newsletter_apply_excerpt_override($article, ['other-slug' => 'Should not apply']);
        $this->assertSame('Original excerpt', $result['excerpt']);
    }

    public function test_excerpt_override_null_article_stays_null(): void
    {
        $this->loadNewsletter();
        $this->assertNull(newsletter_apply_excerpt_override(null, ['my-article' => 'x']));
    }

    public function test_excerpt_override_trims_whitespace(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'T', 'excerpt' => 'Original'];
        $result  = newsletter_apply_excerpt_override($article, ['my-article' => '  Trimmed text  ']);
        $this->assertSame('Trimmed text', $result['excerpt']);
    }

    public function test_excerpt_override_ignores_non_string_values(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'T', 'excerpt' => 'Original'];
        $result  = newsletter_apply_excerpt_override($article, ['my-article' => ['nested']]);
        $this->assertSame('Original', $result['excerpt']);
    }

    public function test_format_articles_uses_overridden_excerpt_in_html(): void
    {
        $this->loadNewsletter();
        $article = ['slug' => 'my-article', 'title' => 'Title', 'excerpt' => 'Original excerpt', 'image' => ''];
        $edited  = newsletter_apply_excerpt_override($article, ['my-article' => 'Edited excerpt for this campaign']);
        $html    = newsletter_format_articles([$edited], 'bg');

        $this->assertStringContainsString('Edited excerpt for this campaign', $html);
        $this->assertStringNotContainsString('Original excerpt', $html);
    }

    public function test_compose_picker_lists_every_article_not_just_ten(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/newsletter-compose.php');
        $this->assertStringNotContainsString('array_slice($articles_bg, 0, 10)', $src);
        $this->assertStringNotContainsString('array_slice($articles_en, 0, 10)', $src);
    }

    // ── Topics: merge-safe subscribe ──────────────────────────────────────────

    /** Status, source and chosen topic ids ([] = everything) for an email. */
    private function topicsOf(string $email): array
    {
        $stmt = self::$pdo->prepare("SELECT id, status, source FROM newsletter_subscribers WHERE email=?");
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return [];
        $row['topics'] = newsletter_subscriber_topic_ids(self::$pdo, (int) $row['id']);
        return $row;
    }

    /** Id of one of the two built-in topics (migration 045). */
    private function legacyTopic(string $key): int
    {
        $st = self::$pdo->prepare('SELECT id FROM newsletter_topics WHERE legacy_key = ?');
        $st->execute([$key]);
        return (int) $st->fetchColumn();
    }

    public function test_subscribe_stores_selected_topics(): void
    {
        $this->skipWithoutDb();
        $email = 'test_topics_' . uniqid() . '@example-test.invalid';
        $news  = $this->legacyTopic('news');
        try {
            $result = newsletter_subscribe($email, 'T', 'bg', 'checkout', [$news]);
            $this->assertSame('subscribed', $result['status']);
            $row = $this->topicsOf($email);
            $this->assertSame([$news], $row['topics']);
            $this->assertSame('checkout', $row['source'], 'checkout is a real source now, not folded into web_banner');
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_without_topics_means_everything(): void
    {
        $this->skipWithoutDb();
        $email = 'test_alltopics_' . uniqid() . '@example-test.invalid';
        try {
            $this->assertSame('subscribed', newsletter_subscribe($email, '', 'bg', 'manual')['status']);
            $this->assertSame([], $this->topicsOf($email)['topics']);
            // A later narrower tick must not take "everything" away.
            $this->assertSame('merged', newsletter_subscribe($email, '', 'bg', 'web_banner', [$this->legacyTopic('news')])['status']);
            $this->assertSame([], $this->topicsOf($email)['topics']);
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_merges_topics_without_clearing(): void
    {
        $this->skipWithoutDb();
        $email = 'test_merge_' . uniqid() . '@example-test.invalid';
        $news  = $this->legacyTopic('news');
        $edu   = $this->legacyTopic('education');
        try {
            newsletter_subscribe($email, 'T', 'bg', 'checkout', [$news]);
            $result = newsletter_subscribe($email, 'T', 'bg', 'checkout', [$edu]);
            $this->assertSame('merged', $result['status']);
            $this->assertTrue($result['duplicate']);
            $topics = $this->topicsOf($email)['topics'];
            sort($topics);
            $expected = [$news, $edu];
            sort($expected);
            $this->assertSame($expected, $topics, 'a later sign-up must never clear a topic');
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_does_not_resurrect_unsubscribed(): void
    {
        $this->skipWithoutDb();
        $email = 'test_noresurrect_' . uniqid() . '@example-test.invalid';
        $this->insertSubscriber($email, 'unsubscribed');
        try {
            $result = newsletter_subscribe($email, 'T', 'bg', 'checkout', [$this->legacyTopic('news')]);
            $this->assertSame('resurrect_blocked', $result['status']);
            $row = $this->topicsOf($email);
            $this->assertSame('unsubscribed', $row['status']);
            $this->assertSame([], $row['topics']);
        } finally {
            $this->deleteSubscriber($email);
        }
    }

    public function test_subscribe_uses_atomic_inserts(): void
    {
        // Concurrency safety lives in the SQL: INSERT IGNORE decides who created
        // the row and adds topics on the (subscriber, topic) key — no read-modify-write.
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php');
        $this->assertStringContainsString('INSERT IGNORE INTO newsletter_subscribers', $src);
        $this->assertStringContainsString('INSERT IGNORE INTO newsletter_subscriber_topics', $src);
    }

    // ── Topics: helpers ───────────────────────────────────────────────────────

    public function test_topic_where_filters_by_topic(): void
    {
        $this->loadNewsletter();
        $this->assertSame("status='active' AND " . newsletter_topic_match_sql(7), newsletter_topic_where('7'));
        $this->assertStringContainsString('st.topic_id = 7)', newsletter_topic_where('7'));
        $this->assertSame("status='active'", newsletter_topic_where('all'));
        $this->assertSame("status='active'", newsletter_topic_where('0'));
        $this->assertSame("status='active'", newsletter_topic_where("7' OR 1=1 --"));
    }

    public function test_clean_topic_whitelists(): void
    {
        $this->skipWithoutDb();
        $news = (string) $this->legacyTopic('news');
        $this->assertSame($news, newsletter_clean_topic($news));
        $this->assertSame('all', newsletter_clean_topic('all'));
        $this->assertSame('all', newsletter_clean_topic('news'), 'old ENUM keys are ids now');
        $this->assertSame('all', newsletter_clean_topic('999999999'));
        $this->assertSame('all', newsletter_clean_topic($news . ' OR 1=1'));
        $this->assertSame('all', newsletter_clean_topic([$news]));
        $this->assertSame('all', newsletter_clean_topic(null));
    }

    public function test_topics_from_post_reads_ids_and_old_checkbox_names(): void
    {
        $this->skipWithoutDb();
        $news = $this->legacyTopic('news');
        $edu  = $this->legacyTopic('education');
        $this->assertSame([$news], newsletter_topics_from_post(['nl_topics' => [(string) $news, '999999999', 'x']]));
        $this->assertSame([$edu], newsletter_topics_from_post(['wants_education' => '1']), 'a page cached before this change still works');
        $this->assertSame([], newsletter_topics_from_post([]));
        $this->assertSame([], newsletter_topics_from_post(['nl_topics' => 'not-an-array']));
    }

    public function test_topic_names_have_generic_defaults_in_both_languages(): void
    {
        $this->loadNewsletter();
        foreach (['bg', 'en'] as $lang) {
            $label = newsletter_topics_heading($lang);
            $this->assertNotSame('', trim($label));
            $this->assertStringNotContainsString('newsletter.', $label, 'never a raw string key');
        }
        foreach (['news', 'education'] as $key) {
            $t = ['name_bg' => '', 'name_en' => '', 'legacy_key' => $key];
            $this->assertNotSame('', trim(newsletter_topic_name($t, 'bg')));
            $this->assertNotSame(newsletter_topic_name($t, 'bg'), newsletter_topic_name($t, 'en'));
        }
        $this->assertSame('Събития', newsletter_topic_name(['name_bg' => 'Събития', 'name_en' => '', 'legacy_key' => null], 'en'),
            'no English name yet: the Bulgarian one, never an empty label');
        $this->assertSame('Events', newsletter_topic_name(['name_bg' => 'Събития', 'name_en' => 'Events', 'legacy_key' => null], 'en'));
    }

    public function test_topic_names_are_inline_editable(): void
    {
        $partial = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-topics.php');
        $save    = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/inline-save.php');
        $this->assertStringContainsString('data-cms-section="newsletter"', $partial);
        $this->assertStringContainsString('data-cms-field="topic_<?= $_nlt_id ?>_name"', $partial);
        $this->assertStringContainsString("'topics_heading'", $save);
        $this->assertStringContainsString('newsletter_topic_rename(', $save, 'topic names are saved to the topics table');
    }

    public function test_every_send_path_filters_by_campaign_topic(): void
    {
        foreach (['admin/newsletter-send.php', 'cron/newsletter-send-scheduled-cron.php'] as $rel) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
            $this->assertStringContainsString('newsletter_topic_where(', $src, "$rel must send only to the campaign's topic");
            $this->assertStringNotContainsString(
                "FROM newsletter_subscribers WHERE status='active' ORDER BY",
                $src,
                "$rel still selects every active subscriber"
            );
        }
    }

    public function test_topic_send_selects_choosers_and_everything_subscribers(): void
    {
        $this->skipWithoutDb();
        $a = 'test_tw_a_' . uniqid() . '@example-test.invalid';
        $b = 'test_tw_b_' . uniqid() . '@example-test.invalid';
        $c = 'test_tw_c_' . uniqid() . '@example-test.invalid';
        $news = $this->legacyTopic('news');
        try {
            newsletter_subscribe($a, '', 'bg', 'manual', [$news]);
            newsletter_subscribe($b, '', 'bg', 'manual', [$this->legacyTopic('education')]);
            newsletter_subscribe($c, '', 'bg', 'manual');   // everything
            $emails = self::$pdo->query("SELECT email FROM newsletter_subscribers WHERE " . newsletter_topic_where((string) $news))
                ->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains($a, $emails);
            $this->assertNotContains($b, $emails);
            $this->assertContains($c, $emails, 'a subscriber who chose no topic gets every campaign');
        } finally {
            foreach ([$a, $b, $c] as $e) $this->deleteSubscriber($e);
        }
    }

    // ── Confirmation-page sign-up ─────────────────────────────────────────────

    private function insertOrder(string $email): string
    {
        $num = 'OM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,lang,customer_name,customer_email,items,subtotal_eur,total_eur,
                                 payment_method,payment_status)
             VALUES (?, 'donation', 'new', 'en', 'Test Buyer', ?, '[]', 10, 10, 'card', 'pending')"
        )->execute([$num, $email]);
        return $num;
    }

    public function test_order_signup_requires_the_session_that_placed_the_order(): void
    {
        $this->skipWithoutDb();
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $email = 'test_order_' . uniqid() . '@example-test.invalid';
        $num   = $this->insertOrder($email);
        $saved = $_SESSION[ORDER_SESSION_KEY] ?? null;
        $edu   = $this->legacyTopic('education');
        try {
            unset($_SESSION[ORDER_SESSION_KEY]);
            $this->assertSame('not_found', newsletter_subscribe_order(self::$pdo, $num, [$edu]),
                'knowing an order number must not be enough');
            $this->assertSame([], $this->topicsOf($email));

            newsletter_remember_order($num);
            $this->assertSame('no_topics', newsletter_subscribe_order(self::$pdo, $num, null));
            $this->assertSame('subscribed', newsletter_subscribe_order(self::$pdo, $num, [$edu]));
            $row = $this->topicsOf($email);
            $this->assertSame('confirmation', $row['source']);
            $this->assertSame([$edu], $row['topics']);
        } finally {
            $_SESSION[ORDER_SESSION_KEY] = $saved;
            self::$pdo->prepare("DELETE FROM orders WHERE order_number=?")->execute([$num]);
            $this->deleteSubscriber($email);
        }
    }

    public function test_remembered_orders_are_capped(): void
    {
        $this->loadNewsletter();
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $saved = $_SESSION[ORDER_SESSION_KEY] ?? null;
        try {
            unset($_SESSION[ORDER_SESSION_KEY]);
            for ($i = 0; $i < 15; $i++) newsletter_remember_order(sprintf('OM-20260101-%04X', $i));
            $this->assertCount(10, $_SESSION[ORDER_SESSION_KEY]);
            $this->assertFalse(newsletter_session_owns_order('OM-20260101-0000'));
            $this->assertTrue(newsletter_session_owns_order('om-20260101-000e'), 'case-insensitive');
        } finally {
            $_SESSION[ORDER_SESSION_KEY] = $saved;
        }
    }

    public function test_order_signup_endpoint_checks_csrf_and_reads_email_from_the_order(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/newsletter/subscribe-order.php');
        $this->assertStringContainsString('csrf_verify()', $src);
        $this->assertStringContainsString('newsletter_subscribe_order(', $src);
        $this->assertStringNotContainsString("\$_POST['email']", $src, 'the email must come from the order, not the request');
    }

    public function test_checkout_and_donation_remember_the_order_for_this_session(): void
    {
        foreach (['checkout/index.php' => '$order_number', 'donation/checkout.php' => '$order_number', 'campaign/checkout.php' => '$pledge_number'] as $rel => $var) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
            $this->assertStringContainsString("order_session_remember($var)", $src, $rel);
        }
    }

    // ── Sign-ups from English pages ───────────────────────────────────────────

    public function test_form_lang_comes_from_the_page_not_the_endpoint_address(): void
    {
        $this->loadNewsletter();
        $this->assertSame('en', newsletter_form_lang(['lang' => 'en']));
        $this->assertSame('bg', newsletter_form_lang(['lang' => 'bg']));
        $this->assertSame(get_lang(), newsletter_form_lang(['lang' => 'fr']));
        $this->assertSame(get_lang(), newsletter_form_lang(['lang' => ['en']]));
        $this->assertSame(get_lang(), newsletter_form_lang([]));
    }

    public function test_every_signup_form_posts_its_page_language(): void
    {
        foreach (['templates/footer.php', 'templates/newsletter-confirm-card.php'] as $rel) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
            $this->assertMatchesRegularExpression('/<input type="hidden" name="lang" value="<\?= h\(\$\w+\) \?>">/', $src, $rel);
        }
        foreach (['newsletter/subscribe.php', 'newsletter/subscribe-order.php'] as $rel) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
            $this->assertStringContainsString('newsletter_form_lang($_POST)', $src, $rel);
        }
    }
}
