<?php
namespace ApiGoat\Middlewares;

/**
 * Builds the api_log.raw_parameters value with credentials removed.
 *
 * RbacMiddleware logged the raw query string and the raw request body in
 * plain text, so every API login, password reset, OAuth token exchange and
 * bearer-bearing body landed readable in api_log (review 2026-09-23).
 *
 * - query string and form-encoded bodies: parsed, sensitive keys replaced at
 *   any depth, re-encoded;
 * - JSON bodies / the decoded QueryBuilder `query`: same, recursively;
 * - any other body (multipart, XML, binary): not logged, only its size;
 * - credential routes (Authy/auth|register|reset|resetConfirm|google and the
 *   oauth/* routes): the body is never logged at all.
 */
final class ApiLogRedactor
{
    public const MASK = '[REDACTED]';

    /** Exact key names (case-insensitive). */
    private const EXACT = [
        'p', 'pass', 'code', 'code_verifier', 'credential', 'credentials', 'key',
        'api_key', 'apikey', 'authorization', 'otp', 'pin',
    ];

    /** Any key containing one of these (case-insensitive). */
    private const CONTAINS = ['passw', 'secret', 'token', 'csrf', 'credential', 'api_key', 'apikey'];

    public static function isSensitiveKey($key): bool
    {
        if (! is_string($key)) {
            return false;
        }
        $k = strtolower($key);
        if (in_array($k, self::EXACT, true)) {
            return true;
        }
        foreach (self::CONTAINS as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Replace every sensitive key's value, at any depth. */
    public static function redactArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if (self::isSensitiveKey($k)) {
                $data[$k] = self::MASK;
            } elseif (is_array($v)) {
                $data[$k] = self::redactArray($v);
            }
        }
        return $data;
    }

    public static function redactQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $parsed);
        return http_build_query(self::redactArray($parsed));
    }

    /**
     * @param string $body        raw request body
     * @param string $contentType Content-Type header value
     */
    public static function redactBody(string $body, string $contentType = ''): string
    {
        if ($body === '') {
            return '';
        }
        $trim = ltrim($body);
        if (stripos($contentType, 'json') !== false || ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['))) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return (string) json_encode(self::redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if (stripos($contentType, 'x-www-form-urlencoded') !== false
            || ($contentType === '' && preg_match('/^[^\s=&]+=[^\s]*$/', $body))) {
            return self::redactQueryString($body);
        }
        return '[body not logged: ' . strlen($body) . ' bytes]';
    }

    /** Routes whose body is a credential exchange: never logged. */
    public static function isCredentialRoute(string $path, ?string $subDir = null): bool
    {
        if (RoutePath::isOAuthRoute($path, $subDir)) {
            return true;
        }
        $rel = RoutePath::relative($path, $subDir);
        return (bool) preg_match('#^(api/v[0-9]+/)?authy/(auth|register|reset|resetconfirm|google|forgotten)(/|$)#i', $rel);
    }

    /**
     * The api_log.raw_parameters value: redacted query string (or the decoded
     * QueryBuilder `query` when there is none) plus the redacted body.
     *
     * @param mixed $dataQuery $args['data']['query'] from RouteParser
     */
    public static function rawParameters(string $path, string $query, $dataQuery, string $body, string $contentType = ''): string
    {
        $out = self::redactQueryString($query);
        if ($out === '' && $dataQuery !== null) {
            $out = (string) json_encode(is_array($dataQuery) ? self::redactArray($dataQuery) : $dataQuery);
        }
        if ($body !== '') {
            $logged = self::isCredentialRoute($path) ? '[body not logged: credential route]' : self::redactBody($body, $contentType);
            $out = $out !== '' ? $out . '&body=' . rawurlencode($logged) : $logged;
        }
        return $out;
    }
}
