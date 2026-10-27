<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';

/** The call-to-action block's optional third button, to the shop. */
final class HomeCtaShopButtonTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_show_admin_bar'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['_show_admin_bar'] = false;
    }

    private function render(array $fields, string $lang = 'bg'): string
    {
        ob_start();
        home_render(['version' => 1, 'rev' => 1, 'sections' => [
            ['id' => 's_cta', 'type' => 'cta', 'visible' => true, 'fields' => $fields + [
                'heading'    => ['bg' => 'Подкрепете ни', 'en' => 'Support us'],
                'btn1_label' => ['bg' => 'Дарете', 'en' => 'Donate'], 'btn1_url' => ['bg' => '/donation/', 'en' => '/en/donation/'],
                'btn2_label' => ['bg' => 'Как да помогна', 'en' => 'How to help'], 'btn2_url' => ['bg' => '/kak-da-pomogna/', 'en' => '/en/how-to-help/'],
                'background' => 'teal',
            ]],
        ]], $lang);
        return (string) ob_get_clean();
    }

    public function test_the_block_offers_the_switch_off_by_default(): void
    {
        $fields = home_types()['cta']['fields'];
        $this->assertSame('choice', $fields['shop_button']['kind']);
        $this->assertSame('off', $fields['shop_button']['default']);
        $this->assertSame('text', $fields['shop_label']['kind']);
        // A block saved before the switch existed reads as off.
        $this->assertSame(['off', []], home_clean_field($fields['shop_button'], null, 'shop_button'));
    }

    public function test_off_by_default_there_is_no_shop_button(): void
    {
        $this->assertStringNotContainsString('href="/magazin/"', $this->render([]));
        $this->assertStringNotContainsString('href="/magazin/"', $this->render(['shop_button' => 'off']));
    }

    public function test_switched_on_it_sits_between_the_two_buttons_with_the_default_wording(): void
    {
        $html = $this->render(['shop_button' => 'on']);
        $this->assertStringContainsString('Разгледай магазина</a>', $html);
        $donate = strpos($html, 'href="/donation/"');
        $shop   = strpos($html, 'href="/magazin/"');
        $help   = strpos($html, 'href="/kak-da-pomogna/"');
        $this->assertTrue($donate < $shop && $shop < $help, 'order: donate, shop, how to help');

        $en = $this->render(['shop_button' => 'on'], 'en');
        $this->assertStringContainsString('href="/en/shop/"', $en);
        $this->assertStringContainsString('Visit the shop</a>', $en);
    }

    public function test_its_own_wording_wins_and_is_escaped(): void
    {
        $html = $this->render(['shop_button' => 'on', 'shop_label' => ['bg' => 'Към <магазина>', 'en' => '']], 'en');
        // An empty English label falls back to the English default, not to the Bulgarian text.
        $this->assertStringContainsString('Visit the shop</a>', $html);
        $bg = $this->render(['shop_button' => 'on', 'shop_label' => ['bg' => 'Към <магазина>', 'en' => '']]);
        $this->assertStringContainsString('Към &lt;магазина&gt;</a>', $bg);
    }

    public function test_edit_mode_can_reword_it_on_the_page(): void
    {
        $GLOBALS['_show_admin_bar'] = true;
        $html = $this->render(['shop_button' => 'on']);
        $this->assertMatchesRegularExpression('/href="\/magazin\/"[^>]*data-cms-section="home:s_cta" data-cms-field="shop_label" data-cms-type="text" data-cms-bg="Разгледай магазина" data-cms-en="Visit the shop"/', $html);
    }

    public function test_the_on_page_editor_saves_the_label_into_the_block(): void
    {
        $def = home_types()['cta']['fields']['shop_label'];
        [$v, $err] = home_clean_field($def, ['bg' => '  Магазин ', 'en' => 'Shop'], 'shop_label');
        $this->assertSame([], $err);
        $this->assertSame(['bg' => 'Магазин', 'en' => 'Shop'], $v);
    }

    public function test_with_the_shop_module_off_the_button_is_left_out(): void
    {
        $saved = dirname(__DIR__, 2) . '/content/organisation.json';
        if (is_file($saved) && array_key_exists('feature_shop', (array) json_decode((string) file_get_contents($saved), true))) {
            $this->markTestSkipped('This site saves its module switches in content/organisation.json.');
        }
        $boot = var_export(dirname(__DIR__) . '/bootstrap.php', true);
        $file = tempnam(sys_get_temp_dir(), 'om_cta_shop_');
        file_put_contents($file, "<?php\ndefine('FEATURE_SHOP', false);\nrequire {$boot};\nrequire_once ROOT_PATH . '/includes/home_render.php';\n"
            . "echo home_cta_shop_button('s1', ['shop_button' => 'on'], 'bg', ['btn', '']) === '' ? 'none' : 'shown';\n");
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertSame('none', trim(implode("\n", $out)));
    }
}
