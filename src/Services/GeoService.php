<?php

namespace ApiGoat\Services;

/**
 * Nominatim geocode proxy for the `location` input type (set_input_options:
 * {type: "location"}) — promoted from apigoatacc's App\Domains\Geo\OsmClient.
 *
 * Routes (per-project wiring registers them; see template config/routes.php):
 *   GET /ApiGoat/geocode?q=<text>&country=<cc[,cc]>&limit=<n>
 *       → Nominatim /search?format=json&addressdetails=1 passthrough JSON array
 *         [{lat, lon, display_name, address:{house_number, road, city, town,
 *         postcode, country}}]. `limit` clamped to 10, default 8. `country`
 *         (optional) = ISO country code(s), comma-separated → countrycodes.
 *   GET /ApiGoat/reverseGeocode?lat=<f>&lng=<f>
 *       → Nominatim /reverse?format=json&addressdetails=1 passthrough object;
 *         `{}` when nothing is found.
 *   Same handlers are also reachable at /api/v1/ApiGoat/... for bearer clients
 *   (mobile). Session cookie OR OAuth bearer (OAuthResourceMiddleware hydrates
 *   the session) — NEVER public: AuthyMiddleware exempts the route from the
 *   model-RBAC matrix (ApiGoat is a URL namespace, not an RBAC model) but still
 *   requires an authenticated session, and this service re-checks it.
 *
 * Fair-use guards toward the public Nominatim instance:
 *   - descriptive User-Agent
 *   - >= ~1s spacing between upstream calls (cross-process, flock-backed)
 *   - 24h result cache keyed on the normalized query/coords
 *     (APCu when available, file fallback under tmp/gc-geocache)
 *   - 5s upstream timeout; upstream failure → HTTP 502 {"error": "..."}
 *   - bad/missing params → HTTP 400 {"error": "..."}
 *
 * The HTTP layer is an injectable callable (string $url): ?string so tests
 * never touch the network — same injection style as apigoatacc's OsmClient.
 */
class GeoService extends Service
{
    public const DEFAULT_LIMIT = 8;
    public const MAX_LIMIT = 10;

    private const NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';
    private const CACHE_TTL = 86400;   // ~24h
    private const UPSTREAM_TIMEOUT = 5;
    private const MIN_SPACING = 1.05;  // seconds between upstream Nominatim calls
    private const MAX_QUERY_LEN = 300;

    /**
     * Injectable HTTP transport for tests: (string $url): ?string (null = failure).
     * When set, the upstream throttle is skipped (no real network involved).
     * @var callable|null
     */
    public $http = null;

    /** Test override for the cache/throttle directory. @var string|null */
    public $cacheDir = null;

    /**
     * Entry point (mirrors PushService): authenticated callers only, then
     * dispatch on the route action.
     */
    public function getApiResponse()
    {
        if (!$this->isAuthenticated()) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        $action = strtolower((string) ($this->args['a'] ?? ''));
        if ($action === 'geocode') {
            [$status, $payload] = $this->geocodeAction();
        } elseif ($action === 'reversegeocode') {
            [$status, $payload] = $this->reverseGeocodeAction();
        } else {
            [$status, $payload] = [400, ['error' => 'Unknown action']];
        }

        return $this->json($payload, $status);
    }

    /**
     * GET /ApiGoat/geocode?q=&country=&limit=
     * @return array{0:int, 1:mixed} [http status, payload]
     */
    public function geocodeAction(): array
    {
        $q = trim((string) ($this->args['q'] ?? ''));
        if ($q === '' || strlen($q) > self::MAX_QUERY_LEN) {
            return [400, ['error' => 'Missing or invalid "q" parameter']];
        }

        $country = self::normalizeCountry($this->args['country'] ?? null);
        if ($country === false) {
            return [400, ['error' => 'Invalid "country" parameter — expected ISO code(s) like "ca" or "ca,us"']];
        }

        $limit = self::clampLimit($this->args['limit'] ?? null);

        $key = self::cacheKeySearch($q, $country, $limit);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return [200, $cached];
        }

        $params = [
            'format'         => 'json',
            'addressdetails' => 1,
            'limit'          => $limit,
            'q'              => $q,
        ];
        if ($country !== null) {
            $params['countrycodes'] = $country;
        }

        $body = $this->fetch(self::NOMINATIM_BASE . '/search?' . http_build_query($params));
        $rows = ($body !== null) ? json_decode($body, true) : null;
        if (!is_array($rows)) {
            return [502, ['error' => 'Geocoding service unavailable']];
        }

        // Nominatim /search returns a JSON list; keep the passthrough shape.
        $rows = array_values($rows);
        $this->cachePut($key, $rows);
        return [200, $rows];
    }

    /**
     * GET /ApiGoat/reverseGeocode?lat=&lng=
     * @return array{0:int, 1:mixed} [http status, payload]
     */
    public function reverseGeocodeAction(): array
    {
        $lat = self::parseCoord($this->args['lat'] ?? null, -90.0, 90.0);
        $lng = self::parseCoord($this->args['lng'] ?? null, -180.0, 180.0);
        if ($lat === null || $lng === null) {
            return [400, ['error' => 'Missing or invalid "lat"/"lng" parameters']];
        }

        $key = self::cacheKeyReverse($lat, $lng);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return [200, empty($cached) ? new \stdClass() : $cached];
        }

        $body = $this->fetch(self::NOMINATIM_BASE . '/reverse?' . http_build_query([
            'format'         => 'json',
            'addressdetails' => 1,
            'lat'            => sprintf('%.8F', $lat),
            'lon'            => sprintf('%.8F', $lng),
        ]));
        $obj = ($body !== null) ? json_decode($body, true) : null;
        if (!is_array($obj)) {
            return [502, ['error' => 'Geocoding service unavailable']];
        }

        // "Unable to geocode" → spec says an empty object, not an error.
        if (isset($obj['error'])) {
            $this->cachePut($key, []);
            return [200, new \stdClass()];
        }

        $this->cachePut($key, $obj);
        return [200, $obj];
    }

    /* ---------------------------------------------------------------------
     * Pure helpers (unit-tested without a container/session/network)
     * ------------------------------------------------------------------- */

    /** Normalize a free-text query for cache keying: trim, collapse whitespace, lowercase. */
    public static function normalizeQuery(string $q): string
    {
        $q = (string) preg_replace('/\s+/u', ' ', trim($q));
        return function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    }

    /** Clamp the result limit: default 8, min 1, max 10; non-numeric → default. */
    public static function clampLimit($limit): int
    {
        if ($limit === null || $limit === '' || !is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }
        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    /**
     * Normalize the optional country filter.
     * @return string|false|null null = absent (worldwide), false = invalid,
     *                           string = normalized "cc[,cc...]" (lowercase)
     */
    public static function normalizeCountry($country)
    {
        if ($country === null) {
            return null;
        }
        $country = strtolower(str_replace(' ', '', (string) $country));
        if ($country === '') {
            return null;
        }
        return preg_match('/^[a-z]{2}(,[a-z]{2})*$/', $country) ? $country : false;
    }

    /** Parse + range-check a coordinate; null on anything non-numeric/out of range. */
    public static function parseCoord($value, float $min, float $max): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return ($f >= $min && $f <= $max) ? $f : null;
    }

    /** Cache key for a forward-geocode request (normalized query + filters). */
    public static function cacheKeySearch(string $q, ?string $country, int $limit): string
    {
        return 'gcgeo:s:' . sha1(self::normalizeQuery($q) . '|' . ($country ?? '') . '|' . $limit);
    }

    /** Cache key for a reverse-geocode request (coords rounded to ~1m). */
    public static function cacheKeyReverse(float $lat, float $lng): string
    {
        return 'gcgeo:r:' . sprintf('%.5F,%.5F', $lat, $lng);
    }

    /** Descriptive User-Agent per the Nominatim usage policy. */
    public static function userAgent(): string
    {
        $site = \defined('_SITE_URL') ? \_SITE_URL : 'https://github.com/drez/GoatCheese';
        return 'GoatCheese-geocode-proxy/1.0 (+' . $site . ')';
    }

    /* ---------------------------------------------------------------------
     * Transport, throttle and cache
     * ------------------------------------------------------------------- */

    /** GET $url; null on transport failure or upstream HTTP >= 400. */
    private function fetch(string $url): ?string
    {
        if ($this->http !== null) {
            return ($this->http)($url);
        }

        $this->throttleUpstream();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::UPSTREAM_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::UPSTREAM_TIMEOUT,
            CURLOPT_USERAGENT      => self::userAgent(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body === false || $code >= 400) ? null : (string) $body;
    }

    /**
     * Enforce ~1s spacing between upstream Nominatim calls across ALL
     * PHP-FPM workers: a flock-serialized timestamp file. Best-effort — an
     * unwritable directory must never break geocoding, only fair-use pacing.
     */
    private function throttleUpstream(): void
    {
        $fh = @fopen($this->resolveCacheDir() . '/throttle.stamp', 'c+');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX)) {
            $last = (float) trim((string) stream_get_contents($fh));
            $wait = self::MIN_SPACING - (microtime(true) - $last);
            if ($wait > 0 && $wait <= self::MIN_SPACING) {
                usleep((int) round($wait * 1000000));
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, sprintf('%.6F', microtime(true)));
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /**
     * Cache read: APCu when usable (mirrors Utility\MicroCache), else the
     * file fallback (survives across FPM workers/requests without APCu).
     * @return array|null cached payload (arrays only), null on miss
     */
    private function cacheGet(string $key): ?array
    {
        if (self::apcuUsable()) {
            $hit = apcu_fetch($key, $ok);
            if ($ok) {
                $val = unserialize((string) $hit, ['allowed_classes' => false]);
                return is_array($val) ? $val : null;
            }
            return null;
        }

        $file = $this->cacheFile($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry) || !isset($entry[0]) || !array_key_exists(1, $entry)) {
            return null;
        }
        if ((int) $entry[0] < time()) {
            @unlink($file);
            return null;
        }
        return is_array($entry[1]) ? $entry[1] : null;
    }

    /** Cache write (best-effort; failures never break the request). */
    private function cachePut(string $key, array $value): void
    {
        if (self::apcuUsable()) {
            apcu_store($key, serialize($value), self::CACHE_TTL);
            return;
        }

        $file = $this->cacheFile($key);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize([time() + self::CACHE_TTL, $value])) !== false) {
            @rename($tmp, $file); // atomic promote — readers never see a torn write
        } else {
            @unlink($tmp);
        }
    }

    private function cacheFile(string $key): string
    {
        return $this->resolveCacheDir() . '/' . sha1($key) . '.cache';
    }

    private function resolveCacheDir(): string
    {
        $dir = $this->cacheDir;
        if ($dir === null) {
            $base = (\defined('_BASE_DIR') && is_dir(\_BASE_DIR . 'tmp'))
                ? rtrim(\_BASE_DIR, '/') . '/tmp'
                : sys_get_temp_dir();
            $dir = $base . '/gc-geocache';
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    private static function apcuUsable(): bool
    {
        return \function_exists('apcu_store')
            && (\PHP_SAPI !== 'cli'
                || \filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL));
    }

    /* ---------------------------------------------------------------------
     * Auth + raw-JSON response (passthrough shape — NOT the ApiResponse
     * envelope: the widget/mobile parsing code consumes Nominatim JSON as-is)
     * ------------------------------------------------------------------- */

    private function isAuthenticated(): bool
    {
        if (!isset($_SESSION[\_AUTH_VAR])
            || !is_object($_SESSION[\_AUTH_VAR])
            || !method_exists($_SESSION[\_AUTH_VAR], 'get')) {
            return false;
        }
        return $_SESSION[\_AUTH_VAR]->get('connected') === 'YES'
            && (int) ($_SESSION[\_AUTH_VAR]->get('id') ?? 0) > 0;
    }

    private function json($payload, int $status = 200)
    {
        $this->response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        return $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', $status < 400 ? 'private, max-age=3600' : 'no-store')
            ->withStatus($status);
    }
}
