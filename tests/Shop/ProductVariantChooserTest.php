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
}
