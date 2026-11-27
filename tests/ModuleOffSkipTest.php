<?php
declare(strict_types=1);

use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * run_admin_page() skips a test whose page belongs to a module this site has
 * switched off, instead of letting it fail — so a site with Кампания or Дарения
 * off can run the whole suite. NGO_TEST_MODULES_OFF switches modules off for
 * the test and the page process it starts.
 */
final class ModuleOffSkipTest extends TestCase
{
    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('needs the local DB');
    }

    protected function tearDown(): void
    {
        putenv('NGO_TEST_MODULES_OFF');
    }

    public function test_the_switch_turns_only_the_named_modules_off(): void
    {
        $own = feature_enabled('campaign');
        putenv('NGO_TEST_MODULES_OFF=campaign, social');
        $this->assertFalse(feature_enabled('campaign'));
        $this->assertFalse(feature_enabled('SOCIAL'), 'case and spaces do not matter');
        putenv('NGO_TEST_MODULES_OFF');
        $this->assertSame($own, feature_enabled('campaign'), 'unset: the site\'s own switch again');
    }

    public function test_an_admin_page_of_a_switched_off_module_skips_the_test(): void
    {
        putenv('NGO_TEST_MODULES_OFF=campaign');
        try {
            run_admin_page('admin/campaign.php', [], ['method' => 'GET']);
            $this->fail('expected the test to be skipped');
        } catch (SkippedWithMessageException $e) {
            $this->assertStringContainsString('admin/campaign.php', $e->getMessage());
        }
    }

    public function test_a_json_endpoint_of_a_switched_off_module_skips_the_test(): void
    {
        putenv('NGO_TEST_MODULES_OFF=social');
        try {
            run_admin_page('admin/buffer-setup-ajax.php', []);
            $this->fail('expected the test to be skipped');
        } catch (SkippedWithMessageException $e) {
            $this->assertStringContainsString('admin/buffer-setup-ajax.php', $e->getMessage());
        }
    }

    public function test_module_off_ok_returns_the_module_off_page(): void
    {
        putenv('NGO_TEST_MODULES_OFF=campaign');
        $r = run_admin_page('admin/campaign.php', [], ['method' => 'GET', 'module_off_ok' => true]);
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('Този модул е изключен', $r['body']);
    }

    public function test_with_the_module_on_the_page_opens_as_usual(): void
    {
        if (!feature_enabled('campaign')) $this->markTestSkipped('Кампания is off on this site');
        $r = run_admin_page('admin/campaign.php', [], ['method' => 'GET']);
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('Този модул е изключен', $r['body']);
    }
}
