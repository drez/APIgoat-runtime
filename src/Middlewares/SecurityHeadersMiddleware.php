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
        $response = $handler->handle($request);

        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
