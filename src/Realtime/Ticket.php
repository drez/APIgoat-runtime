<?php

namespace ApiGoat\Realtime;

/**
 * Short-lived signed handshake ticket for the realtime WebSocket.
 *
 * A browser cannot set an Authorization header on a WebSocket handshake, and
 * teaching the sidecar to read PHP session files would drag AuthySession
 * deserialization (and with it the app) into a process that must stay dumb.
 * Instead the FPM app — which already knows who the caller is — mints a ticket
 * signed with the project's JWT_SECRET, and the sidecar verifies the signature
 * with no database and no session access.
 *
 * The ticket conveys IDENTITY ONLY (authy id + tenant). It grants no read
 * access: the sidecar pushes table names, never rows, so identity is used
 * solely to decide which tenant's notifications a socket may receive.
 *
 * Format: base64url(json payload) . '.' . base64url(hmac-sha256)
 */
final class Ticket
{
    /** Seconds a freshly minted ticket stays valid. Deliberately short: it is
     *  used once, immediately, to open a socket. */
    public const TTL = 30;

    /**
     * Mint a ticket for the currently connected user.
     *
     * @return string '' when nobody is connected or no secret is configured.
     */
    public static function mint(?int $authyId = null, ?string $tenant = null): string
    {
        $secret = self::secret();
        if ($secret === '') {
            return '';
        }

        if ($authyId === null) {
            $authyId = self::sessionAuthyId();
        }
        if ($authyId === null || $authyId <= 0) {
            return '';
        }
        if ($tenant === null) {
            $tenant = self::sessionTenant();
        }

        return self::sign(['u' => $authyId, 'tn' => $tenant, 'e' => \time() + self::TTL], $secret);
    }

    /**
     * Verify a ticket.
     *
     * @return array{u:int,tn:string,e:int}|null null when malformed, tampered
     *         with, expired, or when no secret is configured.
     */
    public static function verify(string $ticket): ?array
    {
        $secret = self::secret();
        if ($secret === '' || $ticket === '') {
            return null;
        }

        $parts = \explode('.', $ticket);
        if (\count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        $expected = self::b64e(\hash_hmac('sha256', $body, $secret, true));
        // Constant-time: a timing oracle on the signature is a forgery oracle.
        if (!\hash_equals($expected, $sig)) {
            return null;
        }

        $raw = self::b64d($body);
        if ($raw === false) {
            return null;
        }
        $payload = \json_decode($raw, true);
        if (!\is_array($payload) || !isset($payload['u'], $payload['e'])) {
            return null;
        }
        if ((int) $payload['e'] < \time()) {
            return null;
        }

        return [
            'u'  => (int) $payload['u'],
            'tn' => (string) ($payload['tn'] ?? 'all'),
            'e'  => (int) $payload['e'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function sign(array $payload, string $secret): string
    {
        $body = self::b64e((string) \json_encode($payload));
        return $body . '.' . self::b64e(\hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * The signing key. Read from the environment rather than the Configuration
     * service so the sidecar — which never boots the app container — can use
     * the exact same code path.
     */
    public static function secret(): string
    {
        $v = \function_exists('env') ? env('JWT_SECRET') : \getenv('JWT_SECRET');
        return \is_string($v) ? $v : '';
    }

    private static function sessionAuthyId(): ?int
    {
        if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
            return null;
        }
        $s = $_SESSION[\_AUTH_VAR];
        if (!\method_exists($s, 'get') || $s->get('connected') !== 'YES') {
            return null;
        }
        return (int) $s->get('id') ?: null;
    }

    /**
     * Tenant token, mirroring TableVersion::tenantToken() VERBATIM so a
     * subscriber's token compares equal to the one a writer stamps on a signal.
     */
    private static function sessionTenant(): string
    {
        if (\class_exists(\ApiGoat\Utility\TableVersion::class)) {
            return \ApiGoat\Utility\TableVersion::tenantToken();
        }
        return 'all';
    }

    private static function b64e(string $bin): string
    {
        return \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return string|false */
    private static function b64d(string $s)
    {
        return \base64_decode(\strtr($s, '-_', '+/'), true);
    }
}
