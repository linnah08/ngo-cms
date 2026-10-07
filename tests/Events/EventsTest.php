<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * includes/events.php — events, seats, buying, payment, fulfilment, door list,
 * bulk actions and the carry-over of the old single-event settings. DB tests
 * use throwaway rows tagged with a random slug prefix and remove them after.
 */
#[Group('events')]
final class EventsTest extends TestCase
{
    private static ?PDO $pdo = null;
    private array $events = [];
    private array $pledges = [];
    private array $files = [];
    private array $settings = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/events.php';
        if (test_db_available()) {
            self::$pdo = get_pdo();
            if (!events_table_ready(self::$pdo)) self::$pdo = null;
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo) {
            foreach ($this->pledges as $num) {
                self::$pdo->prepare("DELETE FROM orders WHERE order_number = ?")->execute([$num]);
                self::$pdo->prepare("DELETE FROM campaign_pledges WHERE pledge_number = ?")->execute([$num]);
            }
            foreach ($this->events as $id) self::$pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            foreach ($this->settings as $k => $old) {
                $old === null ? setting_delete($k) : setting_set($k, $old);
            }
        }
        foreach ($this->files as $f) @unlink($f);
    }

    private function db(): PDO
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with the events table (migration 043).');
        return self::$pdo;
    }

    private function event(array $over = []): array
    {
        $v = array_merge([
            'title' => 'PHPUnit събитие ' . bin2hex(random_bytes(3)), 'title_en' => '', 'event_date' => date('Y-m-d', strtotime('+10 days')),
            'event_time' => '19:00', 'place' => 'Зала', 'place_en' => '', 'description' => '<p>x</p>', 'description_en' => '',
            'fb_url' => '', 'price_eur' => 10.0, 'capacity' => null, 'published' => 1, 'sales_open' => 1,
        ], $over);
        $id = event_save($this->db(), $v);
        $this->events[] = $id;
        return event_get($this->db(), $id);
    }

    private function pledge(array $event, int $qty = 1, string $status = 'paid', string $created = ''): array
    {
        $num = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $this->db()->prepare(
            "INSERT INTO campaign_pledges (pledge_number, pledge_type, event_id, lang, name, email, amount_eur, ticket_qty, payment_status, created_at)
             VALUES (?, 'ticket', ?, 'bg', 'Тест Купувач', 'buyer@test.invalid', ?, ?, ?, ?)"
        )->execute([$num, $event['id'], $event['price_eur'] * $qty, $qty, $status, $created ?: date('Y-m-d H:i:s')]);
        $this->pledges[] = $num;
        return event_ticket_pledge($this->db(), $num);
    }

    private function remember_setting(string $k): void
    {
        if (!array_key_exists($k, $this->settings)) {
            $st = $this->db()->prepare('SELECT 1 FROM settings WHERE `key` = ?');
            $st->execute([$k]);
            $this->settings[$k] = $st->fetchColumn() ? setting_get($k) : null;
        }
    }

    // ── Pure helpers ─────────────────────────────────────────────────────────

    public function test_validate_accepts_a_full_form(): void
    {
        $r = event_validate([
            'title' => '  Пролетен концерт ', 'title_en' => 'Spring concert', 'event_date' => '2030-04-12', 'event_time' => '18:30',
            'place' => 'Читалище', 'price_eur' => '12,50', 'capacity' => '80', 'published' => '1', 'sales_open' => '1',
            'fb_url' => 'https://facebook.com/events/1',
        ]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('Пролетен концерт', $r['values']['title']);
        $this->assertSame(12.5, $r['values']['price_eur']);
        $this->assertSame(80, $r['values']['capacity']);
        $this->assertSame(1, $r['values']['sales_open']);
    }

    public function test_validate_explains_each_problem_in_plain_words(): void
    {
        $r = event_validate(['title' => '', 'event_date' => '2030-02-31', 'event_time' => '25:00', 'price_eur' => 'abc',
                             'capacity' => '0', 'fb_url' => 'javascript:alert(1)', 'published' => ['x']]);
        $this->assertSame(['title', 'event_date', 'event_time', 'fb_url', 'price_eur', 'capacity'], array_keys($r['errors']));
        $this->assertSame(0, $r['values']['published'], 'non-scalar input is just "off"');
    }

    public function test_sales_need_a_price_of_at_least_one_euro(): void
    {
        $r = event_validate(['title' => 'X', 'price_eur' => '0', 'sales_open' => '1']);
        $this->assertStringContainsString('поне 1 EUR', $r['errors']['price_eur']);
        $this->assertSame([], event_validate(['title' => 'X', 'price_eur' => '', 'sales_open' => ''])['errors'], 'a free, not-selling event is fine');
        $this->assertNull(event_validate(['title' => 'X', 'capacity' => ''])['values']['capacity'], 'empty = no limit');
    }

    public function test_text_falls_back_to_bulgarian_and_when_formats_per_language(): void
    {
        $e = ['title' => 'Концерт', 'title_en' => '', 'description' => '<p>БГ</p>', 'description_en' => '<p> </p>',
              'event_date' => '2030-06-01', 'event_time' => '19:00'];
        $this->assertSame('Концерт', event_text($e, 'title', 'en'));
        $this->assertSame('<p>БГ</p>', event_text($e, 'description', 'en'), 'an empty TinyMCE paragraph counts as empty');
        $this->assertSame('01.06.2030, 19:00 ч.', event_when($e, 'bg'));
        $this->assertSame('June 1, 2030, 19:00', event_when($e, 'en'));
        $this->assertSame('', event_when(['event_date' => null, 'event_time' => ''], 'bg'));
    }

    public function test_paths_are_bilingual(): void
    {
        $this->assertSame('/sabitiya/', events_path('list', 'bg'));
        $this->assertSame('/en/events/', events_path('list', 'en'));
        $this->assertSame('/en/events/koncert/', event_url(['slug' => 'koncert'], 'en'));
        $this->assertSame('/sabitiya/confirmation/', events_path('confirmation', 'bg'));
    }

    // ── Saving, states, seats ────────────────────────────────────────────────

    public function test_slug_is_ascii_unique_and_kept_when_the_title_changes(): void
    {
        $a = $this->event(['title' => 'Детски празник']);
        $this->assertSame('detski-praznik', substr($a['slug'], 0, 14));
        $b = $this->event(['title' => $a['title']]);
        $this->assertNotSame($a['slug'], $b['slug']);
        event_save($this->db(), array_merge($a, ['title' => 'Съвсем друго име']), (int) $a['id']);
        $this->assertSame($a['slug'], event_get($this->db(), (int) $a['id'])['slug'], 'shared links keep working');
    }

    public function test_sale_state_and_the_admin_badge_tell_the_real_outcome(): void
    {
        $pdo = $this->db();
        $this->assertSame('on_sale', event_sale_state($pdo, $this->event()));
        $hidden = $this->event(['published' => 0, 'sales_open' => 1]);
        $this->assertSame('hidden', event_sale_state($pdo, $hidden));
        $this->assertStringContainsString('скрито', event_admin_badge($pdo, $hidden)['text'], 'sales on but hidden must not look live');
        $this->assertSame('warn', event_admin_badge($pdo, $hidden)['tone']);
        $this->assertSame('closed', event_sale_state($pdo, $this->event(['sales_open' => 0])));
        $this->assertSame('past', event_sale_state($pdo, $this->event(['event_date' => '2001-01-01'])));
        $full = $this->event(['capacity' => 2]);
        $this->pledge($full, 2);
        $this->assertSame('sold_out', event_sale_state($pdo, $full));
    }

    public function test_seats_count_paid_and_recent_unpaid_but_not_old_or_failed(): void
    {
        $e = $this->event(['capacity' => 10]);
        $this->pledge($e, 3, 'paid');
        $this->pledge($e, 2, 'pending');
        $this->pledge($e, 4, 'pending', date('Y-m-d H:i:s', time() - 3600));
        $this->pledge($e, 1, 'failed');
        $this->assertSame(3, event_tickets_sold($this->db(), (int) $e['id']));
        $this->assertSame(5, event_seats_left($this->db(), $e));
        $this->assertNull(event_seats_left($this->db(), $this->event(['capacity' => null])));
    }

    public function test_public_list_shows_published_upcoming_events_soonest_first(): void
    {
        $tag  = bin2hex(random_bytes(3));
        $late = $this->event(['title' => "B $tag", 'event_date' => date('Y-m-d', strtotime('+30 days'))]);
        $soon = $this->event(['title' => "A $tag", 'event_date' => date('Y-m-d', strtotime('+2 days'))]);
        $this->event(['title' => "C $tag", 'published' => 0]);
        $this->event(['title' => "D $tag", 'event_date' => '2001-01-01']);
        $mine = array_values(array_filter(events_public_list($this->db()), fn($e) => str_ends_with($e['title'], $tag)));
        $this->assertSame([$soon['id'], $late['id']], array_map(fn($e) => (int) $e['id'], $mine));
    }

    // ── Buying and paying ────────────────────────────────────────────────────

    public function test_create_ticket_pledge_locks_the_price_and_checks_input(): void
    {
        $e = $this->event(['price_eur' => 7.5, 'capacity' => 3]);
        $ok = event_create_ticket_pledge($this->db(), $e, ['name' => 'Мария', 'email' => 'm@test.invalid', 'ticket_qty' => '2', 'amount_eur' => '0.01'], 'en');
        $this->assertTrue($ok['ok']);
        $this->pledges[] = $ok['pledge_number'];
        $this->assertSame(15.0, $ok['amount_eur'], 'price comes from the event, never the form');
        $p = event_ticket_pledge($this->db(), $ok['pledge_number']);
        $this->assertSame((int) $e['id'], (int) $p['event_id']);
        $this->assertSame('en', $p['lang']);
        $this->assertSame('pending', $p['payment_status']);

        $bad = fn(array $in) => event_create_ticket_pledge($this->db(), $e, $in + ['name' => 'X', 'email' => 'x@test.invalid', 'ticket_qty' => 1], 'bg');
        $this->assertStringContainsString('имейл', $bad(['email' => 'nope'])['error']);
        $this->assertStringContainsString('между 1 и', $bad(['ticket_qty' => '11'])['error']);
        $this->assertStringContainsString('Останаха само 1', $bad(['ticket_qty' => '2'])['error'], 'the 2 unpaid seats are held');
        $closed = $this->event(['sales_open' => 0]);
        $this->assertFalse(event_create_ticket_pledge($this->db(), $closed, ['name' => 'X', 'email' => 'x@test.invalid'], 'bg')['ok']);
    }

    public function test_paid_status_fulfils_exactly_once(): void
    {
        $e = $this->event(['title' => 'Концерт за тест', 'price_eur' => 5]);
        $p = $this->pledge($e, 2, 'pending');
        $mails = $notes = [];
        $hooks = [
            'render' => fn(array $order, array $doc) => '%PDF-test ' . $doc['event_name'] . ' ' . $doc['ticket_code'],
            'send'   => function (int $oid, string $to, string $subject, string $html, array $opts) use (&$mails) { $mails[] = compact('oid', 'to', 'subject', 'html', 'opts'); return true; },
            'notify' => function (string $to, string $subject) use (&$notes) { $notes[] = $subject; return true; },
        ];
        $this->assertSame('paid', event_process_payment_status($this->db(), $p, 'bank-1', ['orderStatus' => 2], $hooks));
        $this->assertSame('paid', event_process_payment_status($this->db(), $p, 'bank-1', ['orderStatus' => 2], $hooks));

        $this->assertCount(1, $mails, 'a second bank callback sends nothing');
        $this->assertCount(1, $notes);
        $after = event_ticket_pledge($this->db(), $p['pledge_number']);
        $paths = json_decode($after['ticket_path'], true);
        foreach ($paths as $rel) $this->files[] = $_SERVER['DOCUMENT_ROOT'] . $rel;
        $this->assertCount(2, $paths, 'one PDF per ticket');
        $this->assertStringContainsString('Концерт за тест', (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . $paths[0]));
        $this->assertSame('bank-1', $after['dsk_order_id']);
        $this->assertCount(2, $mails[0]['opts']['attachments']);
        $this->assertStringContainsString('Концерт за тест', $mails[0]['subject']);
        $this->assertStringContainsString($after['ticket_code'], $mails[0]['html']);

        $order = $this->db()->prepare("SELECT * FROM orders WHERE order_number = ? AND type = 'ticket'");
        $order->execute([$p['pledge_number']]);
        $o = $order->fetch();
        $this->assertSame((int) $o['id'], $mails[0]['oid'], 'the email is logged on the ticket order');
        $this->assertSame(10.0, (float) $o['total_eur']);
        $this->assertCount(2, json_decode($o['items'], true));

        $door = event_door_list($this->db(), (int) $e['id']);
        $this->assertCount(2, $door);
        $this->assertSame(strstr(basename($paths[1]), '_', true), $door[1]['ticket_code']);
        $this->assertSame(5.0, $door[0]['amount_eur']);
    }

    public function test_declined_payment_is_marked_failed_and_sends_nothing(): void
    {
        $p = $this->pledge($this->event(), 1, 'pending');
        $sent = false;
        $r = event_process_payment_status($this->db(), $p, 'bank-2', ['orderStatus' => 6], ['send' => function () use (&$sent) { $sent = true; return true; }]);
        $this->assertSame('failed', $r);
        $this->assertFalse($sent);
        $this->assertSame('failed', event_ticket_pledge($this->db(), $p['pledge_number'])['payment_status']);
        $this->assertSame('pending', event_process_payment_status($this->db(), $this->pledge($this->event(), 1, 'pending'), '', ['orderStatus' => 0]));
    }

    public function test_a_bank_answer_for_another_payment_changes_nothing(): void
    {
        $p = $this->pledge($this->event(['price_eur' => 10]), 1, 'pending');
        $sent = false;
        $hooks = ['send' => function () use (&$sent) { $sent = true; return true; }, 'render' => fn() => 'x', 'notify' => fn() => true];
        $this->assertSame('pending', event_process_payment_status($this->db(), $p, 'b', ['orderStatus' => 2, 'orderNumber' => 'CP-OTHER_1'], $hooks));
        $this->assertSame('pending', event_process_payment_status($this->db(), $p, 'b', ['orderStatus' => 2, 'orderNumber' => $p['pledge_number'] . '_1', 'amount' => 100], $hooks));
        $this->assertFalse($sent);
        $this->assertSame('pending', event_ticket_pledge($this->db(), $p['pledge_number'])['payment_status']);
    }

    // ── Admin bulk + module bookkeeping ──────────────────────────────────────

    public function test_bulk_actions_change_only_the_selection_and_never_delete_sold_events(): void
    {
        $a = $this->event(['published' => 0, 'sales_open' => 0]);
        $b = $this->event(['published' => 0, 'sales_open' => 0]);
        $keep = $this->event(['published' => 0]);
        events_bulk_apply($this->db(), 'publish', [$a['id'], (string) $b['id'], 'abc', -3, ['x']]);
        $this->assertSame(1, (int) event_get($this->db(), (int) $a['id'])['published']);
        $this->assertSame(0, (int) event_get($this->db(), (int) $keep['id'])['published']);
        events_bulk_apply($this->db(), 'open_sales', [$a['id']]);
        $this->assertSame(1, (int) event_get($this->db(), (int) $a['id'])['sales_open']);
        $this->assertSame(0, events_bulk_apply($this->db(), 'drop_table', [$a['id']])['changed']);

        $this->pledge($b, 1, 'paid');
        $r = events_bulk_apply($this->db(), 'delete', [$a['id'], $b['id']]);
        $this->assertSame(1, $r['changed']);
        $this->assertSame([$b['title']], $r['kept']);
        $this->assertNull(event_get($this->db(), (int) $a['id']));
        $this->assertNotNull(event_get($this->db(), (int) $b['id']));
    }

    public function test_pending_text_counts_upcoming_events_with_sold_tickets(): void
    {
        $pdo = $this->db();
        $before = (int) (preg_replace('/\D.*/s', '', (string) events_pending_text($pdo)) ?: 0);
        $this->pledge($this->event(), 1, 'paid');
        $this->pledge($this->event(['event_date' => '2001-01-01']), 1, 'paid');
        $this->pledge($this->event(), 1, 'pending');
        $text = (string) events_pending_text($pdo);
        $this->assertStringStartsWith((string) ($before + 1), $text);
        $this->assertStringContainsString('продадени билети', $text);
        $this->assertSame($text, module_pending('events', $pdo), 'the registry entry uses it');
    }

    public function test_legacy_event_is_filled_in_from_the_old_settings(): void
    {
        $pdo = $this->db();
        foreach (['event_name', 'event_date', 'event_time', 'event_place', 'event_ticket_price', 'event_active', 'event_description', 'event_fb_url', 'events_legacy_adopted'] as $k) {
            $this->remember_setting($k);
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM events WHERE legacy_settings = 1')->fetchColumn() > 0) {
            $this->markTestSkipped('The dev DB has a real carried-over event waiting; not touching it.');
        }
        setting_set('event_name', 'Старото събитие');
        setting_set('event_date', '2031-05-20');
        setting_set('event_time', '20:00');
        setting_set('event_place', 'Старата зала');
        setting_set('event_ticket_price', '12');
        setting_set('event_active', '1');
        setting_set('event_description', '<p>Описание</p>');
        setting_set('event_fb_url', 'not a url');
        $pdo->exec("INSERT INTO events (slug, title, legacy_settings) VALUES ('phpunit-legacy-" . bin2hex(random_bytes(3)) . "', 'Събитие', 1)");
        $id = (int) $pdo->lastInsertId();
        $this->events[] = $id;
        $p = $this->pledge(event_get($pdo, $id), 1, 'paid');

        events_adopt_legacy($pdo, true);
        $e = event_get($pdo, $id);
        $this->assertSame('Старото събитие', $e['title']);
        $this->assertSame('staroto-sabitie', substr($e['slug'], 0, 15));
        $this->assertSame('2031-05-20', $e['event_date']);
        $this->assertSame('Старата зала', $e['place']);
        $this->assertSame(12.0, (float) $e['price_eur']);
        $this->assertSame([1, 1, 0], [(int) $e['published'], (int) $e['sales_open'], (int) $e['legacy_settings']]);
        $this->assertSame('', $e['fb_url'], 'junk addresses are dropped');
        $this->assertSame('Старото събитие', event_for_pledge($pdo, $p)['title']);
    }

    public function test_a_pledge_without_an_event_falls_back_to_the_old_settings(): void
    {
        $this->remember_setting('event_name');
        setting_set('event_name', 'От настройките');
        $e = event_for_pledge($this->db(), ['event_id' => null]);
        $this->assertSame('От настройките', $e['title']);
        $this->assertSame('TKT-1', event_ticket_document($e, 'TKT-1')['ticket_code']);
    }
}
