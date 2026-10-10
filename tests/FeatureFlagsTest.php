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

    /** Entry points that must become unreachable when their module is off: [file, guard, module]. */
    public static function gatedEntryPoints(): array
    {
        return [
            ['campaign/index.php', 'public', 'campaign'],
            ['campaign/checkout.php', 'public', 'campaign'],
            ['campaign/confirmation/index.php', 'public', 'campaign'],
            ['campaign/payment-failed/index.php', 'public', 'campaign'],
            ['api/campaign-payment-return.php', 'public', 'campaign'],
            ['admin/campaign.php', 'admin', 'campaign'],
            ['admin/campaign-backers.php', 'admin', 'campaign'],
            ['admin/pledge-view.php', 'admin', 'campaign'],
            ['sabitiya/index.php', 'public', 'events'],
            ['sabitiya/checkout.php', 'public', 'events'],
            ['sabitiya/confirmation/index.php', 'public', 'events'],
            ['tickets/index.php', 'public', 'events'],
            ['api/event-payment-return.php', 'public', 'events'],
            ['admin/events.php', 'admin', 'events'],
            ['admin/event-edit.php', 'admin', 'events'],
            ['admin/ticket-checklist.php', 'admin', 'events'],
        ];
    }

    #[DataProvider('gatedEntryPoints')]
    public function test_entry_point_is_gated(string $rel, string $kind, string $module): void
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        $this->assertIsString($src, "$rel should exist");

        // module_public_guard() answers with the site's 404, module_admin_guard()
        // with "Този модул е изключен" — see includes/modules.php.
        $guard = $kind === 'public' ? "module_public_guard('$module');" : "module_admin_guard('$module');";
        $this->assertStringContainsString($guard, $src, "$rel must refuse the request when the $module module is off");
        if ($kind === 'admin') {
            $auth = preg_match('/admin_require_(admin|login|shop|editorial)\(\);/', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
            $this->assertNotFalse($auth, "$rel checks who is asking");
            $this->assertLessThan(strpos($src, $guard), $auth, "$rel: auth first, so a visitor cannot learn which modules a site runs");
        }
    }

    /** Surfaces that must stop advertising the module, without being removed. */
    public static function gatedSurfaces(): array
    {
        return [
            ['templates/home/campaign.php'],
            ['admin/home-sections.php'],
            ['kak-da-pomogna/index.php'],
            ['en/how-to-help/index.php'],
            ['includes/menus.php'], // the BG↔EN address map the header's language switch uses
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

    public function test_admin_menu_hides_module_pages_through_the_registry(): void
    {
        $src = (string) file_get_contents(self::root() . '/admin/includes/admin-header.php');
        $this->assertStringContainsString("module_admin_page_visible('campaign.php')", $src);
        $this->assertStringContainsString("module_admin_page_visible('events.php')", $src);
        $this->assertStringNotContainsString("feature_enabled('campaign')", $src, 'no hard-coded module ifs in the menu');
        $this->assertStringNotContainsString("feature_enabled('events')", $src, 'no hard-coded module ifs in the menu');
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
        $this->assertStringNotContainsString("feature_enabled('events')", $src, "$rel must keep working for tickets already sold");
        $this->assertStringNotContainsString("module_admin_guard('events')", $src, "$rel must keep working for tickets already sold");
    }
}
