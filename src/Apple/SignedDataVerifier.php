<?php

namespace ApiGoat\Apple;

/**
 * Verifies App Store JWS payloads: StoreKit 2 signed transactions and
 * renewal infos from the device, and App Store Server Notifications v2.
 *
 * Every one of them is an ES256 JWS whose `x5c` header carries the chain
 * leaf → Apple intermediate → Apple Root CA - G3. We trust nothing in the
 * payload until:
 *   1. the root in the chain is byte-identical to a pinned root (by SHA-256
 *      of its DER — the x5c root is attacker-supplied, so "it verifies
 *      itself" means nothing),
 *   2. each certificate is signed by the next one and valid at the signing
 *      time,
 *   3. the leaf and intermediate carry Apple's marker OIDs (without these a
 *      certificate Apple issued for anything else would pass),
 *   4. the JWS signature verifies with the leaf key.
 * This is the check Apple's own App Store Server Library performs (minus
 * OCSP, which it also makes optional).
 */
final class SignedDataVerifier
{
    /** SHA-256 of the DER of Apple Root CA - G3 (apple.com/certificateauthority, valid to 2039). */
    public const APPLE_ROOT_CA_G3_SHA256 = '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179';

    /** Leaf: Mac App Store / App Store receipt signing. */
    public const OID_LEAF = '1.2.840.113635.100.6.11.1';
    /** Intermediate: Apple Worldwide Developer Relations CA. */
    public const OID_INTERMEDIATE = '1.2.840.113635.100.6.2.1';

    /** @param string[] $trustedRootSha256 lowercase hex; tests pin their own root */
    public function __construct(private array $trustedRootSha256 = [self::APPLE_ROOT_CA_G3_SHA256])
    {
    }

    /**
     * Returns the decoded payload, or throws \RuntimeException. $effectiveTime
     * is the moment the chain must have been valid (defaults to the payload's
     * signedDate, falling back to now — the same rule Apple's library uses so
     * an old transaction stays verifiable after a leaf certificate rotates).
     */
    public function verify(string $jws, ?int $effectiveTime = null): array
    {
        $parts = \explode('.', $jws);
        if (\count($parts) !== 3) {
            throw new \RuntimeException('Malformed JWS');
        }
        [$h64, $p64, $s64] = $parts;
        $header  = \json_decode(self::b64url($h64), true);
        $payload = \json_decode(self::b64url($p64), true);
        if (!\is_array($header) || !\is_array($payload)) {
            throw new \RuntimeException('Malformed JWS');
        }
        if (($header['alg'] ?? '') !== 'ES256') {
            throw new \RuntimeException('Unexpected JWS algorithm');
        }
        $x5c = $header['x5c'] ?? null;
        if (!\is_array($x5c) || \count($x5c) !== 3) {
            throw new \RuntimeException('Expected a 3-certificate x5c chain');
        }

        $der = [];
        foreach ($x5c as $b64) {
            $bin = \base64_decode((string) $b64, true);
            if ($bin === false || $bin === '') {
                throw new \RuntimeException('Bad certificate encoding');
            }
            $der[] = $bin;
        }
        [$leafDer, $interDer, $rootDer] = $der;

        if (!\in_array(\hash('sha256', $rootDer), $this->trustedRootSha256, true)) {
            throw new \RuntimeException('Untrusted root certificate');
        }

        $leaf  = self::x509($leafDer);
        $inter = self::x509($interDer);
        $root  = self::x509($rootDer);

        if (\openssl_x509_verify($leaf, \openssl_pkey_get_public($inter)) !== 1
            || \openssl_x509_verify($inter, \openssl_pkey_get_public($root)) !== 1) {
            throw new \RuntimeException('Certificate chain does not verify');
        }

        $at = $effectiveTime
            ?? (isset($payload['signedDate']) ? \intdiv((int) $payload['signedDate'], 1000) : \time());
        foreach ([$leaf, $inter, $root] as $cert) {
            $info = \openssl_x509_parse($cert);
            if (!\is_array($info) || $at < (int) $info['validFrom_time_t'] || $at > (int) $info['validTo_time_t']) {
                throw new \RuntimeException('Certificate not valid at signing time');
            }
        }
        if (!self::hasExtension($leaf, self::OID_LEAF) || !self::hasExtension($inter, self::OID_INTERMEDIATE)) {
            throw new \RuntimeException('Certificate is not an App Store signing certificate');
        }

        $sig = self::b64url($s64);
        if (\strlen($sig) !== 64) {
            throw new \RuntimeException('Bad ES256 signature length');
        }
        $ok = \openssl_verify($h64 . '.' . $p64, self::rawEcdsaToDer($sig), \openssl_pkey_get_public($leaf), \OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \RuntimeException('JWS signature does not verify');
        }
        return $payload;
    }

    /**
     * Payload WITHOUT verification — only for routing a request to the
     * verifier (e.g. reading notificationType for logging). Never trust it.
     */
    public static function peek(string $jws): ?array
    {
        $parts = \explode('.', $jws);
        if (\count($parts) !== 3) {
            return null;
        }
        $p = \json_decode(self::b64url($parts[1]), true);
        return \is_array($p) ? $p : null;
    }

    private static function x509(string $der): \OpenSSLCertificate
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . \chunk_split(\base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $cert = \openssl_x509_read($pem);
        if ($cert === false) {
            throw new \RuntimeException('Unreadable certificate');
        }
        return $cert;
    }

    private static function hasExtension(\OpenSSLCertificate $cert, string $oid): bool
    {
        $info = \openssl_x509_parse($cert, false);
        return \is_array($info) && \array_key_exists($oid, $info['extensions'] ?? []);
    }

    /** JWS ES256 signatures are raw r||s (32+32 bytes); OpenSSL wants ASN.1 DER. */
    private static function rawEcdsaToDer(string $raw): string
    {
        $int = static function (string $b): string {
            $b = \ltrim($b, "\x00");
            if ($b === '' || \ord($b[0]) & 0x80) {
                $b = "\x00" . $b;
            }
            return "\x02" . \chr(\strlen($b)) . $b;
        };
        $seq = $int(\substr($raw, 0, 32)) . $int(\substr($raw, 32, 32));
        return "\x30" . \chr(\strlen($seq)) . $seq;
    }

    private static function b64url(string $s): string
    {
        $out = \base64_decode(\strtr($s, '-_', '+/') . \str_repeat('=', (4 - \strlen($s) % 4) % 4), true);
        return $out === false ? '' : $out;
    }
}
