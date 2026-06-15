<?php
/**
 * Newsletter helper functions.
 * Included by public endpoints (subscribe/unsubscribe) and admin send logic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

// ── Subscriber management ──────────────────────────────────────────────────────

/**
 * Subscribe an email address.
 * Uses INSERT IGNORE so concurrent requests cannot create duplicates.
 *
 * @return array ['ok' => bool, 'duplicate' => bool]
 */
function newsletter_subscribe(string $email, string $name, string $lang, string $source): array
{
    $pdo   = get_pdo();
    $token = bin2hex(random_bytes(32));
    $stmt  = $pdo->prepare("
        INSERT IGNORE INTO newsletter_subscribers (email, name, lang, source, token)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        strtolower(trim($email)),
        trim($name) ?: null,
        in_array($lang, ['bg','en']) ? $lang : 'bg',
        in_array($source, ['web_banner','customer_import','manual']) ? $source : 'web_banner',
        $token,
    ]);
    $duplicate = $stmt->rowCount() === 0;
    return ['ok' => true, 'duplicate' => $duplicate];
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
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">' . "\n";
            $html .= '<tr>' . "\n";
            if ($img) {
                $html .= '<td width="120" valign="top" style="padding-right:16px;">';
                $html .= '<img src="' . $img . '" width="120" alt="" style="border-radius:4px;display:block;">';
                $html .= '</td>' . "\n";
            }
            $html .= '<td valign="top">' . "\n";
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

    $inner = $greeting_html . $body_html . $unsub_block;

    return email_wrap($inner);
}
