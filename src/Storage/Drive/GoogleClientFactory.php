<?php

namespace ApiGoat\Storage\Drive;

use ApiGoat\Storage\Drive\Exceptions\AuthFailed;
use ApiGoat\Storage\Drive\Exceptions\RateLimited;
use ApiGoat\Storage\Drive\Exceptions\TransientError;

/**
 * Single chokepoint for every Google Workspace API call routed through the
 * runtime Storage layer. Mints SA JWTs via {@see JwtSigner}, caches access
 * tokens per (subject, scope-set) for the rest of the request, and offers
 * raw HTTP helpers that classify failures into AuthFailed / RateLimited /
 * TransientError so callers can decide retry policy without parsing HTTP
 * codes themselves.
 *
 * Hoisted from /var/www/gc/p/apicrm/.admin/src/App/Domains/Google/GoogleClientFactory.php
 * for the new is_drive_backed behavior. The apicrm copy stays in place for
 * the Calendar/Gmail/legacy Drive flows until a follow-up migrates them.
 */
class GoogleClientFactory
{
    public const SCOPE_GMAIL_SEND      = 'https://www.googleapis.com/auth/gmail.send';
    public const SCOPE_GMAIL_READONLY  = 'https://www.googleapis.com/auth/gmail.readonly';
    public const SCOPE_CALENDAR        = 'https://www.googleapis.com/auth/calendar';
    public const SCOPE_DRIVE_FILE      = 'https://www.googleapis.com/auth/drive.file';

    private JwtSigner $signer;

    /** @var array<string, array{token: string, expires_at: int}> */
    private array $tokenCache = [];

    public function __construct(JwtSigner $signer)
    {
        $this->signer = $signer;
    }

    /**
     * Build from .env. Reads the project's `.admin/.env` (the project loader
     * only touches the project-root .env). Mirrors the apicrm pattern,
     * including the TEST_ prefix override when VERSION=dev.
     */
    public static function fromEnv(): self
    {
        self::loadAdminEnv();

        $version = strtolower(trim((string) (
            $_ENV['VERSION'] ?? getenv('VERSION') ?: 'production'
        )));
        $prefix = ($version === 'dev') ? 'TEST_' : '';

        $email = $_ENV[$prefix . 'GCALENDAR_SERVICE_ACC_EMAIL']
            ?? getenv($prefix . 'GCALENDAR_SERVICE_ACC_EMAIL')
            ?: '';
        $path = $_ENV[$prefix . 'GCALENDAR_SERVICE_ACC_KEY_PATH']
            ?? getenv($prefix . 'GCALENDAR_SERVICE_ACC_KEY_PATH')
            ?: '';

        if (!$email || !$path) {
            throw new AuthFailed(
                "Google SA not configured: set {$prefix}GCALENDAR_SERVICE_ACC_EMAIL "
                . "and {$prefix}GCALENDAR_SERVICE_ACC_KEY_PATH in .admin/.env"
            );
        }

        // The configured key path is a host-absolute path (kept absolute so the
        // deploy env-push rewrites its prefix to the remote layout). That path
        // does not exist in every runtime — e.g. the ddev container mounts the
        // project at /var/www/html, not the host PROJECT_PATH. When the literal
        // path is missing, fall back to the same secrets file under this .admin
        // tree (six parents up from this vendor file) so dev (container) and
        // prod both resolve the key.
        if (!is_file($path)) {
            $adminRoot = realpath(__DIR__ . '/../../../../../..');
            $candidate = ($adminRoot !== false)
                ? $adminRoot . '/config/secrets/' . basename($path)
                : '';
            if ($candidate !== '' && is_file($candidate)) {
                $path = $candidate;
            }
        }

        return new self(JwtSigner::fromKeyFile($email, $path));
    }

    /**
     * Shared Drive id for the is_drive_backed storage, or '' when unset.
     *
     * When configured, {@see GoogleDriveStorage} anchors its folder tree at
     * this org-owned Shared Drive (visible to every drive member) instead of
     * each impersonated user's private My Drive. Empty = classic per-user My
     * Drive mode, so existing projects are unaffected. Honors the same TEST_
     * prefix override as {@see fromEnv} when VERSION=dev.
     */
    public static function sharedDriveIdFromEnv(): string
    {
        self::loadAdminEnv();

        $version = strtolower(trim((string) (
            $_ENV['VERSION'] ?? getenv('VERSION') ?: 'production'
        )));
        $prefix = ($version === 'dev') ? 'TEST_' : '';

        return trim((string) (
            $_ENV[$prefix . 'GDRIVE_SHARED_DRIVE_ID']
            ?? getenv($prefix . 'GDRIVE_SHARED_DRIVE_ID')
            ?: ''
        ));
    }

    private static function loadAdminEnv(): void
    {
        static $loaded = false;
        if ($loaded) return;

        // The PSR-4 path for this class is vendor/apigoat/runtime/src/Storage/Drive/.
        // Walking up to the project's .admin/.env would mean .../../../../../../.env
        // from this file, but we're inside vendor/ of the project, so the actual
        // project root is six parents up. Use a robust fallback: try the relative
        // path; if it doesn't exist, fall back to the .env defined by $_ENV / getenv.
        $envFile = realpath(__DIR__ . '/../../../../../../../.admin/.env')
            ?: realpath(__DIR__ . '/../../../../../../.env')
            ?: '';
        if ($envFile && file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $v = trim(explode(' #', $v, 2)[0], " \t\"'");
                if (!isset($_ENV[trim($k)])) {
                    $_ENV[trim($k)] = $v;
                    putenv(trim($k) . '=' . $v);
                }
            }
        }
        $loaded = true;
    }

    /**
     * Get a cached access token for (subject, scope-set) or mint a new one.
     *
     * @param string[]    $scopes
     * @param string|null $subject Workspace user email (DWD) or null for SA-as-itself
     */
    public function getAccessToken(array $scopes, ?string $subject = null): string
    {
        sort($scopes);
        $key = ($subject ?? '_self_') . '|' . implode(',', $scopes);

        $now = time();
        if (isset($this->tokenCache[$key]) && $this->tokenCache[$key]['expires_at'] > $now + 60) {
            return $this->tokenCache[$key]['token'];
        }

        $minted = $this->signer->mintAccessToken($scopes, $subject);
        $this->tokenCache[$key] = [
            'token'      => $minted['access_token'],
            'expires_at' => $now + $minted['expires_in'],
        ];

        return $minted['access_token'];
    }

    /**
     * @param string[] $scopes
     * @return array<string,mixed>
     */
    public function get(string $url, array $scopes, ?string $subject = null): array
    {
        return $this->request('GET', $url, $scopes, $subject, null);
    }

    /**
     * @param string[] $scopes
     * @return array<string,mixed>
     */
    public function post(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        return $this->request('POST', $url, $scopes, $subject, $payload);
    }

    /**
     * @param string[] $scopes
     * @return array<string,mixed>
     */
    public function put(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        return $this->request('PUT', $url, $scopes, $subject, $payload);
    }

    /**
     * @param string[] $scopes
     * @return array<string,mixed>
     */
    public function patch(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        return $this->request('PATCH', $url, $scopes, $subject, $payload);
    }

    /**
     * @param string[] $scopes
     * @return bool true on success (204), false on 404 (already gone)
     */
    public function delete(string $url, array $scopes, ?string $subject = null): bool
    {
        try {
            $this->request('DELETE', $url, $scopes, $subject, null);
            return true;
        } catch (TransientError $e) {
            if ($e->httpCode === 404) return false;
            throw $e;
        }
    }

    /**
     * Raw multipart upload — used by GoogleDriveStorage for file payloads
     * where the body is binary, not JSON. Returns decoded JSON response.
     *
     * @param string[]                            $scopes
     * @param array{name: string, parents?: array<string>, mimeType?: string} $metadata
     * @return array<string,mixed>
     */
    public function uploadMultipart(
        string $url,
        array $metadata,
        string $body,
        string $bodyMime,
        array $scopes,
        ?string $subject = null
    ): array {
        $token    = $this->getAccessToken($scopes, $subject);
        $boundary = 'b' . bin2hex(random_bytes(16));

        $payload = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$bodyMime}\r\n\r\n"
            . $body . "\r\n"
            . "--{$boundary}--";

        return $this->curl(
            'POST',
            $url,
            $payload,
            [
                'Authorization: Bearer ' . $token,
                "Content-Type: multipart/related; boundary={$boundary}",
            ],
            $subject
        );
    }

    /** @param string[] $scopes */
    private function request(string $method, string $url, array $scopes, ?string $subject, ?array $payload): array
    {
        $token = $this->getAccessToken($scopes, $subject);
        $body  = $payload === null ? null : json_encode($payload);
        $headers = ['Authorization: Bearer ' . $token];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        return $this->curl($method, $url, $body, $headers, $subject);
    }

    /**
     * @param string[] $headers
     * @return array<string,mixed>
     */
    private function curl(string $method, string $url, ?string $body, array $headers, ?string $subject): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        $result     = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr    = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new TransientError("Google API {$method} {$url} cURL error: {$curlErr}", 0);
        }

        $rawHeaders = substr((string) $result, 0, $headerSize);
        $rawBody    = substr((string) $result, $headerSize);
        $data       = $rawBody === '' ? [] : (json_decode($rawBody, true) ?? []);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $data;
        }

        $msg = $data['error']['message'] ?? $rawBody ?: "HTTP {$httpCode}";
        $sub = $subject ? " sub={$subject}" : '';

        if ($httpCode === 401 || $httpCode === 403) {
            $reason = $data['error']['errors'][0]['reason'] ?? '';
            if (in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                throw new RateLimited("Google API {$method} {$url}{$sub}: {$msg}", self::parseRetryAfter($rawHeaders));
            }
            throw new AuthFailed("Google API {$method} {$url}{$sub}: {$msg}");
        }
        if ($httpCode === 404) {
            throw new TransientError("Google API {$method} {$url}{$sub}: not found", 404);
        }
        if ($httpCode === 429) {
            throw new RateLimited("Google API {$method} {$url}{$sub}: {$msg}", self::parseRetryAfter($rawHeaders));
        }
        if ($httpCode >= 500) {
            throw new TransientError("Google API {$method} {$url}{$sub} server error: {$msg}", $httpCode);
        }

        throw new TransientError("Google API {$method} {$url}{$sub} returned HTTP {$httpCode}: {$msg}", $httpCode);
    }

    private static function parseRetryAfter(string $rawHeaders): int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/im', $rawHeaders, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}
