<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('dsk-unit')]
final class DSKBankPaymentTest extends TestCase
{
    #[Group('dsk-unit')]
    public function testIsEnabledReturnsFalseWithoutCredentials(): void
    {
        if (test_dsk_available()) {
            $this->markTestSkipped('DSK credentials are configured — isEnabled() will return true when dsk_enabled=1.');
        }
        $this->assertFalse(DSKBankPayment::isEnabled());
    }
}
