<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * templates/social-links.php: a network with no profile set is left out, not
 * shown as a button with an empty link (lafetki's footer had a LinkedIn
 * button that just reloaded the page).
 */
final class SocialLinksTest extends TestCase
{
    private static function render(string $variant): string
    {
        $social_variant = $variant;
        $lang = 'bg';
        ob_start();
        require dirname(__DIR__) . '/templates/social-links.php';
        return (string) ob_get_clean();
    }

    public function test_only_networks_with_a_profile_get_a_button(): void
    {
        $set = array_filter([SOCIAL_FACEBOOK, SOCIAL_INSTAGRAM, SOCIAL_LINKEDIN], fn($u) => trim((string) $u) !== '');
        foreach (['footer', 'outline', 'primary', 'bar'] as $variant) {
            $html = self::render($variant);
            $this->assertStringNotContainsString('href=""', $html, $variant);
            $this->assertSame(count($set), substr_count($html, '<a '), $variant);
        }
    }

    public function test_the_template_drops_empty_profile_urls(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/templates/social-links.php');
        $this->assertMatchesRegularExpression("/array_filter\(\\\$_social_links, fn\(\\\$l\) => trim\(\(string\) \\\$l\['url'\]\) !== ''\)/", $src);
    }
}
