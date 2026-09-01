<?php

namespace ApiGoat\Google;

use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Default cURL transport shared by the Google client, the token sources and
 * the Gmail connector. Every caller accepts any callable with this signature
 * so tests substitute a fake and never touch the network:
 *
 *   fn(string $method, string $url, string[] $headers, ?string $body)
 *       : array{status:int, headers:string, body:string}
 *
 * Transport-level failures (DNS, TLS, timeout) throw TransientError — the
 * queue retries those; HTTP status classification is the caller's job.
 */
final class HttpTransport
{
    public function __construct(private int $timeout = 60, private int $connectTimeout = 15)
    {
    }

    /**
     * @param string[] $headers
     * @return array{status:int, headers:string, body:string}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result     = curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err        = curl_error($ch);
        curl_close($ch);

        if ($result === false || $err !== '') {
            throw new TransientError("{$method} {$url} cURL error: {$err}", 0);
        }
        return [
            'status'  => $status,
            'headers' => substr((string) $result, 0, $headerSize),
            'body'    => substr((string) $result, $headerSize),
        ];
    }
}
