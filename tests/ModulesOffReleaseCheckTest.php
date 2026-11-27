<?php
declare(strict_types=1);

use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * Before a release, the suite must also pass as a site with every optional
 * module off (tests/modules-off.sh), since ngo-cms itself runs with all of
 * them on. Guards the pieces: the skip helpers a module's tests call, the
 * script reading the module list from the registry, and the pre-push hook
 * running it for a release tag.
 */
final class ModulesOffReleaseCheckTest extends TestCase
{
    private string|false $runOff = false;

    protected function setUp(): void
    {
        $this->runOff = getenv('NGO_TEST_MODULES_OFF');
    }

    protected function tearDown(): void
    {
        putenv($this->runOff === false ? 'NGO_TEST_MODULES_OFF' : 'NGO_TEST_MODULES_OFF=' . $this->runOff);
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    public function test_a_test_needing_a_switched_off_module_is_skipped_with_its_name(): void
    {
        putenv('NGO_TEST_MODULES_OFF=campaign');
        try {
            skip_unless_modules_on('campaign');
            $this->fail('expected a skip');
        } catch (SkippedWithMessageException $e) {
            $this->assertStringContainsString('„' . module_label('campaign') . '“', $e->getMessage());
        }
    }

    public function test_every_named_module_must_be_on(): void
    {
        putenv('NGO_TEST_MODULES_OFF=social');
        $this->expectException(SkippedWithMessageException::class);
        skip_unless_modules_on('campaign', 'social');
    }

    public function test_a_module_whose_needs_are_off_counts_as_off(): void
    {
        $needy = null;
        foreach (modules_registry() as $name => $m) {
            if (!empty($m['needs'])) { $needy = [$name, $m['needs'][0]]; break; }
        }
        if ($needy === null) $this->markTestSkipped('no module needs another one');
        putenv('NGO_TEST_MODULES_OFF=' . $needy[1]);
        $this->expectException(SkippedWithMessageException::class);
        skip_unless_modules_on($needy[0]);
    }

    public function test_any_one_module_on_is_enough_for_the_any_helper(): void
    {
        putenv('NGO_TEST_MODULES_OFF=shop');
        if (!module_enabled_with_needs('donations')) $this->markTestSkipped('needs Дарения on on this site');
        skip_unless_any_module_on('shop', 'donations');
        putenv('NGO_TEST_MODULES_OFF=shop,donations');
        $this->expectException(SkippedWithMessageException::class);
        skip_unless_any_module_on('shop', 'donations');
    }

    public function test_the_script_switches_off_every_registry_module_and_leaves_out_the_switch_tests(): void
    {
        $sh = (string) file_get_contents(self::root() . '/tests/modules-off.sh');
        $this->assertStringContainsString('array_keys(modules_registry())', $sh, 'the module list comes from the registry, not a hard-coded copy');
        $this->assertStringContainsString('NGO_TEST_MODULES_OFF="$modules"', $sh);
        $this->assertStringContainsString('--exclude-group module-switches', $sh);
        $this->assertTrue(is_executable(self::root() . '/tests/modules-off.sh'));
    }

    public function test_the_pre_push_hook_runs_it_for_a_release_tag(): void
    {
        $hook = (string) file_get_contents(self::root() . '/.githooks/pre-push');
        $this->assertStringContainsString('refs/tags/v*)', $hook);
        $this->assertStringContainsString('/tests/modules-off.sh"', $hook);
        $this->assertMatchesRegularExpression('/modules-off\.sh" --no-progress; then\s+.*\n(?:.*\n)*?\s+exit 1/', $hook, 'a failure blocks the push');
    }

    public function test_the_hook_refuses_a_tag_that_is_not_the_checked_out_commit(): void
    {
        $root = self::root();
        $head = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
        $prev = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD~1 2>/dev/null'));
        if (!preg_match('/^[0-9a-f]{40}$/', $head) || !preg_match('/^[0-9a-f]{40}$/', $prev)) {
            $this->markTestSkipped('needs a git checkout with history');
        }
        $line = "refs/tags/v9.9.9 $prev refs/tags/v9.9.9 " . str_repeat('0', 40) . "\n";
        $proc = proc_open(['bash', $root . '/.githooks/pre-push', 'origin', 'x'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        fwrite($pipes[0], $line);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $rc  = proc_close($proc);
        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('is not the commit checked out here', $out);
    }
}
