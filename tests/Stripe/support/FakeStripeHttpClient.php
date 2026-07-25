<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe\Support;

/**
 * Minimal \Stripe\HttpClient\ClientInterface test double.
 *
 * Installed via \Stripe\ApiRequestor::setHttpClient() (the seam the Stripe
 * PHP SDK itself exposes for testing — see ApiRequestor::httpClient()) so
 * SubscriptionService/PortalService exercise the REAL Stripe SDK request-
 * building + response-parsing path (params, resource typing via OBJECT_NAME,
 * ->id chains, etc.) with ZERO network calls. Responses are handed out in
 * call order; each entry is a plain array that gets json_encode'd back as
 * the simulated response body.
 */
final class FakeStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    private int $next = 0;

    /** @var array<int, array{method:string, url:string, params:array}> recorded in call order */
    public array $calls = [];

    /** @param array<int, array<string, mixed>> $responses one entry per expected request, in order */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->calls[] = ['method' => $method, 'url' => $absUrl, 'params' => $params ?: []];

        if (!\array_key_exists($this->next, $this->responses)) {
            throw new \RuntimeException(
                "FakeStripeHttpClient: unexpected extra request #{$this->next}: {$method} {$absUrl}"
            );
        }
        $body = $this->responses[$this->next++];

        return [(string) \json_encode($body), 200, []];
    }
}
