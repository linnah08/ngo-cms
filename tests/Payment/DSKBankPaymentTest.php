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

    /** A bank answering with this reply to every call. */
    private function dskReplying(array $reply): DSKBankPayment
    {
        return new class($reply) extends DSKBankPayment {
            public function __construct(private array $reply) { parent::__construct(); }
            protected function post(string $endpoint, array $params): array { return $this->reply; }
        };
    }

    #[Group('dsk-unit')]
    public function testGetStatusThrowsWithTheBanksCodeAndMessageOnAnError(): void
    {
        $dsk = $this->dskReplying(['errorCode' => '6', 'errorMessage' => 'Order not found']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DSK Bank status error 6: Order not found');
        $dsk->getStatus('11111111-2222-3333-4444-555555555555');
    }

    #[Group('dsk-unit')]
    public function testGetStatusReturnsTheReplyWhenTheBankReportsNoError(): void
    {
        $reply = ['errorCode' => '0', 'orderStatus' => 2, 'orderNumber' => 'OM-1_1', 'amount' => 1000];
        $this->assertSame($reply, $this->dskReplying($reply)->getStatus('x'));
        // Some replies leave errorCode out entirely on success.
        $this->assertSame(['orderStatus' => 0], $this->dskReplying(['orderStatus' => 0])->getStatus('x'));
    }
}
