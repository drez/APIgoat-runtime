<?php

namespace ApiGoat\Ai;

/**
 * The one outbound-AI transport.
 *
 * Before this existed the same ~40-line block — curl_init(...) ->
 * curl_setopt_array -> HTTP-code check -> json_decode -> strip a ```json
 * fence -> json_decode again — appeared 21 times across 7 projects, with
 * timeouts anywhere from 30s to 180s and four different key ladders. The
 * shape is extracted from p/apigTutor's App\Domains\Tutor\OpenAiGateway,
 * which was already the most complete version (shared handle, cross-process
 * throttle, per-call telemetry) and whose own header says it was copied from
 * p/apichatbot.
 *
 * Everything is best-effort around the call itself: logging, throttling and
 * Server-Timing must never break the request they observe.
 */
final class AiGateway
{
    /**
     * Shared curl handle, kept open for the life of the PHP worker so the
     * TCP+TLS connection is REUSED across calls (saves ~150-400ms of
     * handshake per call after the first — significant when one request
     * makes several). curl_reset() clears the options between calls but
     * preserves the handle's live connection cache; deliberately never
     * curl_close()d.
     */
    private static $sharedHandle = null;

    /** A reset shared handle pointed at $url. */
    private static function handle(string $url)
    {
        if (self::$sharedHandle === null) {
            self::$sharedHandle = \curl_init();
        } else {
            \curl_reset(self::$sharedHandle);
        }
        \curl_setopt(self::$sharedHandle, CURLOPT_URL, $url);

        return self::$sharedHandle;
    }

    /**
     * POST a JSON body to $path (relative to the manifest base URL).
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $opts timeout, retries, api_key, cost,
     *   base_url (overrides the manifest), auth (bearer|x-api-key|none),
     *   headers (extra header lines), throttle (float seconds, this call only)
     * @return array{0:int,1:mixed} [http status, decoded JSON body (or null)]
     */
    public static function post(string $path, array $body, array $opts = []): array
    {
        $apiKey  = (string) ($opts['api_key'] ?? AiConfig::apiKey());
        $timeout = (int) ($opts['timeout'] ?? AiManifest::timeout());
        $retries = (int) ($opts['retries'] ?? AiManifest::retries());
        $model   = isset($body['model']) ? (string) $body['model'] : null;

        $attempt = 0;
        while (true) {
            self::throttle(isset($opts['throttle']) ? (float) $opts['throttle'] : null);
            $ch = self::handle(self::urlFor($path, $opts));
            \curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => \json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => self::headersFor($apiKey, $opts),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HEADER  => true,
            ]);
            $raw     = \curl_exec($ch);
            $code    = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $secs    = (float) \curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $hdrSize = (int) \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlErr = $raw === false ? (string) \curl_error($ch) : '';

            $headers = \is_string($raw) ? \substr($raw, 0, $hdrSize) : '';
            $respBody = \is_string($raw) ? \substr($raw, $hdrSize) : '';
            $decoded = $respBody !== '' ? \json_decode($respBody, true) : null;

            AiUsageLogger::log($path, $model, $code, $secs, $curlErr, $decoded, $opts['cost'] ?? null);

            if (!self::shouldRetry($code, $curlErr) || $attempt >= $retries) {
                return [$code, $decoded];
            }

            // Honour Retry-After when the server sends one, else exponential
            // backoff. Capped so a hostile header cannot park a worker.
            $wait = self::retryAfter($headers);
            if ($wait === null) {
                $wait = (float) (2 ** $attempt);
            }
            $wait = \min($wait, 30.0);
            \usleep((int) \round($wait * 1_000_000));
            $attempt++;
        }
    }

    /**
     * Absolute URL for $path: $opts['base_url'] when given (a per-tenant
     * provider, or the LAN Ollama box), else the build-time manifest.
     *
     * @param array<string,mixed> $opts
     */
    public static function urlFor(string $path, array $opts = []): string
    {
        $base = isset($opts['base_url']) && \is_string($opts['base_url']) && $opts['base_url'] !== ''
            ? $opts['base_url']
            : AiManifest::baseUrl();

        return \rtrim($base, '/') . $path;
    }

    /**
     * Request headers. $opts['auth'] picks how the key travels — `bearer`
     * (default: OpenAI, Ollama), `x-api-key` (Anthropic) or `none` — and
     * $opts['headers'] appends extra lines (e.g. `anthropic-version: ...`).
     *
     * @param array<string,mixed> $opts
     * @return string[]
     */
    public static function headersFor(string $apiKey, array $opts = []): array
    {
        $auth = (string) ($opts['auth'] ?? 'bearer');
        $headers = [];
        if ($auth === 'x-api-key') {
            $headers[] = 'x-api-key: ' . $apiKey;
        } elseif ($auth !== 'none') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $headers[] = 'Content-Type: application/json';
        foreach ((array) ($opts['headers'] ?? []) as $line) {
            if (\is_string($line) && $line !== '') {
                $headers[] = $line;
            }
        }

        return $headers;
    }

    /**
     * Retry only what retrying can fix: a transport failure, 429, or 5xx.
     * A 4xx other than 429 is a bad request — retrying it just burns quota.
     */
    public static function shouldRetry(int $httpStatus, string $transportError): bool
    {
        if ($transportError !== '' || $httpStatus === 0) {
            return true;
        }

        return $httpStatus === 429 || $httpStatus >= 500;
    }

    /** Seconds from a Retry-After header (delta form only), or null. */
    public static function retryAfter(string $headers): ?float
    {
        if (\preg_match('/^Retry-After:\s*([0-9]+(?:\.[0-9]+)?)\s*$/mi', $headers, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * Decode a model's JSON answer, tolerating a ```json fence.
     *
     * Models wrap JSON in a markdown fence often enough that every one of the
     * 21 copied call sites stripped it by hand, each slightly differently.
     *
     * @return mixed decoded value, or null when the text is not JSON
     */
    public static function decodeJson(?string $text)
    {
        if ($text === null) {
            return null;
        }
        $t = \trim($text);
        if ($t === '') {
            return null;
        }
        // ```json ... ```  /  ``` ... ```
        if (\preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $t, $m)) {
            $t = \trim($m[1]);
        }

        return \json_decode($t, true);
    }

    /**
     * Cross-process minimum spacing between outbound calls (flock-serialized
     * timestamp file), a cheap backstop underneath any per-day ceiling: even
     * within quota, nothing else stops a caller firing as fast as the network
     * allows. Best-effort — an unwritable directory must never break a call,
     * only its pacing.
     */
    private static function throttle(?float $spacing = null): void
    {
        $spacing = $spacing ?? AiManifest::throttleSeconds();
        if ($spacing <= 0) {
            return;
        }
        $dir = (\defined('_BASE_DIR') && \is_dir(\_BASE_DIR . 'tmp'))
            ? \rtrim(\_BASE_DIR, '/') . '/tmp'
            : \sys_get_temp_dir();
        $dir .= '/ai-throttle';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0770, true);
        }
        $fh = @\fopen($dir . '/throttle.stamp', 'c+');
        if (!$fh) {
            return;
        }

        // Read the stamp, RELEASE, sleep, then re-lock to write. Sleeping
        // while holding LOCK_EX turns the pacing gap into a convoy: N callers
        // each wait out every earlier one's sleep, so the throttle costs O(N)
        // instead of pacing at O(1).
        $wait = 0.0;
        if (\flock($fh, LOCK_SH)) {
            $last = (float) \trim((string) \stream_get_contents($fh));
            $wait = $spacing - (\microtime(true) - $last);
            \flock($fh, LOCK_UN);
        }
        if ($wait > 0 && $wait <= $spacing) {
            \usleep((int) \round($wait * 1_000_000));
        }
        if (\flock($fh, LOCK_EX)) {
            \ftruncate($fh, 0);
            \rewind($fh);
            \fwrite($fh, \sprintf('%.6F', \microtime(true)));
            \fflush($fh);
            \flock($fh, LOCK_UN);
        }
        \fclose($fh);
    }
}
