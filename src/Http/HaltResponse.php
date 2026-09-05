<?php

declare(strict_types=1);

namespace ApiGoat\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * "Stop here and send exactly this" — the structured replacement for the
 * `die(json_encode(...))` the generated services used to end short-circuit
 * branches with (delete refusals, access-denied guards, PDF/Stripe/mass-action
 * payloads).
 *
 * A raw `die()` writes straight to the SAPI and terminates the process, so the
 * response never travels back up Slim's middleware stack: CorsMiddleware,
 * SecurityHeadersMiddleware (CSP nonce, X-Frame-Options, nosniff),
 * ServerTimingMiddleware and SessionReleaseMiddleware were all skipped for
 * exactly the responses that carry the most sensitive payloads. Throwing this
 * instead unwinds only as far as the route closure, which turns it back into a
 * normal PSR-7 response — so every middleware still runs on the way out.
 *
 * It extends RuntimeException purely so that a stray one escaping a closure is
 * still caught by the error middleware (ExceptionHandler special-cases it and
 * renders the payload rather than a 500).
 *
 * @see \ApiGoat\Services\Concerns\HaltsResponses::halt()
 */
class HaltResponse extends \RuntimeException
{
    /** Verbatim response body. */
    private string $bodyText;

    /** HTTP status code to send. */
    private int $statusCode;

    /** Extra response headers, name => value. */
    private array $haltHeaders;

    /**
     * @param string $body    Response body, already serialized.
     * @param int    $status  HTTP status code.
     * @param array  $headers Header name => value.
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        parent::__construct('halt');
        $this->bodyText    = $body;
        $this->statusCode  = $status;
        $this->haltHeaders = $headers;
    }

    public function getBodyText(): string
    {
        return $this->bodyText;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function getHaltHeaders(): array
    {
        return $this->haltHeaders;
    }

    /**
     * Turn this halt into the response the route closure returns.
     *
     * The body is REPLACED, not appended to: the closure may already have
     * written a partial render before a nested call halted, and PSR-7 streams
     * cannot be truncated. A fresh stream is built through the PSR-17 factory
     * (slim/psr7 is a hard dependency); the in-place write is only a fallback
     * for an exotic PSR-7 implementation.
     */
    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->haltHeaders as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }

        if (class_exists(\Slim\Psr7\Factory\StreamFactory::class)) {
            $response = $response->withBody(
                (new \Slim\Psr7\Factory\StreamFactory())->createStream($this->bodyText)
            );

            return $response->withStatus($this->statusCode);
        }

        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $stream->write($this->bodyText);

        return $response->withStatus($this->statusCode);
    }
}
