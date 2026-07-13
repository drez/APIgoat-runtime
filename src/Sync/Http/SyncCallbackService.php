<?php

namespace ApiGoat\Sync\Http;

use ApiGoat\Sync\ConnectionStore;
use ApiGoat\Sync\Exceptions;
use ApiGoat\Sync\QuickBooks\QboApiClient;
use Psr\Http\Message\ResponseInterface;

/** GET Sync/callback — verify state, exchange the code, persist the connection. */
final class SyncCallbackService
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
        $q        = $this->request->getQueryParams();
        $expected = (string) ($_SESSION['sync_oauth_state'] ?? '');
        unset($_SESSION['sync_oauth_state']);
        if ($expected === '' || !hash_equals($expected, (string) ($q['state'] ?? ''))) {
            $this->response->getBody()->write('OAuth state mismatch — restart from Sync/status.');
            return $this->response->withStatus(400);
        }
        $code  = (string) ($q['code'] ?? '');
        $realm = (string) ($q['realmId'] ?? '');
        if ($code === '' || $realm === '' || !ConnectionStore::available()) {
            return $this->response->withStatus(400);
        }
        try {
            $tok = QboApiClient::fromEnv()->exchangeCode($code, SyncUrls::callback());
        } catch (Exceptions\AuthFailed $e) {
            $this->response->getBody()->write('Token exchange failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
            return $this->response->withStatus(502);
        }
        $conn = ConnectionStore::find() ?: new \App\AcctConnection();
        $conn->setProvider('quickbooks');
        $conn->setRealmId($realm);
        $conn->setConnectedBy((int) ($session->get('id') ?? 0));
        ConnectionStore::storeTokens($conn, $tok);
        return $this->response->withHeader('Location', SyncUrls::status())->withStatus(302);
    }
}
