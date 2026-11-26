<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The security scan only helps while it actually runs and reports. It runs in
 * GitHub, not here; this guards the wiring, which a later edit to the YAML
 * could quietly break (e.g. a continue-on-error that hides a known CVE).
 */
#[Group('security')]
final class SecurityScanningWorkflowTest extends TestCase
{
    private function read(string $path): string
    {
        $full = dirname(__DIR__, 2) . '/' . $path;
        $this->assertFileExists($full);
        return (string) file_get_contents($full);
    }

    public function test_runs_on_push_weekly_and_on_demand(): void
    {
        $yml = $this->read('.github/workflows/security.yml');
        $this->assertStringContainsString('branches: [main]', $yml);
        $this->assertStringContainsString("cron: '0 6 * * 1'", $yml);
        $this->assertStringContainsString('workflow_dispatch', $yml);
    }

    public function test_composer_audit_fails_the_run(): void
    {
        $yml = $this->read('.github/workflows/security.yml');
        $this->assertStringContainsString('composer audit', $yml);
        $this->assertStringNotContainsString('continue-on-error', $yml, 'A known vulnerability must turn the run red');
    }

    public function test_semgrep_is_pinned(): void
    {
        $this->assertMatchesRegularExpression('/semgrep==\d+\.\d+\.\d+/', $this->read('.github/workflows/security.yml'));
    }

    public function test_semgrep_results_are_uploaded_even_when_findings_exist(): void
    {
        $yml = $this->read('.github/workflows/security.yml');
        $this->assertStringContainsString('upload-sarif', $yml);
        $this->assertStringContainsString('if: always()', $yml);
        $this->assertStringContainsString('security-events: write', $yml);
        $this->assertDoesNotMatchRegularExpression('/semgrep scan[^\n]*--error/', $yml, 'Findings must reach the Security tab, not stop the job first');
    }

    public function test_third_party_code_is_not_scanned(): void
    {
        $lines = array_map('trim', explode("\n", $this->read('.semgrepignore')));
        foreach (['vendor/', 'node_modules/', 'tests/'] as $dir) {
            $this->assertContains($dir, $lines);
        }
    }

    public function test_dependabot_only_opens_security_updates(): void
    {
        $yml = $this->read('.github/dependabot.yml');
        foreach (['composer', 'npm', 'github-actions'] as $eco) {
            $this->assertStringContainsString('package-ecosystem: "' . $eco . '"', $yml);
        }
        $this->assertSame(3, preg_match_all('/^\s+open-pull-requests-limit: 0\s*$/m', $yml),'Routine version-bump PRs stay off; security PRs are unaffected');
    }
}
