<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FEATURE_* module flags.
 *
 * The behavioural half of this (does /campaign/ really 404?) needs a live server
 * and a site.config.php with the flag off, so it is not asserted here. What IS
 * asserted is that every entry point still carries its guard: the failure mode
 * this protects against is someone adding a new campaign page, or refactoring an
 * existing one, and quietly leaving it reachable with the module switched off.
 */
final class FeatureFlagsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    public function test_missing_constant_means_enabled(): void
    {
        // An install predating a flag has no constant for it and must keep working.
        $this->assertFalse(defined('FEATURE_NO_SUCH_MODULE'));
        $this->assertTrue(feature_enabled('no_such_module'));
    }

    public function test_name_is_case_insensitive_and_maps_to_the_constant(): void
    {
        $this->assertTrue(defined('FEATURE_CAMPAIGN'), 'site.config.example.php should define FEATURE_CAMPAIGN');
        $expected = (bool) constant('FEATURE_CAMPAIGN');

        $this->assertSame($expected, feature_enabled('campaign'));
        $this->assertSame($expected, feature_enabled('CAMPAIGN'));
        $this->assertSame($expected, feature_enabled('CaMpAiGn'));
    }

    /** Entry points that must become unreachable when the module is off. */
    public static function gatedEntryPoints(): array
    {
        return [
            ['campaign/index.php'],
            ['campaign/checkout.php'],
            ['campaign/confirmation/index.php'],
            ['campaign/payment-failed/index.php'],
            ['tickets/index.php'],
            ['api/campaign-payment-return.php'],
            ['admin/campaign.php'],
            ['admin/campaign-backers.php'],
            ['admin/pledge-view.php'],
            ['admin/ticket-checklist.php'],
        ];
    }

    #[DataProvider('gatedEntryPoints')]
    public function test_entry_point_is_gated(string $rel): void
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        $this->assertIsString($src, "$rel should exist");

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*feature_enabled\(\s*[\'"]campaign[\'"]\s*\)\s*\)\s*\{/',
            $src,
            "$rel must refuse the request when the campaign module is off"
        );
        $this->assertStringContainsString(
            "/errors/404.php",
            $src,
            "$rel should answer with a 404, not a redirect"
        );
    }

    /** Surfaces that must stop advertising the module, without being removed. */
    public static function gatedSurfaces(): array
    {
        return [
            ['templates/home/campaign.php'],
            ['admin/home-sections.php'],
            ['kak-da-pomogna/index.php'],
            ['en/how-to-help/index.php'],
            ['templates/header.php'],
            ['admin/includes/admin-header.php'],
            ['admin/dashboard.php'],
            ['admin/pages.php'],
        ];
    }

    #[DataProvider('gatedSurfaces')]
    public function test_surface_consults_the_flag(string $rel): void
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        $this->assertIsString($src, "$rel should exist");
        $this->assertStringContainsString(
            "feature_enabled('campaign')",
            $src,
            "$rel links to the campaign module and must check the flag first"
        );
    }

    /**
     * The order/payment/document plumbing takes real card payments, and the
     * order and ticket views are how past pledges stay legible afterwards.
     * Both are deliberately NOT gated — switching the module off makes the
     * surface unreachable, it does not unpick the code that settled past
     * pledges or hide the records they produced.
     */
    public static function ungatedPlumbing(): array
    {
        return [
            ['checkout/index.php'],
            ['includes/pledge_shipping.php'],
            ['includes/pledge_documents.php'],
            ['includes/order_view.php'],
            ['admin/orders.php'],
            // Past tickets stay downloadable with the module off, so neither the
            // endpoint nor the button that points at it may consult the flag.
            ['admin/order-view.php'],
            ['admin/download-ticket.php'],
        ];
    }

    #[DataProvider('ungatedPlumbing')]
    public function test_plumbing_is_left_alone(string $rel): void
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        $this->assertIsString($src, "$rel should exist");
        $this->assertStringNotContainsString(
            "feature_enabled('campaign')",
            $src,
            "$rel is payment/history plumbing and must keep working for existing pledges"
        );
    }
}
