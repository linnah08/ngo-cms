<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Edit mode on the public pages opens rich text blocks (За нас, front page texts,
 * product descriptions, the donation text…) in TinyMCE. The editor used to load only
 * in the admin, so on the site a click on such a block did nothing at all.
 */
final class InlineRichTextTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
    }

    /** The admin-bar block of the public header: from the bar's markup to its endif. */
    private static function adminBarBlock(): string
    {
        $h = self::src('templates/header.php');
        $start = strpos($h, '<div id="om-admin-bar">');
        $end = strpos($h, 'assets/js/inline-cms.js', (int) $start);
        return substr($h, (int) $start, (int) $end - (int) $start);
    }

    public function test_public_admin_bar_loads_tinymce_and_the_shared_config_before_the_editor_script(): void
    {
        $bar = self::adminBarBlock();
        $this->assertStringContainsString('tinymce_script_tag()', $bar);
        $this->assertStringContainsString("/templates/tinymce-base.php'", $bar);
        $this->assertStringContainsString('window._csrfToken', $bar, 'image uploads from the editor post _csrfToken');
    }

    public function test_admin_and_site_share_one_tinymce_config(): void
    {
        $this->assertStringContainsString("/templates/tinymce-base.php'", self::src('admin/includes/admin-header.php'));
        $this->assertStringNotContainsString('window._tinyBase = {', self::src('admin/includes/admin-header.php'));
        $this->assertStringContainsString('window._tinyBase = {', self::src('templates/tinymce-base.php'));
    }

    public function test_editor_script_uses_the_current_tinymce_api_and_never_fails_silently(): void
    {
        $js = self::src('assets/js/inline-cms.js');
        // TinyMCE 6+ removed tinymce.editors; reading it threw when Edit mode was switched off.
        $this->assertStringNotContainsString('tinymce.editors', $js);
        $this->assertMatchesRegularExpression('/function _initRichText\(el\) \{\s*if \(!window\.tinymce\) \{\s*\/\/[^\n]*\n\s*if \(!_rtWarned\)/', $js);
    }

    public function test_rich_text_blocks_are_reachable_by_keyboard_in_edit_mode(): void
    {
        $js = self::src('assets/js/inline-cms.js');
        $this->assertStringContainsString("el.setAttribute('tabindex', '0')", $js);
        $this->assertStringContainsString("document.addEventListener('focusin', _onRichTextActivate)", $js);
    }

    public function test_header_renders_the_editor_for_an_admin(): void
    {
        // The rendered bar, not just the source: tinymce_script_tag() falls back to the
        // open-source build without a Tiny Cloud key, and the shared config follows it.
        require_once dirname(__DIR__) . '/includes/settings.php';
        ob_start();
        require dirname(__DIR__) . '/templates/tinymce-base.php';
        $cfg = (string) ob_get_clean();
        $this->assertStringContainsString('<script>', $cfg);
        $this->assertStringContainsString('window._tinyBase', $cfg);
        $this->assertStringContainsString('tinymce', tinymce_script_tag());
    }
}
