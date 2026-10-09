<?php
/**
 * Events and tickets — the "Събития и билети" module (modules_registry()['events']).
 *
 * An event lives in the `events` table (migration 043). A ticket purchase is a
 * campaign_pledges row with pledge_type = 'ticket' and event_id set: that is
 * where the existing payment, ticket-PDF, order-view and download plumbing
 * already read tickets, so tickets sold before this module existed keep
 * working unchanged. Running a fundraising campaign is not needed.
 *
 * Flow: /sabitiya/<slug>/ (EN /en/events/<slug>/) → POST /sabitiya/checkout.php
 * → event_create_ticket_pledge() → card payment (DSK) →
 * /api/event-payment-return.php → event_process_payment_status() →
 * event_tickets_fulfil(): ticket PDFs, an orders row of type 'ticket', the
 * buyer's email with the PDFs, a note to the organisation.
 *
 * Needs config.php (h(), ascii_slug(), url_is_web(), t_or()) and
 * includes/settings.php (only for the one-off carry-over of the old
 * single-event settings).
 */

/** A buyer may take at most this many tickets in one purchase. */
const EVENT_MAX_TICKETS_PER_ORDER = 10;

/** Unpaid purchases hold their seats this long, so two buyers can't take the last ones. */
const EVENT_PENDING_HOLD_MINUTES = 30;

// ── URLs ──────────────────────────────────────────────────────────────────────

/** Public addresses of the module, in the visitor's language. */
function events_path(string $page = 'list', ?string $lang = null): string
{
    $en = ($lang ?? get_lang()) === 'en';
    return match ($page) {
        'list'         => $en ? '/en/events/' : '/sabitiya/',
        'checkout'     => '/sabitiya/checkout.php',
        'confirmation' => $en ? '/en/events/confirmation/' : '/sabitiya/confirmation/',
        default        => throw new InvalidArgumentException("Unknown events page: $page"),
    };
}

/** The public page of one event. */
function event_url(array $event, ?string $lang = null): string
{
    return events_path('list', $lang) . rawurlencode((string) $event['slug']) . '/';
}

// ── Reading events ────────────────────────────────────────────────────────────

/** Does the events table exist yet (migration 043)? */
function events_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM events LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function event_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function event_by_slug(PDO $pdo, string $slug, bool $published_only = true): ?array
{
    $sql = 'SELECT * FROM events WHERE slug = ?' . ($published_only ? ' AND published = 1' : '');
    $st  = $pdo->prepare($sql);
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Published events for the public listing: upcoming ones (today or later, or
 * no date yet), soonest first. Past events drop off the listing but their own
 * pages stay reachable (old links, shared posts).
 */
function events_public_list(PDO $pdo, ?string $today = null): array
{
    $st = $pdo->prepare(
        'SELECT * FROM events
          WHERE published = 1 AND (event_date IS NULL OR event_date >= ?)
          ORDER BY event_date IS NULL, event_date, event_time, id'
    );
    $st->execute([$today ?? date('Y-m-d')]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Events a visitor can buy tickets for right now (for the campaign page, /tickets/). */
function events_on_sale(PDO $pdo, ?string $today = null): array
{
    $out = [];
    foreach (events_public_list($pdo, $today) as $e) {
        if (event_sale_state($pdo, $e, $today) === 'on_sale') $out[] = $e;
    }
    return $out;
}

/** A text field in the visitor's language; an empty English one falls back to Bulgarian. */
function event_text(array $event, string $field, ?string $lang = null): string
{
    $bg = (string) ($event[$field] ?? '');
    if (($lang ?? get_lang()) !== 'en') return $bg;
    $en = (string) ($event[$field . '_en'] ?? '');
    return trim(strip_tags($en)) !== '' ? $en : $bg;
}

/** "12.06.2026, 19:00 ч." / "June 12, 2026, 19:00", or '' when there is no date. */
function event_when(array $event, ?string $lang = null): string
{
    $en   = ($lang ?? get_lang()) === 'en';
    $date = (string) ($event['event_date'] ?? '');
    $time = (string) ($event['event_time'] ?? '');
    $out  = '';
    if ($date !== '') {
        try {
            $out = (new DateTimeImmutable($date))->format($en ? 'F j, Y' : 'd.m.Y');
        } catch (Throwable $e) {
            $out = $date;
        }
    }
    if ($time !== '') $out .= ($out !== '' ? ', ' : '') . $time . ($en ? '' : ' ч.');
    return $out;
}

// ── Seats ─────────────────────────────────────────────────────────────────────

/** Paid tickets of an event (a purchase of 3 counts 3). */
function event_tickets_sold(PDO $pdo, int $event_id): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(ticket_qty), 0) FROM campaign_pledges
          WHERE event_id = ? AND pledge_type = 'ticket' AND payment_status = 'paid'"
    );
    $st->execute([$event_id]);
    return (int) $st->fetchColumn();
}

/** Paid tickets plus unpaid ones started in the last EVENT_PENDING_HOLD_MINUTES. */
function event_tickets_taken(PDO $pdo, int $event_id): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(ticket_qty), 0) FROM campaign_pledges
          WHERE event_id = ? AND pledge_type = 'ticket'
            AND (payment_status = 'paid'
                 OR (payment_status = 'pending' AND created_at >= ?))"
    );
    $st->execute([$event_id, date('Y-m-d H:i:s', time() - EVENT_PENDING_HOLD_MINUTES * 60)]);
    return (int) $st->fetchColumn();
}

/** Seats still free, or null when the event has no limit. Never below 0. */
function event_seats_left(PDO $pdo, array $event): ?int
{
    if ($event['capacity'] === null || $event['capacity'] === '') return null;
    return max(0, (int) $event['capacity'] - event_tickets_taken($pdo, (int) $event['id']));
}

/**
 * Where an event stands for a visitor, as one word:
 *   hidden      not published (whatever else is set)
 *   past        its date is before today
 *   closed      published, sales switched off
 *   no_price    sales on, but no price of at least 1 EUR
 *   sold_out    sales on, no seats left
 *   on_sale     tickets can be bought
 */
function event_sale_state(PDO $pdo, array $event, ?string $today = null): string
{
    if (empty($event['published'])) return 'hidden';
    $date = (string) ($event['event_date'] ?? '');
    if ($date !== '' && $date < ($today ?? date('Y-m-d'))) return 'past';
    if (empty($event['sales_open'])) return 'closed';
    if ((float) $event['price_eur'] < 1) return 'no_price';
    $left = event_seats_left($pdo, $event);
    if ($left !== null && $left < 1) return 'sold_out';
    return 'on_sale';
}

/**
 * The admin list's badge: what the event really does on the site, not just
 * its switches — "sales on but hidden" must not look like it is selling.
 *
 * @return array{text:string, tone:string}  tone: ok | warn | off
 */
function event_admin_badge(PDO $pdo, array $event, ?string $today = null): array
{
    $state = event_sale_state($pdo, $event, $today);
    return match ($state) {
        'on_sale'  => ['text' => '✓ Продава билети', 'tone' => 'ok'],
        'sold_out' => ['text' => 'Разпродадено', 'tone' => 'warn'],
        'no_price' => ['text' => '⚠ Продажбите са включени, но няма цена', 'tone' => 'warn'],
        'closed'   => ['text' => 'Показано, продажбите са спрени', 'tone' => 'off'],
        'past'     => ['text' => 'Отминало', 'tone' => 'off'],
        default    => !empty($event['sales_open'])
            ? ['text' => '⚠ Продажбите са включени, но събитието е скрито', 'tone' => 'warn']
            : ['text' => 'Скрито', 'tone' => 'off'],
    };
}

// ── Saving events (admin) ─────────────────────────────────────────────────────

/**
 * Check and clean the event form. Pure — no database.
 *
 * @return array{values: array<string,mixed>, errors: array<string,string>}
 */
function event_validate(array $in): array
{
    $s = static fn(string $k, int $max = 255): string => mb_substr(trim((string) (is_scalar($in[$k] ?? null) ? $in[$k] : '')), 0, $max);
    $v = [
        'title'          => $s('title'),
        'title_en'       => $s('title_en'),
        'event_date'     => $s('event_date', 10),
        'event_time'     => $s('event_time', 5),
        'place'          => $s('place'),
        'place_en'       => $s('place_en'),
        'description'    => $s('description', 200000),
        'description_en' => $s('description_en', 200000),
        'fb_url'         => $s('fb_url', 500),
        'published'      => ($in['published'] ?? '') === '1' ? 1 : 0,
        'sales_open'     => ($in['sales_open'] ?? '') === '1' ? 1 : 0,
    ];
    $errors = [];

    if ($v['title'] === '') $errors['title'] = 'Напишете име на събитието.';

    if ($v['event_date'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v['event_date']);
        if (!$d || $d->format('Y-m-d') !== $v['event_date']) {
            $errors['event_date'] = 'Датата не е разпозната. Изберете я от календара.';
        }
    }
    if ($v['event_time'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['event_time'])) {
        $errors['event_time'] = 'Часът не е разпознат. Напишете го като 19:30.';
    }
    if ($v['fb_url'] !== '' && !url_is_web($v['fb_url'])) {
        $errors['fb_url'] = 'Връзката към събитието във Facebook трябва да започва с https://';
    }

    $price_raw = str_replace([',', ' '], ['.', ''], $s('price_eur', 20));
    if ($price_raw === '') $price_raw = '0';
    if (!is_numeric($price_raw) || (float) $price_raw < 0 || (float) $price_raw > 100000) {
        $errors['price_eur'] = 'Цената трябва да е число в евро, например 15 или 12.50.';
        $v['price_eur'] = 0.0;
    } else {
        $v['price_eur'] = round((float) $price_raw, 2);
    }
    if ($v['sales_open'] && $v['price_eur'] < 1 && !isset($errors['price_eur'])) {
        $errors['price_eur'] = 'За да продавате билети, цената трябва да е поне 1 EUR.';
    }

    $cap_raw = $s('capacity', 10);
    if ($cap_raw === '') {
        $v['capacity'] = null;
    } elseif (!ctype_digit($cap_raw) || (int) $cap_raw < 1) {
        $errors['capacity'] = 'Броят места трябва да е цяло число, поне 1 — или оставете полето празно, ако няма ограничение.';
        $v['capacity'] = null;
    } else {
        $v['capacity'] = (int) $cap_raw;
    }

    return ['values' => $v, 'errors' => $errors];
}

/** A slug for the event's address, unique among events. ASCII, like article addresses. */
function event_unique_slug(PDO $pdo, string $title, ?int $except_id = null): string
{
    $base = ascii_slug($title);
    $base = substr($base !== '' ? $base : 'sabitie', 0, 150);
    $base = rtrim($base, '-');
    $st   = $pdo->prepare('SELECT 1 FROM events WHERE slug = ? AND id <> ?');
    for ($i = 1; ; $i++) {
        $slug = $i === 1 ? $base : $base . '-' . $i;
        $st->execute([$slug, $except_id ?? 0]);
        if (!$st->fetchColumn()) return $slug;
    }
}

/**
 * Insert or update an event from event_validate()'s values. The address
 * (slug) is set once, from the first title, so links already shared keep
 * working when the title is edited.
 */
function event_save(PDO $pdo, array $v, ?int $id = null): int
{
    $cols = ['title', 'title_en', 'event_date', 'event_time', 'place', 'place_en', 'description',
             'description_en', 'fb_url', 'price_eur', 'capacity', 'published', 'sales_open'];
    $vals = [];
    foreach ($cols as $c) {
        $vals[] = $c === 'event_date' && ($v[$c] ?? '') === '' ? null : ($v[$c] ?? null);
    }
    if ($id) {
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $pdo->prepare("UPDATE events SET $set, legacy_settings = 0 WHERE id = ?")->execute([...$vals, $id]);
        return $id;
    }
    $slug = event_unique_slug($pdo, (string) $v['title']);
    $pdo->prepare('INSERT INTO events (slug, ' . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ')
                   VALUES (?' . str_repeat(', ?', count($cols)) . ')')->execute([$slug, ...$vals]);
    return (int) $pdo->lastInsertId();
}

/** Bulk actions on the admin list. */
const EVENT_BULK_ACTIONS = ['publish', 'hide', 'open_sales', 'close_sales', 'delete'];

/**
 * Apply a bulk action to the selected events. Junk ids are dropped. An event
 * that has sold tickets is never deleted (the tickets and their orders must
 * stay readable) — it is reported back so the admin can hide it instead.
 *
 * @return array{changed:int, kept:string[]}  kept = titles not deleted
 */
function events_bulk_apply(PDO $pdo, string $action, array $raw_ids): array
{
    if (!in_array($action, EVENT_BULK_ACTIONS, true)) return ['changed' => 0, 'kept' => []];
    $ids = [];
    foreach ($raw_ids as $r) {
        if (is_string($r) || is_int($r)) {
            $n = filter_var($r, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($n !== false) $ids[$n] = $n;
        }
    }
    if (!$ids) return ['changed' => 0, 'kept' => []];
    $ids = array_values($ids);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete') {
        $st = $pdo->prepare(
            "SELECT e.id, e.title, (SELECT COUNT(*) FROM campaign_pledges p WHERE p.event_id = e.id) AS n
               FROM events e WHERE e.id IN ($in)"
        );
        $st->execute($ids);
        $del = $kept = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['n'] > 0) $kept[] = (string) $r['title'];
            else $del[] = (int) $r['id'];
        }
        if ($del) {
            $pdo->prepare('DELETE FROM events WHERE id IN (' . implode(',', array_fill(0, count($del), '?')) . ')')->execute($del);
        }
        return ['changed' => count($del), 'kept' => $kept];
    }

    [$col, $val] = match ($action) {
        'publish'     => ['published', 1],
        'hide'        => ['published', 0],
        'open_sales'  => ['sales_open', 1],
        'close_sales' => ['sales_open', 0],
    };
    $st = $pdo->prepare("UPDATE events SET `$col` = ? WHERE id IN ($in)");
    $st->execute([$val, ...$ids]);
    return ['changed' => $st->rowCount(), 'kept' => []];
}

// ── Buying ────────────────────────────────────────────────────────────────────

/**
 * Check a ticket purchase and record it as an unpaid ticket pledge.
 *
 * @return array{ok:bool, error?:string, pledge_number?:string, pledge_id?:int, amount_eur?:float}
 */
function event_create_ticket_pledge(PDO $pdo, array $event, array $in, string $lang): array
{
    $lang  = $lang === 'en' ? 'en' : 'bg';
    $name  = mb_substr(trim((string) (is_scalar($in['name'] ?? null) ? $in['name'] : '')), 0, 200);
    $email = trim((string) (is_scalar($in['email'] ?? null) ? $in['email'] : ''));
    $qty   = filter_var($in['ticket_qty'] ?? 1, FILTER_VALIDATE_INT);
    $fail  = static fn(string $bg, string $en): array => ['ok' => false, 'error' => $lang === 'en' ? $en : $bg];

    $state = event_sale_state($pdo, $event);
    if ($state === 'sold_out') return $fail('Билетите за това събитие са разпродадени.', 'This event is sold out.');
    if ($state !== 'on_sale')  return $fail('В момента не се продават билети за това събитие.', 'Tickets for this event are not on sale right now.');

    if ($name === '') return $fail('Моля, напишете името си.', 'Please enter your name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        return $fail('Моля, напишете валиден имейл адрес — там ще изпратим билета.', 'Please enter a valid email address — we will send the ticket there.');
    }
    if ($qty === false || $qty < 1 || $qty > EVENT_MAX_TICKETS_PER_ORDER) {
        return $fail('Изберете между 1 и ' . EVENT_MAX_TICKETS_PER_ORDER . ' билета.', 'Choose between 1 and ' . EVENT_MAX_TICKETS_PER_ORDER . ' tickets.');
    }
    $left = event_seats_left($pdo, $event);
    if ($left !== null && $qty > $left) {
        return $fail(
            'Останаха само ' . $left . ' свободни места. Изберете по-малко билети.',
            'Only ' . $left . ' seats are left. Please choose fewer tickets.'
        );
    }

    $amount = round((float) $event['price_eur'] * $qty, 2);
    for ($try = 0; $try < 5; $try++) {
        $number = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        try {
            $pdo->prepare(
                "INSERT INTO campaign_pledges
                    (pledge_number, pledge_type, event_id, lang, name, email, amount_eur, ticket_qty, payment_status)
                 VALUES (?, 'ticket', ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$number, (int) $event['id'], $lang, $name, $email, $amount, $qty]);
            return ['ok' => true, 'pledge_number' => $number, 'pledge_id' => (int) $pdo->lastInsertId(), 'amount_eur' => $amount];
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;   // duplicate number: draw again
        }
    }
    throw new RuntimeException('event_create_ticket_pledge: could not draw a free pledge number');
}

/** A ticket pledge by its number (CP-YYYYMMDD-XXXX), or null. */
function event_ticket_pledge(PDO $pdo, string $number): ?array
{
    if (!preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $number)) return null;
    $st = $pdo->prepare("SELECT * FROM campaign_pledges WHERE pledge_number = ? AND pledge_type = 'ticket'");
    $st->execute([$number]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── After payment ─────────────────────────────────────────────────────────────

/**
 * The event a ticket pledge belongs to. Tickets sold before migration 043
 * that somehow have no event fall back to the old single-event settings, so
 * a resend still prints the right name.
 */
function event_for_pledge(PDO $pdo, array $pledge): array
{
    if (!empty($pledge['event_id'])) {
        $e = event_get($pdo, (int) $pledge['event_id']);
        if ($e) {
            events_adopt_legacy($pdo);
            return !empty($e['legacy_settings']) ? (event_get($pdo, (int) $e['id']) ?? $e) : $e;
        }
    }
    $sg = static fn(string $k, string $d = ''): string => function_exists('setting_get') ? setting_get($k, $d) : $d;
    return [
        'id' => 0, 'slug' => '', 'title' => $sg('event_name', 'Събитие'), 'title_en' => '',
        'event_date' => $sg('event_date') ?: null, 'event_time' => $sg('event_time'),
        'place' => $sg('event_place'), 'place_en' => '', 'price_eur' => (float) ($sg('event_ticket_price', '0') ?: 0),
        'capacity' => null, 'published' => 0, 'sales_open' => 0,
    ];
}

/** The ticket PDF's event block (TicketGenerator's $document). Tickets print in Bulgarian, as before. */
function event_ticket_document(array $event, string $ticket_code): array
{
    return [
        'ticket_code' => $ticket_code,
        'event_name'  => (string) ($event['title'] ?: 'Събитие'),
        'event_date'  => (string) ($event['event_date'] ?? ''),
        'event_time'  => (string) ($event['event_time'] ?? ''),
        'event_place' => (string) ($event['place'] ?? ''),
    ];
}

/**
 * Make one PDF per ticket of a pledge (documents/tickets/<year>/) and store
 * the first code and all paths on the pledge. Replaces whatever paths it had.
 *
 * @param ?callable $render  tests only: fn(array $order, array $doc): string (PDF bytes)
 * @return string[] relative paths, one per ticket
 */
function event_generate_ticket_pdfs(PDO $pdo, array $pledge, array $event, ?callable $render = null): array
{
    $qty   = max(1, (int) ($pledge['ticket_qty'] ?? 1));
    $year  = date('Y');
    $root  = rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__), '/');
    $dir   = "$root/documents/tickets/$year/";
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("event_generate_ticket_pdfs: cannot create $dir");
    }
    if ($render === null) {
        require_once __DIR__ . '/documents/TicketGenerator.php';
        $render = static fn(array $order, array $doc): string => (new TicketGenerator())->generate($order, [], $doc);
    }
    $each  = round((float) $pledge['amount_eur'] / $qty, 2);
    $paths = [];
    $first = '';
    for ($i = 1; $i <= $qty; $i++) {
        $code  = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $first = $first ?: $code;
        $file  = $code . '_' . $pledge['pledge_number'] . ($qty > 1 ? "-$i" : '') . '.pdf';
        $bytes = $render([
            'name'          => $pledge['name'],
            'email'         => $pledge['email'],
            'pledge_number' => $pledge['pledge_number'],
            'amount_eur'    => $each,
            'created_at'    => $pledge['created_at'] ?? date('Y-m-d H:i:s'),
        ], event_ticket_document($event, $code));
        if (file_put_contents($dir . $file, $bytes) === false) {
            throw new RuntimeException("event_generate_ticket_pdfs: cannot write $file");
        }
        $paths[] = "/documents/tickets/$year/$file";
    }
    $pdo->prepare('UPDATE campaign_pledges SET ticket_code = ?, ticket_path = ? WHERE id = ?')
        ->execute([$first, json_encode($paths), (int) $pledge['id']]);
    return $paths;
}

/** The ticket's orders row (type 'ticket', order_number = pledge number): created once, id returned. */
function event_ticket_order_row(PDO $pdo, array $pledge, array $event): int
{
    $find = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? AND type = 'ticket'");
    $find->execute([$pledge['pledge_number']]);
    $id = $find->fetchColumn();
    if ($id !== false) return (int) $id;

    $qty   = max(1, (int) ($pledge['ticket_qty'] ?? 1));
    $each  = round((float) $pledge['amount_eur'] / $qty, 2);
    $items = [];
    for ($i = 1; $i <= $qty; $i++) {
        $items[] = [
            'type'          => 'ticket',
            'name'          => ($event['title'] ?: 'Билет') . ($qty > 1 ? " ($i/$qty)" : ''),
            'ticket_code'   => (string) ($pledge['ticket_code'] ?? ''),
            'pledge_number' => $pledge['pledge_number'],
            'event_id'      => (int) ($event['id'] ?? 0),
            'amount_eur'    => $each,
        ];
    }
    $pdo->prepare(
        "INSERT IGNORE INTO orders
            (order_number, type, status, customer_name, customer_email,
             items, subtotal_eur, shipping_eur, total_eur,
             payment_method, payment_status, created_at)
         VALUES (?, 'ticket', 'confirmed', ?, ?, ?, ?, 0, ?, 'card', 'paid', ?)"
    )->execute([
        $pledge['pledge_number'], $pledge['name'], $pledge['email'], json_encode($items, JSON_UNESCAPED_UNICODE),
        (float) $pledge['amount_eur'], (float) $pledge['amount_eur'], $pledge['created_at'] ?? date('Y-m-d H:i:s'),
    ]);
    $find->execute([$pledge['pledge_number']]);
    return (int) $find->fetchColumn();
}

/** The PDFs of a pledge as email attachments. */
function event_ticket_attachments(array $pledge, array $paths): array
{
    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__), '/');
    $out  = [];
    $n    = count($paths);
    foreach (array_values($paths) as $i => $rel) {
        if (is_file($root . $rel)) {
            $out[] = ['path' => $root . $rel, 'name' => 'ticket-' . $pledge['pledge_number'] . ($n > 1 ? '-' . ($i + 1) : '') . '.pdf'];
        }
    }
    return $out;
}

/**
 * Email the buyer their tickets (template 'campaign-ticket', editable in
 * Admin → Имейл шаблони), logged in the order's history.
 *
 * @param ?callable $send tests only: fn(int $order_id, string $to, string $subject, string $html, array $opts): bool
 */
function event_send_tickets(PDO $pdo, array $pledge, array $event, array $paths, int $order_id, ?callable $send = null): bool
{
    $lang = ($pledge['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
    $send ??= 'send_order_mail';
    return (bool) $send(
        $order_id,
        (string) $pledge['email'],
        render_email_subject('campaign-ticket', $lang, ['event_name' => event_text($event, 'title', $lang), 'pledge_number' => $pledge['pledge_number']]),
        render_email('campaign-ticket', ['pledge' => $pledge, 'event' => $event, 'lang' => $lang]),
        ['template_key' => 'campaign-ticket', 'attachments' => event_ticket_attachments($pledge, $paths)]
    );
}

/**
 * Everything that happens once a ticket purchase is paid: PDFs, the orders
 * row, the buyer's email, a note to the organisation. Each step is
 * attempted even if an earlier one failed, and failures are logged, never
 * thrown — the buyer has paid and must reach the thank-you page.
 *
 * @param array $hooks tests only: ['render' => callable, 'send' => callable, 'notify' => callable]
 */
function event_tickets_fulfil(PDO $pdo, array $pledge, array $hooks = []): void
{
    $event = event_for_pledge($pdo, $pledge);
    $paths = [];
    try {
        $paths = event_generate_ticket_pdfs($pdo, $pledge, $event, $hooks['render'] ?? null);
        $re = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id = ?');
        $re->execute([(int) $pledge['id']]);
        $pledge = $re->fetch(PDO::FETCH_ASSOC) ?: $pledge;
    } catch (Throwable $e) {
        error_log('event_tickets_fulfil: PDFs for ' . $pledge['pledge_number'] . ': ' . $e->getMessage());
    }
    $order_id = 0;
    try {
        $order_id = event_ticket_order_row($pdo, $pledge, $event);
    } catch (Throwable $e) {
        error_log('event_tickets_fulfil: order row for ' . $pledge['pledge_number'] . ': ' . $e->getMessage());
    }
    try {
        event_send_tickets($pdo, $pledge, $event, $paths, $order_id, $hooks['send'] ?? null);
    } catch (Throwable $e) {
        error_log('event_tickets_fulfil: email for ' . $pledge['pledge_number'] . ': ' . $e->getMessage());
    }
    try {
        $notify = $hooks['notify'] ?? static fn(string $to, string $subject, string $html): bool => send_mail($to, $subject, $html);
        $notify(
            defined('SITE_EMAIL') ? SITE_EMAIL : '',
            'Продаден билет — ' . ($event['title'] ?: 'събитие') . ' — ' . $pledge['pledge_number'],
            render_email('event-ticket-admin', ['pledge' => $pledge, 'event' => $event])
        );
    } catch (Throwable $e) {
        error_log('event_tickets_fulfil: admin note for ' . $pledge['pledge_number'] . ': ' . $e->getMessage());
    }
}

/**
 * Act on the bank's answer for a ticket purchase. Marks it paid exactly once
 * (a second callback for the same payment does nothing) and then fulfils it.
 *
 * @return string 'paid' | 'failed' | 'pending'
 */
function event_process_payment_status(PDO $pdo, array $pledge, string $bank_order_id, array $status, array $hooks = []): string
{
    if (empty($pledge['dsk_order_id']) && $bank_order_id !== '') {
        $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id = ? WHERE id = ?')->execute([$bank_order_id, (int) $pledge['id']]);
    }
    // The answer must be about this purchase: our reference is "<pledge>_<time>"
    // and the amount is in cents. Anything else (a bank id copied from another
    // payment into the return address) changes nothing.
    $ref = (string) ($status['orderNumber'] ?? '');
    if ($ref !== '' && !str_starts_with($ref, $pledge['pledge_number'] . '_')) {
        error_log('event_process_payment_status: bank answer for ' . $ref . ' does not belong to ' . $pledge['pledge_number']);
        return 'pending';
    }
    if (isset($status['amount']) && (int) $status['amount'] !== (int) round((float) $pledge['amount_eur'] * 100)) {
        error_log('event_process_payment_status: amount ' . (int) $status['amount'] . ' does not match ' . $pledge['pledge_number']);
        return 'pending';
    }
    $code = (int) ($status['orderStatus'] ?? -1);
    if ($code === 1 || $code === 2) {
        $upd = $pdo->prepare("UPDATE campaign_pledges SET payment_status = 'paid' WHERE id = ? AND payment_status = 'pending'");
        $upd->execute([(int) $pledge['id']]);
        if ($upd->rowCount() === 1) {
            $pledge['payment_status'] = 'paid';
            event_tickets_fulfil($pdo, $pledge, $hooks);
        }
        return 'paid';
    }
    if ($code === 3 || $code === 6) {
        $pdo->prepare("UPDATE campaign_pledges SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'")
            ->execute([(int) $pledge['id']]);
        return 'failed';
    }
    return 'pending';
}

// ── Door list ─────────────────────────────────────────────────────────────────

/**
 * Paid tickets of an event, one row per ticket (a purchase of 3 = 3 rows), by
 * buyer name — the printable list for the door.
 *
 * @return list<array{name:string, ticket_code:string, created_at:string, amount_eur:float, pledge_number:string}>
 */
function event_door_list(PDO $pdo, int $event_id): array
{
    $st = $pdo->prepare(
        "SELECT pledge_number, ticket_code, ticket_path, ticket_qty, name, created_at, amount_eur
           FROM campaign_pledges
          WHERE event_id = ? AND pledge_type = 'ticket' AND payment_status = 'paid'
          ORDER BY name, id"
    );
    $st->execute([$event_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty   = max(1, (int) $r['ticket_qty']);
        $paths = json_decode((string) $r['ticket_path'], true);
        if (!is_array($paths)) $paths = $r['ticket_path'] ? [$r['ticket_path']] : [];
        $each  = round((float) $r['amount_eur'] / $qty, 2);
        for ($i = 0; $i < $qty; $i++) {
            // Each PDF's file name starts with its own code: TKT-YYYYMMDD-XXXXXXXX_<pledge>.pdf
            $code = isset($paths[$i]) ? (strstr(basename((string) $paths[$i]), '_', true) ?: '') : '';
            if ($code === '' && $i === 0) $code = (string) $r['ticket_code'];
            $out[] = [
                'name'          => (string) $r['name'],
                'ticket_code'   => $code !== '' ? $code : '—',
                'created_at'    => (string) $r['created_at'],
                'amount_eur'    => $each,
                'pledge_number' => (string) $r['pledge_number'],
            ];
        }
    }
    return $out;
}

// ── Module bookkeeping ────────────────────────────────────────────────────────

/** For Admin → Модули: upcoming events that already sold tickets, or null. */
function events_pending_text(PDO $pdo, ?string $today = null): ?string
{
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT e.id) FROM events e
           JOIN campaign_pledges p ON p.event_id = e.id AND p.pledge_type = 'ticket' AND p.payment_status = 'paid'
          WHERE e.event_date IS NULL OR e.event_date >= ?"
    );
    $st->execute([$today ?? date('Y-m-d')]);
    $n = (int) $st->fetchColumn();
    if ($n < 1) return null;
    return $n === 1
        ? '1 предстоящо събитие вече има продадени билети.'
        : $n . ' предстоящи събития вече имат продадени билети.';
}

/**
 * One-off carry-over of the old single event (encrypted in `settings` as
 * event_name, event_date, …, which SQL cannot read):
 *  - the event migration 043 created for already sold tickets
 *    (legacy_settings = 1) gets its title, date, place, price, description
 *    and on/off state from those settings, and a real address;
 *  - a site that had a ticket sale switched on but sold nothing yet gets that
 *    event created, so it does not silently disappear with the update.
 * Cheap when there is nothing to do (one indexed query + one setting read).
 */
function events_adopt_legacy(PDO $pdo, bool $again = false): void
{
    static $done = false;
    if ($again) $done = false;   // tests only
    if ($done || !function_exists('setting_get')) return;
    $done = true;
    try {
        $id = $pdo->query('SELECT id FROM events WHERE legacy_settings = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            if (setting_get('events_legacy_adopted') === '1') return;
            setting_set('events_legacy_adopted', '1');
            $any = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
            if ($any > 0 || setting_get('event_active') !== '1' || trim(setting_get('event_name')) === '') return;
            $pdo->exec("INSERT INTO events (slug, title, legacy_settings) VALUES ('bileti', 'Събитие', 1)");
            $id = (int) $pdo->lastInsertId();
        }
        $title = trim(setting_get('event_name')) ?: 'Събитие';
        $date  = setting_get('event_date');
        $time  = setting_get('event_time');
        $on    = setting_get('event_active') === '1' ? 1 : 0;
        $price = round((float) (setting_get('event_ticket_price', '0') ?: 0), 2);
        $fb    = setting_get('event_fb_url');
        $pdo->prepare(
            'UPDATE events SET slug = ?, title = ?, event_date = ?, event_time = ?, place = ?, fb_url = ?,
                    price_eur = ?, description = ?, published = ?, sales_open = ?, legacy_settings = 0
              WHERE id = ? AND legacy_settings = 1'
        )->execute([
            event_unique_slug($pdo, $title, (int) $id), mb_substr($title, 0, 255),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '',
            mb_substr(setting_get('event_place'), 0, 255), url_is_web($fb) ? $fb : '',
            $price, setting_get('event_description'), $on, $on && $price >= 1 ? 1 : 0, (int) $id,
        ]);
        setting_set('events_legacy_adopted', '1');
    } catch (Throwable $e) {
        error_log('events_adopt_legacy: ' . $e->getMessage());
    }
}
