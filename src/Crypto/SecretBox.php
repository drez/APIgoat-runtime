<?php

namespace ApiGoat\Crypto;

/**
 * Authenticated secrets at rest — libsodium secretbox, not en_de().
 *
 * Wire format:  v1:<base64( nonce[24] || secretbox(plain) )>
 *
 * The per-tenant key is derived from the master with
 * sodium_crypto_kdf_derive_from_key(context 'gmsecret', subkey id = tenant
 * id), so a ciphertext copied from one tenant's row to another's fails the
 * MAC rather than decrypting. The `v<N>:` prefix names the master key
 * generation (env APP_SECRET_KEY_V<N>) so a future rotation can open old
 * rows while sealing with the new key; today only v1 exists.
 *
 * Honest limit: root has the DB and the env, so one master key cannot hide
 * secrets from the operator. What this removes is every casual path — a
 * dump, a log line, a copied row, a mis-scoped query.
 */
final class SecretBox
{
    public const VERSION = 1;
    public const CONTEXT = 'gmsecret';
    public const ENV_PREFIX = 'APP_SECRET_KEY_V';

    /** @var array<int,string> version -> master key, test seam */
    private static array $keyOverride = [];

    /** Inject a master key for tests (null clears). Never call from app code. */
    public static function setMasterKey(?string $key, int $version = self::VERSION): void
    {
        if ($key === null) {
            unset(self::$keyOverride[$version]);
        } else {
            self::$keyOverride[$version] = $key;
        }
    }

    public static function keyVersion(): int
    {
        return self::VERSION;
    }

    /** sodium is loaded and the current master key is present and well-formed. */
    public static function available(): bool
    {
        if (!\function_exists('sodium_crypto_secretbox')) {
            return false;
        }
        try {
            $k = self::masterKey(self::VERSION);
            \sodium_memzero($k);

            return true;
        } catch (CryptoException $e) {
            return false;
        }
    }

    public static function seal(string $plain, int $idTenant): string
    {
        $sub = self::subkey(self::VERSION, $idTenant);
        try {
            $nonce = \random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = \sodium_crypto_secretbox($plain, $nonce, $sub);
        } finally {
            \sodium_memzero($sub);
        }

        return 'v' . self::VERSION . ':' . \base64_encode($nonce . $box);
    }

    /**
     * @throws CryptoException on ANY failure — never returns partial data
     */
    public static function open(string $cipher, int $idTenant): string
    {
        if (!\preg_match('/^v([0-9]{1,3}):([A-Za-z0-9+\/=]+)$/', $cipher, $m)) {
            throw new CryptoException('SecretBox: malformed ciphertext');
        }
        $version = (int) $m[1];
        if ($version !== self::VERSION) {
            throw new CryptoException('SecretBox: unsupported key version v' . $version);
        }
        $bin = \base64_decode($m[2], true);
        if (!\is_string($bin) || \strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new CryptoException('SecretBox: malformed ciphertext');
        }
        $nonce = \substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = \substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $sub = self::subkey($version, $idTenant);
        try {
            $plain = \sodium_crypto_secretbox_open($box, $nonce, $sub);
        } finally {
            \sodium_memzero($sub);
        }
        if ($plain === false) {
            throw new CryptoException('SecretBox: authentication failed');
        }

        return $plain;
    }

    /** First 12 hex of sha256 — enough to show "which secret" without showing it. */
    public static function fingerprint(string $plain): string
    {
        return \substr(\hash('sha256', $plain), 0, 12);
    }

    private static function subkey(int $version, int $idTenant): string
    {
        if ($idTenant < 0) {
            throw new CryptoException('SecretBox: tenant id must be >= 0');
        }
        $master = self::masterKey($version);
        try {
            return \sodium_crypto_kdf_derive_from_key(
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $idTenant,
                self::CONTEXT,
                $master
            );
        } finally {
            \sodium_memzero($master);
        }
    }

    /**
     * The 32-byte master key for $version from APP_SECRET_KEY_V<n>:
     * base64 of 32 bytes, or 64 hex chars.
     */
    private static function masterKey(int $version): string
    {
        $raw = self::$keyOverride[$version] ?? self::env(self::ENV_PREFIX . $version);
        if ($raw === '') {
            throw new CryptoException('SecretBox: ' . self::ENV_PREFIX . $version . ' is not set');
        }
        $raw = \trim($raw);
        $key = null;
        if (\preg_match('/^[0-9a-fA-F]{64}$/', $raw)) {
            $key = \hex2bin($raw);
        } else {
            $b = \base64_decode($raw, true);
            if (\is_string($b)) {
                $key = $b;
            }
        }
        if (!\is_string($key) || \strlen($key) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new CryptoException('SecretBox: ' . self::ENV_PREFIX . $version . ' must be base64 of 32 bytes or 64 hex chars');
        }

        return $key;
    }

    private static function env(string $name): string
    {
        if (\function_exists('env')) {
            $v = env($name);
            if (\is_string($v) && $v !== '') {
                return $v;
            }
        }
        if (isset($_ENV[$name]) && \is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        $v = \getenv($name);

        return \is_string($v) ? $v : '';
    }
}
