<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * "Имейл до клиента" — includes/order_email_composer.php: which ready-made
 * messages the picker offers, how they are filled in, and what a send does.
 * No email is sent: every send passes a capturing mailer.
 */
#[Group('order-email-history')]
final class OrderEmailComposerTest extends TestCase
{
    /** @var list<array{order_id:int,to:string,subject:string,html:string,opts:array}> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_email_composer.php';
    }

    private function mailer(bool $ok = true): callable
    {
        return function (int $order_id, string $to, string $subject, string $html, array $opts) use ($ok): bool {
            $this->sent[] = compact('order_id', 'to', 'subject', 'html', 'opts');
            return $ok;
        };
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7, 'type' => 'physical', 'status' => 'new', 'lang' => 'bg',
            'customer_name' => 'Мария Иванова', 'customer_email' => 'maria@example.com',
            'order_number' => 'ORD-20260924-AB12', 'total_eur' => '19.76',
            'payment_method' => 'cod', 'payment_status' => 'pending',
        ];
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    // ── presets ───────────────────────────────────────────────────────────────

    public function testEveryPresetHasEditableDefaultTextInBothLanguages(): void
    {
        $defaults = _email_tpl_defaults();
        foreach (admin_message_presets() as $key => $meta) {
            $this->assertNotSame('', $meta['label']);
            $this->assertNotEmpty($meta['types'], "$key must say which orders it is for");
            foreach (['subject_bg', 'intro_bg', 'subject_en', 'intro_en'] as $f) {
                $this->assertNotSame('', $defaults[$key][$f] ?? '', "$key is missing $f");
            }
        }
    }

    public function testPresetsAreListedInTheEmailTemplatesEditor(): void
    {
        $src = (string)file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/email-templates.php');
        $this->assertStringContainsString('admin_message_presets()', $src);
    }

    public function testPresetWordingIsGeneric(): void
    {
        $all = json_encode([_email_tpl_defaults(), admin_message_presets()], JSON_UNESCAPED_UNICODE);
        // The defaults fill in this site's own name and email on purpose — on a
        // fork like lafetki that IS a brand. Only hard-coded brands are wrong.
        foreach (['SITE_NAME_BG', 'SITE_NAME_EN', 'SITE_EMAIL'] as $const) {
            if (defined($const) && constant($const) !== '') $all = str_ireplace((string) constant($const), '', $all);
        }
        foreach (['Odd Minds', 'oddminds', 'Лафетки', 'lafetki'] as $brand) {
            $this->assertStringNotContainsStringIgnoringCase($brand, $all);
        }
    }

    public function testShopOrderGetsShopPresetsFilledInItsLanguage(): void
    {
        $bg = order_email_choices($this->order());
        $this->assertArrayHasKey('admin-product-unavailable', $bg);
        $this->assertArrayHasKey('admin-order-delayed', $bg);
        $this->assertArrayHasKey('admin-need-info', $bg);
        $this->assertSame(ORDER_EMAIL_BLANK, array_key_last($bg), 'The blank choice comes last');

        foreach ($bg as $key => $c) {
            $this->assertTrue($c['available'], $key);
            $this->assertStringNotContainsString('{{', $c['subject'] . $c['body'], "$key left a placeholder");
        }
        $this->assertStringContainsString('ORD-20260924-AB12', $bg['admin-order-delayed']['subject']);
        $this->assertStringContainsString('Мария Иванова', $bg['admin-order-delayed']['body']);

        $en = order_email_choices($this->order(['lang' => 'en']));
        $this->assertStringContainsString('Hello Мария Иванова', $en[ORDER_EMAIL_BLANK]['body']);
        $this->assertStringContainsString('Здравейте, Мария Иванова', $bg[ORDER_EMAIL_BLANK]['body']);
    }

    public function testPledgeDoesNotGetShopOnlyPresets(): void
    {
        $choices = order_email_choices($this->order(['type' => 'pledge']));
        $this->assertArrayNotHasKey('admin-product-unavailable', $choices);
        $this->assertArrayNotHasKey('admin-order-delayed', $choices);
        $this->assertArrayHasKey('admin-need-info', $choices);
        $this->assertArrayHasKey(ORDER_EMAIL_BLANK, $choices);
    }

    public function testBuyerNameIsEscapedInTheBody(): void
    {
        $c = order_email_choices($this->order(['customer_name' => '<b>Иван</b>']));
        $this->assertStringContainsString('&lt;b&gt;Иван&lt;/b&gt;', $c[ORDER_EMAIL_BLANK]['body']);
        $this->assertStringContainsString('&lt;b&gt;Иван&lt;/b&gt;', $c['admin-need-info']['body']);
    }

    // ── sending ───────────────────────────────────────────────────────────────

    public function testSendGoesThroughTheOrderMailerWithTheLayoutAndReplyTo(): void
    {
        $r = order_email_send($this->pdo(), 7, $this->order(), "Тема\r\nBcc: x@evil.test", '<p>Здравейте!</p>', 'admin-order-delayed', $this->mailer());

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $this->sent);
        $s = $this->sent[0];
        $this->assertSame(7, $s['order_id']);
        $this->assertSame('maria@example.com', $s['to']);
        $this->assertStringNotContainsString("\n", $s['subject'], 'No header injection through the subject');
        $this->assertStringContainsString('<p>Здравейте!</p>', $s['html']);
        $this->assertStringContainsString('<html', strtolower($s['html']), 'Wrapped in the site email layout');
        $this->assertSame('admin-order-delayed', $s['opts']['template_key']);
        $this->assertSame(defined('SITE_EMAIL') ? SITE_EMAIL : '', $s['opts']['reply_to']);
    }

    public function testBlankOrUnknownPresetIsRecordedAsAManualMessage(): void
    {
        foreach ([ORDER_EMAIL_BLANK, '', 'order-shipped-customer', 'admin-product-unavailable'] as $preset) {
            $order = $preset === 'admin-product-unavailable' ? $this->order(['type' => 'pledge']) : $this->order();
            $this->sent = [];
            order_email_send($this->pdo(), 7, $order, 'Тема', '<p>Текст</p>', $preset, $this->mailer());
            $this->assertSame('admin-message', $this->sent[0]['opts']['template_key'], "preset '$preset'");
        }
    }

    public function testNothingIsSentWithoutSubjectBodyOrAddress(): void
    {
        $cases = [
            [$this->order(), '   ', '<p>Текст</p>', 'Въведете тема'],
            [$this->order(), 'Тема', '<p>&nbsp;</p>', 'Въведете съобщение'],
            [$this->order(), 'Тема', '<p> </p><br>', 'Въведете съобщение'],
            [$this->order(['customer_email' => 'not-an-email']), 'Тема', '<p>Текст</p>', 'имейл адрес'],
        ];
        foreach ($cases as [$order, $subject, $body, $expect]) {
            $r = order_email_send($this->pdo(), 7, $order, $subject, $body, '', $this->mailer());
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString($expect, $r['error']);
        }
        $r = order_email_send($this->pdo(), 0, $this->order(), 'Тема', '<p>Текст</p>', '', $this->mailer());
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->sent);

        // An image alone is a message.
        $this->assertTrue(order_email_send($this->pdo(), 7, $this->order(), 'Тема', '<p><img src="/x.png" alt="x"></p>', '', $this->mailer())['ok']);
    }

    public function testFailedSendSaysSoInPlainLanguage(): void
    {
        $r = order_email_send($this->pdo(), 7, $this->order(), 'Тема', '<p>Текст</p>', '', $this->mailer(false));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('не беше изпратен', $r['error']);
    }

    public function testManualMessagesHaveReadableHistoryLabels(): void
    {
        foreach (array_keys(admin_message_presets()) as $key) {
            $this->assertNotSame('Имейл', order_email_kind_label($key), "$key needs a history label");
        }
        $this->assertSame('Ръчно съобщение', order_email_kind_label('admin-message'));
    }
}
