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
}
