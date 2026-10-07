<?php

namespace ApiGoat\Microsoft;

use ApiGoat\Google\HttpTransport;
use ApiGoat\Mail\TokenSource;

/**
 * Authenticated Microsoft Graph calls. Always asks for immutable ids so
 * message ids survive folder moves. A 401 invalidates the token source and
 * retries exactly once; everything else goes through {@see GraphErrorMapper}.
 */
final class GraphHttp
{
    public const BASE = 'https://graph.microsoft.com/v1.0';

    /** @var callable */
    private $http;

    public function __construct(private TokenSource $tokens, ?callable $transport = null)
    {
        $this->http = $transport ?? new HttpTransport(60, 15);
    }

    /**
     * @param string[] $extraHeaders
     * @return array<string,mixed>|string  decoded JSON ([] on 202/204/empty) or the raw body when $raw
     */
    public function call(string $method, string $pathOrUrl, ?array $json = null, array $extraHeaders = [], bool $raw = false): array|string
    {
        $headers = $extraHeaders;
        $body    = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body      = (string) json_encode($json);
        }
        return $this->send($method, $pathOrUrl, $headers, $body, $raw);
    }

    /** POST a pre-encoded body as-is (MIME, base64). @return array<string,mixed> */
    public function postRaw(string $path, string $body, string $contentType): array
    {
        $r = $this->send('POST', $path, ['Content-Type: ' . $contentType], $body, false);
        return is_array($r) ? $r : [];
    }

    /** @param string[] $headers */
    private function send(string $method, string $path, array $headers, ?string $body, bool $raw, bool $retried = false): array|string
    {
        $url  = str_starts_with($path, 'http') ? $path : self::BASE . $path;
        $all  = array_merge([
            'Authorization: Bearer ' . $this->tokens->accessToken(),
            'Accept: application/json',
            'Prefer: IdType="ImmutableId"',
        ], $headers);
        $r      = ($this->http)($method, $url, $all, $body);
        $status = (int) $r['status'];
        if ($status >= 200 && $status < 300) {
            if ($raw) {
                return (string) $r['body'];
            }
            if ($status === 202 || $status === 204 || $r['body'] === '') {
                return [];
            }
            $data = json_decode((string) $r['body'], true);
            return is_array($data) ? $data : [];
        }
        if ($status === 401 && !$retried) {
            $this->tokens->invalidate();
            return $this->send($method, $path, $headers, $body, $raw, true);
        }
        GraphErrorMapper::fail("Graph {$method} {$path} (" . $this->tokens->describe() . ')', $status, (string) ($r['headers'] ?? ''), (string) $r['body']);
    }
}
