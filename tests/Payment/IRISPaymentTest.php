<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * IRISPayment request building — no network: request() is replaced by a spy.
 */
#[Group('payment')]
final class IRISPaymentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
    }

    /** IRISPayment whose HTTP call is recorded instead of sent. */
    private function spy(string $merchantKey): IRISPayment
    {
        return new class($merchantKey) extends IRISPayment {
            public array $calls = [];
            public function __construct(string $key)
            {
                parent::__construct();
                // merchantKey is private to IRISPayment — set it in that class's scope.
                \Closure::bind(fn() => $this->merchantKey = $key, $this, IRISPayment::class)();
            }
            protected function request(string $method, string $path, ?array $payload = null): array
            {
                $this->calls[] = compact('method', 'path', 'payload');
                return ['paymentLink' => 'https://example.test/pay', 'paymentHash' => 'hash123'];
            }
        };
    }

    private function registerArgs(): array
    {
        return [
            'currency' => 'EUR', 'amountEur' => 19.76, 'name' => 'Поръчка OM-20260919-B60E',
            'description' => 'Тест', 'orderId' => 'OM-20260919-B60E',
            'redirectUrl' => 'https://example.test/return', 'hookUrl' => 'https://example.test/hook', 'lang' => 'bg',
        ];
    }

    public function testMerchantKeyWithSpecialCharactersIsUrlEncoded(): void
    {
        // Real keys can contain ^ $ @ = — unencoded, IRIS answers a bare HTTP 400.
        $iris = $this->spy('ab^c$d@e=f');
        $iris->register($this->registerArgs());

        $path = $iris->calls[0]['path'];
        $this->assertSame('/backend/payment/external/ab%5Ec%24d%40e%3Df', $path);
        $this->assertDoesNotMatchRegularExpression('/[\^$@=\s]/', $path);
    }

    public function testPlainMerchantKeyIsUnchanged(): void
    {
        $iris = $this->spy('abc123-DEF');
        $iris->register($this->registerArgs());

        $this->assertSame('/backend/payment/external/abc123-DEF', $iris->calls[0]['path']);
    }
}
