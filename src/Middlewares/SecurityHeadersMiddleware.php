<?php

/*
 * Appends baseline security headers to every response.
 *
 * Deliberately conservative: no Content-Security-Policy is set here because
 * generated pages rely on inline scripts (readyJs blocks); a CSP rollout
 * needs a report-only trial per project first. Headers already present on
 * the response are left untouched so a project can override any of them.
 */

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const HEADERS = [
        'X-Frame-Options'        => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // #19: mint a per-request CSP nonce BEFORE rendering so the emitted
        // inline <script> tags (html_helper script()/scriptReady()/loadjs/
        // message via gcCspNonce()) can carry it. Fresh per request — overwrites
        // any value left in a persistent FPM worker; null when the trial is off
        // so emitted scripts stay byte-identical.
        $nonce = $this->cspEnabled()
            ? rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')
            : null;
        $GLOBALS['__gc_csp_nonce'] = $nonce;

        $response = $handler->handle($request);

        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        // Report-only: observe violations without breaking pages. eval is gone
        // (#22); the gcScreens re-exec'd scripts ride on 'strict-dynamic' (they
        // are created by the nonced index.js). Un-nonced inline scripts, inline
        // event handlers and javascript: URLs are REPORTED, not blocked, so they
        // can be cleaned up before any enforcing policy. Opt-in: GC_CSP_REPORT.
        if ($nonce !== null && !$response->hasHeader('Content-Security-Policy-Report-Only')) {
            $response = $response->withHeader('Content-Security-Policy-Report-Only', $this->policy($nonce));
        }

        return $response;
    }

    private function cspEnabled(): bool
    {
        return function_exists('env')
            && filter_var(env('GC_CSP_REPORT'), FILTER_VALIDATE_BOOLEAN);
    }

    private function policy(string $nonce): string
    {
        $report = function_exists('env') ? (string) (env('GC_CSP_REPORT_URI') ?: '') : '';
        $parts = [
            "default-src 'self'",
            // 'strict-dynamic' trusts scripts created by the nonced scripts (the
            // re-exec arm) and makes conformant browsers ignore the host list;
            // 'self' https: is the legacy fallback for browsers without it.
            "script-src 'nonce-{$nonce}' 'strict-dynamic' 'self' https:",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ];
        if ($report !== '') {
            $parts[] = 'report-uri ' . $report;
        }
        return implode('; ', $parts);
    }
}
