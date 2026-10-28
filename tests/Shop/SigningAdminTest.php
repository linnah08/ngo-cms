<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * includes/signer.php — who signs donation certificates: chosen in
 * Admin → Организация (signing_admin_id), else the admin with
 * SIGNING_ADMIN_EMAIL, else nobody.
 */
#[Group('shop')]
#[Group('signature')]
final class SigningAdminTest extends TestCase
{
    private const USERS = [
        ['id' => 1, 'name' => 'Мария',  'email' => 'maria@example.org', 'role' => 'admin'],
        ['id' => 2, 'name' => 'Иван',   'email' => 'ivan@example.org',  'role' => 'admin'],
        ['id' => 3, 'name' => 'Автор',  'email' => 'author@example.org', 'role' => 'author'],
        ['id' => 4, 'name' => 'Магазин', 'email' => 'shop@example.org', 'role' => 'shop_admin'],
    ];

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/signer.php';
    }

    // ── Resolution order ─────────────────────────────────────────────────────────

    public function test_chosen_admin_wins_over_the_fallback_email(): void
    {
        $s = signing_admin_resolve(self::USERS, '2', 'maria@example.org');
        $this->assertSame(['id' => 2, 'email' => 'ivan@example.org', 'name' => 'Иван'], $s);
    }

    public function test_nothing_chosen_falls_back_to_the_admin_with_the_config_email(): void
    {
        $this->assertSame(1, signing_admin_resolve(self::USERS, '', 'maria@example.org')['id']);
        $this->assertSame(1, signing_admin_resolve(self::USERS, '', ' MARIA@example.org ')['id'], 'email match ignores case and spaces');
    }

    public function test_deleted_chosen_user_falls_back_to_the_config_email(): void
    {
        $this->assertSame(1, signing_admin_resolve(self::USERS, '99', 'maria@example.org')['id']);
    }

    public function test_chosen_user_who_is_no_longer_an_admin_falls_back(): void
    {
        $this->assertSame(1, signing_admin_resolve(self::USERS, '3', 'maria@example.org')['id']);
        $this->assertSame(1, signing_admin_resolve(self::USERS, '4', 'maria@example.org')['id']);
    }

    public function test_fallback_email_of_a_non_admin_does_not_sign(): void
    {
        $this->assertNull(signing_admin_resolve(self::USERS, '', 'author@example.org'));
    }

    public function test_nobody_when_neither_resolves(): void
    {
        $this->assertNull(signing_admin_resolve(self::USERS, '', null));
        $this->assertNull(signing_admin_resolve(self::USERS, '', 'nobody@example.org'));
        $this->assertNull(signing_admin_resolve(self::USERS, '99', null));
        $this->assertNull(signing_admin_resolve([], '1', 'maria@example.org'));
    }

    public function test_garbage_chosen_id_is_ignored(): void
    {
        $this->assertNull(signing_admin_resolve(self::USERS, '1 OR 1=1', null));
        $this->assertNull(signing_admin_resolve(self::USERS, '-1', null));
        $this->assertSame(1, signing_admin_resolve(self::USERS, 'abc', 'maria@example.org')['id']);
    }

    // ── admin_is_signer (what admin_can_sign() checks) ───────────────────────────

    public function test_only_the_signer_by_id_can_sign(): void
    {
        $signer = ['id' => 2, 'email' => 'ivan@example.org', 'name' => 'Иван'];
        $this->assertTrue(admin_is_signer(['id' => 2, 'role' => 'admin', 'email' => 'other@example.org'], $signer),
            'compared by id, not by the email in the session');
        $this->assertFalse(admin_is_signer(['id' => 1, 'role' => 'admin', 'email' => 'ivan@example.org'], $signer),
            'the same email on another account does not sign');
        $this->assertFalse(admin_is_signer(['id' => 2, 'role' => 'author'], $signer));
        $this->assertFalse(admin_is_signer([], $signer));
        $this->assertFalse(admin_is_signer(['id' => 2, 'role' => 'admin'], null), 'nobody signs when no signer resolves');
    }

    public function test_admin_can_sign_is_false_when_logged_out(): void
    {
        $saved = $_SESSION ?? [];
        unset($_SESSION[ADMIN_SESSION_NAME]);
        try {
            $this->assertFalse(admin_can_sign());
        } finally {
            $_SESSION = $saved;
        }
    }

    // ── Организация: the chosen id must be an admin ─────────────────────────────

    public function test_validate_accepts_an_admin_id_and_empty(): void
    {
        $this->assertSame(['value' => '2', 'error' => null], signing_admin_validate(' 2 ', [1, 2]));
        $this->assertSame(['value' => '', 'error' => null], signing_admin_validate('', [1, 2]));
    }

    public function test_validate_rejects_ids_that_are_not_admins(): void
    {
        foreach (['3', '99', 'abc', '1; DROP TABLE x', '0', '-2', '2.0'] as $bad) {
            $r = signing_admin_validate($bad, [1, 2]);
            $this->assertSame('', $r['value'], $bad);
            $this->assertNotNull($r['error'], $bad);
        }
    }

    public function test_org_validate_checks_the_signer_when_given_the_admin_ids(): void
    {
        $in = ['site_name_bg' => 'Тест', 'site_name_en' => 'Test', 'site_email' => 'info@example.org',
               'brand_theme' => 'classic', 'brand_primary' => '#112233', 'brand_accent' => '#445566'];
        $certsOn = module_certificates_enabled();

        $ok = org_validate($in + ['signing_admin_id' => '2'], ['classic'], [1, 2]);
        $bad = org_validate($in + ['signing_admin_id' => '3'], ['classic'], [1, 2]);
        if ($certsOn) {
            $this->assertSame('2', $ok['values']['signing_admin_id']);
            $this->assertArrayNotHasKey('signing_admin_id', $ok['errors']);
            $this->assertArrayHasKey('signing_admin_id', $bad['errors'], 'a non-admin id is refused');
        } else {
            $this->assertArrayNotHasKey('signing_admin_id', $ok['values'], 'hidden without certificates: the saved value is kept');
            $this->assertArrayNotHasKey('signing_admin_id', $bad['errors']);
        }

        // Without the admin list (no lookup), the posted value is never saved.
        $none = org_validate($in + ['signing_admin_id' => '3'], ['classic']);
        $this->assertArrayNotHasKey('signing_admin_id', $none['values']);
        $this->assertArrayNotHasKey('signing_admin_id', $none['errors']);
    }

    public function test_setting_is_stored_with_the_other_organisation_settings(): void
    {
        $this->assertSame('SIGNING_ADMIN_ID', org_fields()['signing_admin_id'] ?? null);
        $file = sys_get_temp_dir() . '/om-signer-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $this->assertTrue(org_save_overrides(['signing_admin_id' => '7'], $file));
            $this->assertSame('7', org_load_overrides($file)['SIGNING_ADMIN_ID'] ?? null);
        } finally {
            @unlink($file);
        }
    }

    // ── Notice when nobody can sign ─────────────────────────────────────────────

    public function test_notice_only_when_certificates_on_and_no_signer(): void
    {
        $signer = ['id' => 1, 'email' => 'a@example.org', 'name' => 'A'];
        $this->assertSame('', signer_missing_notice_html($signer, true, true));
        $this->assertSame('', signer_missing_notice_html(null, false, true));

        $admin = signer_missing_notice_html(null, true, true);
        $this->assertStringContainsString('role="status"', $admin);
        $this->assertStringContainsString('href="/admin/organisation.php#f-signing_admin_id"', $admin);
        $this->assertStringContainsString('Подписва сертификатите за дарение', $admin);

        $staff = signer_missing_notice_html(null, true, false);
        $this->assertStringNotContainsString('href=', $staff, 'shop staff cannot open Организация');
        $this->assertStringContainsString('Помолете администратор', $staff);
    }

    public function test_organisation_page_has_a_labelled_signer_select_with_the_anchor(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/organisation.php');
        $this->assertStringContainsString('id="f-signing_admin_id"', $src);
        $this->assertStringContainsString('<label for="signing_admin_id"', $src);
        $this->assertStringContainsString('<select id="signing_admin_id" name="signing_admin_id"', $src);
        $this->assertStringContainsString('module_certificates_enabled()', $src);
    }

    // ── Database: candidates are the admins ─────────────────────────────────────

    public function test_candidates_are_only_admins_from_the_database(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $tag = bin2hex(random_bytes(4));
            $ins = $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)');
            $ins->execute(['Signer ' . $tag, "signer-$tag@test.local", 'x', 'admin']);
            $adminId = (int) $pdo->lastInsertId();
            $ins->execute(['Author ' . $tag, "author-$tag@test.local", 'x', 'author']);
            $authorId = (int) $pdo->lastInsertId();

            $ids = array_map('intval', array_column(signing_admin_candidates($pdo), 'id'));
            $this->assertContains($adminId, $ids);
            $this->assertNotContains($authorId, $ids);

            $s = signing_admin_resolve(signing_admin_candidates($pdo), (string) $adminId, null);
            $this->assertSame("signer-$tag@test.local", $s['email']);
            $this->assertNull(signing_admin_resolve(signing_admin_candidates($pdo), (string) $authorId, null));
        } finally {
            $pdo->rollBack();
        }
    }

    // ── Nobody reads SIGNING_ADMIN_EMAIL around the helper ──────────────────────

    public function test_only_config_and_the_helper_read_signing_admin_email(): void
    {
        $root    = $_SERVER['DOCUMENT_ROOT'];
        // config.php mentions it in a comment; the wizard and the example config write it.
        // site.config.php is the site's own settings file, where the constant is defined.
        $allowed = ['includes/signer.php', 'config.php', 'site.config.php', 'site.config.example.php', 'install/wizard-lib.php'];
        $skipDirs = ['vendor', 'node_modules', 'tests', '.git', '.claude', 'content', 'documents', 'uploads'];
        $found   = [];
        $dirs = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $f, string $k, $it): bool => !($it->hasChildren()
                && dirname($f->getPathname()) === $root && in_array($f->getFilename(), $skipDirs, true))
        );
        foreach (new RecursiveIteratorIterator($dirs) as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
            if (in_array($rel, $allowed, true)) continue;
            if (str_contains((string) file_get_contents($f->getPathname()), 'SIGNING_ADMIN_EMAIL')) $found[] = $rel;
        }
        $this->assertSame([], $found, 'Use signing_admin() / signing_admin_email() from includes/signer.php instead.');
    }
}
