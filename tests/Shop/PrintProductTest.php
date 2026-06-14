<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('print')]
final class PrintProductTest extends TestCase
{
    private static ?PDO $pdo       = null;
    private static array $created_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo && !empty(self::$created_ids)) {
            $in = implode(',', array_fill(0, count(self::$created_ids), '?'));
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                      ->execute(self::$created_ids);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function sampleVariants(): array
    {
        return [
            'colours' => [
                ['name' => 'white', 'label_bg' => 'Бяло',  'label_en' => 'White',  'mockup' => '/assets/images/products/tshirt-white.jpg'],
                ['name' => 'black', 'label_bg' => 'Черно',  'label_en' => 'Black',  'mockup' => '/assets/images/products/tshirt-black.jpg'],
                ['name' => 'navy',  'label_bg' => 'Синьо',  'label_en' => 'Navy',   'mockup' => '/assets/images/products/tshirt-navy.jpg'],
            ],
            'sizes'       => ['S', 'M', 'L', 'XL', 'XXL'],
            'print_area'  => ['x' => 0.28, 'y' => 0.18, 'w' => 0.44, 'h' => 0.50],
            'custom'      => false,
        ];
    }

    private function insertPrintProduct(array $overrides = []): int
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $variants = $overrides['variants'] ?? $this->sampleVariants();
        $defaults = [
            'slug'           => 'test-print-' . uniqid(),
            'name_bg'        => 'Тест Тениска',
            'name_en'        => 'Test T-Shirt',
            'description_bg' => 'Описание',
            'description_en' => 'Description',
            'price_eur'      => 25.00,
            'stock'          => 999,
            'active'         => 1,
            'image'          => null,
            'type'           => 'print',
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare(
            'INSERT INTO products
             (slug,name_bg,name_en,description_bg,description_en,price_eur,stock,active,image,`type`,variants)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['slug'], $d['name_bg'], $d['name_en'],
            $d['description_bg'], $d['description_en'],
            $d['price_eur'], $d['stock'], $d['active'], $d['image'],
            $d['type'], json_encode($variants),
        ]);

        $id = (int) self::$pdo->lastInsertId();
        self::$created_ids[] = $id;
        return $id;
    }

    // ════════════════════════════════════════════════════════════════════════
    // DATA MODEL
    // ════════════════════════════════════════════════════════════════════════

    public function testVariantsJsonRoundTrip(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB.');

        $variants = $this->sampleVariants();
        $id = $this->insertPrintProduct(['variants' => $variants]);

        $stmt = self::$pdo->prepare('SELECT `type`, variants FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame('print', $row['type']);
        $decoded = json_decode($row['variants'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(['S', 'M', 'L', 'XL', 'XXL'], $decoded['sizes']);
        $this->assertCount(3, $decoded['colours']);
        $this->assertSame('navy', $decoded['colours'][2]['name']);
        $this->assertSame(0.28, $decoded['print_area']['x']);
    }

    public function testStandardProductHasNullVariants(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB.');

        $slug = 'test-standard-' . uniqid();
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,?)'
        )->execute([$slug, 'Standard', '', 10.00, 5, 1]);
        $id = (int) self::$pdo->lastInsertId();
        self::$created_ids[] = $id;

        $stmt = self::$pdo->prepare('SELECT `type`, variants FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertSame('standard', $row['type']);
        $this->assertNull($row['variants']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // CART VALIDATION (pure function tests — no DB needed)
    // ════════════════════════════════════════════════════════════════════════

    public function testValidColourAndSizeAccepted(): void
    {
        $variants = $this->sampleVariants();
        $errors = print_validate_item($variants, 'navy', 'L');
        $this->assertEmpty($errors);
    }

    public function testInvalidColourRejected(): void
    {
        $errors = print_validate_item($this->sampleVariants(), 'purple', 'M');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('цвят', $errors[0]);
    }

    public function testInvalidSizeRejected(): void
    {
        $errors = print_validate_item($this->sampleVariants(), 'white', 'XXXL');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('размер', $errors[0]);
    }

    public function testMissingColourRejected(): void
    {
        $errors = print_validate_item($this->sampleVariants(), '', 'S');
        $this->assertNotEmpty($errors);
    }

    public function testMissingSizeRejected(): void
    {
        $errors = print_validate_item($this->sampleVariants(), 'black', '');
        $this->assertNotEmpty($errors);
    }

    public function testBothMissingReturnsTwoErrors(): void
    {
        $errors = print_validate_item($this->sampleVariants(), '', '');
        $this->assertCount(2, $errors);
    }

    public function testEmptyVariantsRejectsBoth(): void
    {
        $errors = print_validate_item([], 'navy', 'L');
        $this->assertCount(2, $errors);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DESIGN FILE VALIDATION
    // ════════════════════════════════════════════════════════════════════════

    public function testJpegAccepted(): void
    {
        $errors = print_validate_design_file(['type' => 'image/jpeg', 'size' => 1024]);
        $this->assertEmpty($errors);
    }

    public function testPngAccepted(): void
    {
        $errors = print_validate_design_file(['type' => 'image/png', 'size' => 2048]);
        $this->assertEmpty($errors);
    }

    public function testWebpAccepted(): void
    {
        $errors = print_validate_design_file(['type' => 'image/webp', 'size' => 512]);
        $this->assertEmpty($errors);
    }

    public function testPdfRejected(): void
    {
        $errors = print_validate_design_file(['type' => 'application/pdf', 'size' => 100]);
        $this->assertNotEmpty($errors);
    }

    public function testGifRejected(): void
    {
        $errors = print_validate_design_file(['type' => 'image/gif', 'size' => 100]);
        $this->assertNotEmpty($errors);
    }

    public function testHtmlRejected(): void
    {
        $errors = print_validate_design_file(['type' => 'text/html', 'size' => 100]);
        $this->assertNotEmpty($errors);
    }

    public function testFileTooLargeRejected(): void
    {
        $errors = print_validate_design_file(['type' => 'image/jpeg', 'size' => 11 * 1024 * 1024]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('10 MB', $errors[0]);
    }

    public function testExactly10MbAccepted(): void
    {
        $errors = print_validate_design_file(['type' => 'image/png', 'size' => 10 * 1024 * 1024]);
        $this->assertEmpty($errors);
    }

    // ════════════════════════════════════════════════════════════════════════
    // FILENAME SAFETY
    // ════════════════════════════════════════════════════════════════════════

    public function testGeneratedFilenameMatchesSafePattern(): void
    {
        $name = print_generate_filename('png');
        $this->assertTrue(print_filename_safe($name), "Generated filename failed safe check: $name");
    }

    public function testGeneratedFilenameHasExpectedFormat(): void
    {
        // Filenames are uniqid()_{16 hex chars}.ext — entirely server-generated
        $name = print_generate_filename('jpg');
        $this->assertMatchesRegularExpression('/^[a-f0-9]+_[a-f0-9]{16}\.(jpg|jpeg|png|webp)$/i', $name);
    }

    public function testPathTraversalRejectedByFilenameCheck(): void
    {
        $this->assertFalse(print_filename_safe('../../../etc/passwd'));
        $this->assertFalse(print_filename_safe('../../config.php'));
        $this->assertFalse(print_filename_safe('/etc/passwd'));
        $this->assertFalse(print_filename_safe('foo bar.png'));
    }

    public function testValidFilenamePassesSafeCheck(): void
    {
        $this->assertTrue(print_filename_safe('5f3c8a2b_deadbeef12345678.png'));
        $this->assertTrue(print_filename_safe('abc123ff00aa_001122334455aabb.jpg'));
    }

    public function testUppercaseExtensionRejectedByFilenameCheck(): void
    {
        // print_filename_safe() requires lowercase extension — uppercase must be rejected
        $this->assertFalse(print_filename_safe('photo.PNG'));
        $this->assertFalse(print_filename_safe('design.JPG'));
        $this->assertFalse(print_filename_safe('img.WEBP'));
    }

    public function testPhpExtensionRejectedByFilenameCheck(): void
    {
        // PHP files must never pass the safe-filename check (stem chars outside [a-f0-9_])
        $this->assertFalse(print_filename_safe('shell.php'));
        $this->assertFalse(print_filename_safe('backdoor.PHP'));
        $this->assertFalse(print_filename_safe('exploit.php5'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // ORDER SERIALISATION
    // ════════════════════════════════════════════════════════════════════════

    public function testPrintCartItemContainsRequiredFields(): void
    {
        // Simulate what cart/add.php stores in the session
        $cart_item = [
            'product_id'      => 7,
            'quantity'        => 1,
            'colour'          => 'navy',
            'size'            => 'L',
            'design_file'     => 'uploads/print-designs/abc123_def456789abc.png',
            'design_position' => ['x' => 0.42, 'y' => 0.31, 'scale' => 0.25, 'rotation' => 0],
        ];

        // Simulate what checkout stores in orders.items JSON
        $serialised = json_encode([$cart_item]);
        $decoded    = json_decode($serialised, true);
        $item       = $decoded[0];

        $this->assertArrayHasKey('colour',          $item);
        $this->assertArrayHasKey('size',            $item);
        $this->assertArrayHasKey('design_file',     $item);
        $this->assertArrayHasKey('design_position', $item);
        $this->assertSame('navy', $item['colour']);
        $this->assertSame('L',    $item['size']);
    }

    public function testStandardCartItemHasNoVariantFields(): void
    {
        $cart_item = ['product_id' => 3, 'quantity' => 2];
        $decoded   = json_decode(json_encode([$cart_item]), true)[0];

        $this->assertArrayNotHasKey('colour',      $decoded);
        $this->assertArrayNotHasKey('size',        $decoded);
        $this->assertArrayNotHasKey('design_file', $decoded);
    }
}
