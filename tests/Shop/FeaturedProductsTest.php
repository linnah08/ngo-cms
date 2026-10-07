<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The homepage "featured products" section.
 *
 * This exists because the admin half of the feature shipped without the public
 * half: admin/product-edit.php has always written `featured`, and
 * admin/products.php has always shown the "★ Начало" badge, but no public page
 * ever read the column — so ticking the box did nothing at all. These tests
 * cover the query the homepages run and the card the section renders.
 */
#[Group('shop')]
#[Group('db')]
final class FeaturedProductsTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $created_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured (db.config.php missing or unreachable).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo || !self::$created_ids) return;
        $in = implode(',', array_fill(0, count(self::$created_ids), '?'));
        self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")
                  ->execute(self::$created_ids);
        self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                  ->execute(self::$created_ids);
        self::$created_ids = [];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $row = array_merge([
            'slug'           => 'feat-test-' . uniqid(),
            'name_bg'        => 'Тест продукт',
            'name_en'        => 'Test product',
            'description_bg' => 'Описание',
            'description_en' => 'Description',
            'price_eur'      => 9.90,
            'stock'          => 5,
            'active'         => 1,
            'featured'       => 0,
            'image'          => '',
            'type'           => 'standard',
            'sort_order'     => 0,
        ], $overrides);

        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,description_bg,description_en,
                                   price_eur,stock,active,featured,image,`type`,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $row['slug'], $row['name_bg'], $row['name_en'], $row['description_bg'],
            $row['description_en'], $row['price_eur'], $row['stock'], $row['active'],
            $row['featured'], $row['image'], $row['type'], $row['sort_order'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$created_ids[] = $id;
        return $id;
    }

    private function insertVariant(int $product_id, array $overrides = []): int
    {
        $row = array_merge([
            'label_bg'   => 'Вариант ' . uniqid(),
            'attributes' => '{}',
            'stock'      => 3,
            'active'     => 1,
            'image'      => '',
            'sort_order' => 0,
        ], $overrides);

        self::$pdo->prepare(
            'INSERT INTO product_variants (product_id,label_bg,attributes,stock,active,image,sort_order)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $product_id, $row['label_bg'], $row['attributes'], $row['stock'],
            $row['active'], $row['image'], $row['sort_order'],
        ]);

        return (int)self::$pdo->lastInsertId();
    }

    private function featuredIds(): array
    {
        return array_map(
            static fn(array $p): int => (int)$p['id'],
            product_featured_list(self::$pdo)
        );
    }

    // ── which products the homepage picks up ─────────────────────────────────

    public function test_featured_and_active_product_is_listed(): void
    {
        $id = $this->insertProduct(['featured' => 1, 'active' => 1]);
        $this->assertContains($id, $this->featuredIds());
    }

    public function test_unticked_product_is_not_listed(): void
    {
        $id = $this->insertProduct(['featured' => 0, 'active' => 1]);
        $this->assertNotContains($id, $this->featuredIds());
    }

    public function test_featured_but_inactive_product_is_not_listed(): void
    {
        // The admin list warns about exactly this with "★ Начало (скрит, неактивен)".
        $id = $this->insertProduct(['featured' => 1, 'active' => 0]);
        $this->assertNotContains($id, $this->featuredIds());
    }

    public function test_listed_in_sort_order(): void
    {
        $last  = $this->insertProduct(['featured' => 1, 'sort_order' => 900]);
        $first = $this->insertProduct(['featured' => 1, 'sort_order' => 800]);

        $ids = $this->featuredIds();
        $this->assertLessThan(
            array_search($last, $ids, true),
            array_search($first, $ids, true),
            'Featured products must follow the same sort_order the shop uses'
        );
    }

    // ── variant support data ─────────────────────────────────────────────────

    public function test_variant_product_borrows_its_first_variant_image(): void
    {
        $id = $this->insertProduct(['featured' => 1, 'type' => 'variant', 'image' => '']);
        $this->insertVariant($id, ['image' => 'second.webp', 'sort_order' => 2]);
        $this->insertVariant($id, ['image' => 'first.webp',  'sort_order' => 1]);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'variant']]);

        $this->assertSame('first.webp', $support['images'][$id] ?? null);
    }

    public function test_variant_stock_sums_active_variants_only(): void
    {
        $id = $this->insertProduct(['featured' => 1, 'type' => 'variant']);
        $this->insertVariant($id, ['stock' => 4, 'active' => 1]);
        $this->insertVariant($id, ['stock' => 6, 'active' => 1]);
        $this->insertVariant($id, ['stock' => 99, 'active' => 0]);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'variant']]);

        $this->assertSame(10, $support['stock'][$id] ?? null);
    }

    public function test_no_variant_products_means_no_queries_worth_of_data(): void
    {
        $id = $this->insertProduct(['featured' => 1, 'type' => 'standard']);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'standard']]);

        $this->assertSame([], $support['images']);
        $this->assertSame([], $support['stock']);
        $this->assertSame([], $support['single']);
    }

    public function test_single_active_variant_is_reported_by_id(): void
    {
        // An inactive variant doesn't count — the buyer can only ever get the active one.
        $id   = $this->insertProduct(['type' => 'variant']);
        $only = $this->insertVariant($id, ['active' => 1]);
        $this->insertVariant($id, ['active' => 0]);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'variant']]);

        $this->assertSame($only, $support['single'][$id] ?? null);
    }

    public function test_product_with_several_active_variants_is_not_single(): void
    {
        $id = $this->insertProduct(['type' => 'variant']);
        $this->insertVariant($id);
        $this->insertVariant($id);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'variant']]);

        $this->assertArrayNotHasKey($id, $support['single']);
    }

    public function test_product_with_no_active_variant_is_not_single(): void
    {
        $id = $this->insertProduct(['type' => 'variant']);
        $this->insertVariant($id, ['active' => 0]);

        $support = product_variant_support_data(self::$pdo, [['id' => $id, 'type' => 'variant']]);

        $this->assertArrayNotHasKey($id, $support['single']);
    }

    // ── the card the section renders ─────────────────────────────────────────

    private function renderCard(array $product, array $opts = []): string
    {
        // templates/product-card.php reads these from the including scope.
        $p               = $product;
        $lang            = $opts['lang'] ?? 'bg';
        $variant_images  = $opts['variant_images'] ?? [];
        $variant_stock   = $opts['variant_stock'] ?? [];
        $variant_single  = $opts['variant_single'] ?? [];
        $_show_admin_bar = false;
        $card_removable  = $opts['card_removable'] ?? null;
        $card_redirect   = $opts['card_redirect'] ?? null;

        // Who is looking: the "×" is only for someone who manages the shop.
        if (session_status() === PHP_SESSION_NONE) session_start();
        $had = $_SESSION[ADMIN_SESSION_NAME] ?? null;
        if (isset($opts['role'])) $_SESSION[ADMIN_SESSION_NAME] = ['logged_in' => true, 'role' => $opts['role'], 'time' => time()];
        else unset($_SESSION[ADMIN_SESSION_NAME]);

        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-card.php';
        $html = (string)ob_get_clean();

        if ($had === null) unset($_SESSION[ADMIN_SESSION_NAME]);
        else $_SESSION[ADMIN_SESSION_NAME] = $had;
        return $html;
    }

    private function sampleProduct(array $overrides = []): array
    {
        return array_merge([
            'id'             => 4242,
            'slug'           => 'sample',
            'name_bg'        => 'Салфетки',
            'name_en'        => 'Napkins',
            'description_bg' => 'Описание',
            'description_en' => 'Description',
            'price_eur'      => 9.90,
            'stock'          => 5,
            'image'          => 'sample.webp',
            'type'           => 'standard',
        ], $overrides);
    }

    public function test_homepage_card_sends_failed_adds_back_to_the_homepage(): void
    {
        $html = $this->renderCard($this->sampleProduct(), ['card_redirect' => 'home']);

        $this->assertStringContainsString('name="redirect" value="home"', $html);
    }

    public function test_homepage_card_omits_the_store_wide_remove_control(): void
    {
        // The CMS "×" deactivates the product everywhere, not just on this page,
        // so only the shop listing may offer it.
        $html = $this->renderCard($this->sampleProduct(), ['card_removable' => false]);

        $this->assertStringNotContainsString('om-removable', $html);
        $this->assertStringNotContainsString('data-cms-remove-type', $html);
    }

    public function test_shop_card_keeps_the_remove_control_and_shop_redirect(): void
    {
        $html = $this->renderCard($this->sampleProduct(), [
            'card_removable' => true,
            'card_redirect'  => 'shop',
            'role'           => 'shop_admin',
        ]);

        $this->assertStringContainsString('om-removable', $html);
        $this->assertStringContainsString('name="redirect" value="shop"', $html);
    }

    public function test_shop_card_offers_no_remove_control_to_an_author(): void
    {
        // inline-remove.php refuses products to authors, so they get no "×" to press.
        $html = $this->renderCard($this->sampleProduct(), ['card_removable' => true, 'role' => 'author']);

        $this->assertStringNotContainsString('om-removable', $html);
        $this->assertStringNotContainsString('data-cms-remove-type', $html);
    }

    public function test_card_defaults_match_the_shop_listing(): void
    {
        // Callers that set neither flag get the shop's behaviour.
        $html = $this->renderCard($this->sampleProduct());

        $this->assertStringContainsString('name="redirect" value="shop"', $html);
        $this->assertStringNotContainsString('om-removable', $html);
    }

    public function test_out_of_stock_card_shows_no_add_button(): void
    {
        $html = $this->renderCard($this->sampleProduct(['stock' => 0]), ['card_redirect' => 'home']);

        $this->assertStringContainsString('Изчерпан', $html);
        $this->assertStringNotContainsString('Добави в количката', $html);
    }

    public function test_variant_card_links_to_the_variant_picker(): void
    {
        $html = $this->renderCard(
            $this->sampleProduct(['type' => 'variant', 'image' => '', 'stock' => 0]),
            ['card_redirect' => 'home', 'variant_stock' => [4242 => 7], 'variant_images' => [4242 => 'v.webp']]
        );

        $this->assertStringContainsString('Избери вариант', $html);
        $this->assertStringContainsString('v.webp', $html);
    }

    public function test_variant_card_with_several_variants_has_no_hidden_variant_id(): void
    {
        $html = $this->renderCard(
            $this->sampleProduct(['type' => 'variant', 'image' => '', 'stock' => 0]),
            ['variant_stock' => [4242 => 7]]
        );

        $this->assertStringNotContainsString('name="variant_id"', $html);
        $this->assertStringNotContainsString('Добави в количката', $html);
    }

    // ── a product with exactly one variant behaves like a plain product ──────

    public function test_single_variant_card_adds_that_variant_straight_to_the_cart(): void
    {
        $html = $this->renderCard(
            $this->sampleProduct(['type' => 'variant', 'image' => '', 'stock' => 0]),
            ['variant_stock' => [4242 => 3], 'variant_single' => [4242 => 77]]
        );

        $this->assertStringContainsString('Добави в количката', $html);
        $this->assertStringContainsString('name="variant_id" value="77"', $html);
        $this->assertStringNotContainsString('Избери вариант', $html);
    }

    public function test_single_variant_card_in_english(): void
    {
        $html = $this->renderCard(
            $this->sampleProduct(['type' => 'variant', 'image' => '', 'stock' => 0]),
            ['lang' => 'en', 'variant_stock' => [4242 => 3], 'variant_single' => [4242 => 77]]
        );

        $this->assertStringContainsString('Add to cart', $html);
        $this->assertStringContainsString('name="variant_id" value="77"', $html);
        $this->assertStringNotContainsString('Choose variant', $html);
    }

    public function test_sold_out_single_variant_card_says_out_of_stock(): void
    {
        // The product row's own stock is irrelevant for a variant product —
        // only the variant's stock decides.
        $html = $this->renderCard(
            $this->sampleProduct(['type' => 'variant', 'image' => '', 'stock' => 9]),
            ['variant_stock' => [4242 => 0], 'variant_single' => [4242 => 77]]
        );

        $this->assertStringContainsString('Изчерпан', $html);
        $this->assertStringNotContainsString('Добави в количката', $html);
        $this->assertStringNotContainsString('Избери вариант', $html);
    }
}
