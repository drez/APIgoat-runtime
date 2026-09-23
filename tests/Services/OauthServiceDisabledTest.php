<?php

namespace ApiGoat\Tests\Services;

use ApiGoat\Services\OauthService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Review 2026-09-23: the legacy Opauth callback logged the caller in from an
 * unsigned client-supplied `opauth` payload (provider + uid) and echoed
 * error_description unescaped. Both entry points must now refuse without
 * reading the payload, touching the session, or reflecting input.
 */
final class OauthServiceDisabledTest extends TestCase
{
    private function forged(): array
    {
        $payload = base64_encode(json_encode(['auth' => [
            'provider' => 'Google', 'uid' => '1',
            'info' => ['email' => 'root@example.com', 'uid' => '1'],
        ]]));
        return [
            'action' => 'callback', 'method' => 'GET',
            'data' => ['opauth' => $payload, 'error' => '1', 'error_description' => '<script>alert(1)</script>'],
            'opauth' => $payload, 'error' => '1', 'error_description' => '<script>alert(1)</script>',
        ];
    }

    private function service(array $args): OauthService
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/oauth/google/callback');
        return new OauthService($request, (new ResponseFactory())->createResponse(), $args);
    }

    public function testHtmlCallbackRefusesAndReflectsNothing(): void
    {
        $before = $_SESSION ?? null;
        $result = $this->service($this->forged())->getResponse();

        self::assertIsArray($result);
        self::assertNotEmpty($result['error']);
        self::assertStringNotContainsString('<script', $result['error']);
        self::assertSame($before, $_SESSION ?? null, 'no session side effect');
    }

    public function testApiCallbackIsAJson404WithNoTokenOrRedirect(): void
    {
        $response = $this->service($this->forged())->getApiResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('failure', $body['status']);
        self::assertArrayNotHasKey('jwt', (array) ($body['data'] ?? []));
        self::assertStringNotContainsString('<script', (string) $response->getBody());
    }

    public function testNonCallbackActionsRefuseToo(): void
    {
        $svc = $this->service(['action' => 'google', 'method' => 'GET']);
        self::assertNotEmpty($svc->getResponse()['error']);
        self::assertSame(404, $this->service(['action' => 'google', 'method' => 'GET'])->getApiResponse()->getStatusCode());
    }
}
