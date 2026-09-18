<?php
declare(strict_types=1);

namespace SupportRelay;

/** Minimal HTTP response value object. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {}
}

/** Thrown when the request could not be completed at all (DNS, TLS, timeout...). */
final class HttpTransportException extends \RuntimeException {}

/**
 * The only thing the relay needs from HTTP. Injectable so tests never hit the network.
 */
interface HttpClient
{
    /** POST application/x-www-form-urlencoded. */
    public function postForm(string $url, array $fields): HttpResponse;

    /** POST multipart/form-data. $fields values may be \CURLFile instances. */
    public function postMultipart(string $url, array $fields): HttpResponse;
}

final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 15) {}

    public function postForm(string $url, array $fields): HttpResponse
    {
        return $this->send($url, http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
    }

    public function postMultipart(string $url, array $fields): HttpResponse
    {
        return $this->send($url, $fields);
    }

    private function send(string $url, string|array $body): HttpResponse
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'ngo-cms-support-relay/1.0',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            // Error text never contains the query string, but keep it short anyway.
            $err = curl_error($ch);
            throw new HttpTransportException('transport error: ' . substr($err, 0, 200));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, (string) $raw);
    }
}
