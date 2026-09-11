<?php
/**
 * Shared spam defenses for public-facing forms (contact, comments, reviews):
 * an admin-editable content blocklist, a fixed link-count rule, and
 * Cloudflare Turnstile bot-challenge verification.
 */

require_once __DIR__ . '/settings.php';

// ── Content blocklist ────────────────────────────────────────────────────────

function spam_blocklist_terms(): array
{
    $raw = setting_get('spam_blocklist');
    if ($raw === '') return [];
    $terms = array_map('trim', explode("\n", $raw));
    $terms = array_map('mb_strtolower', $terms);
    return array_values(array_filter($terms, fn($t) => $t !== ''));
}

function spam_blocklist_add(array $terms): void
{
    if (!$terms) return;
    $existing = spam_blocklist_terms();
    $new = array_map('trim', $terms);
    $new = array_map('mb_strtolower', $new);
    $new = array_filter($new, fn($t) => $t !== '');
    $merged = array_values(array_unique(array_merge($existing, $new)));
    setting_set('spam_blocklist', implode("\n", $merged));
}

function spam_content_is_blocked(string $text): bool
{
    $lower = mb_strtolower($text);
    foreach (spam_blocklist_terms() as $term) {
        if ($term !== '' && str_contains($lower, $term)) {
            return true;
        }
    }
    return preg_match_all('/\b(?:https?:\/\/|www\.)[^\s<>"\']+/i', $text) >= 3;
}

// ── Domain extraction (feeds the admin bulk "mark as spam" learning flow) ────

function spam_extract_domains(array $texts): array
{
    $domains = [];
    foreach ($texts as $text) {
        if (!preg_match_all('/\b(?:https?:\/\/|www\.)[^\s<>"\']+/i', $text, $matches)) {
            continue;
        }
        foreach ($matches[0] as $url) {
            $withScheme = str_starts_with(strtolower($url), 'http') ? $url : 'http://' . $url;
            $host = parse_url($withScheme, PHP_URL_HOST);
            if ($host) {
                $host = rtrim($host, ".,;:)]}\'\"");
                $host = preg_replace('/^www\./i', '', $host);
                $domains[] = mb_strtolower($host);
            }
        }
    }
    return array_values(array_unique($domains));
}

// ── Cloudflare Turnstile (bot challenge) ─────────────────────────────────────

function turnstile_is_configured(): bool
{
    return setting_get('turnstile_enabled', '0') === '1'
        && setting_is_set('turnstile_site_key')
        && setting_is_set('turnstile_secret_key');
}

function turnstile_verify(string $token, string $ip): bool
{
    if ($token === '') return false;
    $secret = setting_get('turnstile_secret_key');
    if ($secret === '') return false;

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $raw === false) return false;
    $data = json_decode((string)$raw, true);
    return (bool)($data['success'] ?? false);
}

// ── Bulk "mark as spam" (admin/comments.php) ─────────────────────────────────

function spam_mark_comments_as_spam(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("SELECT content FROM comments WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $texts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare("UPDATE comments SET status = 'spam' WHERE id IN ($placeholders)")->execute($ids);

    return spam_extract_domains($texts);
}

function spam_mark_contacts_as_spam(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("SELECT message FROM contact_submissions WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $texts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare("UPDATE contact_submissions SET status = 'spam' WHERE id IN ($placeholders)")->execute($ids);

    return spam_extract_domains($texts);
}
