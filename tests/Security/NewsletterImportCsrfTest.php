<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The customer → newsletter import subscribes everyone who ever paid. It used to
 * run on a plain link (?confirm=yes), so any page with an <img> pointing there
 * could trigger it while an admin was logged in. It must only run on the
 * confirmation button's POST, after the CSRF check.
 */
final class NewsletterImportCsrfTest extends TestCase
{
    private static function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/newsletter-import.php');
    }

    public function test_a_link_can_no_longer_run_the_import(): void
    {
        $this->assertStringNotContainsString('$_GET', self::src());
        $this->assertStringNotContainsString('confirm=yes', self::src());
    }

    public function test_the_import_runs_only_after_a_post_and_the_csrf_check(): void
    {
        $src = self::src();
        $post      = strpos($src, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $csrf      = strpos($src, 'if (!csrf_verify())');
        $subscribe = strpos($src, 'newsletter_subscribe(');
        $this->assertNotFalse($post, 'a GET only shows the confirmation page');
        $this->assertNotFalse($csrf, 'the POST checks the CSRF token');
        $this->assertNotFalse($subscribe);
        $this->assertLessThan($csrf, $post);
        $this->assertLessThan($subscribe, $csrf);
    }

    public function test_the_confirm_button_posts_the_token(): void
    {
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/admin/newsletter-import\.php"[^>]*>\s*<\?= csrf_field\(\) \?>#',
            self::src()
        );
    }
}
