<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';

/**
 * The homepage "featured products" section picks its layout from how many
 * products are featured: one gets a wide spotlight, two sit centred, three or
 * more fill the grid. Renders the section template directly — no database.
 */
final class HomeProductsLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
    }

    private function product(array $o = []): array
    {
        static $n = 0;
        $n++;
        return array_merge([
            'id' => 9000 + $n, 'slug' => "spot-$n", 'type' => 'standard',
            'name_bg' => "Продукт $n", 'name_en' => "Product $n",
            'description_bg' => '<p>Първи абзац.</p><p>Втори&nbsp;абзац.</p>', 'description_en' => '',
            'price_eur' => 8.0, 'stock' => 20, 'image' => 'p.png',
        ], $o);
    }

    private function render(array $products, string $lang = 'bg', bool $admin = false, array $ctx_extra = []): string
    {
        $s   = ['id' => 's_products', 'type' => 'products', 'visible' => true, 'fields' => [
            'heading'    => ['bg' => 'Продукти', 'en' => 'Products'],
            'btn1_label' => ['bg' => 'Виж всички продукти', 'en' => 'All products'],
            'btn1_url'   => ['bg' => '/magazin/', 'en' => '/en/shop/'],
        ]];
        $ctx = $ctx_extra + ['featured_products' => $products, 'variant_images' => [], 'variant_stock' => []];
        ob_start();
        home_render_section($s, $lang, $ctx, $admin);
        return (string) ob_get_clean();
    }

    public function test_one_product_gets_the_spotlight_without_view_all(): void
    {
        $html = $this->render([$this->product()]);
        $this->assertStringContainsString('class="home-spot"', $html);
        $this->assertStringNotContainsString('grid--3', $html);
        $this->assertStringNotContainsString('Виж всички продукти', $html, '"View all" leads to the same single product');
        $this->assertStringContainsString('Добави в количката', $html);
        $this->assertStringContainsString('Виж детайли', $html);
        $this->assertStringContainsString('<h2 class="section-label home-spot__label"', $html);
        $this->assertStringContainsString('alt="Продукт', $html);
    }

    public function test_spotlight_keeps_paragraphs_as_plain_escaped_text(): void
    {
        $html = $this->render([$this->product(['description_bg' => '<p>Първи.</p><p>Втори <b>&lt;x&gt;</b></p>'])]);
        $this->assertStringContainsString("Първи.\n\nВтори &lt;x&gt;</p>", $html);
    }

    public function test_spotlight_english_falls_back_to_bulgarian_description(): void
    {
        $html = $this->render([$this->product()], 'en');
        $this->assertStringContainsString('Product ', $html);
        $this->assertStringContainsString("Първи абзац.\n\nВтори абзац.", $html);
        $this->assertStringContainsString('/en/shop/spot-', $html);
        $this->assertStringContainsString('Add to cart', $html);
    }

    public function test_spotlight_out_of_stock_shows_text_not_a_cart_button(): void
    {
        $html = $this->render([$this->product(['stock' => 0])]);
        $this->assertStringContainsString('Изчерпан', $html);
        $this->assertStringNotContainsString('/cart/add.php', $html);
    }

    public function test_spotlight_variant_product_links_to_choose_a_variant(): void
    {
        $html = $this->render([$this->product(['type' => 'variant', 'stock' => 0, 'image' => ''])]);
        $this->assertStringContainsString('Избери вариант', $html);
        $this->assertStringNotContainsString('/cart/add.php', $html);
    }

    public function test_spotlight_single_variant_product_adds_that_variant_directly(): void
    {
        $p    = $this->product(['type' => 'variant', 'stock' => 0, 'image' => '']);
        $html = $this->render([$p], 'bg', false, [
            'variant_stock'  => [$p['id'] => 4],
            'variant_single' => [$p['id'] => 77],
        ]);
        $this->assertStringNotContainsString('Избери вариант', $html, 'one variant: nothing to choose');
        $this->assertStringContainsString('/cart/add.php', $html);
        $this->assertStringContainsString('name="variant_id" value="77"', $html);
    }

    public function test_spotlight_hides_other_language_copy_from_visitors(): void
    {
        $this->assertStringNotContainsString('data-cms-en=', $this->render([$this->product()]));
        $this->assertStringContainsString('data-cms-en=', $this->render([$this->product()], 'bg', true));
    }

    public function test_two_products_are_centred_cards(): void
    {
        $html = $this->render([$this->product(), $this->product()]);
        $this->assertStringNotContainsString('home-spot', $html);
        $this->assertStringContainsString('max-width:52rem;margin-inline:auto;', $html);
        $this->assertStringContainsString('Виж всички продукти', $html);
    }

    public function test_three_products_keep_the_full_grid(): void
    {
        $html = $this->render([$this->product(), $this->product(), $this->product()]);
        $this->assertStringNotContainsString('home-spot', $html);
        $this->assertStringNotContainsString('max-width:52rem', $html);
        $this->assertStringContainsString('class="grid grid--3"', $html);
    }

    public function test_spotlight_exposes_theme_variables_and_no_inline_styles(): void
    {
        $css = home_shared_head();
        foreach (['--spot-bg', '--spot-radius', '--spot-columns', '--spot-title-size'] as $var) {
            $this->assertStringContainsString("var($var,", $css, "Themes restyle the spotlight through $var");
        }
        $tpl = (string) file_get_contents(ROOT_PATH . '/templates/home/product-spotlight.php');
        $this->assertDoesNotMatchRegularExpression('/class="home-spot[^"]*"[^>]*style=/', $tpl,
            'An inline style on a spotlight part would beat the site theme stylesheet');
    }
}
