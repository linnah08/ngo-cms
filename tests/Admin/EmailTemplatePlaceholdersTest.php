<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/email-templates.php';

/**
 * Every placeholder Admin → Имейл шаблони offers for a template is filled by
 * the code that sends it — otherwise an admin who uses it sends a raw {{…}}.
 */
final class EmailTemplatePlaceholdersTest extends TestCase
{
    /** Template key => the files that send it. A template offered in the admin must be listed here. */
    private const SENDERS = [
        'campaign-confirmation'            => ['includes/emails/campaign-confirmation.php'],
        'campaign-ticket'                  => ['includes/emails/campaign-ticket.php'],
        'order-confirmation-customer'      => ['includes/emails/order-confirmation-customer.php'],
        'order-shipped-customer'           => ['includes/emails/order-shipped-customer.php'],
        'donation-confirmation-customer'   => ['includes/emails/donation-confirmation-customer.php'],
        'order-cancelled-customer'         => ['includes/payment/order_refund.php'],
        'order-payment-failed-customer'    => ['includes/payment/unpaid_orders.php', 'includes/order_email_composer.php'],
        'donation-payment-failed-customer' => ['includes/payment/unpaid_orders.php', 'includes/order_email_composer.php'],
        'credit-note-customer'             => ['admin/generate-document.php'],
        'error-alert'                      => ['includes/emails/error-alert.php'],
        'error-digest'                     => ['includes/emails/error-digest.php'],
        'ai-fix-success'                   => ['includes/emails/ai-fix-success.php'],
        'ai-fix-failed'                    => ['includes/emails/ai-fix-failed.php'],
    ];

    /** @return array<string,string[]> key => offered placeholder names */
    private function offered(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/email-templates.php');
        preg_match_all("/'([a-z-]+)' => \\[\\s*'label' => '[^']*',\\s*'vars'  => \\[([^\\]]*)\\]/u", $src, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $key, $vars]) {
            preg_match_all('/\{\{(\w+)\}\}/', $vars, $v);
            $out[$key] = $v[1];
        }
        foreach (admin_message_presets() as $key => $meta) {
            $out[$key] = array_map(fn($s) => trim($s, '{}'), $meta['vars']);
        }
        return $out;
    }

    public function test_every_offered_placeholder_is_filled_by_its_sender(): void
    {
        $offered = $this->offered();
        $this->assertArrayHasKey('order-cancelled-customer', $offered);
        $this->assertContains('amount_eur', $offered['order-cancelled-customer']);

        $base = array_keys(email_tpl_order_vars([]));
        foreach ($offered as $key => $vars) {
            $files = self::SENDERS[$key] ?? (array_key_exists($key, admin_message_presets()) ? ['includes/order_email_composer.php'] : null);
            $this->assertNotNull($files, "$key is offered in the admin — list who sends it in SENDERS");
            foreach ($files as $file) {
                $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
                $uses_base = str_contains($src, 'email_tpl_order_vars(');
                foreach ($vars as $var) {
                    $filled = preg_match("/'" . $var . "'\\s*=>/", $src) || ($uses_base && in_array($var, $base, true));
                    $this->assertTrue((bool) $filled, "$file sends $key but never fills {{" . $var . "}}");
                }
            }
        }
    }

    public function test_order_vars_carry_name_number_and_total(): void
    {
        $this->assertSame(
            ['customer_name' => 'Мария', 'order_number' => 'AB-1', 'amount_eur' => '1234.50'],
            email_tpl_order_vars(['customer_name' => 'Мария', 'order_number' => 'AB-1', 'total_eur' => '1234.5'])
        );
        $this->assertSame(['customer_name' => '', 'order_number' => '', 'amount_eur' => '0.00'], email_tpl_order_vars([]));
    }
}
