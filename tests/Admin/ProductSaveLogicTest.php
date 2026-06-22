<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the product-save logic extracted out of admin/product-edit.php
 * into includes/products.php — the POST-to-structure shaping (colour validation,
 * print-area clamping, per-size dims, variant-row gallery sanitisation) that was
 * previously fused into the page and untestable.
 */
#[Group('admin')]
final class ProductSaveLogicTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/products.php';
    }

    // ── product_compute_slug ─────────────────────────────────────────────────

    public function testSlugPrefersExplicitInput(): void
    {
        $this->assertSame('my-slug', product_compute_slug('My Slug', 'EN Name', 'BG Name'));
    }

    public function testSlugFallsBackToEnNameThenBgName(): void
    {
        $this->assertSame('en-name', product_compute_slug('', 'EN Name', 'BG Name'));
        $this->assertSame('bg-ime', product_compute_slug('', '', 'BG Ime'));
    }

    // ── product_build_print_variants ─────────────────────────────────────────

    public function testPrintColoursAreValidatedAndLabelled(): void
    {
        $out = product_build_print_variants([
            'colour_name'     => ['Black', 'in valid!', ''],
            'colour_label_bg' => ['Черно', 'x', 'y'],
            'colour_label_en' => ['Black', 'x', 'y'],
            'colour_mockup'   => ['m.png', '', ''],
        ]);
        // Only the valid, non-empty colour name survives.
        $this->assertCount(1, $out['colours']);
        $this->assertSame('Black', $out['colours'][0]['name']);
        $this->assertSame('Черно', $out['colours'][0]['label_bg']);
        $this->assertSame('m.png', $out['colours'][0]['mockup']);
    }

    public function testPrintSizesAreWhitelisted(): void
    {
        $out = product_build_print_variants(['sizes' => ['S', 'XXL', 'BOGUS', 'M']]);
        $this->assertSame(['S', 'XXL', 'M'], $out['sizes']);
    }

    public function testPrintAreaIsClampedToZeroOneRange(): void
    {
        $out = product_build_print_variants(['pa_x' => 200, 'pa_y' => -50, 'pa_w' => 44, 'pa_h' => 50]);
        $this->assertSame(1.0, $out['print_area']['x']); // 200% clamped to 1.0
        $this->assertSame(0.0, $out['print_area']['y']); // -50% clamped to 0.0
        $this->assertSame(0.44, $out['print_area']['w']);
        $this->assertSame(0.50, $out['print_area']['h']);
    }

    public function testPrintAreaDefaultsWhenMissing(): void
    {
        $out = product_build_print_variants([]);
        $this->assertSame(0.28, $out['print_area']['x']);
        $this->assertSame(0.18, $out['print_area']['y']);
    }

    public function testSizeDimsKeepOnlyPositivePairsRounded(): void
    {
        $out = product_build_print_variants([
            'size_dim_w' => ['S' => '50.04', 'M' => '0', 'L' => '55'],
            'size_dim_h' => ['S' => '70.06', 'M' => '72', 'L' => '0'],
        ]);
        $this->assertSame(['S' => ['w' => 50.0, 'h' => 70.1]], $out['size_dims']);
    }

    public function testSizeGuideFallsBackToExistingWhenNotResubmitted(): void
    {
        $out = product_build_print_variants([], ['size_guide' => 'old-guide.png']);
        $this->assertSame('old-guide.png', $out['size_guide']);

        $out2 = product_build_print_variants(['size_guide_filename' => 'new.png'], ['size_guide' => 'old.png']);
        $this->assertSame('new.png', $out2['size_guide']);
    }

    // ── product_clean_variant_attributes ─────────────────────────────────────

    public function testVariantAttributesTrimmedAndCapped(): void
    {
        $out = product_clean_variant_attributes(['  Size ', '', str_repeat('x', 65), 'Colour']);
        $this->assertSame(['Size', 'Colour'], $out); // empty + over-64 dropped
    }

    // ── product_parse_variant_rows ───────────────────────────────────────────

    public function testVariantRowsSkipEmptyLabelAndSanitiseGallery(): void
    {
        $post = [
            'pv_id'       => ['5', '0'],
            'pv_label_bg' => ['Red', ''],            // 2nd row skipped (no label)
            'pv_label_en' => ['Red EN', 'x'],
            'pv_stock'    => ['-3', '9'],            // negative clamped to 0
            'pv_attrs'    => ['{"colour":"red"}', '{}'],
            'pv_images'   => ['{"images":["a.png","a.png","BAD NAME.png","ghost.png"],"primary":"a.png"}', ''],
        ];
        // Only a.png "exists"; dupes + bad-name + missing rejected.
        $rows = product_parse_variant_rows($post, fn(string $f) => $f === 'a.png');

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['id']);
        $this->assertSame('Red', $rows[0]['label_bg']);
        $this->assertSame(['a.png'], $rows[0]['images']);
        $this->assertSame('a.png', $rows[0]['image']);
        $this->assertSame(0, $rows[0]['stock']);
        $this->assertSame(['colour' => 'red'], $rows[0]['attrs']);
    }

    public function testVariantGalleryCapsAtEightImages(): void
    {
        $files = array_map(fn($n) => "img$n.png", range(1, 12));
        $post = [
            'pv_label_bg' => ['Many'],
            'pv_images'   => [json_encode(['images' => $files, 'primary' => 'img1.png'])],
        ];
        $rows = product_parse_variant_rows($post, fn(string $f) => true);
        $this->assertCount(8, $rows[0]['images']);
    }
}
