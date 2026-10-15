<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Send me news" in the donation form: the choice is recorded with the
 * donation (orders.consents) and the donor is signed up only once the payment
 * has gone through — and never while „Бюлетин“ is off.
 */
final class DonationNewsletterOptInTest extends TestCase
{
    private array $emails = [];

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->emails as $e) {
            get_pdo()->prepare('DELETE FROM newsletter_subscribers WHERE email = ?')->execute([$e]);
        }
    }

    private function needsNewsletter(): void
    {
        if (!module_enabled_with_needs('newsletter')) $this->markTestSkipped('Newsletter module is off on this checkout.');
    }

    /** A form post that opts in, as the site's form would send it: a ticked topic, or the one box. */
    private function optInPost(): array
    {
        $topics = newsletter_topic_list();
        return count($topics) >= 2 ? ['nl_topics' => [(string) $topics[1]['id']]] : ['nl_optin' => '1'];
    }

    public function testTickingIsTheOptIn(): void
    {
        $this->needsNewsletter();
        $post = $this->optInPost();
        $c = newsletter_donation_consent($post);
        $this->assertTrue($c['newsletter']);
        $this->assertSame(newsletter_signup_topics($post), $c['newsletter_topics'], 'the chosen topic ids, as at the checkout');
        $this->assertNotEmpty($c['accepted_at']);
    }

    public function testNoTickNoOptIn(): void
    {
        $c = newsletter_donation_consent([]);
        $this->assertFalse($c['newsletter']);
        $this->assertSame([], $c['newsletter_topics']);
    }

    private function paidDonation(string $email, array $consents, string $type = 'donation'): array
    {
        return [
            'type' => $type, 'customer_email' => $email, 'customer_name' => 'Дарител Тест', 'lang' => 'en',
            'consents' => json_encode($consents),
        ];
    }

    public function testPaidDonationWithOptInSubscribes(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB available.');
        $this->needsNewsletter();
        $email = 'phpunit-don-nl-' . bin2hex(random_bytes(4)) . '@test.invalid';
        $this->emails[] = $email;

        $post   = $this->optInPost();
        $status = newsletter_subscribe_paid_donation($this->paidDonation($email, newsletter_donation_consent($post)));
        $this->assertSame('subscribed', $status);

        $st = get_pdo()->prepare('SELECT id, source, lang FROM newsletter_subscribers WHERE email = ?');
        $st->execute([$email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('donation', $row['source']);
        $this->assertSame('en', $row['lang']);
        $this->assertSame(newsletter_signup_topics($post), newsletter_subscriber_topic_ids(get_pdo(), (int) $row['id']),
            'signed up for the topic the donor ticked (none listed = every topic)');
    }

    public function testATopicRemovedSinceTheDonationIsIgnored(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB available.');
        $this->needsNewsletter();
        $email = 'phpunit-don-nl-' . bin2hex(random_bytes(4)) . '@test.invalid';
        $this->emails[] = $email;
        $consents = ['newsletter' => true, 'newsletter_topics' => [999999], 'accepted_at' => date('c')];
        $this->assertSame('subscribed', newsletter_subscribe_paid_donation($this->paidDonation($email, $consents)));
    }

    public function testNoOptInOrNotADonationSubscribesNobody(): void
    {
        $email = 'phpunit-don-nl-' . bin2hex(random_bytes(4)) . '@test.invalid';
        $this->assertSame('skipped', newsletter_subscribe_paid_donation($this->paidDonation($email, newsletter_donation_consent([]))));
        $this->assertSame('skipped', newsletter_subscribe_paid_donation($this->paidDonation($email, ['newsletter' => true, 'newsletter_topics' => []], 'physical')));
        $this->assertSame('skipped', newsletter_subscribe_paid_donation(['type' => 'donation', 'customer_email' => $email, 'consents' => null]));
    }

    public function testWiredThroughFormCheckoutAndPayment(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $form = (string) file_get_contents("$root/templates/donation-form.php");
        $this->assertStringContainsString("module_enabled_with_needs('newsletter')", $form, 'the tick appears only while the newsletter is on');
        $this->assertStringContainsString('templates/newsletter-topics.php', $form);
        $this->assertStringContainsString("te('donation.newsletter_optin'", $form, 'the heading is editable in Edit mode');

        $checkout = (string) file_get_contents("$root/donation/checkout.php");
        $this->assertStringContainsString('newsletter_donation_consent($_POST)', $checkout);
        $this->assertStringContainsString('invoice_data,consents)', $checkout, 'the choice is stored with the donation');
        $this->assertStringNotContainsString('newsletter_subscribe(', $checkout, 'nobody is signed up before paying');

        $this->assertStringContainsString('newsletter_subscribe_paid_donation($order)', (string) file_get_contents("$root/includes/payment/process_payment.php"));
        require_once "$root/includes/donation.php";
        $this->assertContains('nl_topics', DONATION_FORM_FIELDS, 'the ticks survive a form sent back with errors');
        start_session();
        donation_form_fail(['x'], ['nl_topics' => ['3', '5'], 'nl_optin' => '1']);
        $old = donation_form_state()['old'];
        $this->assertSame([3, 5], $old['nl_topics'], 'kept as a list of ids, not flattened to text');
        $this->assertSame('1', $old['nl_optin']);
    }

    public function testDonationPageShowsTheTick(): void
    {
        $this->needsNewsletter();
        if (!module_enabled_with_needs('donations')) $this->markTestSkipped('Donations module is off on this checkout.');
        foreach (['donation/index.php', 'en/donation/index.php'] as $page) {
            $r = run_admin_page($page, [], ['method' => 'GET', 'role' => null]);
            $this->assertSame(200, $r['status'], $page);
            $this->assertMatchesRegularExpression('/name="(nl_topics\\[\\]|nl_optin)"/', $r['body'], $page);
        }
    }
}

