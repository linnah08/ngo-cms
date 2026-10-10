<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The events module's pages, run for real (one PHP process per request):
 * admin list + bulk actions, the event form, the door list, the public list
 * and event page in BG and EN, the checkout's refusals, the /tickets/
 * redirect and the old campaign return address forwarding tickets.
 */
#[Group('events')]
final class EventsPagesTest extends TestCase
{
    private static ?PDO $pdo = null;
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private array $events = [];
    private array $pledges = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/events.php';
        if (test_db_available()) {
            self::$pdo = get_pdo();
            if (!events_table_ready(self::$pdo)) self::$pdo = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            $st = proc_get_status(self::$server);
            proc_terminate(self::$server, 9);
            if (!empty($st['pid'])) @exec('kill -9 ' . (int) $st['pid'] . ' 2>/dev/null');
        }
    }

    /** A php -S server on a free port, for checking redirect addresses (the CLI has no headers). */
    private function server(): string
    {
        if (self::$base !== '') return self::$base;
        $sock = @stream_socket_server('tcp://127.0.0.1:0');
        if (!$sock) $this->markTestSkipped('No free port for a test server.');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2)],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) { self::$base = "http://127.0.0.1:$port"; return self::$base; }
            usleep(100_000);
        }
        $this->markTestSkipped('The test server did not start.');
    }

    /** One request to the test server, redirects not followed. Returns [status, location, body]. */
    private function http(string $path, array $post = [], ?string $jar = null): array
    {
        $ch = curl_init($this->server() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
        if ($post) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $loc  = preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hs), $m) ? trim($m[1]) : '';
        return [$code, $loc, substr($raw, $hs)];
    }

    protected function tearDown(): void
    {
        if (!self::$pdo) return;
        foreach ($this->pledges as $n) self::$pdo->prepare('DELETE FROM campaign_pledges WHERE pledge_number = ?')->execute([$n]);
        foreach ($this->events as $id) self::$pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        self::$pdo->prepare("DELETE FROM events WHERE title LIKE 'PHPUnit form %'")->execute();
    }

    private function db(): PDO
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with the events table (migration 043).');
        return self::$pdo;
    }

    private function event(array $over = []): array
    {
        $id = event_save($this->db(), array_merge([
            'title' => 'PHPUnit страница ' . bin2hex(random_bytes(3)), 'title_en' => 'PHPUnit page EN', 'event_date' => date('Y-m-d', strtotime('+5 days')),
            'event_time' => '18:00', 'place' => 'Зала БГ', 'place_en' => 'Hall EN', 'description' => '<p>Описание БГ</p>',
            'description_en' => '<p>Description EN</p>', 'fb_url' => '', 'price_eur' => 8.0, 'capacity' => null, 'published' => 1, 'sales_open' => 1,
        ], $over));
        $this->events[] = $id;
        return event_get($this->db(), $id);
    }

    private function paid(array $e, string $name, int $qty = 1): string
    {
        $n = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $this->db()->prepare("INSERT INTO campaign_pledges (pledge_number, pledge_type, event_id, lang, name, email, amount_eur, ticket_qty, payment_status, ticket_code, ticket_path)
                              VALUES (?, 'ticket', ?, 'bg', ?, 'x@test.invalid', ?, ?, 'paid', 'TKT-20260101-AAAA0000', NULL)")
            ->execute([$n, $e['id'], $name, $e['price_eur'] * $qty, $qty]);
        $this->pledges[] = $n;
        return $n;
    }

    /** A public request: GET/POST $uri served by $rel, no admin session. */
    private function visit(string $rel, string $uri, array $get = [], array $post = [], bool $csrf = false): array
    {
        $spec = ['root' => dirname(__DIR__, 2), 'rel' => $rel, 'uri' => $uri, 'get' => $get, 'post' => $post, 'csrf' => $csrf];
        $code = <<<'PHP'
$s = json_decode($argv[1], true);
$_SERVER['DOCUMENT_ROOT'] = $s['root']; $_SERVER['REQUEST_URI'] = $s['uri']; $_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = $s['post'] ? 'POST' : 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $s['get']; $_POST = $s['post'];
session_id('phpunit' . bin2hex(random_bytes(8)));
require $s['root'] . '/config.php';
start_session();
if ($s['csrf']) $_POST['csrf_token'] = csrf_token();
register_shutdown_function(function () {
    $loc = '';
    foreach (headers_list() as $h) if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    echo "\n@@RESULT@@" . json_encode(['status' => http_response_code() ?: 200, 'location' => $loc, 'session' => $_SESSION ?? []]);
});
include $s['root'] . '/' . $s['rel'];
PHP;
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off -r ' . escapeshellarg($code) . ' ' . escapeshellarg(json_encode($spec)) . ' 2>&1');
        $pos = strrpos($out, "\n@@RESULT@@");
        if ($pos === false) return ['status' => 500, 'body' => $out, 'location' => '', 'session' => []];
        $meta = json_decode(substr($out, $pos + 11), true) ?: [];
        return ['status' => (int) $meta['status'], 'body' => substr($out, 0, $pos), 'location' => (string) $meta['location'], 'session' => $meta['session'] ?? []];
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function test_list_shows_events_with_badges_and_a_bulk_bar(): void
    {
        $e = $this->event(['published' => 0, 'sales_open' => 1]);
        $r = run_admin_page('admin/events.php', [], ['method' => 'GET']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString(h($e['title']), $r['body']);
        $this->assertStringContainsString('Продажбите са включени, но събитието е скрито', $r['body']);
        $this->assertStringContainsString('id="select-all"', $r['body']);
        $this->assertStringContainsString('data-published="0" data-sales="1"', $r['body']);
        $this->assertStringContainsString('/admin/ticket-checklist.php?event=' . $e['id'], $r['body']);

        $past = $this->event(['event_date' => '2001-02-03']);
        $this->assertStringNotContainsString(h($past['title']), $r['body'], 'upcoming is the default filter');
        $r = run_admin_page('admin/events.php', [], ['method' => 'GET', 'get' => ['when' => 'past']]);
        $this->assertStringContainsString(h($past['title']), $r['body']);
        $this->assertStringNotContainsString(h($e['title']), $r['body']);
    }

    public function test_bulk_publish_needs_csrf_and_the_right_role(): void
    {
        $e = $this->event(['published' => 0]);
        $post = ['bulk_action' => 'publish', 'ids' => [$e['id']]];
        $this->assertSame(400, run_admin_page('admin/events.php', $post, ['csrf' => false])['status']);
        $this->assertSame(403, run_admin_page('admin/events.php', $post, ['role' => 'author'])['status']);
        $this->assertSame(302, run_admin_page('admin/events.php', $post, ['role' => null])['status']);
        $this->assertSame(0, (int) event_get($this->db(), (int) $e['id'])['published']);

        $r = run_admin_page('admin/events.php', $post, ['role' => 'shop_admin']);
        $this->assertSame(302, $r['status']);
        $this->assertSame(1, (int) event_get($this->db(), (int) $e['id'])['published']);
    }

    public function test_bulk_delete_keeps_events_with_tickets_and_says_why(): void
    {
        $sold = $this->event();
        $this->paid($sold, 'Купувач');
        $r = run_admin_page('admin/events.php', ['bulk_action' => 'delete', 'ids' => [$sold['id']]]);
        $this->assertNotNull(event_get($this->db(), (int) $sold['id']));
        $this->assertStringContainsString('вече имат продадени билети', json_encode($r['flash'], JSON_UNESCAPED_UNICODE));
    }

    public function test_form_creates_and_edits_an_event(): void
    {
        $title = 'PHPUnit form ' . bin2hex(random_bytes(3));
        $r = run_admin_page('admin/event-edit.php', ['title' => $title, 'title_en' => 'EN', 'event_date' => '2031-01-02',
            'event_time' => '10:00', 'price_eur' => '9.5', 'capacity' => '', 'published' => '1', 'sales_open' => '1',
            'description' => '<p>Здравей</p>', 'description_en' => '']);
        $this->assertSame(302, $r['status'], $r['body']);
        $st = $this->db()->prepare('SELECT * FROM events WHERE title = ?');
        $st->execute([$title]);
        $e = $st->fetch();
        $this->assertSame('9.50', $e['price_eur']);
        $this->assertNull($e['capacity']);

        $r = run_admin_page('admin/event-edit.php', ['title' => '', 'price_eur' => '0', 'sales_open' => '1'], ['get' => ['id' => $e['id']]]);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Събитието не е запазено', $r['body']);
        $this->assertStringContainsString('aria-invalid="true"', $r['body']);
        $this->assertSame($title, event_get($this->db(), (int) $e['id'])['title'], 'nothing saved on an error');

        $r = run_admin_page('admin/event-edit.php', [], ['method' => 'GET', 'get' => ['id' => $e['id']]]);
        $this->assertStringContainsString('data-translate-from="title"', $r['body']);
        $this->assertStringContainsString("selector: '#ev-desc, #ev-desc-en'", $r['body']);
        $this->assertMatchesRegularExpression('#tinymce(@7[.0-9]*|/7)/tinymce\.min\.js#', $r['body'], 'the editor script itself is loaded (Tiny Cloud or the open-source build)');
        $this->assertSame(403, run_admin_page('admin/event-edit.php', [], ['method' => 'GET', 'role' => 'author'])['status']);
    }

    public function test_door_list_has_one_row_per_ticket_sorted_by_name(): void
    {
        $e = $this->event();
        $this->paid($e, 'Яна', 1);
        $this->paid($e, 'Анна', 2);
        $r = run_admin_page('admin/ticket-checklist.php', [], ['method' => 'GET', 'get' => ['event' => $e['id']]]);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('Общо: 3 билета', $r['body']);
        $this->assertLessThan(strpos($r['body'], 'Яна'), strpos($r['body'], 'Анна'));
        $this->assertStringContainsString(h($e['title']), $r['body']);
    }

    // ── Public ───────────────────────────────────────────────────────────────

    public function test_public_list_and_event_page_in_both_languages(): void
    {
        $e = $this->event();
        $bg = $this->visit('sabitiya/index.php', '/sabitiya/');
        $this->assertSame(200, $bg['status'], $bg['body']);
        $this->assertStringContainsString(h($e['title']), $bg['body']);
        $this->assertStringContainsString('/sabitiya/' . $e['slug'] . '/', $bg['body']);

        $page = $this->visit('sabitiya/index.php', '/sabitiya/' . $e['slug'] . '/');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Описание БГ', $page['body']);
        $this->assertStringContainsString('name="event_id" value="' . $e['id'] . '"', $page['body']);
        $this->assertStringContainsString('<label for="tk-email"', $page['body']);

        $en = $this->visit('en/events/index.php', '/en/events/' . $e['slug'] . '/');
        $this->assertSame(200, $en['status']);
        $this->assertStringContainsString('PHPUnit page EN', $en['body']);
        $this->assertStringContainsString('Description EN', $en['body']);
        $this->assertStringContainsString('Continue to card payment', $en['body']);
        $this->assertStringContainsString('name="lang" value="en"', $en['body']);
    }

    public function test_hidden_or_unknown_events_are_404_and_closed_ones_have_no_form(): void
    {
        $hidden = $this->event(['published' => 0]);
        $this->assertSame(404, $this->visit('sabitiya/index.php', '/sabitiya/' . $hidden['slug'] . '/')['status']);
        $this->assertSame(404, $this->visit('sabitiya/index.php', '/sabitiya/no-such-event-xyz/')['status']);
        $closed = $this->event(['sales_open' => 0]);
        $r = $this->visit('sabitiya/index.php', '/sabitiya/' . $closed['slug'] . '/');
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('id="ticketForm"', $r['body']);
        $this->assertStringContainsString('Билетите още не се продават онлайн', $r['body']);
    }

    public function test_checkout_refuses_bad_requests_back_on_the_event_page(): void
    {
        $e = $this->event();
        [$code, $loc] = $this->http('/sabitiya/checkout.php', ['event_id' => $e['id'], 'name' => 'X', 'email' => 'x@test.invalid', 'lang' => 'bg']);
        $this->assertSame(302, $code);
        $this->assertStringStartsWith('/sabitiya/' . $e['slug'] . '/', $loc, 'no CSRF token → back to the form');

        $closed = $this->event(['sales_open' => 0]);
        $jar = tempnam(sys_get_temp_dir(), 'evjar');
        [, , $page] = $this->http('/en/events/' . $e['slug'] . '/', [], $jar);
        $this->assertMatchesRegularExpression('/name="csrf_token" value="([^"]+)"/', $page);
        preg_match('/name="csrf_token" value="([^"]+)"/', $page, $t);
        [$code, $loc] = $this->http('/sabitiya/checkout.php', ['csrf_token' => $t[1], 'event_id' => $closed['id'], 'name' => 'X', 'email' => 'x@test.invalid', 'lang' => 'en'], $jar);
        $this->assertSame(302, $code);
        $this->assertStringStartsWith('/en/events/' . $closed['slug'] . '/', $loc);
        [, , $back] = $this->http($loc, [], $jar);
        $this->assertMatchesRegularExpression('/role="alert"[^>]*>\s*<strong>The ticket was not bought\.<\/strong>/', $back, 'the reason is shown, persistently');
        @unlink($jar);
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM campaign_pledges WHERE event_id = ' . (int) $closed['id'])->fetchColumn());

        [, $loc] = $this->http('/sabitiya/checkout.php', ['event_id' => 999999999, 'lang' => 'bg']);
        $this->assertSame('/sabitiya/', $loc);
    }

    public function test_tickets_address_and_old_return_address_still_work(): void
    {
        [$code, $loc] = $this->http('/tickets/');
        $this->assertSame(302, $code);
        $this->assertStringStartsWith('/sabitiya/', $loc);

        $e = $this->event();
        $n = $this->paid($e, 'Стар билет');
        [, $loc] = $this->http('/api/campaign-payment-return.php?pledge=' . $n . '&mdOrder=abc&evil=x');
        $this->assertSame('/api/event-payment-return.php?pledge=' . $n . '&mdOrder=abc', $loc);
        [, $loc] = $this->http('/api/event-payment-return.php?pledge=' . $n);
        $this->assertSame('/sabitiya/confirmation/?pledge=' . $n, $loc, 'already paid → thank-you page');

        $c = $this->visit('sabitiya/confirmation/index.php', '/sabitiya/confirmation/', ['pledge' => $n]);
        $this->assertSame(200, $c['status']);
        $this->assertStringContainsString('Плащането е получено', $c['body']);
        $this->assertStringNotContainsString('x@test.invalid', $c['body'], 'another browser does not see the buyer\'s email');
    }
}
