<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * SMTP mail transport (includes/mailer.php) + admin/email-settings.php save logic.
 *
 * No real email is sent and no network connection is opened: PHPMailer
 * instances are built but never ->send()'d, and the actual delivery step is
 * replaced via mail_set_smtp_sender().
 *
 * DB-backed tests snapshot the raw (encrypted) smtp_* / mail_from_* rows in
 * setUp and restore them exactly in tearDown.
 */
final class MailerSmtpTest extends TestCase
{
    /** @var array<string,string>|null raw encrypted rows, key => stored value */
    private ?array $settingsBackup = null;

    protected function tearDown(): void
    {
        mail_set_smtp_sender(null);
        if ($this->settingsBackup !== null) {
            $pdo = get_pdo();
            $del = $pdo->prepare('DELETE FROM settings WHERE `key` = ?');
            foreach (MAIL_SMTP_SETTING_KEYS as $k) $del->execute([$k]);
            $ins = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
            foreach ($this->settingsBackup as $k => $v) $ins->execute([$k, $v]);
            $this->settingsBackup = null;
        }
    }

    /** Skip unless a DB is available; snapshot + clear SMTP settings for isolation. */
    private function requireCleanSmtpSettings(): void
    {
        if (!defined('DB_HOST') || !defined('SETTINGS_ENCRYPTION_KEY') || SETTINGS_ENCRYPTION_KEY === '') {
            $this->markTestSkipped('No DB / SETTINGS_ENCRYPTION_KEY available.');
        }
        try {
            settings_ensure_table();
            $pdo = get_pdo();
            $placeholders = implode(',', array_fill(0, count(MAIL_SMTP_SETTING_KEYS), '?'));
            $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($placeholders)");
            $stmt->execute(MAIL_SMTP_SETTING_KEYS);
            $this->settingsBackup = [];
            foreach ($stmt->fetchAll() as $row) $this->settingsBackup[$row['key']] = $row['value'];
        } catch (Throwable $e) {
            $this->markTestSkipped('No DB available: ' . $e->getMessage());
        }
        mail_smtp_settings_clear();
    }

    private static function rawSettingValue(string $key): ?string
    {
        $stmt = get_pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private static function cfg(array $overrides = []): array
    {
        return array_merge([
            'host'       => 'mail.example.org',
            'port'       => 465,
            'encryption' => 'ssl',
            'username'   => 'info@example.org',
            'password'   => 's3cret pass',
            'from_email' => 'info@example.org',
            'from_name'  => 'Сдружение Тест',
        ], $overrides);
    }

    private static function validPost(array $overrides = []): array
    {
        return array_merge([
            'smtp_host'       => 'mail.example.org',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_username'   => 'info@example.org',
            'smtp_password'   => 'hunter2',
            'mail_from_email' => 'info@example.org',
            'mail_from_name'  => 'Сдружение Тест',
        ], $overrides);
    }

    // ── Transport selection (pure) ──────────────────────────────────────────────

    public function test_smtp_wins_over_graph(): void
    {
        $this->assertSame('smtp', mail_select_transport(true, true));
        $this->assertSame('smtp', mail_select_transport(true, false));
    }

    public function test_graph_used_when_no_smtp(): void
    {
        $this->assertSame('graph', mail_select_transport(false, true));
    }

    public function test_none_when_nothing_configured(): void
    {
        $this->assertSame('none', mail_select_transport(false, false));
    }

    public function test_smtp_config_complete_requires_host(): void
    {
        $this->assertTrue(mail_smtp_config_is_complete(self::cfg()));
        $this->assertTrue(mail_smtp_config_is_complete(self::cfg(['username' => '', 'password' => ''])));
        $this->assertFalse(mail_smtp_config_is_complete(self::cfg(['host' => ''])));
        $this->assertFalse(mail_smtp_config_is_complete(self::cfg(['host' => '   '])));
    }

    public function test_transport_from_db_settings(): void
    {
        $this->requireCleanSmtpSettings();

        // bootstrap.php defines stub GRAPH_* constants, so with no SMTP → graph.
        $this->assertSame(mail_graph_is_configured() ? 'graph' : 'none', mail_transport());

        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost())['values']);
        $this->assertSame('smtp', mail_transport());
        $this->assertTrue(mail_is_configured());

        mail_smtp_settings_clear();
        $this->assertNotSame('smtp', mail_transport());
    }

    // ── Password encryption ─────────────────────────────────────────────────────

    public function test_encrypt_decrypt_round_trip(): void
    {
        if (!defined('SETTINGS_ENCRYPTION_KEY') || SETTINGS_ENCRYPTION_KEY === '') {
            $this->markTestSkipped('SETTINGS_ENCRYPTION_KEY not defined.');
        }
        $plain = 'Пар0ла с интервал & символи!';
        $enc   = settings_encrypt($plain);
        $this->assertNotSame($plain, $enc);
        $this->assertStringNotContainsString($plain, $enc);
        $this->assertSame($plain, settings_decrypt($enc));
    }

    public function test_saved_password_is_not_stored_in_plaintext(): void
    {
        $this->requireCleanSmtpSettings();
        $password = 'plaintext-canary-' . bin2hex(random_bytes(4));

        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost(['smtp_password' => $password]))['values']);

        $raw = self::rawSettingValue('smtp_password');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($password, $raw);
        $this->assertStringNotContainsString($password, (string) base64_decode($raw));
        $this->assertSame($password, setting_get('smtp_password'));
        $this->assertSame($password, mail_smtp_config()['password']);
    }

    // ── Empty password keeps existing ───────────────────────────────────────────

    public function test_empty_password_is_omitted_from_values(): void
    {
        $r = mail_smtp_validate_input(self::validPost(['smtp_password' => '']));
        $this->assertSame([], $r['errors']);
        $this->assertArrayNotHasKey('smtp_password', $r['values']);

        $r = mail_smtp_validate_input(array_diff_key(self::validPost(), ['smtp_password' => 1]));
        $this->assertArrayNotHasKey('smtp_password', $r['values']);
    }

    public function test_new_password_is_included_untrimmed(): void
    {
        $r = mail_smtp_validate_input(self::validPost(['smtp_password' => ' pa ss ']));
        $this->assertSame(' pa ss ', $r['values']['smtp_password']);
    }

    public function test_empty_password_keeps_existing_in_db(): void
    {
        $this->requireCleanSmtpSettings();

        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost(['smtp_password' => 'original-pass']))['values']);
        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost([
            'smtp_password' => '',
            'smtp_host'     => 'smtp.other.example.org',
        ]))['values']);

        $this->assertSame('original-pass', setting_get('smtp_password'));
        $this->assertSame('smtp.other.example.org', setting_get('smtp_host'));

        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost(['smtp_password' => 'changed']))['values']);
        $this->assertSame('changed', setting_get('smtp_password'));
    }

    public function test_save_ignores_unknown_keys(): void
    {
        $this->requireCleanSmtpSettings();
        $key = 'mailer_smtp_test_should_not_exist';
        mail_smtp_settings_save(['smtp_host' => 'mail.example.org', $key => 'x']);
        $this->assertNull(self::rawSettingValue($key));
    }

    // ── Input validation ────────────────────────────────────────────────────────

    public function test_valid_input_has_no_errors(): void
    {
        $r = mail_smtp_validate_input(self::validPost());
        $this->assertSame([], $r['errors']);
        $this->assertSame('mail.example.org', $r['values']['smtp_host']);
        $this->assertSame('587', $r['values']['smtp_port']);
        $this->assertSame('tls', $r['values']['smtp_encryption']);
    }

    /** @return array<string,array{string}> */
    public static function badHosts(): array
    {
        return [
            'empty'        => [''],
            'url'          => ['https://mail.example.org'],
            'with path'    => ['mail.example.org/x'],
            'spaces'       => ['mail example org'],
            'no dot'       => ['mailserver'],
            'header chars' => ["mail.example.org\r\nX: y"],
            'too long'     => [str_repeat('a', 250) . '.org'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badHosts')]
    public function test_bad_host_rejected(string $host): void
    {
        $r = mail_smtp_validate_input(self::validPost(['smtp_host' => $host]));
        $this->assertNotEmpty($r['errors']);
    }

    public function test_host_accepts_ip_and_is_lowercased(): void
    {
        $this->assertSame([], mail_smtp_validate_input(self::validPost(['smtp_host' => '192.0.2.10']))['errors']);
        $r = mail_smtp_validate_input(self::validPost(['smtp_host' => '  Mail.Example.ORG ']));
        $this->assertSame([], $r['errors']);
        $this->assertSame('mail.example.org', $r['values']['smtp_host']);
    }

    /** @return array<string,array{string}> */
    public static function badPorts(): array
    {
        return [
            'zero' => ['0'], 'too big' => ['65536'], 'negative' => ['-1'],
            'text' => ['abc'], 'float' => ['58.7'], 'mixed' => ['587abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPorts')]
    public function test_bad_port_rejected(string $port): void
    {
        $r = mail_smtp_validate_input(self::validPost(['smtp_port' => $port]));
        $this->assertNotEmpty($r['errors']);
    }

    public function test_port_boundaries_accepted(): void
    {
        $this->assertSame([], mail_smtp_validate_input(self::validPost(['smtp_port' => '1']))['errors']);
        $this->assertSame([], mail_smtp_validate_input(self::validPost(['smtp_port' => '65535']))['errors']);
    }

    public function test_empty_port_defaults_by_encryption(): void
    {
        $this->assertSame('465', mail_smtp_validate_input(self::validPost(['smtp_port' => '', 'smtp_encryption' => 'ssl']))['values']['smtp_port']);
        $this->assertSame('587', mail_smtp_validate_input(self::validPost(['smtp_port' => '', 'smtp_encryption' => 'tls']))['values']['smtp_port']);
        $this->assertSame('25',  mail_smtp_validate_input(self::validPost(['smtp_port' => '', 'smtp_encryption' => 'none']))['values']['smtp_port']);
    }

    public function test_encryption_whitelist(): void
    {
        foreach (['ssl', 'tls', 'none'] as $ok) {
            $this->assertSame([], mail_smtp_validate_input(self::validPost(['smtp_encryption' => $ok]))['errors'], $ok);
        }
        foreach (['starttls', 'SSL', '', 'tls; DROP'] as $bad) {
            $r = mail_smtp_validate_input(self::validPost(['smtp_encryption' => $bad]));
            $this->assertNotEmpty($r['errors'], $bad);
            $this->assertContains($r['values']['smtp_encryption'], MAIL_SMTP_ENCRYPTIONS);
        }
    }

    public function test_from_email_validated_but_optional(): void
    {
        $this->assertSame([], mail_smtp_validate_input(self::validPost(['mail_from_email' => '']))['errors']);
        $this->assertNotEmpty(mail_smtp_validate_input(self::validPost(['mail_from_email' => 'not-an-email']))['errors']);
        $this->assertNotEmpty(mail_smtp_validate_input(self::validPost(['mail_from_email' => "a@b.org\r\nBcc: x@y.org"]))['errors']);
    }

    public function test_from_name_strips_control_chars(): void
    {
        $r = mail_smtp_validate_input(self::validPost(['mail_from_name' => "Име\r\nBcc: x@y.org"]));
        $this->assertStringNotContainsString("\n", $r['values']['mail_from_name']);
        $this->assertStringNotContainsString("\r", $r['values']['mail_from_name']);
    }

    public function test_non_string_input_does_not_crash(): void
    {
        $r = mail_smtp_validate_input(['smtp_host' => ['x'], 'smtp_port' => ['1'], 'smtp_encryption' => ['ssl'], 'smtp_password' => ['p']]);
        $this->assertNotEmpty($r['errors']);
        $this->assertArrayNotHasKey('smtp_password', $r['values']);
    }

    // ── PHPMailer construction (no send) ────────────────────────────────────────

    public function test_builder_configures_ssl_smtp(): void
    {
        $m = mail_build_smtp_mailer(self::cfg(), 'donor@example.com', 'Тема', '<p>Здравей</p>');
        $this->assertInstanceOf(PHPMailer::class, $m);
        $this->assertSame('smtp', $m->Mailer);
        $this->assertSame('mail.example.org', $m->Host);
        $this->assertSame(465, $m->Port);
        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS, $m->SMTPSecure);
        $this->assertTrue($m->SMTPAuth);
        $this->assertSame('info@example.org', $m->Username);
        $this->assertSame('s3cret pass', $m->Password);
        $this->assertSame(0, $m->SMTPDebug);
        $this->assertSame(PHPMailer::CHARSET_UTF8, $m->CharSet);
        $this->assertSame('info@example.org', $m->From);
        $this->assertSame('Сдружение Тест', $m->FromName);
        $this->assertSame('Тема', $m->Subject);
        $this->assertSame('<p>Здравей</p>', $m->Body);
        $this->assertSame('Здравей', $m->AltBody);
        $this->assertSame([['donor@example.com', '']], $m->getToAddresses());
        $this->assertSame([], $m->getReplyToAddresses());
        $this->assertSame([], $m->getAttachments());
    }

    public function test_builder_tls_and_none(): void
    {
        $tls = mail_build_smtp_mailer(self::cfg(['encryption' => 'tls', 'port' => 587]), 'a@example.com', 's', 'b');
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $tls->SMTPSecure);
        $this->assertSame(587, $tls->Port);

        $none = mail_build_smtp_mailer(self::cfg(['encryption' => 'none', 'port' => 25]), 'a@example.com', 's', 'b');
        $this->assertSame('', $none->SMTPSecure);
        $this->assertFalse($none->SMTPAutoTLS);
    }

    public function test_builder_without_username_disables_auth(): void
    {
        $m = mail_build_smtp_mailer(self::cfg(['username' => '', 'password' => 'ignored']), 'a@example.com', 's', 'b');
        $this->assertFalse($m->SMTPAuth);
        $this->assertSame('', $m->Password);
    }

    public function test_builder_from_falls_back_to_site_constants(): void
    {
        $m = mail_build_smtp_mailer(self::cfg(['from_email' => '', 'from_name' => '']), 'a@example.com', 's', 'b');
        $this->assertSame(SITE_EMAIL, $m->From);
        $this->assertSame(SITE_NAME_BG, $m->FromName);
    }

    public function test_builder_reply_to_and_attachments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mailtest');
        file_put_contents($file, '%PDF-1.4 test');
        try {
            $m = mail_build_smtp_mailer(self::cfg(), 'a@example.com', 's', 'b', 'reply@example.com', [
                ['path' => $file, 'name' => 'Сертификат.pdf'],
            ]);
            $this->assertArrayHasKey('reply@example.com', $m->getReplyToAddresses());
            $atts = $m->getAttachments();
            $this->assertCount(1, $atts);
            $this->assertSame($file, $atts[0][0]);
            $this->assertSame('Сертификат.pdf', $atts[0][2]);
        } finally {
            @unlink($file);
        }
    }

    // ── Sending through the injectable sender (no network) ──────────────────────

    public function test_send_smtp_uses_injected_sender(): void
    {
        $captured = null;
        mail_set_smtp_sender(function (PHPMailer $m) use (&$captured): bool { $captured = $m; return true; });

        $this->assertTrue(mail_send_smtp(self::cfg(), 'a@example.com', 'Тема', '<p>x</p>', 'r@example.com'));
        $this->assertInstanceOf(PHPMailer::class, $captured);
        $this->assertSame('Тема', $captured->Subject);
    }

    public function test_send_smtp_failure_returns_false_and_records_error(): void
    {
        mail_set_smtp_sender(function (PHPMailer $m): bool {
            throw new \PHPMailer\PHPMailer\Exception('SMTP Error: Could not authenticate.');
        });
        $prevLog = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse(mail_send_smtp(self::cfg(), 'a@example.com', 's', 'b'));
        } finally {
            ini_set('error_log', (string) $prevLog);
        }
        $this->assertStringContainsString('Could not authenticate', mail_last_error());
    }

    public function test_send_smtp_invalid_recipient_returns_false_without_sending(): void
    {
        $called = false;
        mail_set_smtp_sender(function () use (&$called): bool { $called = true; return true; });
        $prevLog = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse(mail_send_smtp(self::cfg(), 'not-an-email', 's', 'b'));
        } finally {
            ini_set('error_log', (string) $prevLog);
        }
        $this->assertFalse($called);
    }

    public function test_send_mail_routes_to_smtp_when_configured(): void
    {
        $this->requireCleanSmtpSettings();
        mail_smtp_settings_save(mail_smtp_validate_input(self::validPost(['smtp_password' => 'pw-routing']))['values']);

        $captured = null;
        mail_set_smtp_sender(function (PHPMailer $m) use (&$captured): bool { $captured = $m; return true; });

        $this->assertTrue(send_mail('a@example.com', 'Тема', '<p>x</p>', 'reply@example.com'));
        $this->assertNotNull($captured, 'send_mail must use the SMTP transport when SMTP is configured');
        $this->assertSame('mail.example.org', $captured->Host);
        $this->assertSame(587, $captured->Port);
        $this->assertSame('pw-routing', $captured->Password);
        $this->assertArrayHasKey('reply@example.com', $captured->getReplyToAddresses());
    }

    // ── Plain-language errors ───────────────────────────────────────────────────

    public function test_explain_error_is_plain_and_never_leaks_raw_text(): void
    {
        $cases = [
            'SMTP Error: Could not authenticate. secretpw123'                     => 'паролата',
            'SMTP Error: Could not connect to SMTP host. Failed to connect to server' => 'свържем',
            'stream_socket_enable_crypto(): SSL operation failed; certificate verify failed' => 'защитена връзка',
            'SMTP Error: The following recipients failed: x@y.org'                  => 'получателя',
            'Sender address rejected: not owned by user'                            => 'подателя',
            'something totally unexpected'                                          => 'не беше изпратен',
        ];
        foreach ($cases as $raw => $needle) {
            $msg = mail_explain_error($raw);
            $this->assertStringContainsString($needle, $msg, $raw);
            $this->assertStringNotContainsString('secretpw123', $msg);
            $this->assertStringNotContainsString('SMTP Error', $msg);
            $this->assertTrue(mb_check_encoding($msg, 'UTF-8'));
        }
    }

    public function test_transport_labels(): void
    {
        $this->assertStringContainsString('SMTP', mail_transport_label('smtp'));
        $this->assertSame('Microsoft 365', mail_transport_label('graph'));
        $this->assertStringContainsString('Няма', mail_transport_label('none'));
    }
}
