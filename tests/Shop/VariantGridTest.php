<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Products with several variants show all of them as a photo grid where the big
 * photo would be, and an ad can link straight to one variant with ?v=<link slug>.
 * Presentation only: stock still lives in product_variants, untouched here.
 */
#[Group('shop')]
final class VariantGridTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
    }

    private function variants(): array
    {
        return [
            ['id' => 1, 'label_bg' => 'Ледоразбивачи',   'label_en' => '', 'attributes' => '{}', 'image' => '', 'stock' => 5],
            ['id' => 2, 'label_bg' => 'Семейни Хроники', 'label_en' => '', 'attributes' => '{}', 'image' => '', 'stock' => 3],
            ['id' => 3, 'label_bg' => 'Очи в Очи',       'label_en' => '', 'attributes' => '{}', 'image' => '', 'stock' => 0],
        ];
    }

    private function render(array $prod_variants, ?string $pv_wanted = null, string $lang = 'bg'): string
    {
        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-variant-chooser.php';
        return (string)ob_get_clean();
    }

    public function testLinkSlugIsLatinAndLowercase(): void
    {
        $v = $this->variants();
        $this->assertSame('ledorazbivachi',  variant_link_slug($v[0]));
        $this->assertSame('semeyni-hroniki', variant_link_slug($v[1]));
        $this->assertSame('ochi-v-ochi',     variant_link_slug($v[2]));
    }

    public function testParamPicksThatVariant(): void
    {
        $v = $this->variants();
        $this->assertSame(2, product_default_variant($v, false, 'semeyni-hroniki')['id']);
        $this->assertSame(2, product_default_variant($v, false, ' Semeyni-Hroniki ')['id']);
    }

    public function testUnknownOrMissingParamFallsBackToFirstInStock(): void
    {
        $v = $this->variants();
        $this->assertSame(1, product_default_variant($v, false, 'nope')['id']);
        $this->assertSame(1, product_default_variant($v, false, null)['id']);
    }

    public function testSoldOutParamFallsBackUnlessPreorder(): void
    {
        $v = $this->variants();
        $this->assertSame(1, product_default_variant($v, false, 'ochi-v-ochi')['id']);
        $this->assertSame(3, product_default_variant($v, true, 'ochi-v-ochi')['id']);
    }

    public function testChooserIsAPhotoGridWithTheParamVariantChecked(): void
    {
        $html = $this->render($this->variants(), 'semeyni-hroniki');
        $this->assertStringContainsString('class="pv-grid"', $html);
        $this->assertStringContainsString('repeat(3,', $html);
        $this->assertMatchesRegularExpression('/id="pvr-2" value="2"\s+checked/', $html);
        $this->assertStringContainsString('В наличност', $html);
        $this->assertStringNotContainsString('Налични:', $html);
    }

    public function testFourVariantsMakeTwoByTwo(): void
    {
        $v = $this->variants();
        $v[] = ['id' => 4, 'label_bg' => 'Четвърти', 'label_en' => '', 'attributes' => '{}', 'image' => '', 'stock' => 1];
        $this->assertStringContainsString('repeat(2,', $this->render($v));
    }

    public function testProductPageReadsParamAndMovesGridToPhotoSpot(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/magazin/index.php');
        $this->assertStringContainsString("is_string(\$_GET['v'] ?? null) ? \$_GET['v'] : null", $src);
        $this->assertStringContainsString('product_default_variant($prod_variants, $p_preorder_on, $pv_wanted)', $src);
        $this->assertStringContainsString("!empty(\$prod_variants) && !\$pv_multi", $src);
    }

    public function testAdminShowsCopyableAdLinkPerVariant(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/product-edit.php');
        $this->assertStringContainsString("'/?v=' . variant_link_slug(\$pvr)", $src);
    }
}
