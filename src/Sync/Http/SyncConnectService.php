<?php

namespace ApiGoat\Sync\Http;

use ApiGoat\Sync\Exceptions;
use ApiGoat\Sync\QuickBooks\QboApiClient;
use Psr\Http\Message\ResponseInterface;

/** GET Sync/connect — 302 to Intuit's consent screen with a session-bound state. */
final class SyncConnectService
{
    public function __construct(private $request, private $response, private array $args)
    {
    }

    public function getApiResponse(): ResponseInterface
    {
        $session = $_SESSION[_AUTH_VAR] ?? null;
        if (!$session || $session->get('connected') !== 'YES') {
            return $this->response->withStatus(403);
        }
        try {
            $client = QboApiClient::fromEnv();
        } catch (Exceptions\AuthFailed $e) {
            $this->response->getBody()->write('QuickBooks app keys missing — add QB_CLIENT_ID / QB_CLIENT_SECRET to .admin/.env (see docs/ACCOUNTING-SYNC.md).');
            return $this->response->withStatus(500);
        }
        $state = bin2hex(random_bytes(20));
        $_SESSION['sync_oauth_state'] = $state;
        return $this->response
            ->withHeader('Location', $client->authorizeUrl(SyncUrls::callback(), $state))
            ->withStatus(302);
    }
}
