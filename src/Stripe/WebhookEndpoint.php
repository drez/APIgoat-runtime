<?php

namespace ApiGoat\Stripe;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public POST /stripe/webhook. Unauthenticated by design; security is the
 * Stripe signature (constant-time HMAC check + 5-min timestamp tolerance).
 * Every verified event is stored in stripe_event first (unique event id =
 * idempotency), claimed atomically (one concurrent delivery runs it), checked
 * for ordering (an event older than one already applied to the same object is
 * skipped), then processed; failures 500 so Stripe retries.
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

        // Mode gate (audit M11): a test-mode event must never mutate a live
        // deployment (and vice versa). Distinct signing secrets make this
        // unlikely, but a copy-pasted secret would otherwise let it through.
        if (isset($event['livemode']) && (bool) $event['livemode'] !== StripeManifest::livemode()) {
            return self::json($response, 200, ['status' => 'ok', 'message' => 'Wrong mode — ignored']);
        }

        $eventQ = StripeDb::query('StripeEvent');
        $row = $eventQ::create()->filterByStripeEventId((string) $event['id'])->findOne();
        if ($row === null) {
            $model = StripeDb::model('StripeEvent');
            $row = new $model();
            $row->setStripeEventId((string) $event['id']);
            $row->setType((string) $event['type']);
            $row->setPayload($payload);
            $row->setStatus('received');
            try {
                $row->save();
            } catch (\Throwable $e) {
                // A concurrent delivery inserted it first (unique event id).
                $row = $eventQ::create()->filterByStripeEventId((string) $event['id'])->findOne();
                if ($row === null) {
                    throw $e;
                }
            }
        }
        // Dedup is PROCESS-state-aware (audit C3 2026-07-27): a row stuck at
        // received (handler died — OOM, timeout) or failed must let Stripe's
        // retry re-drive processing. Only finished events short-circuit.
        if (\in_array((string) $row->getStatus(), ['processed', 'ignored'], true)) {
            return self::json($response, 200, ['status' => 'ok', 'message' => 'Already received']);
        }

        // Atomic claim: two concurrent deliveries of one event used to both
        // run the handler. Exactly one wins the UPDATE; the other answers 409
        // so Stripe retries it later (by then: "Already received").
        $now = \time();
        if (!self::claim($eventQ, (int) $row->getPrimaryKey(), $now)) {
            return self::json($response, 409, ['status' => 'error', 'message' => 'Event is being processed']);
        }
        // Mirror the lease in memory so the failure path's NULL is a real
        // change Propel writes (a fresh row already holds NULL in memory).
        $row->setProcessedAt(-$now);

        try {
            // Ordering: Stripe does not deliver in order. An event older than
            // one already applied to the same object (subscription, payment
            // intent, dispute) must not roll its state back — e.g. a late
            // payment_intent.succeeded re-flipping the paid flag after
            // charge.refunded.
            $newer = self::newerAppliedEvent($eventQ, $event);
            if ($newer !== null) {
                $row->setStatus('ignored');
                $row->setErrorMessage(\substr('stale: older than applied ' . $newer, 0, 500));
                $row->setProcessedAt($now);
                $row->save();
                return self::json($response, 200, ['status' => 'ok', 'message' => 'Stale event ignored']);
            }
            WebhookHandler::process($event);
            $row->setStatus(WebhookHandler::wasIgnored($event['type']) ? 'ignored' : 'processed');
            $row->setErrorMessage(null);
            $row->setProcessedAt(\time());
            $row->save();
            return self::json($response, 200, ['status' => 'ok']);
        } catch (\Throwable $e) {
            $row->setStatus('failed');
            $row->setErrorMessage(\substr($e->getMessage(), 0, 500));
            $row->setProcessedAt(null);   // release the claim: the retry may re-drive at once
            $row->save();
            return self::json($response, 500, ['status' => 'error', 'message' => 'Handler failed']);
        }
    }

    /** A claim older than this is presumed dead (handler killed) and may be re-taken. */
    public const CLAIM_LEASE_SECONDS = 300;

    /**
     * Claim an event row for processing, atomically. The stripe_event status
     * ENUM has no 'processing' value (no schema change), so the claim is a
     * lease on processed_at: a NEGATIVE processed_at = -(claim time) while a
     * handler runs; it becomes the real (positive) processed time on success
     * and NULL on failure. The single UPDATE matches only an unfinished row
     * (received/failed) whose lease is free (NULL, positive, or older than
     * CLAIM_LEASE_SECONDS) — rowCount 1 = this request owns the event.
     */
    public static function claim(string $eventQ, int $pk, int $now): bool
    {
        $cutoff = self::leaseCutoff($now);
        $affected = $eventQ::create()
            ->filterByPrimaryKey($pk)
            ->filterByStatus(['received', 'failed'])
            ->filterByProcessedAt(null, \Criteria::ISNULL)
            ->_or()
            ->filterByProcessedAt($cutoff, \Criteria::GREATER_THAN)
            ->update(['ProcessedAt' => -$now]);
        return (int) $affected === 1;
    }

    /** processed_at values above this are claimable (free, done, or an expired lease). Pure. */
    public static function leaseCutoff(int $now): int
    {
        return -($now - self::CLAIM_LEASE_SECONDS);
    }

    /**
     * The object whose state an event sets, for ordering — null when the
     * event only inserts idempotently (invoice.paid) or is ignored. Payment
     * state is keyed by the payment intent across event types (checkout
     * completion, intent status, refund). Pure.
     */
    public static function orderingKey(array $event): ?string
    {
        $obj  = $event['data']['object'] ?? [];
        $type = (string) ($event['type'] ?? '');
        $id = static function ($v): string {
            return \is_array($v) ? (string) ($v['id'] ?? '') : (string) ($v ?? '');
        };
        $key = null;
        if (\strpos($type, 'customer.subscription.') === 0) {
            $key = 'sub:' . $id($obj['id'] ?? null);
        } elseif (\strpos($type, 'payment_intent.') === 0) {
            $key = 'pi:' . $id($obj['id'] ?? null);
        } elseif ($type === 'charge.refunded' || $type === 'checkout.session.completed') {
            $key = 'pi:' . $id($obj['payment_intent'] ?? null);
        } elseif (\strpos($type, 'charge.dispute.') === 0) {
            $key = 'dp:' . $id($obj['id'] ?? null);
        }
        return ($key === null || \substr($key, -1) === ':') ? null : $key;
    }

    /**
     * Is $applied (an already-processed event) newer than $event for the same
     * object? Later `created` wins; within the same second a subscription's
     * created < updated < deleted. Pure.
     */
    public static function supersedes(array $applied, array $event): bool
    {
        $key = self::orderingKey($event);
        if ($key === null || self::orderingKey($applied) !== $key || ($applied['id'] ?? null) === ($event['id'] ?? null)) {
            return false;
        }
        $a = (int) ($applied['created'] ?? 0);
        $e = (int) ($event['created'] ?? 0);
        if ($a !== $e) {
            return $a > $e;
        }
        $rank = ['customer.subscription.created' => 0, 'customer.subscription.updated' => 1, 'customer.subscription.deleted' => 2];
        $ra = $rank[$applied['type'] ?? ''] ?? null;
        $re = $rank[$event['type'] ?? ''] ?? null;
        return $ra !== null && $re !== null && $ra > $re;
    }

    /**
     * Id of a processed event that supersedes $event (see supersedes()), or
     * null. The stored payloads are the record of what was applied: rows are
     * narrowed by a LIKE on the object id, then decoded and compared.
     */
    private static function newerAppliedEvent(string $eventQ, array $event): ?string
    {
        $key = self::orderingKey($event);
        if ($key === null) {
            return null;
        }
        $objectId = \substr($key, \strpos($key, ':') + 1);
        $like = '%' . \addcslashes($objectId, '%_\\') . '%';
        $rows = $eventQ::create()
            ->filterByStatus('processed')
            ->filterByPayload($like, \Criteria::LIKE)
            ->filterByStripeEventId((string) $event['id'], \Criteria::NOT_EQUAL)
            ->find();
        foreach ($rows as $r) {
            $applied = \json_decode((string) $r->getPayload(), true);
            if (\is_array($applied) && self::supersedes($applied, $event)) {
                return (string) ($applied['id'] ?? $r->getStripeEventId());
            }
        }
        return null;
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
