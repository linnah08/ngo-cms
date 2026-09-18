<?php
declare(strict_types=1);

namespace Tests\Relay;

use PHPUnit\Framework\TestCase;
use SupportRelay\CardFormatter;
use SupportRelay\HttpClient;
use SupportRelay\HttpResponse;
use SupportRelay\HttpTransportException;
use SupportRelay\InstallRegistry;
use SupportRelay\Logger;
use SupportRelay\RateLimiter;
use SupportRelay\Relay;
use SupportRelay\RelayResponse;
use SupportRelay\TicketValidator;
use SupportRelay\TrelloClient;

// The relay is standalone: load it directly, never via the CMS bootstrap.
require_once dirname(__DIR__, 2) . '/relay/src/bootstrap.php';

/** Records requests, replays queued responses (or throws queued exceptions). No network. */
final class FakeHttp implements HttpClient
{
    /** @var list<array{kind:string,url:string,fields:array}> */
    public array $calls = [];
    /** @var list<HttpResponse|\Throwable> */
    public array $queue = [];

    public function postForm(string $url, array $fields): HttpResponse      { return $this->next('form', $url, $fields); }
    public function postMultipart(string $url, array $fields): HttpResponse { return $this->next('multipart', $url, $fields); }

    private function next(string $kind, string $url, array $fields): HttpResponse
    {
        $this->calls[] = ['kind' => $kind, 'url' => $url, 'fields' => $fields];
        $r = array_shift($this->queue) ?? new HttpResponse(200, '{}');
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r;
    }
}

final class RelayTest extends TestCase
{
    // 1x1 transparent PNG.
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const LIST_ID = '5f2b9c1e8a7d6b5c4e3f2a1b';

    private string $dir;
    private string $installsFile;
    private InstallRegistry $registry;
    private string $code;
    private FakeHttp $http;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/relay-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->installsFile = $this->dir . '/installs.json';
        $this->registry = new InstallRegistry($this->installsFile);
        $this->code = $this->registry->add('Give Time Foundation', self::LIST_ID);
        $this->http = new FakeHttp();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function relay(int $limit = 10): Relay
    {
        return new Relay(
            $this->registry,
            new RateLimiter($this->dir . '/data', $limit, 3600, fn() => $this->now),
            new TicketValidator(false),
            fn() => new TrelloClient($this->http, 'KEY123', 'TOKEN456'),
            new Logger($this->dir . '/data'),
        );
    }

    private function server(?string $key = null, array $extra = []): array
    {
        $s = $extra + ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '1234'];
        $s['HTTP_X_SUPPORT_KEY'] = $key ?? $this->code;
        return $s;
    }

    private function post(array $override = []): array
    {
        return $override + ['subject' => 'Cart page broken', 'description' => 'Clicking checkout shows an error.'];
    }

    private function send(array $server, array $post, array $files = [], int $limit = 10): RelayResponse
    {
        return $this->relay($limit)->handle($server, $post, $files);
    }

    private function cardOk(): HttpResponse
    {
        return new HttpResponse(200, json_encode(['id' => '65a1b2c3d4e5f6a7b8c9d0e1', 'shortLink' => 'AbCd1234', 'url' => 'https://trello.com/c/AbCd1234']));
    }

    private function fileEntry(string $bytes, string $clientName = 'shot.png', string $clientType = 'image/png'): array
    {
        $path = $this->dir . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);
        return ['screenshot' => ['name' => $clientName, 'type' => $clientType, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]];
    }

    private function logContents(): string
    {
        $f = $this->dir . '/data/logs/relay.log';
        return is_file($f) ? (string) file_get_contents($f) : '';
    }

    // ── method / size ────────────────────────────────────────────────────────

    public function testNonPostIs405(): void
    {
        foreach (['GET', 'PUT', 'OPTIONS', 'HEAD'] as $m) {
            $r = $this->send($this->server(null, ['REQUEST_METHOD' => $m]), $this->post());
            $this->assertSame(405, $r->status, $m);
            $this->assertSame('POST', $r->headers['Allow'] ?? null);
        }
        $this->assertSame([], $this->http->calls);
    }

    public function testOversizedBodyRejectedEarly(): void
    {
        $r = $this->send($this->server(null, ['CONTENT_LENGTH' => (string) (6 * 1024 * 1024 + 1)]), $this->post());
        $this->assertSame(400, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'invalid'], $r->body);
        $this->assertSame([], $this->http->calls);
    }

    // ── auth ─────────────────────────────────────────────────────────────────

    public function testValidKeyCreatesCard(): void
    {
        $this->http->queue[] = $this->cardOk();
        $r = $this->send($this->server(), $this->post());

        $this->assertSame(200, $r->status);
        $this->assertTrue($r->body['ok']);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $r->body['reference']);
        $this->assertSame(['ok', 'reference'], array_keys($r->body), 'no card URL or other fields leaked');

        $this->assertCount(1, $this->http->calls);
        $call = $this->http->calls[0];
        $this->assertSame('form', $call['kind']);
        $this->assertStringStartsWith('https://api.trello.com/1/cards?', $call['url']);
        $this->assertStringContainsString('key=KEY123', $call['url']);
        $this->assertStringContainsString('token=TOKEN456', $call['url']);
        $this->assertSame(self::LIST_ID, $call['fields']['idList']);
        $this->assertSame('top', $call['fields']['pos']);
        $this->assertSame('[Give Time Foundation] Cart page broken', $call['fields']['name']);
        $this->assertStringContainsString($r->body['reference'], $call['fields']['desc']);
    }

    public function testMissingHeaderIsUnauthorized(): void
    {
        $s = $this->server();
        unset($s['HTTP_X_SUPPORT_KEY']);
        $r = $this->send($s, $this->post());
        $this->assertSame(401, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $r->body);
        $this->assertSame([], $this->http->calls);
    }

    public function testUnknownKeyIsUnauthorized(): void
    {
        foreach (['ngo_wrong', '', str_repeat('x', 5000), $this->code . 'x', InstallRegistry::hashCode($this->code)] as $bad) {
            $r = $this->send($this->server($bad), $this->post());
            $this->assertSame(401, $r->status);
            $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $r->body);
        }
        $this->assertSame([], $this->http->calls);
    }

    public function testInactiveKeyLooksExactlyLikeUnknown(): void
    {
        $this->registry->deactivate('Give Time Foundation');
        $inactive = $this->send($this->server(), $this->post());
        $unknown  = $this->send($this->server('ngo_nope'), $this->post());
        $this->assertSame(401, $inactive->status);
        $this->assertEquals($unknown, $inactive);
        $this->assertSame([], $this->http->calls);
    }

    public function testInstallsFileStoresOnlyHashes(): void
    {
        $raw = (string) file_get_contents($this->installsFile);
        $this->assertStringNotContainsString($this->code, $raw);
        $this->assertArrayHasKey(hash('sha256', $this->code), json_decode($raw, true));
    }

    public function testMissingInstallsFileIsServerErrorNotUnauthorized(): void
    {
        unlink($this->installsFile);
        $r = $this->send($this->server(), $this->post());
        $this->assertSame(500, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $r->body);
    }

    // ── validation ───────────────────────────────────────────────────────────

    /** @return array<string, array{array}> */
    public static function invalidPosts(): array
    {
        return [
            'missing subject'       => [['subject' => '']],
            'whitespace subject'    => [['subject' => "  \n "]],
            'subject too long'      => [['subject' => str_repeat('a', 151)]],
            'missing description'   => [['description' => '']],
            'description too long'  => [['description' => str_repeat('a', 5001)]],
            'page too long'         => [['page' => str_repeat('a', 301)]],
            'diagnostics too long'  => [['diagnostics' => json_encode(['x' => str_repeat('a', 20000)])]],
            'diagnostics not json'  => [['diagnostics' => '{not json']],
            'diagnostics scalar'    => [['diagnostics' => '"just a string"']],
            'diagnostics number'    => [['diagnostics' => '42']],
            'array subject'         => [['subject' => ['a', 'b']]],
            'invalid utf8'          => [['description' => "bad \xC3\x28 bytes"]],
        ];
    }

    /** @dataProvider invalidPosts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPosts')]
    public function testValidationFailuresAre400(array $override): void
    {
        $r = $this->send($this->server(), $this->post($override));
        $this->assertSame(400, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'invalid'], $r->body);
        $this->assertSame([], $this->http->calls);
    }

    public function testLimitsAreInclusiveAndMultibyteAware(): void
    {
        $this->http->queue[] = $this->cardOk();
        $r = $this->send($this->server(), $this->post([
            'subject'     => str_repeat('я', 150),                   // 150 chars, 300 bytes
            'description' => str_repeat('д', 5000),
            'page'        => '/admin/' . str_repeat('p', 293),
            'diagnostics' => json_encode(['php' => '8.4', 'cms' => ['version' => '2.3.1']]),
        ]));
        $this->assertSame(200, $r->status);
    }

    public function testControlCharsStrippedAndSubjectSingleLine(): void
    {
        $this->http->queue[] = $this->cardOk();
        $this->send($this->server(), $this->post([
            'subject'     => "Broken\x00\x07 \r\n checkout",
            'description' => "line1\r\nline2\x1B[31m\ttab",
        ]));
        $f = $this->http->calls[0]['fields'];
        $this->assertSame('[Give Time Foundation] Broken checkout', $f['name']);
        $this->assertStringStartsWith("line1\nline2[31m\ttab", $f['desc']);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $f['desc']);
    }

    // ── screenshot ───────────────────────────────────────────────────────────

    public function testRealPngIsAttached(): void
    {
        $this->http->queue[] = $this->cardOk();
        $this->http->queue[] = new HttpResponse(200, '{"id":"att1"}');
        $r = $this->send($this->server(), $this->post(), $this->fileEntry(base64_decode(self::PNG_B64)));

        $this->assertSame(200, $r->status);
        $this->assertCount(2, $this->http->calls);
        $att = $this->http->calls[1];
        $this->assertSame('multipart', $att['kind']);
        $this->assertStringStartsWith('https://api.trello.com/1/cards/65a1b2c3d4e5f6a7b8c9d0e1/attachments?', $att['url']);
        $this->assertInstanceOf(\CURLFile::class, $att['fields']['file']);
        $this->assertSame('image/png', $att['fields']['file']->getMimeType());
        $this->assertSame('screenshot-' . $r->body['reference'] . '.png', $att['fields']['file']->getPostFilename());
    }

    public function testTextFileRenamedToPngIsRejected(): void
    {
        $files = $this->fileEntry("<?php echo 'hi'; ?>\nnot an image", 'shot.png', 'image/png');
        $r = $this->send($this->server(), $this->post(), $files);
        $this->assertSame(400, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'invalid'], $r->body);
        $this->assertSame([], $this->http->calls);
    }

    public function testDisallowedImageTypeRejected(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $r = $this->send($this->server(), $this->post(), $this->fileEntry($gif, 'x.png', 'image/png'));
        $this->assertSame(400, $r->status);
    }

    public function testOversizedScreenshotRejected(): void
    {
        $big = base64_decode(self::PNG_B64) . str_repeat("\0", 5 * 1024 * 1024);
        $r = $this->send($this->server(), $this->post(), $this->fileEntry($big));
        $this->assertSame(400, $r->status);
    }

    public function testUploadErrorAndNoFile(): void
    {
        $r = $this->send($this->server(), $this->post(), ['screenshot' => ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '']]);
        $this->assertSame(400, $r->status);

        $this->http->queue[] = $this->cardOk();
        $r = $this->send($this->server(), $this->post(), ['screenshot' => ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']]);
        $this->assertSame(200, $r->status);
        $this->assertCount(1, $this->http->calls, 'no attachment call when no file');
    }

    public function testValidatorRequiresIsUploadedFileInProduction(): void
    {
        $this->expectException(\SupportRelay\ValidationException::class);
        (new TicketValidator(true))->validate($this->post(), $this->fileEntry(base64_decode(self::PNG_B64)));
    }

    public function testAttachmentFailureStillSucceedsAndComments(): void
    {
        $this->http->queue[] = $this->cardOk();
        $this->http->queue[] = new HttpResponse(500, 'internal attachment explosion');
        $this->http->queue[] = new HttpResponse(200, '{}');
        $r = $this->send($this->server(), $this->post(), $this->fileEntry(base64_decode(self::PNG_B64)));

        $this->assertSame(200, $r->status);
        $this->assertTrue($r->body['ok']);
        $this->assertCount(3, $this->http->calls);
        $this->assertStringContainsString('/actions/comments?', $this->http->calls[2]['url']);
        $this->assertStringContainsString('trello_attach_failed', $this->logContents());
    }

    // ── rate limiting ────────────────────────────────────────────────────────

    public function testRateLimitPerInstallRollingHour(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->http->queue[] = $this->cardOk();
            $this->assertSame(200, $this->send($this->server(), $this->post(), [], 3)->status);
        }
        $r = $this->send($this->server(), $this->post(), [], 3);
        $this->assertSame(429, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'rate_limited'], $r->body);

        // A different install is unaffected.
        $other = $this->registry->add('Other NGO', 'aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->http->queue[] = $this->cardOk();
        $this->assertSame(200, $this->send($this->server($other), $this->post(), [], 3)->status);

        // Rolling window: after an hour the first install can post again.
        $this->now += 3601;
        $this->http->queue[] = $this->cardOk();
        $this->assertSame(200, $this->send($this->server(), $this->post(), [], 3)->status);
    }

    public function testUnauthorizedRequestsDoNotConsumeQuota(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->send($this->server('ngo_bad'), $this->post(), [], 1);
        }
        $this->http->queue[] = $this->cardOk();
        $this->assertSame(200, $this->send($this->server(), $this->post(), [], 1)->status);
    }

    // ── card formatting ──────────────────────────────────────────────────────

    public function testDescriptionFormatting(): void
    {
        $ticket = [
            'subject' => 'x', 'description' => 'Something broke.', 'page' => '/admin/orders.php?id=5',
            'diagnostics' => ['php' => '8.4.1', 'cms' => ['version' => '2.3', 'debug' => false], 'ext' => ['curl', 'gd'], 'null' => null],
            'screenshot' => null,
        ];
        $d = CardFormatter::description($ticket, 'Give Time Foundation', 'ABCD2345', 1_800_000_000);
        $this->assertStringStartsWith("Something broke.\n\n**Page:** /admin/orders.php?id=5", $d);
        $this->assertStringContainsString('**Reference:** ABCD2345', $d);
        $this->assertStringContainsString('**Customer:** Give Time Foundation', $d);
        $this->assertStringContainsString("**Diagnostics**\n", $d);
        $this->assertStringContainsString('- php: 8.4.1', $d);
        $this->assertStringContainsString('- cms.version: 2.3', $d);
        $this->assertStringContainsString('- cms.debug: false', $d);
        $this->assertStringContainsString('- ext.1: gd', $d);
        $this->assertStringContainsString('- null: null', $d);
    }

    public function testDescriptionNeverExceedsTrelloLimit(): void
    {
        $diag = [];
        for ($i = 0; $i < 400; $i++) {
            $diag['key_' . $i] = str_repeat('v', 45);   // ~20k JSON chars → far over budget once formatted
        }
        $ticket = ['subject' => 'x', 'description' => str_repeat('д', 5000), 'page' => str_repeat('p', 300),
                   'diagnostics' => $diag, 'screenshot' => null];
        $d = CardFormatter::description($ticket, str_repeat('N', 100), 'ABCD2345');
        $this->assertLessThanOrEqual(CardFormatter::MAX_DESC, mb_strlen($d));
        $this->assertLessThan(16384, mb_strlen($d));
        $this->assertStringContainsString('more lines truncated)', $d);
        $this->assertStringContainsString('**Reference:** ABCD2345', $d, 'metadata survives truncation');

        // Single huge value is capped too.
        $ticket['diagnostics'] = ['log' => str_repeat('z', 19000)];
        $d = CardFormatter::description($ticket, 'N', 'R');
        $this->assertLessThanOrEqual(CardFormatter::MAX_DESC, mb_strlen($d));
    }

    public function testCardNameTruncated(): void
    {
        $n = CardFormatter::name(str_repeat('C', 200), str_repeat('s', 150));
        $this->assertSame(CardFormatter::MAX_NAME, mb_strlen($n));
        $this->assertStringEndsWith('…', $n);
    }

    // ── Trello failures ──────────────────────────────────────────────────────

    public function testTrelloHttpErrorIsGenericServerError(): void
    {
        $this->http->queue[] = new HttpResponse(401, 'invalid token SECRET_DETAIL at /home/x/support-relay');
        $r = $this->send($this->server(), $this->post());
        $this->assertSame(500, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $r->body);
        $json = json_encode($r->body);
        $this->assertStringNotContainsString('SECRET_DETAIL', $json);
        $this->assertStringNotContainsString('TOKEN456', $json);

        $log = $this->logContents();
        $this->assertStringContainsString('SECRET_DETAIL', $log, 'details go to the server log');
        $this->assertStringNotContainsString('TOKEN456', $log, 'token never logged');
        $this->assertStringNotContainsString($this->code, $log, 'support code never logged');
    }

    public function testTrelloTransportErrorAndGarbageBody(): void
    {
        $this->http->queue[] = new HttpTransportException('Operation timed out after 15000 ms');
        $r = $this->send($this->server(), $this->post());
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $r->body);
        $this->assertSame(500, $r->status);

        $this->http->queue[] = new HttpResponse(200, '<html>not json</html>');
        $r = $this->send($this->server(), $this->post());
        $this->assertSame(500, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $r->body);
    }

    public function testMissingTrelloCredentialsIsServerError(): void
    {
        $relay = new Relay(
            $this->registry,
            new RateLimiter($this->dir . '/data', 10),
            new TicketValidator(false),
            fn() => new TrelloClient($this->http, '', ''),
            new Logger($this->dir . '/data'),
        );
        $r = $relay->handle($this->server(), $this->post(), []);
        $this->assertSame(500, $r->status);
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $r->body);
    }

    // ── CLI ──────────────────────────────────────────────────────────────────

    private function runCli(string $script, array $args): array
    {
        $cfg = $this->dir . '/cli-config.php';
        file_put_contents($cfg, '<?php return ' . var_export([
            'installs_file' => $this->dir . '/cli-installs.json',
            'data_dir'      => $this->dir . '/data',
        ], true) . ';');
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/relay/bin/' . $script], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['RELAY_CONFIG' => $cfg]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string) $out, (string) $err];
    }

    public function testAddInstallCliStoresHashOfPrintedCode(): void
    {
        [$exit, $out] = $this->runCli('add-install.php', ['Sofia Parents Club', 'ari:cloud:trello::list/workspace/abc123/' . self::LIST_ID]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1, preg_match('/^\s+(ngo_[A-Za-z0-9_-]{43})$/m', $out, $m), $out);
        $code = $m[1];

        $raw = (string) file_get_contents($this->dir . '/cli-installs.json');
        $this->assertStringNotContainsString($code, $raw, 'plaintext code never stored');
        $data = json_decode($raw, true);
        $this->assertSame([hash('sha256', $code)], array_keys($data));
        $entry = $data[hash('sha256', $code)];
        $this->assertSame('Sofia Parents Club', $entry['name']);
        $this->assertSame(self::LIST_ID, $entry['trello_list_id'], 'ARI normalised to the bare object id');
        $this->assertTrue($entry['active']);

        // Two runs → two different codes.
        [, $out2] = $this->runCli('add-install.php', ['Second', self::LIST_ID]);
        preg_match('/^\s+(ngo_\S+)$/m', $out2, $m2);
        $this->assertNotSame($code, $m2[1]);

        // And it authenticates.
        $this->assertNotNull((new InstallRegistry($this->dir . '/cli-installs.json'))->authenticate($code));

        // Deactivate via CLI → no longer authenticates.
        [$exit] = $this->runCli('deactivate-install.php', ['sofia parents club']);
        $this->assertSame(0, $exit);
        $this->assertNull((new InstallRegistry($this->dir . '/cli-installs.json'))->authenticate($code));
    }

    public function testAddInstallCliRejectsBadListIdAndUsage(): void
    {
        [$exit, , $err] = $this->runCli('add-install.php', ['X', 'not-a-list-id']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('24-character hex', $err);
        $this->assertFileDoesNotExist($this->dir . '/cli-installs.json');

        [$exit] = $this->runCli('add-install.php', ['only-one-arg']);
        $this->assertSame(2, $exit);
    }

    public function testDeactivateByHashPrefixAndAmbiguity(): void
    {
        $prefix = substr(InstallRegistry::hashCode($this->code), 0, 10);
        $this->assertSame([InstallRegistry::hashCode($this->code)], $this->registry->deactivate($prefix));
        $this->assertNull($this->registry->authenticate($this->code));

        $this->registry->add('Twin', self::LIST_ID);
        $this->registry->add('twin', self::LIST_ID);
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->deactivate('TWIN');
    }

    public function testNormalizeListId(): void
    {
        $this->assertSame(self::LIST_ID, InstallRegistry::normalizeListId(strtoupper(self::LIST_ID)));
        $this->assertSame(self::LIST_ID, InstallRegistry::normalizeListId('ari:cloud:trello::list/workspace/w1/' . self::LIST_ID));
        $this->assertNull(InstallRegistry::normalizeListId('ari:cloud:trello::list/workspace/w1/short'));
        $this->assertNull(InstallRegistry::normalizeListId(self::LIST_ID . '0'));
    }
}
