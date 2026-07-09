<?php

namespace ApiGoat\Pdf;

/**
 * HTML → PDF bytes via dompdf. Generalized from apicrm's QuotePdf: DejaVu Sans
 * (bundled with dompdf) so accented French renders correctly; letter portrait.
 *
 * dompdf is a PROJECT composer dependency (apicrm ships ^2, apigoatacc ^3 —
 * the API used here is stable across both). The runtime soft-requires it so
 * projects without with_pdf don't pay the dependency.
 *
 * SSRF hardening: the rendered HTML is template-driven and can carry
 * user-supplied fields (quote/invoice notes, product answers, addresses). With
 * dompdf's remote fetching enabled, an injected <img src> / CSS url() would let
 * dompdf issue server-side requests. We therefore (a) never allow file:// (no
 * local-file disclosure via <img src="file:///etc/passwd">), and (b) gate every
 * http(s) fetch on a rule that rejects non-public hosts (loopback, private,
 * link-local incl. the cloud metadata endpoint, and other reserved ranges).
 * Public remote images still load; data: URIs (the logo path) are unaffected.
 */
final class HtmlToPdf
{
    public function render(string $html): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException(
                'with_pdf requires dompdf: add "dompdf/dompdf": "^3.1" (or ^2) to the project composer.json and run composer update.'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        // file:// dropped; http/https gated by the SSRF guard rule below.
        $options->setAllowedProtocols([
            'http://'  => ['rules' => [[self::class, 'assertPublicUrl']]],
            'https://' => ['rules' => [[self::class, 'assertPublicUrl']]],
        ]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * dompdf allowed-protocol rule: [$ok, $message]. Rejects any URL whose host
     * resolves to a non-public address, blocking SSRF to internal services and
     * the cloud metadata endpoint. DNS is resolved so a public hostname pointing
     * at a private IP (DNS-rebinding style) is caught too.
     *
     * @return array{0: bool, 1: string}
     */
    public static function assertPublicUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return [false, 'Blocked remote resource: unparseable host'];
        }
        $host = trim($host, '[]'); // strip IPv6 brackets

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                return [false, "Blocked remote resource: cannot resolve {$host}"];
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [false, "Blocked remote resource: non-public address for {$host}"];
            }
        }
        return [true, ''];
    }

    /**
     * True only for globally-routable addresses. Rejects private, loopback,
     * link-local (incl. 169.254.169.254 metadata) and other reserved ranges via
     * the filter flags; also rejects IPv4-mapped/compat IPv6 that would smuggle
     * a private v4 address, which the flags alone don't always catch.
     */
    private static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
            return false;
        }
        // Unwrap ::ffff:10.0.0.1 / ::10.0.0.1 and re-check the embedded v4.
        if (($packed = @inet_pton($ip)) !== false && strlen($packed) === 16) {
            $v4 = null;
            if (substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                $v4 = inet_ntop(substr($packed, 12));
            } elseif (substr($packed, 0, 12) === str_repeat("\0", 12) && $packed !== str_repeat("\0", 15) . "\1") {
                $v4 = inet_ntop("\0\0\0\0\0\0\0\0\0\0\xff\xff" . substr($packed, 12));
            }
            if ($v4 !== false && $v4 !== null && strpos($v4, '.') !== false) {
                return (bool) filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE);
            }
        }
        return true;
    }
}
