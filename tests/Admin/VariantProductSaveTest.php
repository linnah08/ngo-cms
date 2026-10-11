<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A product with variants keeps its stock per variant. Its own stock field is
 * hidden but still posted, and a stale negative value there used to block
 * every save without a visible reason (oddminds 8958622).
 */
final class VariantProductSaveTest extends TestCase
{
    public function test_a_variant_product_saves_despite_a_stale_negative_own_stock(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo  = get_pdo();
        $slug = 'phpunit-variant-' . bin2hex(random_bytes(4));
        $pdo->prepare("INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active, `type`) VALUES (?, 'Тест', 'Test', 10, -2, 1, 'variant')")
            ->execute([$slug]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_variants (product_id, label_bg, label_en, attributes, stock, active, sort_order) VALUES (?, 'Червен', 'Red', '{}', 5, 1, 0)")
            ->execute([$id]);
        $vid = (int) $pdo->lastInsertId();
        try {
            run_admin_page('admin/product-edit.php', [
                'name_bg' => 'Тест променен', 'name_en' => 'Test', 'price_eur' => '10', 'stock' => '-2', 'type' => 'variant',
                'active' => '1', 'slug' => $slug,
                'pv_id' => [$vid], 'pv_label_bg' => ['Червен'], 'pv_label_en' => ['Red'], 'pv_stock' => ['7'],
                'pv_images' => ['{"images":[],"primary":""}'], 'pv_attrs' => ['{}'],
            ], ['get' => ['id' => $id]]);
            $this->assertSame('Тест променен', $pdo->query("SELECT name_bg FROM products WHERE id = $id")->fetchColumn(), 'the save went through');
        } finally {
            $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
    }
}
