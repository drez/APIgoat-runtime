<?php

/*
 * Whole-response cache for ANONYMOUS public API GETs.
 *
 * Why: the public read routes (feeds, browse lists, category trees) are the
 * same bytes for every logged-out visitor, yet each request walks the full
 * stack — RBAC ruleset match, OAuth/JWT probes, session boot, routing, Propel
 * hydration, JSON encoding. This middleware serves those bytes straight out of
 * MicroCache (APCu) for the routes the BUILD declared cacheable
 * (config/Built/cache.map.php, see ApiGoat\Utility\PublicCacheMap), and does
 * nothing at all for everything else.
 *
 * Placement (config/middlewares.php, Slim add() is LIFO — the last add()
 * executes first on the way in):
 *
 *     $app->add(RbacMiddleware::class);                  // public pass
 *     $app->add(PublicResponseCacheMiddleware::class);  // <- here
 *     $app->add(RouteParser::class);
 *
 * i.e. it EXECUTES after ApiGoat\Middlewares\RouteParser — it needs the
 * `parsed_args` request attribute (method / model / action / id / route /
 * is_api / query) — and before the public-pass RBAC, OAuth, JWT, Authy,
 * routing and SessionRelease, so a HIT skips every one of them.
 *
 * Outcomes, reported in `X-GC-Cache` so a curl can tell them apart:
 *   (no header) OFF     — GC_HTTPCACHE_TTL unset/0, not a GET, not an API
 *                         route, or the route is not declared. The response
 *                         is returned exactly as the handler produced it.
 *   BYPASS              — declared, but this particular request must not be
 *                         served shared bytes: it carries credentials
 *                         (Authorization / X-Authorization header, or a
 *                         connected GUI session), hits a bypass_param, uses a
 *                         query key outside the allowlist, the key is too big,
 *                         a handler opted out, or the response was not
 *                         storable (non-200, non-JSON, Set-Cookie, too large).
 *   MISS                — handler ran, response stored.
 *   HIT                 — served from cache, handler never ran.
 *   VERIFY              — GC_HTTPCACHE_VERIFY=1: a HIT existed but the handler
 *                         ran anyway and its bytes were served; a mismatch is
 *                         error_log()ged as `[gc-httpcache] divergence`.
 *
 * Identity is decided from the raw request, never from anything a
 * downstream middleware would have set — that is the whole point of running
 * before them. The anonymity gate is deliberately coarse: ANY credential
 * header bypasses, valid or not, because a stale/invalid bearer must still
 * get the handler's own 401 rather than a cached 200.
 *
 * Never throws on the cache path: any Throwable in the decision, the hit
 * rebuild or the store falls through to the plain handler call. The
 * handler's OWN exceptions (HaltResponse, ...) propagate untouched — they
 * are never caught here, and the handler is never run twice.
 */

namespace ApiGoat\Middlewares;

use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\PublicCacheMap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

final class PublicResponseCacheMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-GC-Cache';

    /** Seconds a stale entry may still be served after its TTL while one request refreshes it. */
    public const STALE_GRACE_SECONDS = 30;
    /** Seconds the refresh lock lives (bounded so a crashed refresher never wedges the key). */
    public const REFRESH_LOCK_SECONDS = 10;
    /** Request attribute a handler can set (or a project middleware before us) to force BYPASS. */
    public const ATTR_SKIP = 'gc_httpcache_skip';

    /** Request attribute set on a MISS: ['key' => string, 'ttl' => int]. */
    public const ATTR_INFO = 'gc_httpcache';

    public function __construct()
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $plan = $this->decide($request);
        } catch (\Throwable $e) {
            $plan = null;
        }

        // OFF: not our concern, hand the request on untouched (with VERIFY on,
        // say why — the route is not declared, or it is not an API path).
        if ($plan === null) {
            return $handler->handle($request);
        }
        if ($plan['mode'] === 'off') {
            $response = $handler->handle($request);
            if (PublicCacheMap::verify() && !empty($plan['reason'])) {
                $response = $response->withHeader(self::HEADER . '-Reason', 'off:' . (string) $plan['reason']);
            }
            return $response;
        }

        if ($plan['mode'] === 'bypass') {
            $response = $handler->handle($request)->withHeader(self::HEADER, 'BYPASS');
            // Diagnostics ride on the VERIFY switch: say WHY a declared route
            // was not served from cache (auth header, connected session, a
            // bypass param, an unlisted param, oversized query).
            if (PublicCacheMap::verify() && !empty($plan['reason'])) {
                $response = $response->withHeader(self::HEADER . '-Reason', (string) $plan['reason']);
            }
            return $response;
        }

        $key = $plan['key'];
        $ttl = $plan['ttl'];

        if ($plan['mode'] === 'hit' || $plan['mode'] === 'stale') {
            try {
                return $this->serveHit($plan['entry'], $ttl, $plan['mode'] === 'stale' ? 'STALE' : 'HIT');
            } catch (\Throwable $e) {
                return $handler->handle($request);
            }
        }

        if ($plan['mode'] === 'verify') {
            $response = $handler->handle($request);
            try {
                $fresh = $this->readBody($response);
                if ($fresh[0] !== (string) ($plan['entry']['body'] ?? '')) {
                    \error_log('[gc-httpcache] divergence key=' . $key . ' route=' . $plan['route']);
                }
                return $fresh[1]->withHeader(self::HEADER, 'VERIFY');
            } catch (\Throwable $e) {
                return $response;
            }
        }

        // MISS
        $response = $handler->handle($request->withAttribute(self::ATTR_INFO, ['key' => $key, 'ttl' => $ttl]));
        try {
            return $this->store($response, $key, $ttl);
        } catch (\Throwable $e) {
            return $response->withHeader(self::HEADER, 'BYPASS');
        }
    }

    /**
     * Classify the request. null => OFF (untouched); otherwise a plan with
     * mode bypass | hit | verify | miss.
     *
     * @return array{mode:string,key?:string,ttl?:int,route?:string,entry?:array}|null
     */
    private function decide(ServerRequestInterface $request): ?array
    {
        $ttlDefault = PublicCacheMap::ttl();
        if ($ttlDefault <= 0) {
            return null;
        }
        if (\strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }
        $args = $request->getAttribute('parsed_args');
        if (!\is_array($args) || empty($args['is_api'])) {
            return ['mode' => 'off', 'reason' => 'not-api'];
        }
        $model  = (string) ($args['model'] ?? '');
        $action = (string) ($args['action'] ?? '');
        $decl   = PublicCacheMap::lookup($model, $action, 'GET');
        if ($decl === null) {
            return ['mode' => 'off', 'reason' => 'undeclared:' . $model . '/' . $action . '/GET'];
        }

        // --- anonymity gate: anything that could personalise the bytes => BYPASS
        // Non-EMPTY only: Apache's `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
        // (project .htaccess) leaves an empty Authorization header on every
        // request, so presence alone would bypass everything.
        if ($request->getHeaderLine('Authorization') !== '' || $request->getHeaderLine('X-Authorization') !== '') {
            return ['mode' => 'bypass', 'reason' => 'auth-header'];
        }
        if ($this->guiSessionConnected()) {
            return ['mode' => 'bypass', 'reason' => 'session-connected'];
        }
        if ($request->getAttribute(self::ATTR_SKIP)) {
            return ['mode' => 'bypass', 'reason' => 'skip-attribute'];
        }

        $query = \is_array($args['query'] ?? null) ? $args['query'] : $request->getQueryParams();
        foreach ($decl['bypass_params'] as $p) {
            if (\array_key_exists($p, $query)) {
                return ['mode' => 'bypass', 'reason' => 'bypass-param:' . $p];
            }
        }
        if (\is_array($decl['params'])) {
            foreach (\array_keys($query) as $k) {
                if (!\in_array((string) $k, $decl['params'], true)) {
                    return ['mode' => 'bypass', 'reason' => 'param-not-allowed:' . $k];
                }
            }
        }

        $vary = [];
        foreach ($decl['vary'] as $h) {
            $vary[$h] = $request->getHeaderLine($h);
        }
        $route = (string) ($args['route'] ?? $request->getUri()->getPath());
        $build = PublicCacheMap::load()['_build'];
        $key   = PublicCacheMap::key($decl, 'GET', $route, $query, $vary, $build);
        if ($key === null) {
            return ['mode' => 'bypass', 'reason' => 'query-too-large'];
        }

        $ttl   = (int) $decl['ttl'];
        $entry = MicroCache::get($key);
        $isHit = \is_array($entry) && isset($entry['body'], $entry['ctype']) && \is_string($entry['body']);

        if ($isHit && PublicCacheMap::verify()) {
            return ['mode' => 'verify', 'key' => $key, 'ttl' => $ttl, 'route' => $route, 'entry' => $entry];
        }
        if ($isHit) {
            $freshUntil = (int) ($entry['fresh_until'] ?? 0);
            if ($freshUntil === 0 || \time() < $freshUntil) {
                return ['mode' => 'hit', 'key' => $key, 'ttl' => $ttl, 'route' => $route, 'entry' => $entry];
            }
            // Stale-while-revalidate: the entry outlived its TTL but is still
            // in the grace window. ONE request takes the refresh lock and
            // recomputes; everyone else keeps being served the stale bytes.
            // Without this, every client that arrives in the same second as
            // the expiry misses together and the handler runs N times at once
            // (a 40-client load test turned a 60 s TTL into 17 s stalls).
            // add() is atomic (apcu_add): a get()-then-put() here let every
            // request that read "no lock" in the same instant refresh at once.
            if (MicroCache::add($key . ':lock', self::REFRESH_LOCK_SECONDS, 1)) {
                return ['mode' => 'miss', 'key' => $key, 'ttl' => $ttl, 'route' => $route];
            }
            return ['mode' => 'stale', 'key' => $key, 'ttl' => $ttl, 'route' => $route, 'entry' => $entry];
        }
        return ['mode' => 'miss', 'key' => $key, 'ttl' => $ttl, 'route' => $route];
    }

    /**
     * Same read RbacMiddleware::denialStatus() does: the Authy session object
     * lives in $_SESSION[_AUTH_VAR] and says 'YES' once logged in. With
     * GC_SESSION_DEFER_ANON_API the session is not even started for anonymous
     * API GETs, so $_SESSION is simply unset here and the read is false.
     */
    private function guiSessionConnected(): bool
    {
        if (!\defined('_AUTH_VAR')) {
            return false;
        }
        $var = (string) \constant('_AUTH_VAR');
        return isset($_SESSION[$var]) && \is_object($_SESSION[$var])
            && \method_exists($_SESSION[$var], 'get')
            && $_SESSION[$var]->get('connected') === 'YES';
    }

    /** @param array{status?:int,ctype:string,body:string} $entry */
    private function serveHit(array $entry, int $ttl, string $label = 'HIT'): ResponseInterface
    {
        $t0 = \hrtime(true);

        $response = (new ResponseFactory())->createResponse((int) ($entry['status'] ?? 200))
            ->withBody((new StreamFactory())->createStream($entry['body']))
            ->withHeader('Content-Type', (string) $entry['ctype']);
        $response = $this->stampPublic($response, $ttl)->withHeader(self::HEADER, $label);

        if (\class_exists(\ApiGoat\Utility\Timing::class)) {
            \ApiGoat\Utility\Timing::add('cache', (\hrtime(true) - $t0) / 1e6);
        }
        return $response;
    }

    /**
     * Store the handler's response when — and only when — it is a plain
     * 200 JSON document with no cookie and within the size cap, and the
     * handler did not opt out by stamping BYPASS itself.
     */
    private function store(ResponseInterface $response, string $key, int $ttl): ResponseInterface
    {
        [$body, $response] = $this->readBody($response);
        $ctype = $response->getHeaderLine('Content-Type');

        $storable = $response->getStatusCode() === 200
            && \stripos($ctype, 'application/json') !== false
            && !$response->hasHeader('Set-Cookie')
            && \strlen($body) <= PublicCacheMap::maxBytes()
            && \strtoupper($response->getHeaderLine(self::HEADER)) !== 'BYPASS';

        if (!$storable) {
            return $response->withHeader(self::HEADER, 'BYPASS');
        }

        // Kept for ttl + grace so a stale copy exists to serve while one
        // request refreshes (see decide()); fresh_until marks the real TTL.
        $grace = \min($ttl, self::STALE_GRACE_SECONDS);
        MicroCache::put($key, $ttl + $grace, [
            'status'      => 200,
            'ctype'       => $ctype,
            'body'        => $body,
            'fresh_until' => \time() + $ttl,
        ]);
        MicroCache::forget($key . ':lock');

        return $this->stampPublic($response, $ttl)->withHeader(self::HEADER, 'MISS');
    }

    /**
     * Generated routes stamp `no-store` + Pragma via ApiResponse::getResponse();
     * for a shared-cacheable response that is exactly wrong, so the
     * freshness headers are REPLACED, not merged. Vary keeps any intermediary
     * cache from serving the anonymous bytes to a credentialed request.
     */
    private function stampPublic(ResponseInterface $response, int $ttl): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'public, max-age=' . $ttl)
            ->withoutHeader('Pragma')
            ->withoutHeader('Expires')
            ->withHeader('Vary', 'Authorization, Cookie');
    }

    /**
     * Read the whole body and hand back a response whose stream can still be
     * emitted: a rewindable stream is rewound in place, anything else is
     * replaced by a fresh stream holding the same bytes.
     *
     * @return array{0:string,1:ResponseInterface}
     */
    private function readBody(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $body   = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            $response = $response->withBody((new StreamFactory())->createStream($body));
        }
        return [$body, $response];
    }
}
