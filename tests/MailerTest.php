<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards Cyrillic encoding in outgoing email HTML.
 *
 * These tests do not send real mail — they verify that the rendering
 * functions produce valid UTF-8 HTML and that Cyrillic strings survive
 * the full template pipeline untouched.
 */
final class MailerTest extends TestCase
{
    // ── email_wrap ────────────────────────────────────────────────────────────

    public function test_email_wrap_is_valid_utf8(): void
    {
        $html = email_wrap('<p>Здравей свят</p>');
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'email_wrap output must be valid UTF-8');
    }

    public function test_email_wrap_preserves_cyrillic(): void
    {
        $cyrillic = 'Тест кирилица — Здравей!';
        $html = email_wrap('<p>' . $cyrillic . '</p>');
        $this->assertStringContainsString($cyrillic, $html);
    }

    public function test_email_wrap_declares_utf8_charset(): void
    {
        $html = email_wrap('<p>test</p>');
        $this->assertStringContainsString('charset="UTF-8"', $html);
    }

    // ── email_tpl_get ─────────────────────────────────────────────────────────

    public function test_tpl_subject_is_valid_utf8_bg(): void
    {
        $tpl = email_tpl_get('campaign-ticket', 'bg', ['event_name' => 'Нашето събитие', 'pledge_number' => 'CP-001']);
        $this->assertTrue(mb_check_encoding($tpl['subject'], 'UTF-8'));
        $this->assertNotEmpty($tpl['subject']);
    }

    public function test_tpl_intro_is_valid_utf8_bg(): void
    {
        $tpl = email_tpl_get('campaign-ticket', 'bg', ['event_name' => 'Нашето събитие', 'name' => 'Мария', 'pledge_number' => 'CP-001']);
        $this->assertTrue(mb_check_encoding($tpl['intro'], 'UTF-8'));
        $this->assertStringContainsString('Мария', $tpl['intro']);
    }

    public function test_tpl_subject_preserves_cyrillic(): void
    {
        $tpl = email_tpl_get('order-confirmation-customer', 'bg', [
            'order_number'   => 'OM-001',
            'customer_name'  => 'Иван Петров',
        ]);
        $this->assertTrue(mb_check_encoding($tpl['subject'], 'UTF-8'));
        $this->assertStringContainsString('поръчка', $tpl['subject']);
    }

    /** Placeholder substitution must not mangle multibyte names. */
    public function test_tpl_render_cyrillic_name_in_placeholder(): void
    {
        $tpl = email_tpl_get('donation-confirmation-customer', 'bg', [
            'donor_name' => 'Деляна Василева',
            'amount_eur' => '50.00',
        ]);
        $this->assertStringContainsString('Деляна Василева', $tpl['intro']);
        $this->assertTrue(mb_check_encoding($tpl['intro'], 'UTF-8'));
    }

    // ── email_tpl_render edge cases ───────────────────────────────────────────

    public function test_tpl_render_does_not_double_encode(): void
    {
        $tpl = email_tpl_get('campaign-ticket', 'bg', [
            'event_name'    => 'Събитие & тест',
            'name'          => 'Тест',
            'pledge_number' => 'CP-001',
        ]);
        // htmlspecialchars escapes & → &amp; once; it must not appear as &amp;amp;
        $this->assertStringNotContainsString('&amp;amp;', $tpl['intro']);
    }
}
