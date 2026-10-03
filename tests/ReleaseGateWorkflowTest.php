<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The release workflow must never publish a ZIP the release gate has not
 * passed — v0.17.0 was published, installed by a fork, and took it down.
 * The gate itself runs in CI (and locally via tests/release-gate/gate.sh);
 * this only guards the wiring, which a later edit to the YAML could quietly
 * break.
 */
final class ReleaseGateWorkflowTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/build-release.yml');
    }

    public function test_the_gate_runs_before_anything_is_published(): void
    {
        $yml     = $this->workflow();
        $gate    = strpos($yml, 'bash tests/release-gate/gate.sh');
        $publish = strpos($yml, 'gh release create');
        $upload  = strpos($yml, 'actions/upload-artifact');

        $this->assertNotFalse($gate, 'The workflow must run the release gate');
        $this->assertNotFalse($publish);
        $this->assertLessThan($publish, $gate, 'The gate must run before the release asset is published');
        $this->assertLessThan($upload, $gate, 'The gate must run before the build artifact is uploaded');
        $this->assertStringNotContainsString('continue-on-error', $yml, 'A failed gate must stop the workflow');
    }

    public function test_the_zip_the_gate_checks_is_the_one_that_is_published(): void
    {
        $yml = $this->workflow();
        $this->assertStringContainsString('bash tests/release-gate/build-zip.sh "$ZIP"', $yml);
        $this->assertStringContainsString('--new-zip "$ZIP"', $yml);
    }

    public function test_gate_scripts_exist_and_are_not_shipped(): void
    {
        $dir = dirname(__DIR__) . '/tests/release-gate';
        foreach (['gate.sh', 'gate.php', 'apply.php', 'boot-check.php', 'build-zip.sh'] as $f) {
            $this->assertFileExists($dir . '/' . $f);
        }
        // tests/ never goes into the release ZIP, so neither does the gate.
        $this->assertStringContainsString('"tests/*"', (string) file_get_contents($dir . '/build-zip.sh'));
    }
}
