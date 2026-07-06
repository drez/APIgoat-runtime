<?php

namespace ApiGoat\Services;

use ApiGoat\Api\ApiResponse;

/**
 * Expo push notifications — device-token registry + send.
 *
 * Routes (per-project wiring registers them; see gc mobile / template routes.php):
 *   POST /api/v1/Push            body {token, platform}  → register/refresh this
 *                                                          user's device token.
 *   POST /api/v1/Push/test       → send a test push to the caller's own devices.
 *
 * Reusable server-side send:
 *   PushService::sendToUser($userId, $title, $body, $data=[]) — POSTs to the
 *   Expo push API for every stored token of that user; prunes tokens Expo
 *   reports as DeviceNotRegistered. Call it from any hook/service to notify a
 *   user (e.g. on a new assignment, a state change, …).
 *
 * Storage is the `push_device` table (with_mobile provisions it; base.hjson).
 * Guarded by class_exists so a project without the table degrades gracefully.
 */
class PushService extends Service
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function getApiResponse()
    {
        $userId = (int) ($_SESSION[\_AUTH_VAR]->get('id') ?? 0);
        if (!$userId) {
            return $this->respond(['status' => 'failure', 'errors' => ['Not authenticated']], 401);
        }
        if (!class_exists('\App\PushDeviceQuery')) {
            return $this->respond(['status' => 'failure', 'errors' => ['Push not provisioned — rebuild with the push_device table']], 501);
        }

        $action = strtolower((string) ($this->args['a'] ?? ''));

        if ($action === 'test') {
            $sent = self::sendToUser($userId, 'Test notification', 'Push notifications are working ✅', ['type' => 'test']);
            return $this->respond(['status' => 'success', 'data' => ['sent' => $sent]]);
        }

        // Default: register/refresh this user's token.
        $token    = trim((string) ($this->args['data']['token'] ?? ''));
        $platform = strtolower((string) ($this->args['data']['platform'] ?? 'ios'));
        if ($token === '' || !preg_match('/^ExponentPushToken\[.+\]$|^ExpoPushToken\[.+\]$/', $token)) {
            return $this->respond(['status' => 'failure', 'errors' => ['A valid Expo push token is required']], 400);
        }
        if (!in_array($platform, ['ios', 'android', 'web'], true)) {
            $platform = 'ios';
        }

        // Upsert on the unique token: an existing token re-registered by another
        // user is reassigned (device handed over); else insert.
        $device = \App\PushDeviceQuery::create()->filterByToken($token)->findOne();
        if (!$device) {
            $device = new \App\PushDevice();
            $device->setToken($token);
        }
        $device->setIdAuthy($userId);
        if (method_exists($device, 'setPlatform')) {
            $device->setPlatform($platform);
        }
        $device->save();

        return $this->respond(['status' => 'success', 'data' => ['registered' => true]]);
    }

    private function respond(array $body, int $status = null)
    {
        $ApiResponse = new ApiResponse($this->args, $this->response, $body);
        if ($status !== null) {
            $ApiResponse->setStatus($status);
        }
        return $ApiResponse->getResponse();
    }

    /**
     * Send a push to every stored device of a user. Returns the number of
     * tokens accepted by Expo. Never throws — logs and returns 0 on failure so
     * a notification problem can't break the triggering request.
     */
    public static function sendToUser($userId, string $title, string $body, array $data = []): int
    {
        if (!class_exists('\App\PushDeviceQuery')) {
            return 0;
        }
        $devices = \App\PushDeviceQuery::create()->filterByIdAuthy((int) $userId)->find();
        $messages = [];
        $tokenByIndex = [];
        foreach ($devices as $d) {
            $tokenByIndex[] = $d->getToken();
            $messages[] = ['to' => $d->getToken(), 'title' => $title, 'body' => $body, 'data' => $data, 'sound' => 'default'];
        }
        if (!$messages) {
            return 0;
        }

        $receipts = self::postToExpo($messages);
        if ($receipts === null) {
            return 0;
        }

        // Prune tokens Expo rejects as permanently unreachable.
        $sent = 0;
        foreach ($receipts as $i => $r) {
            if (($r['status'] ?? '') === 'ok') {
                $sent++;
            } elseif (($r['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($tokenByIndex[$i])) {
                \App\PushDeviceQuery::create()->filterByToken($tokenByIndex[$i])->delete();
            }
        }
        return $sent;
    }

    /** POST the message batch to Expo; returns the per-message `data` array or null. */
    private static function postToExpo(array $messages): ?array
    {
        $payload = json_encode($messages);
        $ch = curl_init(self::EXPO_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code >= 300) {
            error_log('[PushService] Expo push failed (' . $code . '): ' . substr((string) $res, 0, 300));
            return null;
        }
        $decoded = json_decode($res, true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
