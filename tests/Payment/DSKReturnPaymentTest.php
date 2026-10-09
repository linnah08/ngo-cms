<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';

/**
 * A DSK gateway that answers from a script instead of the network.
 * Each status check pops the next state; refund/reverse answer with the
 * given reply (or throw it, to act like a timeout).
 */
final class ScriptedDSK extends DSKBankPayment
{
    /** @var list<string> endpoints called, in order */
    public array $calls = [];

    /**
     * @param list<int|Throwable> $states   answers to successive status checks
     * @param array<string, array|Throwable> $replies  answer per endpoint (refund.do, reverse.do)
     */
    public function __construct(private array $states, private array $replies = [])
    {
        parent::__construct();
    }

    protected function post(string $endpoint, array $params): array
    {
        $this->calls[] = $endpoint;
        if ($endpoint === 'getOrderStatusExtended.do') {
            $next = array_shift($this->states);
            if ($next === null) throw new LogicException('status checked more often than scripted');
            if ($next instanceof Throwable) throw $next;
            return ['errorCode' => '0', 'orderStatus' => $next];
        }
        $reply = $this->replies[$endpoint] ?? ['errorCode' => '0'];
        if ($reply instanceof Throwable) throw $reply;
        return $reply;
    }

    /** Refund/reverse calls only, without the status checks. */
    public function actions(): array
    {
        return array_values(array_filter($this->calls, fn($c) => $c !== 'getOrderStatusExtended.do'));
    }
}

#[Group('dsk-unit')]
final class DSKReturnPaymentTest extends TestCase
{
    private const IMPOSSIBLE = ['errorCode' => '7', 'errorMessage' => 'Refund is impossible for current transaction state'];

    public function testAlreadyReversedIsDoneWithoutCallingTheBankAgain(): void
    {
        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_REVERSED]);
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertTrue($r['ok']);
        $this->assertSame(DSKBankPayment::STATUS_REVERSED, $r['state']);
        $this->assertSame([], $dsk->actions());
    }

    public function testAlreadyRefundedIsDone(): void
    {
        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_REFUNDED]);
        $this->assertTrue($dsk->returnPayment('uuid', 25.0)['ok']);
        $this->assertSame([], $dsk->actions());
    }

    public function testRefundReportedAsFailedButBankReversedItCountsAsDone(): void
    {
        // What happened to OM-20261009-131C: refund.do answered with an error,
        // yet the payment ended up reversed in DSK.
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_REVERSED],
            ['refund.do' => self::IMPOSSIBLE]
        );
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertTrue($r['ok']);
        $this->assertSame(DSKBankPayment::STATUS_REVERSED, $r['state']);
        $this->assertSame(['refund.do'], $dsk->actions(), 'no second call once the bank says it is back');
    }

    public function testRefundTimingOutButGoingThroughCountsAsDone(): void
    {
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_REFUNDED],
            ['refund.do' => new RuntimeException('DSK Bank cURL error: timeout')]
        );
        $this->assertTrue($dsk->returnPayment('uuid', 25.0)['ok']);
    }

    public function testSettledPaymentIsRefunded(): void
    {
        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_REFUNDED]);
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertTrue($r['ok']);
        $this->assertSame(['refund.do'], $dsk->actions());
    }

    public function testSameDayPaymentFallsBackToReversal(): void
    {
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_REVERSED],
            ['refund.do' => self::IMPOSSIBLE]
        );
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertTrue($r['ok']);
        $this->assertSame(['refund.do', 'reverse.do'], $dsk->actions());
    }

    public function testHeldAmountIsReversedNotRefunded(): void
    {
        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_APPROVED, DSKBankPayment::STATUS_REVERSED]);
        $this->assertTrue($dsk->returnPayment('uuid', 25.0)['ok']);
        $this->assertSame(['reverse.do'], $dsk->actions());
    }

    public function testReversalReportedAsFailedButBankReversedItCountsAsDone(): void
    {
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_APPROVED, DSKBankPayment::STATUS_REVERSED],
            ['reverse.do' => ['errorCode' => '6', 'errorMessage' => 'Reversal is impossible']]
        );
        $this->assertTrue($dsk->returnPayment('uuid', 25.0)['ok']);
    }

    public function testBankRefusingBothLeavesItForTheAdminWithTheReasons(): void
    {
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_DEPOSITED],
            ['refund.do' => self::IMPOSSIBLE, 'reverse.do' => ['errorCode' => '6', 'errorMessage' => 'Reversal is impossible']]
        );
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertFalse($r['ok']);
        $this->assertSame(DSKBankPayment::STATUS_DEPOSITED, $r['state']);
        $this->assertStringContainsString('Refund is impossible', $r['detail']);
        $this->assertStringContainsString('Reversal is impossible', $r['detail']);
    }

    public function testNoStatusFromTheBankMeansNothingIsTried(): void
    {
        $dsk = new ScriptedDSK([new RuntimeException('DSK Bank cURL error: timeout')]);
        $r = $dsk->returnPayment('uuid', 25.0);

        $this->assertFalse($r['ok']);
        $this->assertNull($r['state']);
        $this->assertSame([], $dsk->actions());
    }

    public function testUnpaidOrDeclinedPaymentIsNotTouched(): void
    {
        foreach ([DSKBankPayment::STATUS_CREATED, DSKBankPayment::STATUS_DECLINED, DSKBankPayment::STATUS_AUTH_STARTED] as $state) {
            $dsk = new ScriptedDSK([$state]);
            $r = $dsk->returnPayment('uuid', 25.0);
            $this->assertFalse($r['ok'], "state $state");
            $this->assertSame([], $dsk->actions(), "state $state");
        }
    }

    public function testStatusCodesMatchTheGatewayDocs(): void
    {
        $this->assertSame(3, DSKBankPayment::STATUS_REVERSED);
        $this->assertSame(4, DSKBankPayment::STATUS_REFUNDED);
        $this->assertSame(6, DSKBankPayment::STATUS_DECLINED);
    }
}
