<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The editor loads without a Tiny Cloud key (tinymce_script_tag()): a new site
 * must never get read-only editors because nobody signed up at tiny.cloud.
 */
final class TinyMceLoaderTest extends TestCase
{
    public function test_every_admin_page_loads_the_editor_through_the_shared_helper(): void
    {
        $root = dirname(__DIR__, 2);
        $users = 0;
        foreach (glob($root . '/admin/*.php') as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringNotContainsString('cdn.tiny.cloud', $src, basename($f) . ': use tinymce_script_tag()');
            if (str_contains($src, 'tinymce.init(')) {
                $users++;
                $this->assertStringContainsString('tinymce_script_tag()', $src, basename($f) . ' starts an editor without loading it through the helper');
            }
        }
        $this->assertGreaterThan(5, $users);
    }

    public function test_without_a_key_the_open_source_build_loads(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/settings.php';
        if (test_db_available() && setting_is_set('tinymce_api_key')) $this->markTestSkipped('this database has a Tiny Cloud key');
        $tag = tinymce_script_tag();
        $this->assertStringContainsString(TINYMCE_OSS_URL, $tag);
        $this->assertStringContainsString('license_key: "gpl"', $tag);
        $this->assertMatchesRegularExpression('#tinymce@\d+\.\d+\.\d+/#', TINYMCE_OSS_URL, 'pinned to an exact version');
    }
}
