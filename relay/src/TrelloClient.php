<?php
declare(strict_types=1);

namespace SupportRelay;

/** Trello API failure. Message is for the server log only — never echo it to clients. */
final class TrelloException extends \RuntimeException {}

final class TrelloClient
{
    private const BASE = 'https://api.trello.com/1';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $key,
        private readonly string $token,
    ) {
        if ($key === '' || $token === '') {
            throw new TrelloException('Trello key/token not configured');
        }
    }

    /** @return array{id:string, shortLink:string} */
    public function createCard(string $listId, string $name, string $desc): array
    {
        $res = $this->call('postForm', '/cards', [
            'idList' => $listId,
            'name'   => $name,
            'desc'   => $desc,
            'pos'    => 'top',
        ]);
        $data = json_decode($res->body, true);
        if (!is_array($data) || !isset($data['id']) || !is_string($data['id'])) {
            throw new TrelloException('createCard: unexpected response body');
        }
        return ['id' => $data['id'], 'shortLink' => (string) ($data['shortLink'] ?? '')];
    }

    public function attachFile(string $cardId, string $path, string $mime, string $filename): void
    {
        $this->call('postMultipart', '/cards/' . rawurlencode($cardId) . '/attachments', [
            'file'     => new \CURLFile($path, $mime, $filename),
            'name'     => $filename,
            'mimeType' => $mime,
        ]);
    }

    public function addComment(string $cardId, string $text): void
    {
        $this->call('postForm', '/cards/' . rawurlencode($cardId) . '/actions/comments', ['text' => $text]);
    }

    private function call(string $method, string $path, array $fields): HttpResponse
    {
        // Key/token go in the query string (standard Trello REST auth). The URL is never logged.
        $url = self::BASE . $path . '?' . http_build_query(['key' => $this->key, 'token' => $this->token]);
        try {
            $res = $this->http->$method($url, $fields);
        } catch (HttpTransportException $e) {
            throw new TrelloException("$path: " . $e->getMessage(), 0, $e);
        }
        if ($res->status < 200 || $res->status >= 300) {
            throw new TrelloException(sprintf('%s: HTTP %d: %s', $path, $res->status,
                substr(TicketValidator::singleLine($res->body), 0, 300)));
        }
        return $res;
    }
}
