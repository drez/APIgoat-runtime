<?php

namespace ApiGoat\Tests\Apple;

/**
 * Builds a throwaway root → intermediate → leaf EC chain shaped like Apple's
 * (marker OIDs on intermediate and leaf) and signs ES256 JWS with the leaf.
 * Tests pin this root's fingerprint instead of Apple's.
 */
final class AppleTestChain
{
    public \OpenSSLAsymmetricKey $leafKey;
    public array $x5c = [];
    public string $rootSha256;
    private string $cnf;

    public function __construct(bool $leafOid = true, bool $interOid = true)
    {
        $this->cnf = \tempnam(\sys_get_temp_dir(), 'gcapple');
        \file_put_contents($this->cnf, <<<CNF
[ req ]
distinguished_name = dn
[ dn ]
[ root_ext ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[ inter_ext ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
1.2.840.113635.100.6.2.1 = ASN1:NULL
[ inter_plain ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[ leaf_ext ]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature
1.2.840.113635.100.6.11.1 = ASN1:NULL
[ leaf_plain ]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature
CNF);
        $ec = ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $this->cnf];
        $rootKey  = \openssl_pkey_new($ec);
        $interKey = \openssl_pkey_new($ec);
        $this->leafKey = \openssl_pkey_new($ec);

        $root  = $this->sign(['commonName' => 'Test Root'], $rootKey, null, $rootKey, 'root_ext');
        $inter = $this->sign(['commonName' => 'Test WWDR'], $interKey, $root, $rootKey, $interOid ? 'inter_ext' : 'inter_plain');
        $leaf  = $this->sign(['commonName' => 'Test Leaf'], $this->leafKey, $inter, $interKey, $leafOid ? 'leaf_ext' : 'leaf_plain');

        foreach ([$leaf, $inter, $root] as $c) {
            \openssl_x509_export($c, $pem);
            $this->x5c[] = \preg_replace('/-----[^-]+-----|\s/', '', $pem);
        }
        $this->rootSha256 = \hash('sha256', \base64_decode($this->x5c[2]));
        @\unlink($this->cnf);
    }

    private function sign(array $dn, $key, $caCert, $caKey, string $ext): \OpenSSLCertificate
    {
        $opts = ['config' => $this->cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $ext];
        $csr = \openssl_csr_new($dn, $key, $opts);
        return \openssl_csr_sign($csr, $caCert, $caKey, 365, $opts, \random_int(1, \PHP_INT_MAX));
    }

    public function jws(array $payload, ?array $x5c = null): string
    {
        $h = self::b64(\json_encode(['alg' => 'ES256', 'x5c' => $x5c ?? $this->x5c]));
        $p = self::b64(\json_encode($payload));
        \openssl_sign("$h.$p", $der, $this->leafKey, \OPENSSL_ALGO_SHA256);
        return "$h.$p." . self::b64(self::derToRaw($der));
    }

    private static function derToRaw(string $der): string
    {
        // SEQUENCE { INTEGER r, INTEGER s } → r||s, each left-padded to 32 bytes
        $off = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = \ord($der[$off + 1]);
            $int = \ltrim(\substr($der, $off + 2, $len), "\x00");
            $out .= \str_pad($int, 32, "\x00", \STR_PAD_LEFT);
            $off += 2 + $len;
        }
        return $out;
    }

    public static function b64(string $s): string
    {
        return \rtrim(\strtr(\base64_encode($s), '+/', '-_'), '=');
    }
}
