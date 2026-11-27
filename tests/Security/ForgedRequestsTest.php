<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holes found by the first Semgrep scan and its triage, each pinned by a real
 * request against the page: a forged request (no CSRF token) is refused, and a
 * crafted file path can't reach files outside the campaign's own folder.
 */
#[Group('security')]
final class ForgedRequestsTest extends TestCase
{
    // ── CSRF on JSON / upload endpoints ─────────────────────────────────────

    /** The reply's words, with JSON's \\u escapes decoded. */
    private function text(array $r): string
    {
        $d = json_decode($r['body'], true);
        return is_array($d) ? (string) ($d['message'] ?? $d['error'] ?? '') : $r['body'];
    }

    public function test_email_print_files_refuses_a_request_without_csrf_token(): void
    {
        $r = run_admin_page('admin/email-print-files.php', [], [
            'role' => 'admin', 'csrf' => false,
            'json' => ['order_id' => 1, 'items' => [0], 'email' => 'attacker@example.com'],
        ]);
        $this->assertSame(403, $r['status'], $r['body']);
        $this->assertStringContainsString('Презаредете', $this->text($r));
    }

    public function test_email_print_files_accepts_its_own_page_token(): void
    {
        // Empty data: gets past CSRF and stops at validation, sending nothing.
        $r = run_admin_page('admin/email-print-files.php', [], ['role' => 'admin', 'json' => ['order_id' => 0]]);
        $this->assertNotSame(403, $r['status'], $r['body']);
        $this->assertStringContainsString('Невалидни данни', $this->text($r));
    }

    public function test_inline_upload_refuses_a_request_without_csrf_token(): void
    {
        $r = run_admin_page('admin/inline-upload.php', ['section' => 'home'], ['role' => 'admin', 'csrf' => false]);
        $this->assertSame(403, $r['status'], $r['body']);
        $this->assertStringContainsString('Презаредете', $this->text($r));
        // "Try again" would fail forever — the editor must be told to reload.
        $this->assertSame('session', json_decode($r['body'], true)['reason'] ?? null);
    }

    public function test_article_photo_upload_tells_people_to_reload_on_an_expired_page(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/admin/article-edit.php');
        $this->assertMatchesRegularExpression('/if \(why\.session\)[^\n]*презаредете/iu', $src);
    }

    public function test_inline_upload_accepts_its_own_page_token(): void
    {
        // No file attached: gets past CSRF and stops at "no file".
        $r = run_admin_page('admin/inline-upload.php', ['section' => 'home'], ['role' => 'admin']);
        $this->assertNotSame(403, $r['status'], $r['body']);
        $this->assertStringContainsString('"error":"no file"', $r['body']);
    }

    // ── campaign photos: delete only what the campaign owns ─────────────────

    private function campaignPost(array $post): array
    {
        $r = run_admin_page('admin/campaign.php', ['section' => 'photos'] + $post, ['role' => 'admin']);
        // With Кампания off the page is a 404 and deletes nothing, so these
        // tests would fail, or pass without checking anything.
        if ($r['status'] === 404) $this->markTestSkipped('admin/campaign.php is off on this site (Кампания is switched off).');
        return $r;
    }

    public function test_campaign_photo_delete_cannot_reach_files_outside_its_folder(): void
    {
        if (!test_db_available()) $this->markTestSkipped('needs the local DB');
        $rel  = '/test-results/campaign-delete-sentinel-' . bin2hex(random_bytes(4)) . '.txt';
        $full = ROOT_PATH . $rel;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'keep me');
        try {
            with_setting('campaign_photos', json_encode([$rel]), function () use ($rel) {
                $this->campaignPost(['delete_photo' => $rel]);
                $this->campaignPost(['delete_photo' => '/assets/images/campaign/../../..' . $rel]);
            });
            $this->assertFileExists($full, 'Removing a campaign photo must never delete a file outside /assets/images/campaign/');
        } finally {
            @unlink($full);
        }
    }

    public function test_campaign_photo_delete_still_removes_its_own_upload(): void
    {
        if (!test_db_available()) $this->markTestSkipped('needs the local DB');
        $rel  = '/assets/images/campaign/test-' . bin2hex(random_bytes(4)) . '.jpg';
        $full = ROOT_PATH . $rel;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');
        try {
            with_setting('campaign_photos', json_encode([$rel]), function () use ($rel) {
                $this->campaignPost(['delete_photo' => $rel]);
                $this->assertSame([], json_decode(setting_get('campaign_photos'), true));
            });
            $this->assertFileDoesNotExist($full);
        } finally {
            @unlink($full);
        }
    }

    public function test_campaign_library_photo_rejects_parent_directory_paths(): void
    {
        if (!test_db_available()) $this->markTestSkipped('needs the local DB');
        with_setting('campaign_photos', '[]', function () {
            $this->campaignPost(['image_from_library' => '/assets/images/../../site.config.php']);
            $this->assertSame([], json_decode(setting_get('campaign_photos'), true));
        });
    }
}
