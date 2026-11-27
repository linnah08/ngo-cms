<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Starting texts for the legal pages (includes/legal_templates.php): built from
 * the organisation's data, covering only what the site does, with gaps marked.
 */
final class LegalTemplatesTest extends TestCase
{
    private const KEYS = ['privacy', 'cookies', 'legal_info', 'terms'];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/legal_templates.php';
        require_once dirname(__DIR__) . '/includes/launch.php';
    }

    private static function facts(array $over = []): array
    {
        return array_merge([
            'name' => 'Фондация Проба', 'eik' => '000696327', 'address' => 'гр. София, ул. Проба 1',
            'email' => '<a href="mailto:info@example.org">info@example.org</a>', 'phone' => '', 'mol' => 'Иван Иванов',
            'url' => 'https://example.org', 'privacy' => '/politika-za-poveritelnost/', 'cookies' => '/politika-za-biskvitki/',
        ], $over);
    }

    private static function scope(array $on = []): array
    {
        return array_merge(['shop' => false, 'donations' => false, 'campaign' => false, 'events' => false, 'newsletter' => false,
            'comments' => false, 'analytics' => false, 'turnstile' => false, 'money' => false,
            'cart_reminders' => false, 'cart_cookie' => false], $on);
    }

    public function test_every_page_has_a_text_in_both_languages_with_the_organisations_details(): void
    {
        foreach (self::KEYS as $k) {
            foreach (['bg', 'en'] as $lang) {
                $t = legal_template($k, $lang, self::facts(), self::scope(['shop' => true, 'money' => true]));
                $this->assertStringContainsString('<h2>', $t, "$k/$lang");
                if ($k !== 'cookies') $this->assertStringContainsString('000696327', $t, "$k/$lang names the ЕИК");
            }
        }
        $this->assertSame('', legal_template('nope', 'bg'));
    }

    public function test_missing_details_become_highlighted_gaps(): void
    {
        if (defined('SITE_EIK') && SITE_EIK !== '') $this->markTestSkipped('this checkout has an ЕИК');
        $this->assertStringContainsString(LEGAL_GAP_OPEN . 'ЕИК]</mark>', legal_facts('bg')['eik']);
        $this->assertStringContainsString(LEGAL_GAP_OPEN, legal_facts('en')['eik']);
    }

    public function test_the_organisations_details_are_escaped(): void
    {
        $this->assertStringNotContainsString('<script>', legal_gap('<script>'));
    }

    public function test_texts_cover_only_what_the_site_does(): void
    {
        $plain = legal_template('terms', 'bg', self::facts(), self::scope(['donations' => true, 'money' => true]));
        $this->assertStringNotContainsString('Право на отказ', $plain, 'no shop, no withdrawal rules');
        $this->assertStringContainsString('Дарения', $plain);

        $shop = legal_template('terms', 'bg', self::facts(), self::scope(['shop' => true, 'events' => true, 'money' => true]));
        $this->assertStringContainsString('чл. 50 от Закона за защита на потребителите', $shop);
        $this->assertStringContainsString('чл. 57, т. 12', $shop, 'tickets for a dated event have no 14-day withdrawal');

        $cookies = legal_template('cookies', 'bg', self::facts(), self::scope());
        $this->assertStringNotContainsString('_ga', $cookies, 'no analytics set up, none described');
        $this->assertStringNotContainsString('om_nl_sub', $cookies);
        $with = legal_template('cookies', 'en', self::facts(), self::scope(['analytics' => true, 'newsletter' => true]));
        $this->assertStringContainsString('_ga', $with);
        $this->assertStringContainsString('om_nl_sub', $with);

        $privacy = legal_template('privacy', 'bg', self::facts(), self::scope());
        $this->assertStringNotContainsString('куриер', $privacy);
        $this->assertStringNotContainsString('Google —', $privacy);
    }

    public function test_no_text_belongs_to_one_organisation(): void
    {
        foreach (self::KEYS as $k) {
            foreach (['bg', 'en'] as $lang) {
                $t = legal_template($k, $lang, self::facts(), self::scope(array_fill_keys(['shop', 'donations', 'campaign', 'events', 'newsletter', 'comments', 'analytics', 'turnstile', 'money'], true)));
                foreach (['Различни умове', 'Odd Minds', 'oddminds', 'салфетки', 'деца', 'children'] as $w) {
                    $this->assertStringNotContainsStringIgnoringCase($w, $t, "$k/$lang");
                }
            }
        }
    }

    public function test_a_text_with_gaps_left_is_not_done(): void
    {
        $gappy = ['privacy' => '<p>Текст ' . legal_gap('напр. до 2 години') . '</p>'];
        $item  = array_values(array_filter(launch_checklist(['legal' => $gappy], true), fn($i) => $i['key'] === 'privacy'))[0];
        $this->assertFalse($item['done']);
        $this->assertStringContainsString('в жълто', $item['why']);
        $item  = array_values(array_filter(launch_checklist(['legal' => ['privacy' => '<p>Готов текст</p>']], true), fn($i) => $i['key'] === 'privacy'))[0];
        $this->assertTrue($item['done']);
    }

    public function test_privacy_has_cart_reminder_paragraph_only_when_email_switch_on(): void
    {
        $off = self::scope(['shop' => true, 'money' => true, 'cart_cookie' => true]);
        $on  = self::scope(['shop' => true, 'money' => true, 'cart_cookie' => true, 'cart_reminders' => true]);
        foreach (['bg' => ['Напомняне за поръчка', 'до 30 дни', 'Не ми напомняйте', 'с отметката при поръчката'],
                  'en' => ['Order reminder', 'up to 30 days', 'Don\'t remind me', 'with the checkbox at checkout']] as $lang => $needles) {
            $without = legal_template('privacy', $lang, self::facts(), $off);
            $with    = legal_template('privacy', $lang, self::facts(), $on);
            foreach ($needles as $n) {
                $this->assertStringNotContainsString($n, $without, "$lang: $n absent when off");
                $this->assertStringContainsString($n, $with, "$lang: $n present when on");
            }
        }
    }

    public function test_cookie_table_lists_om_cart_only_when_a_switch_is_on(): void
    {
        foreach (['bg' => ['Запомня количката ви, за да можете да се върнете към нея.', '30 дни'],
                  'en' => ['Remembers your basket so you can come back to it.', '30 days']] as $lang => $texts) {
            $off = legal_template('cookies', $lang, self::facts(), self::scope(['shop' => true]));
            $on  = legal_template('cookies', $lang, self::facts(), self::scope(['shop' => true, 'cart_cookie' => true]));
            $this->assertStringNotContainsString('om_cart', $off, $lang);
            $this->assertStringContainsString('om_cart', $on, $lang);
            foreach ($texts as $t) $this->assertStringContainsString($t, $on, $lang);
        }
    }

    public function test_cart_switches_drive_the_live_scope(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        require_once dirname(__DIR__) . '/includes/settings.php';
        $old = [setting_get('cart_reminder_email', ''), setting_get('cart_reminder_notice', '')];
        try {
            setting_set('cart_reminder_email', '0'); setting_set('cart_reminder_notice', '1');
            $s = legal_scope();
            $this->assertFalse($s['cart_reminders']);
            setting_set('cart_reminder_email', '1');
            $s = legal_scope();
            $shopOn = function_exists('module_enabled_with_needs') && module_enabled_with_needs('shop');
            $this->assertSame($shopOn, $s['cart_reminders']);
            $this->assertSame($shopOn, $s['cart_cookie']);
        } finally {
            setting_set('cart_reminder_email', $old[0]); setting_set('cart_reminder_notice', $old[1]);
        }
    }
}
