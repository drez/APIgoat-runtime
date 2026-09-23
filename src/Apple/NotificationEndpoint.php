<?php

namespace ApiGoat\Apple;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public POST /apple/notifications — App Store Server Notifications v2.
 * Unauthenticated by design: the body is a JWS signed by Apple and verified
 * like any transaction. Every verified notification is stored in apple_event
 * (unique notificationUUID = idempotency) before processing; failures answer
 * 500 so Apple retries (it does, on a backoff, for ~3 days).
 *
 * Projects react to entitlement changes through $onChange — registered from
 * their bootstrap with NotificationEndpoint::onChange(fn (array $change) => …).
 * $change = ['kind' => 'subscription'|'refund', 'client_table', 'client_id',
 *            'transaction' => payload, 'row' => apple_* row].
 */
final class NotificationEndpoint
{
    /** @var callable[] */
    private static array $listeners = [];

    public static function onChange(callable $fn): void
    {
        self::$listeners[] = $fn;
    }

    public static function handle(Request $request, Response $response): Response
    {
        if (!AppleIap::available()) {
            return self::json($response, 404, ['status' => 'error', 'message' => 'In-app purchase not enabled']);
        }
        $body = \json_decode((string) $request->getBody(), true);
        $jws = \is_array($body) ? (string) ($body['signedPayload'] ?? '') : '';
        try {
            $verifier = new SignedDataVerifier();
            $n = $verifier->verify($jws);
            self::assertOurs($n['data'] ?? []);
        } catch (\RuntimeException $e) {
            return self::json($response, 400, ['status' => 'error', 'message' => 'Invalid notification']);
        }

        $uuid = (string) ($n['notificationUUID'] ?? '');
        $claim = self::claimEvent($uuid, $n, $jws, \time());
        if ($claim === 'done') {
            return self::json($response, 200, ['status' => 'ok', 'message' => 'Already received']);
        }
        if ($claim === 'busy') {
            // Another delivery holds it; Apple retries on its backoff.
            return self::json($response, 409, ['status' => 'error', 'message' => 'Notification is being processed']);
        }
        $row = $claim;

        try {
            $handled = (new NotificationHandler($verifier, new PurchaseService($verifier), self::$listeners))->process($n);
            $row->setStatus($handled ? 'processed' : 'ignored');
            $row->setProcessedAt(\time());
            $row->save();
            return self::json($response, 200, ['status' => 'ok']);
        } catch (\Throwable $e) {
            $row->setStatus('failed');
            $row->setErrorMessage(\substr($e->getMessage(), 0, 500));
            $row->setProcessedAt(null);   // release the claim: Apple's retry may re-drive at once
            $row->save();
            return self::json($response, 500, ['status' => 'error', 'message' => 'Handler failed']);
        }
    }

    /** A claim older than this is presumed dead (handler killed) and may be re-taken. */
    public const CLAIM_LEASE_SECONDS = 300;

    /**
     * Store + atomically claim one notification (review-3 #6): two concurrent
     * deliveries of the same notificationUUID used to both run the handler —
     * a REFUND listener clawing back twice. Same lease as the Stripe webhook
     * (WebhookEndpoint::claim): the apple_event status ENUM has no
     * 'processing' value, so a NEGATIVE processed_at = -(claim time) marks a
     * running handler. Each UPDATE is conditional; exactly one request sees
     * affected rows == 1.
     *
     * @return object|string the claimed apple_event row, 'done' (already
     *         processed/ignored) or 'busy' (another delivery holds the lease)
     */
    public static function claimEvent(string $uuid, array $n, string $jws, int $now)
    {
        $q = AppleIap::query('AppleEvent');
        $row = $uuid !== '' ? $q::create()->filterByNotificationUuid($uuid)->findOne() : null;
        if ($row === null) {
            $model = AppleIap::model('AppleEvent');
            $row = new $model();
            $row->setNotificationUuid($uuid);
            $row->setType((string) ($n['notificationType'] ?? ''));
            $row->setSubtype((string) ($n['subtype'] ?? ''));
            $row->setPayload($jws);
            $row->setStatus('received');
            try {
                $row->save();
            } catch (\Throwable $e) {
                // A concurrent delivery inserted it first (unique notification_uuid).
                $row = $uuid !== '' ? $q::create()->filterByNotificationUuid($uuid)->findOne() : null;
                if ($row === null) {
                    throw $e;
                }
            }
        }
        if (\in_array((string) $row->getStatus(), ['processed', 'ignored'], true)) {
            return 'done';
        }
        $pk = (int) $row->getPrimaryKey();
        $affected = (int) $q::create()->filterByPrimaryKey($pk)->filterByStatus(['received', 'failed'])
            ->filterByProcessedAt(null, \Criteria::ISNULL)
            ->update(['ProcessedAt' => -$now]);
        if ($affected !== 1) {
            // Free (a positive "done at" left by an older build) or an expired lease.
            $affected = (int) $q::create()->filterByPrimaryKey($pk)->filterByStatus(['received', 'failed'])
                ->filterByProcessedAt(-($now - self::CLAIM_LEASE_SECONDS), \Criteria::GREATER_THAN)
                ->update(['ProcessedAt' => -$now]);
        }
        if ($affected !== 1) {
            return 'busy';
        }
        // Mirror the lease in memory so the failure path's NULL is a real change.
        $row->setProcessedAt(-$now);
        return $row;
    }

    /** The notification must be for OUR app, in an environment we accept. */
    public static function assertOurs(array $data): void
    {
        if (($data['bundleId'] ?? null) !== AppleIap::bundleId() || AppleIap::bundleId() === '') {
            throw new \RuntimeException('bundle-mismatch');
        }
        $env = (string) ($data['environment'] ?? '');
        if ($env === 'Sandbox' && !AppleIap::sandboxAllowed()) {
            throw new \RuntimeException('environment-refused');
        }
        $appId = AppleIap::appAppleId();
        if ($env === 'Production' && $appId !== null && (int) ($data['appAppleId'] ?? 0) !== $appId) {
            throw new \RuntimeException('app-mismatch');
        }
    }

    private static function json(Response $response, int $status, array $payload): Response
    {
        $response->getBody()->write((string) \json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
