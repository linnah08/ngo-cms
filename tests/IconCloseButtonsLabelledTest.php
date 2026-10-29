<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A button that shows only "✕" or "×" reads as nothing (or "times") to a screen
 * reader. Every such button on the public site needs an aria-label (e.g. „Затвори“).
 */
final class IconCloseButtonsLabelledTest extends TestCase
{
    public function test_icon_only_buttons_on_public_pages_have_an_aria_label(): void
    {
        $root  = dirname(__DIR__);
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr((string) $f, strlen($root) + 1);
            if (!str_ends_with($rel, '.php') || preg_match('#^(admin|vendor|node_modules|tests|install|docs|scripts)/#', $rel)) continue;
            $files[] = $rel;
        }
        $this->assertContains('cart/index.php', $files);
        $bad = [];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            if (!preg_match_all('#<button\b([^>]*)>\s*(?:<span aria-hidden="true">)?\s*(?:✕|×|&times;)\s*(?:</span>)?\s*</button>#u', $src, $m)) continue;
            foreach ($m[1] as $attrs) {
                if (!str_contains($attrs, 'aria-label=')) $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad, 'icon-only buttons without an aria-label');
    }

    public function test_the_cart_preview_close_button_says_close(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/cart/index.php');
        $this->assertStringContainsString('<button type="button" onclick="closePreview()" aria-label="<?= h(t_or(\'common.close\', \'Затвори\', \'Close\', $lang)) ?>"', $src);
    }

    public function test_icon_only_buttons_in_admin_have_type_label_and_hidden_glyph(): void
    {
        $root = dirname(__DIR__) . '/admin';
        $bad  = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = (string) $f;
            if (!str_ends_with($path, '.php')) continue;
            $rel = substr($path, strlen(dirname(__DIR__)) + 1);
            $src = (string) file_get_contents($path);
            // <button ...>✕</button>, also with the glyph wrapped in a span; PHP/JS quotes escaped.
            if (!preg_match_all('#<button\b([^>]*)>\s*(<span[^>]*>)?\s*(?:✕|×|&times;)\s*(?:</span>)?\s*</button>#u', $src, $m, PREG_SET_ORDER)) continue;
            foreach ($m as $x) {
                $attrs = $x[1];
                if (!str_contains($attrs, 'aria-label=')) $bad[] = "$rel: no aria-label";
                if (!str_contains($attrs, 'type=') ) $bad[] = "$rel: no type";
                if (($x[2] ?? '') === '' || !str_contains($x[2], 'aria-hidden')) $bad[] = "$rel: glyph not aria-hidden";
            }
        }
        $this->assertSame([], $bad, 'admin icon-only buttons need type, aria-label and an aria-hidden glyph');
    }
}
