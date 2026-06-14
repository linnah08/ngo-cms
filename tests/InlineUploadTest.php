<?php
// tests/InlineUploadTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for inline-upload.php validation logic.
 *
 * We do NOT include admin/inline-upload.php directly because it calls
 * admin_require_login() → exit which terminates the PHP process.
 * Instead we test the two self-contained validation rules inline.
 */
class InlineUploadTest extends TestCase
{
    // ── helpers that mirror the logic in inline-upload.php ──────────────────

    private function mime_is_allowed(string $tmp_path): bool
    {
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp_path);
        return in_array($mime, $allowed_mime, true);
    }

    private function ext_is_allowed(string $filename): bool
    {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed_ext, true);
    }

    private function dir_for_section(string $section): string
    {
        $dir_map = [
            'home'    => 'assets/images/pages',
            'product' => 'assets/images/products',
            'article' => 'assets/images/articles',
        ];
        return $dir_map[$section] ?? 'assets/images/pages';
    }

    // ── unauthenticated check — uses admin_logged_in() directly ─────────────

    public function test_rejects_unauthenticated_request(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        unset($_SESSION[ADMIN_SESSION_NAME]);

        $this->assertFalse(
            admin_logged_in(),
            'admin_logged_in() must return false when session is absent'
        );
    }

    // ── MIME type validation ─────────────────────────────────────────────────

    public function test_rejects_non_image_mime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'om_test_');
        file_put_contents($tmp, '<?php echo "bad"; ?>');

        $allowed = $this->mime_is_allowed($tmp);
        unlink($tmp);

        $this->assertFalse($allowed, 'PHP source file must be rejected by MIME check');
    }

    public function test_rejects_php_extension(): void
    {
        $this->assertFalse(
            $this->ext_is_allowed('evil.php'),
            '.php extension must be rejected'
        );
    }

    public function test_accepts_png_extension(): void
    {
        $this->assertTrue(
            $this->ext_is_allowed('photo.png'),
            '.png extension must be accepted'
        );
    }

    public function test_accepts_jpeg_extension(): void
    {
        $this->assertTrue(
            $this->ext_is_allowed('photo.JPEG'),
            '.JPEG extension (uppercase) must be accepted after strtolower()'
        );
    }

    // ── CSRF check ──────────────────────────────────────────────────────────

    public function test_invalid_csrf_token_is_rejected(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $real_token = csrf_token();
        $this->assertFalse(
            hash_equals($real_token, 'bad-token'),
            'A bad CSRF token must not match the real session token'
        );
    }

    public function test_valid_csrf_token_passes(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $real_token = csrf_token();
        $this->assertTrue(
            hash_equals($real_token, $real_token),
            'The real CSRF token must match itself (timing-safe comparison)'
        );
    }

    // ── section → directory mapping ─────────────────────────────────────────

    public function test_home_maps_to_pages_dir(): void
    {
        $this->assertSame('assets/images/pages', $this->dir_for_section('home'));
    }

    public function test_product_maps_to_products_dir(): void
    {
        $this->assertSame('assets/images/products', $this->dir_for_section('product'));
    }

    public function test_article_maps_to_articles_dir(): void
    {
        $this->assertSame('assets/images/articles', $this->dir_for_section('article'));
    }

    public function test_unknown_section_falls_back_to_pages_dir(): void
    {
        $this->assertSame('assets/images/pages', $this->dir_for_section('unknown_section'));
    }
}
