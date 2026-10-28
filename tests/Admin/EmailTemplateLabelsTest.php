<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Labels in Admin → Имейл шаблони describe when each email really goes out. */
final class EmailTemplateLabelsTest extends TestCase
{
    public function test_the_donation_thank_you_is_not_labelled_bank_transfer_only(): void
    {
        // It is sent after every paid donation — by card as well as by bank transfer.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/email-templates.php');
        $this->assertMatchesRegularExpression("/'donation-confirmation-customer' => \\[\\s*'label' => 'Потвърждение на дарение',/u", $src);
        $this->assertStringNotContainsString('Потвърждение на дарение (банков превод)', $src);
    }
}
