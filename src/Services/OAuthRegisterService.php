<?php
namespace ApiGoat\Services;

use ApiGoat\OAuth\ClientRepository;
use ApiGoat\Services\Service;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RFC 7591 Dynamic Client Registration endpoint controller.
 *
 * POST /oauth/register — open registration with a per-IP rate limit enforced
 * via the authy_log table (event='dcr'). Delegates to ClientRepository::register()
 * for metadata validation and persistence.
 */
class OAuthRegisterService extends Service
{
    /**
     * Bypass the parent BuilderLayout/BuilderMenus initialization — the DCR
     * endpoint returns raw JSON responses and never uses the HTML rendering layer.
     */
    public function __construct(Request $request, \Psr\Http\Message\ResponseInterface $response, array $args)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->args     = $args;
    }

    public function getApiResponse(): ResponseInterface
    {
        if (!class_exists('\App\OauthClient')) {
            return $this->response->withStatus(404);
        }

        // Per-IP rate limit: reuse the authy_log throttle convention
        // with event='dcr'. Cap: 20 registrations per IP per hour.
        $ip = $this->request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->tooManyRegistrations($ip)) {
            return $this->jsonResponse(['error' => 'rate_limited'], 429);
        }

        // Support JSON body (MCP clients send Content-Type: application/json)
        // as well as form-encoded bodies.
        $body = (array) (
            $this->request->getParsedBody()
            ?? json_decode((string) $this->request->getBody(), true)
            ?? []
        );

        try {
            $reg = (new ClientRepository())->register($body);
            try { $this->recordRegistration($ip); } catch (\Throwable $e) { /* logging failure must not fail a valid registration */ }
            return $this->jsonResponse($reg, 201);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => $e->getMessage(),
            ], 400);
        }
    }

    private function jsonResponse(array $data, int $status): ResponseInterface
    {
        $this->response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Count DCR registrations from this IP in the last hour via authy_log
     * (event='dcr'). Returns false if the authy_log table is unavailable.
     */
    private function tooManyRegistrations(string $ip): bool
    {
        if (!class_exists('\App\AuthyLog')) {
            return false;
        }
        $since = time() - 3600;
        $n = \App\AuthyLogQuery::create()
            ->filterByEvent('dcr')
            ->filterByIp($ip)
            ->filterByTimestamp($since, \Criteria::GREATER_EQUAL)
            ->count();
        return $n >= 20;
    }

    /** Record a successful DCR registration in authy_log for rate-limit tracking. */
    private function recordRegistration(string $ip): void
    {
        if (!class_exists('\App\AuthyLog')) {
            return;
        }
        $log = new \App\AuthyLog();
        $log->setEvent('dcr');
        $log->setIp($ip);
        $log->setLogin('');
        $log->setResult('');
        $log->setTimestamp(time());
        $log->save();
    }
}
