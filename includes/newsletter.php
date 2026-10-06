<?php
/**
 * Newsletter helper functions.
 * Included by public endpoints (subscribe/unsubscribe) and admin send logic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

// ── Subscriber management ──────────────────────────────────────────────────────

/** Sources a subscriber can come from — mirrors the `source` column's ENUM. */
const NEWSLETTER_SOURCES = ['web_banner', 'customer_import', 'manual', 'checkout', 'donation', 'confirmation'];

/**
 * Subscribe an email to one or both newsletter topics.
 *
 * Merge-aware and consent-safe:
 *  - no topic selected            → no write
 *  - new email                    → insert with the chosen topics
 *  - existing & active            → OR-merge topics (never clears one)
 *  - existing & unsubscribed      → left untouched (no resurrection: the
 *                                   person said stop, a later form tick on
 *                                   some other page must not undo that)
 *
 * Concurrency-safe: INSERT IGNORE on the unique email decides atomically
 * whether this call created the row, and the merge uses GREATEST() in a single
 * UPDATE, so two simultaneous sign-ups can neither duplicate a row nor clear
 * each other's topic.
 *
 * @return array ['ok' => bool, 'duplicate' => bool, 'status' => string]
 *   status ∈ subscribed | merged | resurrect_blocked | no_topics
 */
function newsletter_subscribe(
    string $email,
    string $name,
    string $lang,
    string $source,
    bool $wantsNews = true,
    bool $wantsEducation = true
): array {
    if (!$wantsNews && !$wantsEducation) {
        return ['ok' => false, 'duplicate' => false, 'status' => 'no_topics'];
    }

    $email  = strtolower(trim($email));
    $lang   = in_array($lang, ['bg', 'en'], true) ? $lang : 'bg';
    $source = in_array($source, NEWSLETTER_SOURCES, true) ? $source : 'web_banner';

    $pdo   = get_pdo();
    $token = bin2hex(random_bytes(32));

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO newsletter_subscribers
           (email, name, lang, source, token, wants_news, wants_education)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        $email, trim($name) ?: null, $lang, $source, $token,
        $wantsNews ? 1 : 0, $wantsEducation ? 1 : 0,
    ]);
    if ($ins->rowCount() === 1) {
        return ['ok' => true, 'duplicate' => false, 'status' => 'subscribed'];
    }

    // The email is already on the list.
    $sel = $pdo->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ?");
    $sel->execute([$email]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    if (!$row || $row['status'] === 'unsubscribed') {
        // (No row: deleted between the INSERT and this SELECT — treat as a no-op.)
        return ['ok' => false, 'duplicate' => true, 'status' => 'resurrect_blocked'];
    }

    $pdo->prepare(
        "UPDATE newsletter_subscribers
         SET wants_news = GREATEST(wants_news, ?), wants_education = GREATEST(wants_education, ?)
         WHERE id = ?"
    )->execute([$wantsNews ? 1 : 0, $wantsEducation ? 1 : 0, $row['id']]);
    return ['ok' => true, 'duplicate' => true, 'status' => 'merged'];
}

// ── Topics ─────────────────────────────────────────────────────────────────────

/**
 * The topics a subscriber can pick, as campaign topic key => subscriber column.
 * The keys are internal; what people read comes from newsletter_topic_label().
 */
function newsletter_topics(): array
{
    return ['news' => 'wants_news', 'education' => 'wants_education'];
}

/**
 * The visible name of a topic (or of the "topics" heading, $topic = 'heading').
 *
 * Wording is the site's own: an admin edits it in place on the page (inline
 * editing, saved to the `newsletter` section of content/pages.json). Until
 * then the strings.json default is used — generic text a site can also reword
 * in content/{bg,en}/strings.site.json.
 */
function newsletter_topic_label(string $topic, ?string $lang = null): string
{
    $lang = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    if (!in_array($topic, ['heading', 'news', 'education'], true)) return $topic;
    $field = newsletter_topic_field($topic);
    $saved = newsletter_pages_section()[$lang === 'en' ? $field . '_en' : $field] ?? '';
    if (is_string($saved) && trim($saved) !== '') return $saved;
    return match ($topic) {
        'heading'   => t_or('newsletter.topics.heading', 'Какво искате да получавате?', 'What would you like to receive?', $lang),
        'news'      => t_or('newsletter.topic.news', 'Новини за нашата дейност', 'News about our work', $lang),
        'education' => t_or('newsletter.topic.education', 'Полезни материали и съвети', 'Useful resources and tips', $lang),
    };
}

/** The pages.json field (BG; EN adds _en) holding a topic's label. */
function newsletter_topic_field(string $topic): string
{
    return $topic === 'heading' ? 'topics_heading' : 'topic_' . $topic . '_label';
}

/** The `newsletter` section of content/pages.json (inline-edited copy). */
function newsletter_pages_section(): array
{
    $pages = load_json(CONTENT_PATH . '/pages.json');
    return is_array($pages['newsletter'] ?? null) ? $pages['newsletter'] : [];
}

/** Topic flags ticked in a submitted form (the topics partial's checkboxes). */
function newsletter_topics_from_post(array $post): array
{
    return [!empty($post['wants_news']), !empty($post['wants_education'])];
}

/**
 * WHERE fragment selecting the active subscribers a campaign of $topic goes to.
 * A constant string with no user input in it — safe to embed in SQL.
 */
function newsletter_topic_where(string $topic): string
{
    $col = newsletter_topics()[$topic] ?? null;
    return $col ? "status='active' AND $col=1" : "status='active'";
}

/** A campaign topic from untrusted input: one of 'all' / the topic keys. */
function newsletter_clean_topic(mixed $topic): string
{
    return is_string($topic) && isset(newsletter_topics()[$topic]) ? $topic : 'all';
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
 * Called by the shop checkout and the donation form when they create an order.
 */
function newsletter_remember_order(string $order_number): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $list   = is_array($_SESSION['nl_orders'] ?? null) ? $_SESSION['nl_orders'] : [];
    $list[] = strtoupper($order_number);
    $_SESSION['nl_orders'] = array_slice(array_values(array_unique($list)), -10);
}

/**
 * Did this browser session place $order_number? Order numbers are short and
 * appear in URLs, so knowing one must never be enough to sign its buyer up —
 * only the session that placed the order may.
 */
function newsletter_session_owns_order(string $order_number): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    $list = $_SESSION['nl_orders'] ?? [];
    return is_array($list) && in_array(strtoupper($order_number), $list, true);
}

/**
 * Subscribe the buyer of an order this session placed. The email comes from
 * the order row, never from the request.
 *
 * @return string  subscribed | merged | resurrect_blocked | no_topics | not_found
 */
function newsletter_subscribe_order(\PDO $pdo, string $order_number, bool $wantsNews, bool $wantsEducation): string
{
    if (!$wantsNews && !$wantsEducation) return 'no_topics';
    if (!newsletter_session_owns_order($order_number)) return 'not_found';

    $stmt = $pdo->prepare('SELECT customer_email, customer_name, lang FROM orders WHERE order_number = ?');
    $stmt->execute([$order_number]);
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$order || !filter_var($order['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) return 'not_found';

    return newsletter_subscribe(
        (string) $order['customer_email'], (string) ($order['customer_name'] ?? ''),
        (string) ($order['lang'] ?? 'bg'), 'confirmation', $wantsNews, $wantsEducation
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
