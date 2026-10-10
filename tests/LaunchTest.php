<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Going live (includes/launch.php): what a site needs before visitors see it,
 * and which addresses a closed site still serves.
 */
final class LaunchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/launch.php';
    }

    private static function item(array $list, string $key): ?array
    {
        foreach ($list as $i) if ($i['key'] === $key) return $i;
        return null;
    }

    public function test_sites_from_before_the_launch_switch_are_live(): void
    {
        if (defined('SITE_LAUNCHED')) $this->markTestSkipped('this checkout sets SITE_LAUNCHED');
        $this->assertTrue(site_launched());
    }

    public function test_the_legal_pages_and_registration_are_required(): void
    {
        $list = launch_checklist(['legal' => []], true);
        foreach (['legal_name', 'eik', 'address', 'privacy', 'cookies', 'legal_info'] as $k) {
            $this->assertNotNull(self::item($list, $k), "$k is on the list");
            $this->assertTrue(self::item($list, $k)['required']);
            $this->assertNotSame('', self::item($list, $k)['why'], "$k says why in plain words");
            $this->assertStringStartsWith('/admin/', self::item($list, $k)['href']);
        }
        $this->assertSame(module_any_enabled('shop', 'donations', 'campaign', 'events'), self::item($list, 'terms') !== null,
            'terms of sale only when the site takes money');
    }

    public function test_a_legal_text_counts_once_it_has_words(): void
    {
        $this->assertFalse(launch_text_filled(['privacy' => '<p>&nbsp;</p> <br>'], 'privacy'), 'an emptied editor is still empty');
        $this->assertTrue(launch_text_filled(['privacy' => '<p>Събираме само имейл.</p>'], 'privacy'));
        $done = launch_checklist(['legal' => ['privacy' => '<p>Текст</p>', 'cookie_policy_bg' => '<p>Текст</p>']], true);
        $this->assertTrue(self::item($done, 'privacy')['done']);
        $this->assertTrue(self::item($done, 'cookies')['done'], 'the cookie policy is stored as cookie_policy_bg');
    }

    public function test_only_required_skips_the_database_checks(): void
    {
        foreach (launch_checklist(['legal' => []], true) as $i) $this->assertTrue($i['required']);
    }

    public function test_a_closed_site_still_serves_the_admin_and_callbacks(): void
    {
        foreach (['/admin/', '/admin/login.php', '/install/', '/api/payment-return.php', '/assets/css/main.css', '/robots.txt'] as $p) {
            $this->assertTrue(launch_path_is_open($p), $p);
        }
        foreach (['/', '/magazin/', '/en/', '/novini/x/', '/administrator'] as $p) {
            $this->assertSame($p === '/administrator', launch_path_is_open($p), $p);
        }
    }

    public function test_eik_check_digits(): void
    {
        $this->assertSame('000696327', org_eik_normalize('000 696 327'), 'a real ЕИК (Столична община)');
        $this->assertSame('175074752', org_eik_normalize('BG175074752'), 'the VAT prefix is dropped');
        $this->assertSame('1750747521234', org_eik_normalize('1750747521234'), 'a 13-digit БУЛСТАТ');
        $this->assertNull(org_eik_normalize('000696328'), 'one wrong digit');
        $this->assertNull(org_eik_normalize('1750747521235'));
        $this->assertNull(org_eik_normalize('12345'));
        $this->assertNull(org_eik_normalize('abcdefghi'));
    }

    public function test_the_form_never_posts_the_launch_state(): void
    {
        $this->assertArrayHasKey('site_launched', org_system_fields());
        $r = org_validate(['site_launched' => '1'], array_keys(brand_themes()));
        $this->assertArrayNotHasKey('site_launched', $r['values'], 'saving Организация can not open or close the site');
    }

    public function test_new_sites_start_closed(): void
    {
        require_once dirname(__DIR__) . '/install/wizard-lib.php';
        $c = wizard_site_config([
            'account' => ['admin_email' => 'a@example.org'],
            'org'     => ['site_name_bg' => 'П', 'site_name_en' => '', 'site_url' => 'https://example.org', 'site_email' => 'a@example.org'],
            'look'    => ['brand_theme' => 'classic', 'brand_primary' => '#000000', 'brand_accent' => '#111111'],
            'modules' => ['modules' => []],
        ]);
        $this->assertFalse($c['SITE_LAUNCHED']);
    }
}
