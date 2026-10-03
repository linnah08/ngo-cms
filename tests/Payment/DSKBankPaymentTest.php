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
        $this->assertFalse(with_setting('dsk_merchant', '', fn() => DSKBankPayment::isEnabled()));
    }
}
