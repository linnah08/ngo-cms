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
}
