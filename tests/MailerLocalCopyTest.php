<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A copy of the site on someone's computer (dev server, test run, rehearsal)
 * never sends mail — its test orders used to email the real team.
 */
final class MailerLocalCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        mail_set_smtp_sender(null);
    }

    /** @return array<string,array{string}> */
    public static function localUrls(): array
    {
        return [
            'localhost'          => ['http://localhost'],
            'localhost + port'   => ['http://localhost:8080'],
            'loopback ip'        => ['http://127.0.0.1:8775'],
            'other loopback'     => ['http://127.0.1.1'],
            'ipv6 loopback'      => ['http://[::1]:8000'],
            '.test (Herd)'       => ['http://oddminds.test'],
            '.localhost'         => ['https://app.localhost'],
            '.local'             => ['http://ngo.local'],
            'upper case'         => ['HTTP://LOCALHOST:8080'],
        ];
    }

    #[DataProvider('localUrls')]
    public function test_local_addresses_are_local(string $url): void
    {
        $this->assertTrue(mail_site_is_local($url));
    }

    /** @return array<string,array{string}> */
    public static function realUrls(): array
    {
        return [
            'real site'          => ['https://oddminds.org'],
            'www'                => ['https://www.lafetki.com'],
            'example.org'        => ['https://example.org'],
            'test in the name'   => ['https://test.example.org'],
            'localhost in path'  => ['https://ngo.org/localhost'],
            'lookalike tld'      => ['https://localhost.com'],
            'empty'              => [''],
            'garbage'            => ['not a url'],
        ];
    }

    #[DataProvider('realUrls')]
    public function test_real_addresses_are_not_local(string $url): void
    {
        $this->assertFalse(mail_site_is_local($url));
    }

    public function test_local_copy_is_blocked(): void
    {
        $this->assertTrue(mail_blocked_as_local_copy('http://localhost:8080'));
        $this->assertFalse(mail_blocked_as_local_copy('https://oddminds.org'));
    }

    public function test_a_fake_smtp_sender_is_not_blocked(): void
    {
        // Tests that swap in a fake sender never reach a real server.
        mail_set_smtp_sender(fn (PHPMailer $m): bool => true);
        $this->assertFalse(mail_blocked_as_local_copy('http://localhost:8080'));
    }

    public function test_php_built_in_server_is_blocked_even_with_the_live_address(): void
    {
        $this->assertTrue(mail_blocked_as_local_copy('https://ngo.org', 'cli-server'));
        $this->assertFalse(mail_blocked_as_local_copy('https://ngo.org', 'fpm-fcgi'));
        $this->assertFalse(mail_blocked_as_local_copy('https://ngo.org', 'cli'));
    }

    public function test_the_test_run_itself_never_sends_mail(): void
    {
        $this->assertTrue(defined('MAIL_DISABLED') && MAIL_DISABLED);
        $this->assertFalse(send_mail('someone@example.org', 'test', '<p>test</p>'));
    }

    public function test_send_mail_checks_for_a_local_copy_before_any_transport(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__) . '/includes/mailer.php');
        $body = substr($src, (int) strpos($src, 'function send_mail('));
        $guard = strpos($body, 'mail_blocked_as_local_copy(');
        $this->assertNotFalse($guard, 'send_mail must refuse on a local copy');
        $this->assertLessThan(strpos($body, 'mail_send_smtp('), $guard);
        $this->assertLessThan(strpos($body, 'mail_send_graph('), $guard);
    }

    public function test_staff_get_a_plain_explanation(): void
    {
        $msg = mail_explain_error('Not sent: ' . MAIL_LOCAL_COPY_ERROR_MARK . ' (http://localhost:8080)');
        $this->assertStringContainsString('копие на сайта', $msg);
        $this->assertStringNotContainsString('localhost', $msg);
    }
}
