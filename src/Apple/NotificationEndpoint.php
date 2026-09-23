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
        $q = AppleIap::query('AppleEvent');
        $row = $uuid !== '' ? $q::create()->filterByNotificationUuid($uuid)->findOne() : null;
        if ($row !== null && \in_array((string) $row->getStatus(), ['processed', 'ignored'], true)) {
            return self::json($response, 200, ['status' => 'ok', 'message' => 'Already received']);
        }
        if ($row === null) {
            $model = AppleIap::model('AppleEvent');
            $row = new $model();
            $row->setNotificationUuid($uuid);
            $row->setType((string) ($n['notificationType'] ?? ''));
            $row->setSubtype((string) ($n['subtype'] ?? ''));
            $row->setPayload($jws);
            $row->setStatus('received');
            $row->save();
        }

        try {
            $handled = (new NotificationHandler($verifier, new PurchaseService($verifier), self::$listeners))->process($n);
            $row->setStatus($handled ? 'processed' : 'ignored');
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
