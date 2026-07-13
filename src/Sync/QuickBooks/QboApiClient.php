<?php

namespace ApiGoat\Sync\QuickBooks;

use ApiGoat\Sync\Exceptions;

/**
 * QuickBooks Online OAuth2 + v3 REST client. Raw curl (GoogleClientFactory
 * pattern) with an injectable $transport for tests. Error taxonomy:
 * 401/403 → AuthFailed, 429 → RateLimited, 5xx/curl → TransientError,
 * other 4xx → ValidationRejected carrying QuickBooks' Fault detail.
 */
final class QboApiClient
{
    public const AUTH_URL      = 'https://appcenter.intuit.com/connect/oauth2';
    public const TOKEN_URL     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    public const MINOR_VERSION = '75';

    /** @var ?callable(string,string,array,?string):array{status:int,body:string} */
    public $transport = null;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $env = 'sandbox'
    ) {
    }

    public static function fromEnv(): self
    {
        $id  = (string) ($_ENV['QB_CLIENT_ID'] ?? getenv('QB_CLIENT_ID') ?: '');
        $sec = (string) ($_ENV['QB_CLIENT_SECRET'] ?? getenv('QB_CLIENT_SECRET') ?: '');
        $env = (string) ($_ENV['QB_ENV'] ?? getenv('QB_ENV') ?: 'sandbox');
        if ($id === '' || $sec === '') {
            throw new Exceptions\AuthFailed('QB_CLIENT_ID / QB_CLIENT_SECRET missing from .admin/.env');
        }
        return new self($id, $sec, $env);
    }

    public function apiBase(): string
    {
        return $this->env === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->tokenCall(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenCall(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function tokenCall(array $form): array
    {
        $r = $this->send('POST', self::TOKEN_URL, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query($form));
        if ($r['status'] >= 400) {
            throw new Exceptions\AuthFailed('QuickBooks token endpoint HTTP ' . $r['status'] . ': ' . mb_substr($r['body'], 0, 300));
        }
        return json_decode($r['body'], true) ?: [];
    }

    /** $path is relative to /v3/company/{realm}/ (e.g. 'invoice', 'query?query=...'). */
    public function api(string $realmId, string $method, string $path, ?array $payload, string $accessToken): array
    {
        $sep = str_contains($path, '?') ? '&' : '?';
        $url = $this->apiBase() . '/v3/company/' . rawurlencode($realmId) . '/' . $path . $sep . 'minorversion=' . self::MINOR_VERSION;
        $r   = $this->send($method, $url, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $payload === null ? null : (string) json_encode($payload));
        return $this->decode($r);
    }

    public function query(string $realmId, string $q, string $accessToken): array
    {
        $res = $this->api($realmId, 'GET', 'query?query=' . rawurlencode($q), null, $accessToken);
        return $res['QueryResponse'] ?? [];
    }

    private function decode(array $r): array
    {
        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new Exceptions\AuthFailed('QuickBooks HTTP ' . $r['status']);
        }
        if ($r['status'] === 429) {
            throw new Exceptions\RateLimited('QuickBooks rate limit (429)');
        }
        if ($r['status'] >= 500) {
            throw new Exceptions\TransientError('QuickBooks HTTP ' . $r['status']);
        }
        $json = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 400) {
            $detail = $json['Fault']['Error'][0]['Detail'] ?? $json['Fault']['Error'][0]['Message'] ?? $r['body'];
            throw new Exceptions\ValidationRejected('QuickBooks rejected: ' . mb_substr((string) $detail, 0, 500));
        }
        return $json;
    }

    /** @return array{status:int, body:string} */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exceptions\TransientError('curl: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $out];
    }
}
