<?php

namespace ApiGoat\Google;

use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Single chokepoint for every Google Workspace API call made by the runtime.
 *
 * Mints SA JWTs via {@see JwtSigner}, caches access tokens per (subject,
 * scope-set) for the rest of the process, and offers raw HTTP helpers that
 * classify failures into AuthFailed / RateLimited / TransientError
 * ({@see ErrorMapper}) so callers decide retry policy without parsing HTTP
 * codes themselves.
 *
 * Promoted from p/apicrm App\Domains\Google\GoogleClientFactory. The one
 * named change: no `fromEnv()` / `.admin/.env` hand-parse. Build it from
 * explicit config — {@see fromServiceAccount()} — and let the project decide
 * where the key comes from.
 */
class ClientFactory
{
    public const SCOPE_GMAIL_SEND      = 'https://www.googleapis.com/auth/gmail.send';
    public const SCOPE_GMAIL_READONLY  = 'https://www.googleapis.com/auth/gmail.readonly';
    public const SCOPE_GMAIL_MODIFY    = 'https://www.googleapis.com/auth/gmail.modify';
    public const SCOPE_GMAIL_SETTINGS  = 'https://www.googleapis.com/auth/gmail.settings.basic';
    public const SCOPE_CALENDAR        = 'https://www.googleapis.com/auth/calendar';
    public const SCOPE_DRIVE_FILE      = 'https://www.googleapis.com/auth/drive.file';
    /** Full Drive scope — required for Shared Drive mode (drive.file cannot list a Shared Drive). */
    public const SCOPE_DRIVE           = 'https://www.googleapis.com/auth/drive';

    private JwtSigner $signer;
    /** @var callable */
    private $transport;

    /** @var array<string, array{token: string, expires_at: int}> */
    private array $tokenCache = [];

    public function __construct(JwtSigner $signer, ?callable $transport = null)
    {
        $this->signer    = $signer;
        $this->transport = $transport ?? new HttpTransport();
    }

    /**
     * @param string|array<string,mixed> $serviceAccountJson the SA key JSON (string or decoded)
     */
    public static function fromServiceAccount(string|array $serviceAccountJson, ?callable $transport = null): self
    {
        return new self(JwtSigner::fromServiceAccount($serviceAccountJson, $transport), $transport);
    }

    public function signer(): JwtSigner
    {
        return $this->signer;
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

    /** Drop a cached token (a 401 on a call means the token died early). */
    public function forgetToken(array $scopes, ?string $subject = null): void
    {
        sort($scopes);
        unset($this->tokenCache[($subject ?? '_self_') . '|' . implode(',', $scopes)]);
    }

    /** @param string[] $scopes  @return array<string,mixed> */
    public function get(string $url, array $scopes, ?string $subject = null): array
    {
        return $this->request('GET', $url, $scopes, $subject, null);
    }

    /**
     * GET returning the RAW response body (no JSON decode) — for media
     * downloads (files/{id}?alt=media, export). Errors still throw like the
     * JSON path: the error body of a failed download IS JSON.
     *
     * @param string[] $scopes
     */
    public function getRaw(string $url, array $scopes, ?string $subject = null): string
    {
        $token = $this->getAccessToken($scopes, $subject);
        $r     = ($this->transport)('GET', $url, ['Authorization: Bearer ' . $token], null);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new TransientError("Google API GET(raw) {$url} HTTP {$r['status']}: " . substr((string) $r['body'], 0, 300), (int) $r['status']);
        }
        return (string) $r['body'];
    }

    /** @param string[] $scopes  @return array<string,mixed> */
    public function post(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        return $this->request('POST', $url, $scopes, $subject, $payload);
    }

    /** @param string[] $scopes  @return array<string,mixed> */
    public function put(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        return $this->request('PUT', $url, $scopes, $subject, $payload);
    }

    /** @param string[] $scopes  @return array<string,mixed> */
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
            if ($e->getCode() === 404) return false;
            throw $e;
        }
    }

    /**
     * Raw multipart upload — for binary payloads (Drive files). Returns decoded JSON.
     *
     * @param string[] $scopes
     * @param array{name: string, parents?: array<string>, mimeType?: string} $metadata
     * @return array<string,mixed>
     */
    public function uploadMultipart(string $url, array $metadata, string $body, string $bodyMime, array $scopes, ?string $subject = null): array
    {
        $token    = $this->getAccessToken($scopes, $subject);
        $boundary = 'b' . bin2hex(random_bytes(16));
        $payload  = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$bodyMime}\r\n\r\n"
            . $body . "\r\n"
            . "--{$boundary}--";

        return $this->send('POST', $url, $payload, [
            'Authorization: Bearer ' . $token,
            "Content-Type: multipart/related; boundary={$boundary}",
        ], $subject);
    }

    /** @param string[] $scopes */
    private function request(string $method, string $url, array $scopes, ?string $subject, ?array $payload): array
    {
        $token   = $this->getAccessToken($scopes, $subject);
        $body    = $payload === null ? null : json_encode($payload);
        $headers = ['Authorization: Bearer ' . $token];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        return $this->send($method, $url, $body, $headers, $subject);
    }

    /** @param string[] $headers  @return array<string,mixed> */
    private function send(string $method, string $url, ?string $body, array $headers, ?string $subject): array
    {
        $r    = ($this->transport)($method, $url, $headers, $body);
        $data = $r['body'] === '' ? [] : (json_decode((string) $r['body'], true) ?? []);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return is_array($data) ? $data : [];
        }
        $sub = $subject ? " sub={$subject}" : '';
        ErrorMapper::fail("Google API {$method} {$url}{$sub}", (int) $r['status'], (string) $r['headers'], (string) $r['body']);
    }
}
