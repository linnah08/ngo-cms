<?php
/**
 * URL helpers that survive non-ASCII addresses.
 *
 * PHP's own tools cannot be trusted with them:
 *
 *   parse_url('https://пример.бг/път', PHP_URL_HOST)
 *       can return a string that is NOT the host that was typed: it swaps any
 *       byte iscntrl() calls a control character for '_', and under a UTF-8
 *       LC_CTYPE on macOS that includes 0x80 (the second byte of "р"). PHP
 *       takes LC_CTYPE from the environment, so whether it happens depends on
 *       the server's locale and C library, not the PHP version. When it does,
 *       strpos() cannot find the host in the original and every check built
 *       on top works on rubbish. Code here must not depend on which it is.
 *
 *   filter_var('https://пример.бг/път', FILTER_VALIDATE_URL)
 *       returns false. The address is perfectly valid; the filter only knows
 *       ASCII.
 *
 * Between them, an NGO on a .бг domain could not save its own website address,
 * and a spam link on such a domain never matched the blocklist.
 *
 * The rule here: split with a byte-safe regex, and validate a normalised copy
 * (punycode host, percent-encoded path) while handing back what was typed.
 */

/**
 * Split scheme://[userinfo@]host[:port][rest] without parse_url().
 * Returns null when the string is not of that shape.
 *
 * @return array{scheme:string,userinfo:string,host:string,port:string,rest:string}|null
 */
function url_split(string $url): ?array
{
    if (!preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?#]*)(.*)$~s', $url, $m)) {
        return null;
    }

    $authority = $m[2];
    $userinfo  = '';
    if (($at = strrpos($authority, '@')) !== false) {
        $userinfo  = substr($authority, 0, $at + 1);
        $authority = substr($authority, $at + 1);
    }

    // A trailing :digits is a port — unless the authority ends in ']', which
    // makes it a bracketed IPv6 literal with no port.
    $port = '';
    if (!str_ends_with($authority, ']') && preg_match('~^(.*):(\d+)$~', $authority, $pm)) {
        $authority = $pm[1];
        $port      = ':' . $pm[2];
    }

    return [
        'scheme'   => strtolower($m[1]),
        'userinfo' => $userinfo,
        'host'     => $authority,
        'port'     => $port,
        'rest'     => $m[3],
    ];
}

/**
 * The host exactly as it was typed — Cyrillic stays Cyrillic. Null when the
 * string has no host at all.
 */
function url_host(string $url): ?string
{
    $parts = url_split($url);
    if ($parts === null || $parts['host'] === '') {
        return null;
    }
    return $parts['host'];
}

/**
 * An ASCII copy of $url that PHP's validators can actually read: punycode host,
 * percent-encoded non-ASCII elsewhere. Null when the shape is wrong, or when
 * the host needs punycode and the intl extension is not installed — refusing is
 * safer than validating a host nobody has checked.
 */
function url_ascii(string $url): ?string
{
    $parts = url_split($url);
    if ($parts === null || $parts['host'] === '') {
        return null;
    }

    $host = $parts['host'];
    if (preg_match('/[^\x00-\x7F]/', $host)) {
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($host === false || $host === '') {
            return null;
        }
    }

    $rest = (string) preg_replace_callback(
        '/[^\x00-\x7F]+/',
        static fn(array $m): string => rawurlencode($m[0]),
        $parts['rest']
    );

    return $parts['scheme'] . '://' . $parts['userinfo'] . $host . $parts['port'] . $rest;
}

/**
 * Is this a well-formed http(s) address, Cyrillic or otherwise?
 */
function url_is_web(string $url): bool
{
    $parts = url_split($url);
    if ($parts === null || !in_array($parts['scheme'], ['http', 'https'], true)) {
        return false;
    }
    $ascii = url_ascii($url);

    return $ascii !== null && filter_var($ascii, FILTER_VALIDATE_URL) !== false;
}

/**
 * The path part of a request URI, without parse_url() — which alters non-ASCII
 * bytes here too, so a request carrying a raw (unencoded) Cyrillic path came
 * back as a path that matched nothing. Browsers percent-encode, but not every
 * client does, and the language switch and canonical URL are built from this.
 */
function url_request_path(string $requestUri): string
{
    $path = preg_replace('/[?#].*$/s', '', $requestUri);

    return ($path === null || $path === '') ? '/' : $path;
}
