<?php
declare(strict_types=1);

namespace SupportRelay;

final class RelayResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = [],
    ) {}
}

/**
 * The whole request pipeline, free of superglobals so it can be unit-tested:
 * method → body size → auth → rate limit → validation → Trello.
 */
final class Relay
{
    public const MAX_BODY_BYTES = 6 * 1024 * 1024;

    public function __construct(
        private readonly InstallRegistry $installs,
        private readonly RateLimiter $rateLimiter,
        private readonly TicketValidator $validator,
        private readonly \Closure $trelloFactory,   // fn(): TrelloClient — lazy so config errors surface as 500
        private readonly Logger $log,
    ) {}

    /** @param array $server $_SERVER-shaped (REQUEST_METHOD, CONTENT_LENGTH, HTTP_X_SUPPORT_KEY) */
    public function handle(array $server, array $post, array $files): RelayResponse
    {
        try {
            return $this->process($server, $post, $files);
        } catch (\Throwable $e) {
            $this->log->error('unhandled', ['type' => get_class($e), 'error' => $e->getMessage()]);
            return self::error(500, 'server_error');
        }
    }

    private function process(array $server, array $post, array $files): RelayResponse
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return new RelayResponse(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);
        }

        $len = $server['CONTENT_LENGTH'] ?? null;
        if ($len !== null && $len !== '' && (!ctype_digit((string) $len) || (int) $len > self::MAX_BODY_BYTES)) {
            return self::error(400, 'invalid');
        }

        $code = $server['HTTP_X_SUPPORT_KEY'] ?? '';
        $install = is_string($code) ? $this->installs->authenticate(trim($code)) : null;
        if ($install === null) {
            return self::error(401, 'unauthorized');
        }
        $who = ['install' => substr($install['hash'], 0, 12), 'name' => $install['name']];

        if (!$this->rateLimiter->hit($install['hash'])) {
            $this->log->info('rate_limited', $who);
            return self::error(429, 'rate_limited');
        }

        try {
            $ticket = $this->validator->validate($post, $files);
        } catch (ValidationException $e) {
            $this->log->info('invalid', $who + ['reason' => $e->getMessage()]);
            return self::error(400, 'invalid');
        }

        $reference = self::newReference();
        $name = CardFormatter::name($install['name'], $ticket['subject']);
        $desc = CardFormatter::description($ticket, $install['name'], $reference);

        try {
            /** @var TrelloClient $trello */
            $trello = ($this->trelloFactory)();
            $card = $trello->createCard($install['trello_list_id'], $name, $desc);
        } catch (\Throwable $e) {
            $this->log->error('trello_create_failed', $who + ['ref' => $reference, 'error' => $e->getMessage()]);
            return self::error(500, 'server_error');
        }

        if ($ticket['screenshot'] !== null) {
            $s = $ticket['screenshot'];
            try {
                $trello->attachFile($card['id'], $s['path'], $s['mime'], 'screenshot-' . $reference . '.' . $s['ext']);
            } catch (\Throwable $e) {
                $this->log->error('trello_attach_failed', $who + ['ref' => $reference, 'card' => $card['id'], 'error' => $e->getMessage()]);
                try {
                    $trello->addComment($card['id'], 'Note: the customer attached a screenshot, but uploading it to Trello failed. See relay log for ref ' . $reference . '.');
                } catch (\Throwable $e2) {
                    $this->log->error('trello_comment_failed', $who + ['ref' => $reference, 'error' => $e2->getMessage()]);
                }
            }
        }

        $this->log->info('ticket_created', $who + ['ref' => $reference, 'card' => $card['id'], 'shortLink' => $card['shortLink']]);
        return new RelayResponse(200, ['ok' => true, 'reference' => $reference]);
    }

    /** 8 chars, unambiguous alphabet (no 0/O/1/I), ~40 bits. Also written into the card for lookup. */
    public static function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $ref = '';
        for ($i = 0; $i < 8; $i++) {
            $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $ref;
    }

    private static function error(int $status, string $error): RelayResponse
    {
        return new RelayResponse($status, ['ok' => false, 'error' => $error]);
    }
}
