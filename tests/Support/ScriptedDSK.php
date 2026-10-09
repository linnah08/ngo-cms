<?php
declare(strict_types=1);

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
