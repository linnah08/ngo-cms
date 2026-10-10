<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A site whose own config.php the updater keeps (lafetki) still declares
 * feature_enabled() there. Loading includes/organisation.php after it must not
 * fatal — v0.17.0 took such a site down with "Cannot redeclare".
 */
final class OldConfigFeatureEnabledTest extends TestCase
{
    public function test_organisation_loads_after_an_old_config_declared_feature_enabled(): void
    {
        $org  = var_export(dirname(__DIR__) . '/includes/organisation.php', true);
        $code = 'function feature_enabled(string $n): bool { return true; }'
              . ' require ' . $org . ';'
              . ' echo feature_enabled("donations") ? "ok" : "off";';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertSame('ok', trim((string) $out));
    }

    /**
     * Helpers that config.php now loads are loaded again by every file that
     * uses them, so a site that kept an older config.php doesn't fatal on its
     * dashboard, at login, or on the public site while logged in.
     */
    public function test_code_using_new_helpers_loads_them_without_config(): void
    {
        $root  = dirname(__DIR__);
        $needs = [
            '/\b(site_launched|launch_checklist|launch_required_open|launch_tasks_left)\(/' => 'includes/launch.php',
            '/\badmin_session_site\(/' => 'includes/admin_session.php',
        ];
        $files = array_merge(glob($root . '/admin/*.php'), glob($root . '/admin/includes/*.php'), glob($root . '/templates/*.php'));
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            foreach ($needs as $re => $inc) {
                if (preg_match($re, $src)) {
                    $this->assertStringContainsString($inc, $src, str_replace($root . '/', '', $f) . " uses a helper from $inc without loading it");
                }
            }
        }
    }

    public function test_admin_session_helper_loads_after_an_old_config(): void
    {
        $inc  = var_export(dirname(__DIR__) . '/includes/admin_session.php', true);
        $code = 'define("SETTINGS_ENCRYPTION_KEY", str_repeat("k", 32)); require ' . $inc . '; require ' . $inc . ';'
              . ' echo strlen(admin_session_site());';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertSame('32', trim((string) $out));
    }
}
