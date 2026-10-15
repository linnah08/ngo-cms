<?php
/**
 * Newsletter helper functions.
 * Included by public endpoints (subscribe/unsubscribe) and admin send logic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once __DIR__ . '/order_session.php';

// ── Subscriber management ──────────────────────────────────────────────────────

/** Sources a subscriber can come from — mirrors the `source` column's ENUM. */
const NEWSLETTER_SOURCES = ['web_banner', 'customer_import', 'manual', 'checkout', 'donation', 'confirmation'];

/**
 * Subscribe an email, to the topics in $topicIds.
 *
 * $topicIds are newsletter_topics ids. An empty list means "everything": the
 * subscriber gets every campaign, whatever its topic — that is what manual
 * adds, customer imports and sign-ups on a site without topics want.
 * (Forms that need a choice and got none are refused by their caller; see
 * newsletter_signup_topics().)
 *
 * Merge-aware and consent-safe:
 *  - new email                    → insert with the chosen topics
 *  - existing & active            → add the chosen topics (never removes one)
 *  - existing & unsubscribed      → left untouched (no resurrection: the
 *                                   person said stop, a later form tick on
 *                                   some other page must not undo that)
 *
 * Concurrency-safe: INSERT IGNORE on the unique email decides atomically
 * whether this call created the row, and topics are added with INSERT IGNORE
 * on the (subscriber, topic) key, so two simultaneous sign-ups can neither
 * duplicate a row nor clear each other's topic.
 *
 * @return array ['ok' => bool, 'duplicate' => bool, 'status' => string]
 *   status ∈ subscribed | merged | resurrect_blocked
 */
function newsletter_subscribe(
    string $email,
    string $name,
    string $lang,
    string $source,
    array $topicIds = []
): array {
    $email  = strtolower(trim($email));
    $lang   = in_array($lang, ['bg', 'en'], true) ? $lang : 'bg';
    $source = in_array($source, NEWSLETTER_SOURCES, true) ? $source : 'web_banner';

    $pdo   = get_pdo();
    $token = bin2hex(random_bytes(32));

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO newsletter_subscribers (email, name, lang, source, token)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->execute([$email, trim($name) ?: null, $lang, $source, $token]);
    $created = $ins->rowCount() === 1;

    $sel = $pdo->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ?");
    $sel->execute([$email]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    if (!$row || (!$created && $row['status'] === 'unsubscribed')) {
        // (No row: deleted between the INSERT and this SELECT — treat as a no-op.)
        return ['ok' => false, 'duplicate' => true, 'status' => 'resurrect_blocked'];
    }

    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds), fn($v) => $v > 0)));
    if (!$created && $topicIds && !newsletter_subscriber_topic_ids($pdo, (int) $row['id'])) {
        // Already gets everything — a narrower tick now must not take that away.
        $topicIds = [];
    }
    $add = $pdo->prepare('INSERT IGNORE INTO newsletter_subscriber_topics (subscriber_id, topic_id)
                          SELECT ?, id FROM newsletter_topics WHERE id = ?');
    foreach ($topicIds as $tid) $add->execute([(int) $row['id'], $tid]);

    return $created
        ? ['ok' => true, 'duplicate' => false, 'status' => 'subscribed']
        : ['ok' => true, 'duplicate' => true, 'status' => 'merged'];
}

/** The topic ids a subscriber chose; [] means they get everything. */
function newsletter_subscriber_topic_ids(\PDO $pdo, int $subscriberId): array
{
    $st = $pdo->prepare('SELECT topic_id FROM newsletter_subscriber_topics WHERE subscriber_id = ? ORDER BY topic_id');
    $st->execute([$subscriberId]);
    return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
}

// ── Topics ─────────────────────────────────────────────────────────────────────
//
// The topics a subscriber can pick are rows of `newsletter_topics`, managed in
// Admin → Бюлетин → Теми (admin/newsletter-topics.php). A hidden topic
// (is_active = 0) is no longer offered anywhere, but subscribers keep having
// chosen it and campaigns already aimed at it still go to them.

/** Soft limit: above this many shown topics the admin screen warns. */
const NEWSLETTER_TOPICS_SOFT_MAX = 6;

/**
 * Topic rows in display order — only the shown ones unless $includeHidden.
 * [] when the table is missing (a site mid-update, before migrate.php has run)
 * so a page never breaks over it; the sign-up then simply has no checkboxes.
 */
function newsletter_topic_list(bool $includeHidden = false): array
{
    try {
        return get_pdo()->query(
            'SELECT * FROM newsletter_topics'
            . ($includeHidden ? '' : ' WHERE is_active = 1')
            . ' ORDER BY is_active DESC, sort_order, id'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException) {
        return [];
    }
}

/** One topic row by id (shown or hidden), or null. */
function newsletter_topic_get(int $id): ?array
{
    foreach (newsletter_topic_list(true) as $t) {
        if ((int) $t['id'] === $id) return $t;
    }
    return null;
}

/**
 * The name people read for a topic. Saved in the topic row; the two topics a
 * site had before topics were editable keep, until renamed, the wording edited
 * in place on the page back then (pages.json) or the strings.json default.
 * A missing English name falls back to the Bulgarian one.
 */
function newsletter_topic_name(array $topic, ?string $lang = null): string
{
    $lang = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    $own  = trim((string) ($topic[$lang === 'en' ? 'name_en' : 'name_bg'] ?? ''));
    if ($own !== '') return $own;

    $key = (string) ($topic['legacy_key'] ?? '');
    if (in_array($key, ['news', 'education'], true)) {
        $field = 'topic_' . $key . '_label';
        $saved = newsletter_pages_section()[$lang === 'en' ? $field . '_en' : $field] ?? '';
        if (is_string($saved) && trim($saved) !== '') return $saved;
        return $key === 'news'
            ? t_or('newsletter.topic.news', 'Новини за нашата дейност', 'News about our work', $lang)
            : t_or('newsletter.topic.education', 'Полезни материали и съвети', 'Useful resources and tips', $lang);
    }
    return $lang === 'en' ? newsletter_topic_name($topic, 'bg') : '';
}

/**
 * The "What would you like to receive?" heading above the topic checkboxes.
 * Edited in place on the page (`newsletter` section of content/pages.json);
 * until then the strings.json default, which a site can reword in
 * content/{bg,en}/strings.site.json.
 */
function newsletter_topics_heading(?string $lang = null): string
{
    $lang  = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    $saved = newsletter_pages_section()[$lang === 'en' ? 'topics_heading_en' : 'topics_heading'] ?? '';
    if (is_string($saved) && trim($saved) !== '') return $saved;
    return t_or('newsletter.topics.heading', 'Какво искате да получавате?', 'What would you like to receive?', $lang);
}

/**
 * Heading ('title') or line under it ('text') of the sign-up band above the
 * footer. Edited in place on the page (banner_title / banner_text in the
 * `newsletter` section of content/pages.json); until then the strings.json
 * default, which a site can reword in content/{bg,en}/strings.site.json.
 */
function newsletter_banner_text(string $which, ?string $lang = null): string
{
    if ($which !== 'title' && $which !== 'text') return '';
    $lang  = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    $saved = newsletter_pages_section()['banner_' . $which . ($lang === 'en' ? '_en' : '')] ?? '';
    if (is_string($saved) && trim($saved) !== '') return $saved;
    return $which === 'title'
        ? t_or('newsletter.banner.title', 'Бъдете в течение', 'Stay in touch', $lang)
        : t_or('newsletter.banner.text', 'Получавайте новини и истории директно в пощата си.', 'Get news and stories straight to your inbox.', $lang);
}

/** The `newsletter` section of content/pages.json (inline-edited copy). */
function newsletter_pages_section(): array
{
    $pages = load_json(CONTENT_PATH . '/pages.json');
    return is_array($pages['newsletter'] ?? null) ? $pages['newsletter'] : [];
}

/**
 * Ids of the shown topics ticked in a submitted form (the topics partial posts
 * them as nl_topics[]). Also reads the two checkboxes forms had before topics
 * were editable (wants_news / wants_education), so a page cached from then
 * still signs people up for what they ticked.
 */
function newsletter_topics_from_post(array $post): array
{
    $ticked = array_map('intval', array_filter((array) ($post['nl_topics'] ?? []), 'is_scalar'));
    $ids = [];
    foreach (newsletter_topic_list() as $t) {
        $legacy = (string) ($t['legacy_key'] ?? '');
        if (in_array((int) $t['id'], $ticked, true) || ($legacy !== '' && !empty($post['wants_' . $legacy]))) {
            $ids[] = (int) $t['id'];
        }
    }
    return $ids;
}

/**
 * The topics a sign-up form (footer band, confirmation card) subscribes to:
 *  - two or more topics shown → the ticked ones; null when none was ticked
 *    (the caller says "please choose at least one topic")
 *  - one topic shown          → that one (the form had no checkbox)
 *  - no topics                → [] (everything)
 */
function newsletter_signup_topics(array $post): ?array
{
    $shown = newsletter_topic_list();
    if (count($shown) === 1) return [(int) $shown[0]['id']];
    if (!$shown) return [];
    return newsletter_topics_from_post($post) ?: null;
}

/**
 * Did the buyer opt in to the newsletter at checkout? With two or more topics
 * ticking any of them is the opt-in; otherwise the form shows one checkbox
 * of its own (nl_optin).
 */
function newsletter_post_opted_in(array $post): bool
{
    return count(newsletter_topic_list()) >= 2
        ? (bool) newsletter_topics_from_post($post)
        : !empty($post['nl_optin']);
}

/**
 * SQL condition (no user input in it — safe to embed) matching the
 * subscribers a campaign of topic $topicId goes to: those who chose it, and
 * those who chose nothing, i.e. everything. Use on newsletter_subscribers
 * without a table alias.
 */
function newsletter_topic_match_sql(int $topicId): string
{
    $mine = 'SELECT 1 FROM newsletter_subscriber_topics st WHERE st.subscriber_id = newsletter_subscribers.id';
    return "(NOT EXISTS ($mine) OR EXISTS ($mine AND st.topic_id = " . (int) $topicId . '))';
}

/**
 * WHERE fragment selecting the active subscribers a campaign of $topic goes to.
 * A constant string with no user input in it — safe to embed in SQL.
 */
function newsletter_topic_where(string $topic): string
{
    return ctype_digit($topic) && $topic !== '0'
        ? "status='active' AND " . newsletter_topic_match_sql((int) $topic)
        : "status='active'";
}

/**
 * A campaign topic from untrusted input: 'all', or the id (as a string) of an
 * existing topic — a hidden one too, so a campaign already aimed at it keeps
 * its audience.
 */
function newsletter_clean_topic(mixed $topic): string
{
    if (!is_string($topic) && !is_int($topic)) return 'all';
    $topic = (string) $topic;
    return ctype_digit($topic) && newsletter_topic_get((int) $topic) ? $topic : 'all';
}

// ── Topic management (admin/newsletter-topics.php) ──────────────────────────────

/** A topic name from untrusted input: plain text, at most 150 characters. */
function newsletter_clean_topic_name(mixed $name): string
{
    return is_string($name) ? mb_substr(trim(strip_tags($name)), 0, 150) : '';
}

/** Add a shown topic at the end of the list. Returns its id. */
function newsletter_topic_add(string $nameBg, string $nameEn): int
{
    $pdo  = get_pdo();
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM newsletter_topics')->fetchColumn();
    $pdo->prepare('INSERT INTO newsletter_topics (name_bg, name_en, sort_order) VALUES (?, ?, ?)')
        ->execute([newsletter_clean_topic_name($nameBg), newsletter_clean_topic_name($nameEn), $next]);
    return (int) $pdo->lastInsertId();
}

/** Rename a topic. */
function newsletter_topic_rename(int $id, string $nameBg, string $nameEn): void
{
    get_pdo()->prepare('UPDATE newsletter_topics SET name_bg = ?, name_en = ? WHERE id = ?')
        ->execute([newsletter_clean_topic_name($nameBg), newsletter_clean_topic_name($nameEn), $id]);
}

/**
 * Hide a topic, or show it again. Showing puts it back at the end of the list.
 * Nothing about who chose it is touched either way.
 */
function newsletter_topic_set_shown(int $id, bool $shown): void
{
    $pdo = get_pdo();
    if ($shown) {
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM newsletter_topics WHERE is_active = 1')->fetchColumn();
        $pdo->prepare('UPDATE newsletter_topics SET is_active = 1, sort_order = ? WHERE id = ?')->execute([$next, $id]);
    } else {
        $pdo->prepare('UPDATE newsletter_topics SET is_active = 0 WHERE id = ?')->execute([$id]);
    }
}

/** Move a shown topic one place up ($step = -1) or down (+1) in the list. */
function newsletter_topic_move(int $id, int $step): void
{
    $ids = array_map(fn($t) => (int) $t['id'], newsletter_topic_list());
    $at  = array_search($id, $ids, true);
    $to  = $at === false ? false : $at + ($step < 0 ? -1 : 1);
    if ($to === false || $to < 0 || $to >= count($ids)) return;
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

    $upd = get_pdo()->prepare('UPDATE newsletter_topics SET sort_order = ? WHERE id = ?');
    foreach ($ids as $i => $tid) $upd->execute([$i + 1, $tid]);
}

/** Active subscribers who chose each topic themselves: topic id => count. */
function newsletter_topic_subscriber_counts(): array
{
    $rows = get_pdo()->query(
        "SELECT st.topic_id, COUNT(*) AS n FROM newsletter_subscriber_topics st
         JOIN newsletter_subscribers s ON s.id = st.subscriber_id AND s.status = 'active'
         GROUP BY st.topic_id"
    )->fetchAll(\PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
}

/**
 * Language of the page a sign-up form was on. The forms post to
 * /newsletter/…, which has no /en/ in its address, so get_lang() alone would
 * make every English sign-up Bulgarian — Bulgarian messages, and Bulgarian
 * newsletters afterwards. The forms send it in a hidden `lang` field.
 */
function newsletter_form_lang(array $post): string
{
    $lang = $post['lang'] ?? null;
    return in_array($lang, ['bg', 'en'], true) ? $lang : get_lang();
}

/** Mark this browser as subscribed, so the footer sign-up band stops showing. */
function newsletter_set_subscribed_cookie(): void
{
    if (headers_sent()) return;
    setcookie('om_nl_sub', '1', [
        'expires'  => time() + 365 * 24 * 3600,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
        'secure'   => isset($_SERVER['HTTPS']),
    ]);
}

// ── "Subscribe me" on the order / donation confirmation page ─────────────────

/**
 * Remember that this browser session placed $order_number, so its confirmation
 * page may offer a one-click newsletter sign-up for the order's email.
 * Kept for callers and tests; the rule itself lives in includes/order_session.php.
 */
function newsletter_remember_order(string $order_number): void
{
    order_session_remember($order_number);
}

/** Did this browser session place $order_number? See order_session_owns(). */
function newsletter_session_owns_order(string $order_number): bool
{
    return order_session_owns($order_number);
}

/**
 * Subscribe the buyer of an order this session placed, to $topicIds (from
 * newsletter_signup_topics(); null = a choice was needed and none was made).
 * The email comes from the order row, never from the request.
 *
 * @return string  subscribed | merged | resurrect_blocked | no_topics | not_found
 */
function newsletter_subscribe_order(\PDO $pdo, string $order_number, ?array $topicIds): string
{
    if ($topicIds === null) return 'no_topics';   // see newsletter_signup_topics()
    if (!newsletter_session_owns_order($order_number)) return 'not_found';

    $stmt = $pdo->prepare('SELECT customer_email, customer_name, lang FROM orders WHERE order_number = ?');
    $stmt->execute([$order_number]);
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$order || !filter_var($order['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) return 'not_found';

    return newsletter_subscribe(
        (string) $order['customer_email'], (string) ($order['customer_name'] ?? ''),
        (string) ($order['lang'] ?? 'bg'), 'confirmation', $topicIds
    )['status'];
}

// ── "Send me news" ticked in the donation form ───────────────────────────────

/**
 * The newsletter part of a donation's consents (stored in orders.consents):
 * whether the donor asked for news, the ids of the topics they chose, and when.
 * As at the checkout, ticking a topic (or the one opt-in box when the site has
 * fewer than two topics) is the opt-in; nothing counts while „Бюлетин“ is off
 * (Admin → Модули).
 */
function newsletter_donation_consent(array $post): array
{
    $on = module_enabled_with_needs('newsletter') && newsletter_post_opted_in($post);
    return [
        'newsletter'        => $on,
        'newsletter_topics' => $on ? (newsletter_signup_topics($post) ?? []) : [],
        'accepted_at'       => date('c'),
    ];
}

/**
 * Sign up the donor of a just-paid donation who ticked "send me news" in the
 * form. Runs only once the payment went through, so a donation that was never
 * paid never subscribes anyone. The email comes from the order row.
 *
 * @return string  subscribed | merged | resurrect_blocked | skipped
 */
function newsletter_subscribe_paid_donation(array $order): string
{
    if (($order['type'] ?? '') !== 'donation' || !module_enabled_with_needs('newsletter')) return 'skipped';
    $c = json_decode((string) ($order['consents'] ?? ''), true);
    if (!is_array($c) || empty($c['newsletter'])) return 'skipped';
    if (!filter_var($order['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) return 'skipped';
    // Only topics that still exist: one may have been removed since the donation.
    $live = array_map(static fn(array $t): int => (int) $t['id'], newsletter_topic_list());
    $ids  = array_values(array_intersect(array_map('intval', (array) ($c['newsletter_topics'] ?? [])), $live));
    return newsletter_subscribe(
        (string) $order['customer_email'], (string) ($order['customer_name'] ?? ''),
        (string) ($order['lang'] ?? 'bg'), 'donation', $ids
    )['status'];
}

/**
 * Unsubscribe by token. Returns false if token not found.
 * Idempotent — clicking twice is harmless.
 */
function newsletter_unsubscribe(string $token): bool
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return false;
    $stmt = get_pdo()->prepare("
        UPDATE newsletter_subscribers
        SET status = 'unsubscribed', unsubscribed_at = NOW()
        WHERE token = ? AND status = 'active'
    ");
    $stmt->execute([$token]);
    // Return true whether we updated or were already unsubscribed — same result for the user
    // Check if token exists at all to distinguish "not found" from "already done"
    $exists = get_pdo()->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE token = ?");
    $exists->execute([$token]);
    return (int)$exists->fetchColumn() > 0;
}

/**
 * Count active subscribers, optionally broken down by lang.
 *
 * @return array ['total' => int, 'bg' => int, 'en' => int]
 */
function newsletter_active_count(): array
{
    $stmt = get_pdo()->query("
        SELECT lang, COUNT(*) AS n
        FROM newsletter_subscribers
        WHERE status = 'active'
        GROUP BY lang
    ");
    $rows = $stmt->fetchAll();
    $bg = 0; $en = 0;
    foreach ($rows as $r) {
        if ($r['lang'] === 'bg') $bg = (int)$r['n'];
        if ($r['lang'] === 'en') $en = (int)$r['n'];
    }
    return ['total' => $bg + $en, 'bg' => $bg, 'en' => $en];
}

// ── Excerpt override (compose-time only) ─────────────────────────────────────

/**
 * Apply a per-campaign excerpt edit from the newsletter compose picker,
 * keyed by article slug. Returns null unchanged for a missing article; when
 * the override is blank/whitespace-only, the article's own saved excerpt is
 * kept as-is — this never writes back to the article's JSON file, it only
 * affects the HTML this one campaign bakes via newsletter_format_articles().
 */
function newsletter_apply_excerpt_override(?array $article, array $overrides): ?array
{
    if (!$article) return null;
    $override = $overrides[$article['slug'] ?? ''] ?? '';
    $override = is_string($override) ? trim($override) : '';
    if ($override !== '') $article['excerpt'] = mb_substr($override, 0, 400);
    return $article;
}

// ── Article → email formatter ──────────────────────────────────────────────────

/**
 * Convert 1–3 article arrays into an HTML newsletter body (inner HTML only,
 * not yet wrapped in email_wrap). All image src values are made absolute.
 *
 * @param  array  $articles  Up to 3 items from get_articles()
 * @param  string $lang      'bg' or 'en'
 * @return string            Raw inner HTML suitable for pasting into body textarea
 */
function newsletter_format_articles(array $articles, string $lang): string
{
    $articles   = array_slice($articles, 0, 3);
    $read_more  = $lang === 'bg' ? 'Прочети повече →' : 'Read more →';
    $base       = defined('SITE_URL') ? SITE_URL : 'https://example.org';
    $url_prefix = $lang === 'bg' ? $base . '/novini/' : $base . '/en/news/';

    $html = '';

    foreach ($articles as $i => $article) {
        $title   = htmlspecialchars($article['title']   ?? '', ENT_QUOTES, 'UTF-8');
        $excerpt = htmlspecialchars($article['excerpt'] ?? '', ENT_QUOTES, 'UTF-8');
        $url     = $url_prefix . htmlspecialchars($article['slug'] ?? '', ENT_QUOTES, 'UTF-8') . '/';
        $img_raw = $article['image'] ?? '';
        $img     = $img_raw ? (str_starts_with($img_raw, 'http') ? $img_raw : $base . $img_raw) : '';

        if ($i === 0) {
            // ── Hero block ────────────────────────────────────────────────────
            if ($img) {
                $html .= '<img src="' . $img . '" alt="" style="width:100%;max-width:560px;display:block;margin:0 auto 20px;border-radius:6px;">' . "\n";
            }
            $html .= '<h2 style="color:#0387A5;margin:0 0 10px;font-size:22px;line-height:1.3;">' . $title . '</h2>' . "\n";
            if ($excerpt) {
                $html .= '<p style="margin:0 0 16px;color:#4a4640;line-height:1.7;">' . $excerpt . '</p>' . "\n";
            }
            $html .= '<a href="' . $url . '" style="display:inline-block;background:#0387A5;color:#ffffff;padding:10px 24px;border-radius:4px;text-decoration:none;font-size:14px;font-weight:600;">' . $read_more . '</a>' . "\n";

            if (count($articles) > 1) {
                $html .= '<hr style="border:none;border-top:1px solid #e8ddd5;margin:28px 0;">' . "\n";
            }
        } else {
            // ── Article card ──────────────────────────────────────────────────
            // Percentage-based columns (not fixed px) so this can never overflow the
            // container on a narrow phone screen; .nl-card-img/.nl-card-body get a
            // stacking media query from email_wrap() as a progressive enhancement.
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:20px;">' . "\n";
            $html .= '<tr>' . "\n";
            if ($img) {
                $html .= '<td class="nl-card-img" width="30%" valign="top" style="max-width:120px;padding-right:16px;">';
                $html .= '<img src="' . $img . '" alt="" style="width:100%;max-width:120px;border-radius:4px;display:block;">';
                $html .= '</td>' . "\n";
            }
            $html .= '<td class="nl-card-body" valign="top">' . "\n";
            $html .= '<h3 style="color:#0387A5;margin:0 0 6px;font-size:16px;line-height:1.4;">' . $title . '</h3>' . "\n";
            if ($excerpt) {
                $html .= '<p style="margin:0 0 10px;font-size:14px;color:#4a4640;line-height:1.6;">' . $excerpt . '</p>' . "\n";
            }
            $html .= '<a href="' . $url . '" style="color:#0387A5;font-size:13px;text-decoration:none;font-weight:600;">' . $read_more . '</a>' . "\n";
            $html .= '</td>' . "\n";
            $html .= '</tr></table>' . "\n";
        }
    }

    return $html;
}

// ── Tracking injection ────────────────────────────────────────────────────────

/**
 * Rewrite links and inject an open-tracking pixel into a rendered email HTML.
 *
 * - Every <a href="..."> whose target is NOT the unsubscribe URL is replaced
 *   with the click-tracking redirect endpoint.
 * - A 1×1 transparent pixel is appended just before </body> (or at the end).
 *
 * Call this AFTER render_newsletter_email(), before send_mail().
 *
 * @param  string $html   Full email HTML from render_newsletter_email()
 * @param  string $token  32-char hex tracking token for this send row
 * @return string         Modified HTML
 */
function newsletter_inject_tracking(string $html, string $token): string
{
    $base = defined('SITE_URL') ? SITE_URL : '';

    // ── Rewrite links ──────────────────────────────────────────────────────────
    $html = preg_replace_callback(
        '/<a\s([^>]*?)href=["\']([^"\']+)["\']/i',
        static function (array $m) use ($token, $base): string {
            $attrs = $m[1];
            $href  = $m[2];
            // Skip the unsubscribe link
            if (str_contains($href, '/newsletter/unsubscribe.php')) {
                return '<a ' . $attrs . 'href="' . $href . '"';
            }
            $redirect = $base . '/newsletter/track.php'
                . '?type=click&token=' . $token
                . '&url=' . rawurlencode($href);
            return '<a ' . $attrs . 'href="' . $redirect . '"';
        },
        $html
    );

    // ── Append open-tracking pixel ─────────────────────────────────────────────
    $pixel = '<img src="' . $base . '/newsletter/track.php?type=open&token=' . $token
           . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $pixel . '</body>', $html);
    } else {
        $html .= $pixel;
    }

    return $html;
}

// ── Email rendering ────────────────────────────────────────────────────────────

/**
 * Build the final HTML for one campaign email.
 * Prepends a personalised greeting and appends the unsubscribe footer.
 *
 * @param  string $greeting   e.g. "Здравейте, Мария," or "[TEST]"
 * @param  string $body_html  Raw HTML campaign body
 * @param  string $unsub_url  Full unsubscribe URL (or empty string for test preview)
 * @param  string $lang       'bg' or 'en'
 * @return string             Full email HTML via email_wrap()
 */
function render_newsletter_email(
    string $greeting,
    string $body_html,
    string $unsub_url,
    string $lang
): string {
    if (!function_exists('email_wrap')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
    }

    $unsub_text = $lang === 'bg'
        ? 'Отпишете се от бюлетина'
        : 'Unsubscribe from this newsletter';

    $unsub_block = $unsub_url
        ? '<p style="margin-top:32px;font-size:12px;color:#9b9590;text-align:center;">'
          . '<a href="' . htmlspecialchars($unsub_url, ENT_QUOTES, 'UTF-8') . '" style="color:#9b9590;">'
          . htmlspecialchars($unsub_text, ENT_QUOTES, 'UTF-8')
          . '</a></p>'
        : '<p style="margin-top:32px;font-size:12px;color:#9b9590;text-align:center;">'
          . '[' . ($lang === 'bg' ? 'Предварителен преглед — отписването не е активно' : 'Preview — unsubscribe link disabled') . ']'
          . '</p>';

    $greeting_html = $greeting
        ? '<p style="margin:0 0 20px;color:#4a4640;">' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $inner = $greeting_html . $body_html . newsletter_cta_block($lang) . $unsub_block;

    return email_wrap($inner);
}

// ── Donate call-to-action ─────────────────────────────────────────────────────

/**
 * Is the "please donate" box at the bottom of every newsletter switched on?
 *
 * Off unless the site asks for it — not every organisation fundraises by email.
 * Switched in Admin → Организация (saved to content/organisation.json), or by
 * NEWSLETTER_DONATE_CTA in site.config.php until that page is saved. It also
 * needs the donations module on: there is nowhere to send people otherwise.
 */
function newsletter_donate_cta_enabled(): bool
{
    if (!defined('NEWSLETTER_DONATE_CTA')) return false;
    $v  = constant('NEWSLETTER_DONATE_CTA');
    $on = is_string($v) ? filter_var(trim($v), FILTER_VALIDATE_BOOLEAN) : (bool) $v;
    return $on && feature_enabled('donations');
}

/**
 * Heading or text of the donate box: the site's own wording from Admin →
 * Организация, else the strings.json default.
 */
function newsletter_donate_cta_text(string $part, string $lang): string
{
    $lang  = $lang === 'en' ? 'en' : 'bg';
    $const = 'NEWSLETTER_DONATE_' . ($part === 'heading' ? 'HEADING' : 'TEXT') . '_' . strtoupper($lang);
    $own   = defined($const) ? trim((string) constant($const)) : '';
    if ($own !== '') return $own;
    return $part === 'heading'
        ? t_or('newsletter.donate.heading', 'Подкрепете каузата ни', 'Support our cause', $lang)
        : t_or('newsletter.donate.text', 'Всяко дарение ни помага да продължим работата си.', 'Every donation helps us continue our work.', $lang);
}

/**
 * Closing box inviting the reader to donate, placed between the campaign body
 * and the unsubscribe link of every newsletter (send, test and preview alike).
 * Empty when switched off ($enabled overrides the switch, for tests). Its link goes through newsletter_inject_tracking()
 * like any other link in the body. Inline styles only — email clients ignore
 * stylesheets — and nothing fixed-width, so it fits a 375px phone.
 */
function newsletter_cta_block(string $lang, ?bool $enabled = null): string
{
    if (!($enabled ?? newsletter_donate_cta_enabled())) return '';
    if (!function_exists('donation_path')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
    }
    $lang  = $lang === 'en' ? 'en' : 'bg';
    $base  = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    $url   = $base . donation_path('form', $lang);
    $label = t_or('newsletter.donate.button', 'Дарете сега', 'Donate now', $lang);
    $e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '<div class="nl-donate" style="margin-top:32px;padding:24px 16px;background:#e4f0f5;border-radius:8px;text-align:center;">'
        . '<p style="margin:0 0 12px;color:#1a1916;font-size:17px;font-weight:600;line-height:1.4;">' . $e(newsletter_donate_cta_text('heading', $lang)) . '</p>'
        . '<p style="margin:0 0 20px;color:#4a4640;font-size:14px;line-height:1.6;">' . $e(newsletter_donate_cta_text('text', $lang)) . '</p>'
        . '<a href="' . $e($url) . '" style="display:inline-block;background:#0387A5;color:#ffffff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:15px;font-weight:600;">' . $e($label) . '</a>'
        . '</div>';
}

// ── Sending ────────────────────────────────────────────────────────────────────

/**
 * Render, track, and send one campaign email to one subscriber.
 * Shared by the manual send page and the scheduled-send cron so there is a
 * single place that builds the tracking row and calls send_mail().
 *
 * @param array $campaign  Row from newsletter_campaigns (needs subject_bg/en, body_bg/en)
 * @param array $sub       Row from newsletter_subscribers (needs id, email, name, lang, token)
 */
function newsletter_send_to_subscriber(\PDO $pdo, int $campaignId, array $campaign, array $sub): bool
{
    if (!function_exists('send_mail')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
    }

    $lang    = $sub['lang'];
    $subject = $lang === 'bg' ? $campaign['subject_bg'] : $campaign['subject_en'];
    if (!$subject) $subject = $campaign['subject_bg'] ?: $campaign['subject_en'];

    $name     = $sub['name'] ?: '';
    $greeting = $lang === 'bg'
        ? ('Здравейте' . ($name ? ', ' . $name : '') . ',')
        : ('Dear '     . ($name ?: 'friend')          . ',');

    $body_raw  = $lang === 'bg' ? $campaign['body_bg'] : $campaign['body_en'];
    $unsub_url = SITE_URL . '/newsletter/unsubscribe.php?token=' . $sub['token'];

    $html = render_newsletter_email($greeting, $body_raw, $unsub_url, $lang);

    $track_token = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT IGNORE INTO newsletter_sends (campaign_id, subscriber_id, token) VALUES (?,?,?)")
        ->execute([$campaignId, $sub['id'], $track_token]);
    $html = newsletter_inject_tracking($html, $track_token);

    return send_mail($sub['email'], $subject, $html);
}

/**
 * Draft campaigns whose send_date has arrived — candidates for the scheduled-send cron.
 *
 * @return array<int,array<string,mixed>>
 */
function newsletter_due_campaigns(\PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT * FROM newsletter_campaigns
         WHERE status = 'draft' AND send_date IS NOT NULL AND send_date <= CURDATE()"
    );
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Atomically claim a draft campaign for sending, so a manual click and the
 * scheduled cron can never both send the same campaign. Returns true if this
 * caller won the claim.
 */
function newsletter_claim_for_sending(\PDO $pdo, int $campaignId): bool
{
    $stmt = $pdo->prepare("UPDATE newsletter_campaigns SET status='sending' WHERE id=? AND status='draft'");
    $stmt->execute([$campaignId]);
    return $stmt->rowCount() === 1;
}
