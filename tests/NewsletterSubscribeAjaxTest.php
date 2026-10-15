<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The footer band signs up without a page reload: newsletter/subscribe.php
 * answers in JSON when asked (ajax=1 / Accept / X-Requested-With) and keeps the
 * old flash + redirect flow for a plain form post (no JavaScript).
 */
final class NewsletterSubscribeAjaxTest extends TestCase
{
    private array $emails = [];

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB available.');
        if (!module_enabled_with_needs('newsletter')) $this->markTestSkipped('Newsletter module is off on this checkout.');
    }

    protected function tearDown(): void
    {
        foreach ($this->emails as $e) {
            get_pdo()->prepare('DELETE FROM newsletter_subscribers WHERE email = ?')->execute([$e]);
        }
    }

    private function post(array $post, array $opts = []): array
    {
        return run_admin_page('newsletter/subscribe.php', $post + ['lang' => 'bg'], $opts + ['role' => null]);
    }

    private function json(array $r): array
    {
        $data = json_decode(trim($r['body']), true);
        $this->assertIsArray($data, 'expected a JSON reply, got: ' . $r['body']);
        return $data;
    }

    public function testBadEmailAnswersInJsonAndPointsAtTheField(): void
    {
        $r = $this->post(['ajax' => '1', 'email' => 'not-an-email', 'wants_news' => '1']);
        $this->assertSame(422, $r['status']);
        $d = $this->json($r);
        $this->assertFalse($d['ok']);
        $this->assertSame('email', $d['field']);
        $this->assertNotSame('', $d['message']);
        $this->assertSame([], $r['flash'], 'a JSON answer must not also leave a flash for the next page');
    }

    public function testNoTopicAnswersInJson(): void
    {
        $r = $this->post(['ajax' => '1', 'email' => 'nobody@test.invalid']);
        $this->assertSame(422, $r['status']);
        $this->assertSame('topics', $this->json($r)['field']);
    }

    public function testExpiredFormAnswersInJson(): void
    {
        $r = $this->post(['ajax' => '1', 'email' => 'nobody@test.invalid', 'wants_news' => '1'], ['csrf' => 'wrong']);
        $this->assertSame(400, $r['status']);
        $this->assertFalse($this->json($r)['ok']);
    }

    public function testEnglishFormAnswersInEnglish(): void
    {
        $r = $this->post(['ajax' => '1', 'lang' => 'en', 'email' => 'bad']);
        $this->assertSame(t_or('newsletter.err.email', 'Невалиден имейл адрес.', 'Invalid email address.', 'en'), $this->json($r)['message']);
    }

    public function testValidSignUpSubscribesAndAnswersOk(): void
    {
        $email = 'phpunit-nl-ajax-' . bin2hex(random_bytes(4)) . '@test.invalid';
        $this->emails[] = $email;
        $r = $this->post(['ajax' => '1', 'email' => $email, 'wants_news' => '1']);
        $this->assertSame(200, $r['status'], $r['body']);
        $d = $this->json($r);
        $this->assertTrue($d['ok']);
        $st = get_pdo()->prepare('SELECT source FROM newsletter_subscribers WHERE email = ?');
        $st->execute([$email]);
        $this->assertSame('web_banner', $st->fetchColumn());
    }

    public function testPlainPostStillRedirectsWithAMessage(): void
    {
        $r = $this->post(['email' => 'not-an-email', 'wants_news' => '1']);
        $this->assertSame(302, $r['status']);
        $this->assertSame(['error'], array_column($r['flash'], 'type'));
    }

    public function testFooterBandAnnouncesTheResultInPlace(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php');
        $this->assertStringContainsString('id="nlBannerForm"', $src);
        $this->assertMatchesRegularExpression('/id="nlBannerOk" role="status"/', $src);
        $this->assertMatchesRegularExpression('/id="nlBannerErr" role="alert"/', $src);
        $this->assertStringContainsString("data.append('ajax', '1')", $src);
        $this->assertStringContainsString('form.submit()', $src, 'a failed request must fall back to the full-page post');
        // The form still works without JavaScript: a real action and the CSRF field.
        $this->assertStringContainsString('action="/newsletter/subscribe.php" id="nlBannerForm"', $src);
        $this->assertStringContainsString('csrf_field()', $src);
    }
}
