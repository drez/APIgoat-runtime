<?php

namespace ApiGoat\Sync\Http;

use ApiGoat\Sync\ConnectionStore;
use ApiGoat\Sync\SyncMap;
use ApiGoat\Sync\SyncQueue;
use Psr\Http\Message\ResponseInterface;

/**
 * GET/POST Sync/status — connection state, link/job counters, Connect /
 * Run backfill / Disconnect actions. Raw-HTML page in the
 * OAuthAuthorizeService style (self-contained, htmlspecialchars everywhere).
 */
final class SyncStatusService
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
        $map  = SyncMap::load();
        if (!$map) {
            $this->response->getBody()->write('Accounting sync is not built for this project (no sync.map.php).');
            return $this->response->withStatus(404);
        }
        $conn = ConnectionStore::available() ? ConnectionStore::find() : null;
        $msg  = '';

        if (strtoupper($this->request->getMethod()) === 'POST') {
            $body   = (array) $this->request->getParsedBody();
            $action = (string) ($body['action'] ?? '');
            if ($action === 'backfill' && $conn) {
                $msg = SyncQueue::enqueue(SyncQueue::KIND_BACKFILL) !== null
                    ? 'Backfill queued — records appear in the Sync job list as the cron drains.'
                    : 'A backfill is already queued.';
            } elseif ($action === 'disconnect' && $conn) {
                $conn->delete();
                $conn = null;
                $msg  = 'Disconnected. Tokens removed; links and jobs were kept.';
            }
        }

        $csrf = method_exists($session, 'getCsrf') ? (string) $session->getCsrf() : '';
        if ($csrf === '' && method_exists($session, 'setCsrf')) {
            $csrf = bin2hex(random_bytes(32));
            $session->setCsrf($csrf);
        }
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        $rows = '';
        foreach ($this->counts() as [$label, $count]) {
            $rows .= '<tr><td>' . $h($label) . '</td><td style="text-align:right">' . (int) $count . '</td></tr>';
        }
        $connected = $conn && $conn->getStatus() === 'Connected';
        $statusTxt = $conn ? $conn->getStatus() . ' (realm ' . $h($conn->getRealmId()) . ')' : 'Not connected';
        $connectUrl = rtrim((string) _SITE_URL, '/') . '/Sync/connect';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Accounting sync</title>'
            . '<style>body{font:14px/1.5 system-ui;margin:2rem auto;max-width:640px;padding:0 1rem}'
            . 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:.4rem}'
            . '.btn{display:inline-block;padding:.5rem 1rem;border:1px solid #888;border-radius:4px;text-decoration:none;margin-right:.5rem}</style></head><body>'
            . '<h1>Accounting sync — QuickBooks</h1>'
            . ($msg !== '' ? '<p><strong>' . $h($msg) . '</strong></p>' : '')
            . '<p>Status: <strong>' . $h($statusTxt) . '</strong></p>'
            . '<table>' . $rows . '</table><p style="margin-top:1rem">'
            . (!$connected ? '<a class="btn" href="' . $h($connectUrl) . '">Connect to QuickBooks</a>' : '')
            . ($connected
                ? '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
                    . '<input type="hidden" name="action" value="backfill"><button class="btn">Run backfill</button></form>'
                    . '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
                    . '<input type="hidden" name="action" value="disconnect"><button class="btn">Disconnect</button></form>'
                : '')
            . '</p><p><a href="' . $h(rtrim((string) _SITE_URL, '/')) . '">Back to admin</a></p></body></html>';

        $resp = $this->response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
        $resp->getBody()->write($html);
        return $resp;
    }

    /** @return list<array{0:string,1:int}> */
    private function counts(): array
    {
        $out = [];
        if (class_exists('\App\AcctLinkQuery')) {
            foreach (['Synced', 'Pending', 'Error', 'LocalDeleted'] as $s) {
                $out[] = ["Links {$s}", \App\AcctLinkQuery::create()->filterByStatus($s)->count()];
            }
        }
        if (class_exists('\App\AcctSyncJobQuery')) {
            foreach (['Pending', 'Running', 'Failed'] as $s) {
                $out[] = ["Jobs {$s}", \App\AcctSyncJobQuery::create()->filterByState($s)->count()];
            }
        }
        return $out;
    }
}
