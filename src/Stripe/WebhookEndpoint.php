<?php

namespace ApiGoat\Stripe;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public POST /stripe/webhook. Unauthenticated by design; security is the
 * Stripe signature (constant-time HMAC check + 5-min timestamp tolerance).
 * Every verified event is stored in stripe_event first (unique event id =
 * idempotency), then processed; failures 500 so Stripe retries.
 */
final class WebhookEndpoint
{
    private const TOLERANCE_SECONDS = 300;

    public static function handle(Request $request, Response $response): Response
    {
        if (!StripeManifest::available()) {
            return self::json($response, 404, ['status' => 'error', 'message' => 'Stripe not enabled']);
        }
        $secret = StripeGateway::webhookSecret();
        if ($secret === null) {
            return self::json($response, 503, ['status' => 'error', 'message' => 'Webhook secret not configured']);
        }

        $payload = (string) $request->getBody();
        $header  = $request->getHeaderLine('Stripe-Signature');
        try {
            $event = self::verifySignature($payload, $header, $secret);
        } catch (\RuntimeException $e) {
            return self::json($response, 400, ['status' => 'error', 'message' => 'Invalid signature']);
        }

        $eventQ = StripeDb::query('StripeEvent');
        if ($eventQ::create()->filterByStripeEventId((string) $event['id'])->findOne() !== null) {
            return self::json($response, 200, ['status' => 'ok', 'message' => 'Already received']);
        }
        $model = StripeDb::model('StripeEvent');
        $row = new $model();
        $row->setStripeEventId((string) $event['id']);
        $row->setType((string) $event['type']);
        $row->setPayload($payload);
        $row->setStatus('received');
        $row->save();

        try {
            WebhookHandler::process($event);
            $row->setStatus(WebhookHandler::wasIgnored($event['type']) ? 'ignored' : 'processed');
            $row->setProcessedAt(\time());
            $row->save();
            return self::json($response, 200, ['status' => 'ok']);
        } catch (\Throwable $e) {
            $row->setStatus('failed');
            $row->setErrorMessage(\substr($e->getMessage(), 0, 500));
            $row->save();
            return self::json($response, 500, ['status' => 'error', 'message' => 'Handler failed']);
        }
    }

    /**
     * Pure signature check (test seam): Stripe-Signature header format
     * "t=<unix>,v1=<hmac>". Returns the decoded event array or throws.
     */
    public static function verifySignature(string $payload, string $sigHeader, string $secret, ?int $now = null): array
    {
        $parts = [];
        foreach (\explode(',', $sigHeader) as $kv) {
            $bits = \explode('=', \trim($kv), 2);
            if (\count($bits) === 2) {
                $parts[$bits[0]][] = $bits[1];
            }
        }
        $ts = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        if ($ts <= 0 || empty($parts['v1'])) {
            throw new \RuntimeException('Malformed Stripe-Signature header');
        }
        if (\abs(($now ?? \time()) - $ts) > self::TOLERANCE_SECONDS) {
            throw new \RuntimeException('Timestamp outside tolerance');
        }
        $expected = \hash_hmac('sha256', $ts . '.' . $payload, $secret);
        $ok = false;
        foreach ($parts['v1'] as $candidate) {
            if (\hash_equals($expected, $candidate)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new \RuntimeException('Signature mismatch');
        }
        $event = \json_decode($payload, true);
        if (!\is_array($event) || !isset($event['id'], $event['type'])) {
            throw new \RuntimeException('Invalid event payload');
        }
        return $event;
    }

    private static function json(Response $response, int $status, array $body): Response
    {
        $response->getBody()->write((string) \json_encode($body));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
