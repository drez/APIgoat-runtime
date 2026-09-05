<?php

declare(strict_types=1);

namespace ApiGoat\Services\Concerns;

use ApiGoat\Http\HaltResponse;

/**
 * `halt()` — the structured replacement for `die(...)` in a service method.
 *
 * The generated services are plain classes (they do NOT extend
 * ApiGoat\Services\Service), so this lives in a trait that both the emitted
 * services and the runtime Service base class pull in.
 *
 * Byte-compatibility rule: a string body is sent verbatim, so every call site
 * that used to `die($alreadyEncodedJson)` keeps its exact payload (including
 * JSON_UNESCAPED_SLASHES/JSON_PRETTY_PRINT flags chosen by the caller). Passing
 * an array is a convenience for the `die(json_encode($x))` sites and encodes
 * with the same default flags those used.
 *
 * @see \ApiGoat\Http\HaltResponse
 */
trait HaltsResponses
{
    /**
     * Send $body and stop processing this request — without killing the
     * process, so the middleware stack still stamps CORS / security headers /
     * server timing and releases the session lock.
     *
     * @param array|string $body    Array => json_encode()d; string => verbatim.
     * @param int          $status  HTTP status code.
     * @param array        $headers Extra response headers (name => value).
     * @param bool         $json    Force the JSON content type for a string body.
     *
     * @throws HaltResponse always
     */
    protected function halt($body, int $status = 200, array $headers = [], bool $json = false): never
    {
        if (is_array($body)) {
            $body = (string) json_encode($body);
            $json = true;
        }
        if ($json) {
            $hasType = false;
            foreach (array_keys($headers) as $name) {
                if (strcasecmp((string) $name, 'Content-Type') === 0) {
                    $hasType = true;
                    break;
                }
            }
            if (!$hasType) {
                $headers['Content-Type'] = 'application/json;charset=UTF-8';
            }
        }

        throw new HaltResponse((string) $body, $status, $headers);
    }
}
