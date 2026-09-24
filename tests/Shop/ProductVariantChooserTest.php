<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The variant block on the public product page (templates/product-variant-chooser.php,
 * included by magazin/index.php for both /magazin/<slug>/ and /en/shop/<slug>/).
 *
 * A product with exactly one active variant must not ask the buyer to "choose"
 * it — the variant is shown as already the one they get, in plain text a
 * screen reader reads out, while the add-to-cart form carries its id.
 */
#[Group('shop')]
final class ProductVariantChooserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
    }

    private function variant(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'         => $id,
            'label_bg'   => 'Синя',
            'label_en'   => 'Blue',
            'attributes' => '{"Размер":"30x50"}',
            'stock'      => 4,
            'image'      => '',
        ], $overrides);
    }

    private function render(array $prod_variants, string $lang = 'bg'): string
    {
        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-variant-chooser.php';
        return (string)ob_get_clean();
    }

    // ── product_single_variant ────────────────────────────────────────────────

    public function test_single_variant_is_returned(): void
    {
        $only = $this->variant(5);
        $this->assertSame($only, product_single_variant([$only]));
    }

    public function test_several_variants_means_no_single_variant(): void
    {
        $this->assertNull(product_single_variant([$this->variant(5), $this->variant(6)]));
    }

    public function test_no_variants_means_no_single_variant(): void
    {
        $this->assertNull(product_single_variant([]));
    }

    // ── one variant: shown as the one you get, no prompt ─────────────────────

    public function test_single_variant_is_not_offered_as_a_choice_bg(): void
    {
        $html = $this->render([$this->variant(5)], 'bg');

        $this->assertStringNotContainsString('Избери вариант', $html);
        $this->assertStringNotContainsString('selectVariant', $html);
        $this->assertStringNotContainsString('pv-option', $html);
    }

    public function test_single_variant_is_not_offered_as_a_choice_en(): void
    {
        $html = $this->render([$this->variant(5)], 'en');

        $this->assertStringNotContainsString('Choose variant', $html);
        $this->assertStringNotContainsString('selectVariant', $html);
    }

    public function test_single_variant_stays_visible_as_text_bg(): void
    {
        // Not hidden — the buyer (and a screen reader) still learns which one it is.
        $html = $this->render([$this->variant(5)], 'bg');

        $this->assertStringContainsString('Вариант', $html);
        $this->assertStringContainsString('Синя', $html);
        $this->assertStringContainsString('Размер: 30x50', $html);
        $this->assertStringContainsString('4 бр.', $html);
        $this->assertStringNotContainsString('aria-hidden', $html);
        $this->assertStringNotContainsString('display:none', $html);
    }

    public function test_single_variant_stays_visible_as_text_en(): void
    {
        $html = $this->render([$this->variant(5)], 'en');

        $this->assertStringContainsString('Variant', $html);
        $this->assertStringContainsString('Blue', $html);
        $this->assertStringContainsString('4 left', $html);
    }

    public function test_single_variant_en_falls_back_to_bg_label(): void
    {
        $html = $this->render([$this->variant(5, ['label_en' => ''])], 'en');

        $this->assertStringContainsString('Синя', $html);
    }

    public function test_single_variant_label_is_escaped(): void
    {
        $html = $this->render([$this->variant(5, ['label_bg' => '<script>x</script>'])], 'bg');

        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── several variants: the chooser, unchanged ─────────────────────────────

    public function test_several_variants_show_the_chooser_with_first_selected(): void
    {
        $html = $this->render([$this->variant(5), $this->variant(6, ['label_bg' => 'Червена'])], 'bg');

        $this->assertStringContainsString('Избери вариант', $html);
        $this->assertStringContainsString('selectVariant(5)', $html);
        $this->assertStringContainsString('selectVariant(6)', $html);
        $this->assertMatchesRegularExpression('/class="pv-option active"\s+id="pvo-5"/', $html);
    }

    public function test_sold_out_variant_in_chooser_is_not_selectable(): void
    {
        $html = $this->render([$this->variant(5), $this->variant(6, ['stock' => 0])], 'en');

        $this->assertStringNotContainsString('selectVariant(6)', $html);
        $this->assertStringContainsString('Out of stock', $html);
    }

    // ── keyboard + screen reader: native radios in a fieldset ────────────────

    public function test_chooser_is_a_fieldset_of_native_radios(): void
    {
        $html = $this->render([$this->variant(5), $this->variant(6)], 'bg');

        $this->assertMatchesRegularExpression('/<fieldset class="pv-choices">\s*<legend>Избери вариант<\/legend>/', $html);
        $this->assertSame(2, substr_count($html, 'type="radio"'));
        $this->assertStringContainsString('for="pvr-5"', $html);
        $this->assertStringContainsString('id="pvr-5"', $html);
        // Operable without a mouse: selection is driven by the radio's change, not a div click.
        $this->assertStringContainsString('onchange="selectVariant(6)"', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    public function test_chooser_legend_in_english(): void
    {
        $html = $this->render([$this->variant(5), $this->variant(6)], 'en');

        $this->assertStringContainsString('<legend>Choose variant</legend>', $html);
        $this->assertStringContainsString('✓ Selected', $html);
    }

    public function test_selected_variant_is_marked_in_text_not_only_colour(): void
    {
        $html = $this->render([$this->variant(5), $this->variant(6)], 'bg');

        $this->assertMatchesRegularExpression('/id="pvr-5" value="5"\s+checked/', $html);
        $this->assertStringContainsString('✓ Избран', $html);
    }

    // ── preselection skips sold-out variants ─────────────────────────────────

    public function test_first_in_stock_variant_starts_selected(): void
    {
        $html = $this->render([$this->variant(5, ['stock' => 0]), $this->variant(6), $this->variant(7)], 'bg');

        $this->assertMatchesRegularExpression('/id="pvr-6" value="6"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/id="pvr-5" value="5"\s+disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pvr-(5|7)" value="\d+"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/class="pv-option active"\s+id="pvo-6"/', $html);
    }

    public function test_all_sold_out_means_nothing_selected(): void
    {
        $html = $this->render([$this->variant(5, ['stock' => 0]), $this->variant(6, ['stock' => 0])], 'bg');

        $this->assertStringNotContainsString('checked', $html);
        $this->assertSame(2, substr_count($html, ' disabled>'));
        $this->assertSame(2, substr_count($html, 'Изчерпан'));
    }

    public function test_default_variant_is_first_in_stock(): void
    {
        $a = $this->variant(5, ['stock' => 0]);
        $b = $this->variant(6);
        $this->assertSame($b, product_default_variant([$a, $b]));
        $this->assertNull(product_default_variant([$a]));
        $this->assertNull(product_default_variant([]));
    }

    // ── in stock at all (add-to-cart form + JSON-LD availability) ────────────

    public function test_variant_product_is_in_stock_when_any_variant_has_stock(): void
    {
        // A variant product's own stock column is unused (0) — the variants decide.
        $p = ['type' => 'variant', 'stock' => 0];
        $this->assertTrue(product_is_in_stock($p, [$this->variant(5, ['stock' => 0]), $this->variant(6, ['stock' => 2])]));
        $this->assertFalse(product_is_in_stock($p, [$this->variant(5, ['stock' => 0])]));
        $this->assertFalse(product_is_in_stock($p, []));
        $this->assertFalse(product_is_in_stock(['type' => 'variant', 'stock' => 9], []));
    }

    public function test_plain_product_is_in_stock_by_its_own_stock(): void
    {
        $this->assertTrue(product_is_in_stock(['type' => 'standard', 'stock' => 1]));
        $this->assertFalse(product_is_in_stock(['type' => 'standard', 'stock' => 0]));
    }

    // ── sold-out state ───────────────────────────────────────────────────────

    private function renderOutOfStock(bool $is_variant, array $prod_variants, string $lang): string
    {
        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-out-of-stock.php';
        return (string)ob_get_clean();
    }

    public function test_sold_out_variants_show_reason_and_disabled_button_bg(): void
    {
        $html = $this->renderOutOfStock(true, [$this->variant(5, ['stock' => 0]), $this->variant(6, ['stock' => 0])], 'bg');

        $this->assertStringContainsString('Изчерпан', $html);
        $this->assertStringContainsString('Всички варианти са изчерпани.', $html);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*\bdisabled\b[^>]*aria-describedby="oosReason"/', $html);
        $this->assertStringContainsString('id="oosReason"', $html);
        $this->assertStringContainsString('Добави в количката', $html);
    }

    public function test_sold_out_variants_show_reason_and_disabled_button_en(): void
    {
        $html = $this->renderOutOfStock(true, [$this->variant(5, ['stock' => 0]), $this->variant(6, ['stock' => 0])], 'en');

        $this->assertStringContainsString('All variants are sold out.', $html);
        $this->assertStringContainsString('Add to cart', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_sold_out_plain_or_single_variant_product_reason(): void
    {
        $this->assertStringContainsString(
            'Продуктът в момента е изчерпан.',
            $this->renderOutOfStock(false, [], 'bg')
        );
        $this->assertStringContainsString(
            'This product is currently sold out.',
            $this->renderOutOfStock(true, [$this->variant(5, ['stock' => 0])], 'en')
        );
    }
}
