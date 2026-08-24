<?php

namespace ApiGoat\Realtime;

use OpenSwoole\Constant;
use OpenSwoole\WebSocket\Server as WsServer;

/**
 * The realtime sidecar: an OpenSwoole WebSocket server that fans out table-change
 * notifications to subscribed clients.
 *
 * It deliberately knows NOTHING about the application. It never loads the Slim
 * app, never opens a database connection, never touches $_SESSION, and never
 * sends row data — a message is {table, gen, tenant} and nothing else. Clients
 * react by re-fetching through the normal FPM API, where RbacMiddleware,
 * AuthyMiddleware, Api::authorize and setAclFilter apply exactly as they do for
 * any other request. That is what makes it safe to run as a persistent process
 * alongside an application whose request path is full of superglobals.
 *
 * Two ingresses:
 *   - TCP  127.0.0.1:<port>       WebSocket clients (via an Apache ws tunnel)
 *   - UNIX <sock> (SOCK_DGRAM)    change signals from FPM (Signal::emit)
 *
 * worker_num is pinned to 1: the subscriber table lives in worker memory, and a
 * second worker would see half the sockets. This is not a throughput ceiling —
 * the loop is event-driven and a single worker holds thousands of idle sockets
 * comfortably — but it IS a hard constraint on ever adding blocking work here.
 */
final class Server
{
    /** @var array<int,array{u:int,tn:string,tables:array<string,true>}> keyed by fd */
    private array $clients = [];

    /**
     * @param string $sockGroup group to own the signal socket. The sidecar runs
     *        as the developer/deploy user while PHP-FPM runs as the web-server
     *        user, so a socket left at 0600 is unwritable by the very process
     *        that needs to signal it. '' leaves ownership untouched.
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $sockPath,
        private string $logPath,
        private string $sockGroup = ''
    ) {
    }

    public function run(): void
    {
        // A stale socket file from a crashed run makes bind() fail.
        if (\file_exists($this->sockPath)) {
            @\unlink($this->sockPath);
        }
        $dir = \dirname($this->sockPath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        $server = new WsServer($this->host, $this->port);
        $server->set([
            'worker_num'          => 1,
            'log_file'            => $this->logPath,
            'log_rotation'        => \defined('OpenSwoole\Constant::LOG_ROTATION_DAILY')
                ? Constant::LOG_ROTATION_DAILY
                : 1,
            'max_request'         => 0,   // never recycle: recycling drops every open socket
            'heartbeat_check_interval' => 60,
            'heartbeat_idle_time'      => 300,
        ]);

        $listener = $server->addlistener($this->sockPath, 0, Constant::SOCK_UNIX_DGRAM);
        if ($listener === false) {
            throw new \RuntimeException("realtime: cannot bind signal socket {$this->sockPath}");
        }
        $listener->set(['open_websocket_protocol' => false]);

        $server->on('Start', function () {
            // The socket must be writable by the FPM user and NOBODY else:
            // anyone who can write to it can make every connected client
            // re-fetch at will. Group-writable, never world-writable.
            if ($this->sockGroup !== '' && @\chgrp($this->sockPath, $this->sockGroup)) {
                @\chmod($this->sockPath, 0660);
            } else {
                @\chmod($this->sockPath, 0600);
                if ($this->sockGroup !== '') {
                    $this->log("WARNING: could not chgrp {$this->sockPath} to '{$this->sockGroup}'"
                        . " — PHP-FPM will not be able to send change signals.");
                }
            }
            $this->log("listening ws://{$this->host}:{$this->port} signal={$this->sockPath}");
        });

        $server->on('Open', fn($srv, $req) => $this->onOpen($srv, $req));
        $server->on('Message', fn($srv, $frame) => $this->onMessage($srv, $frame));
        $server->on('Close', function ($srv, $fd) {
            unset($this->clients[$fd]);
        });
        $server->on('Packet', fn($srv, $data, $info) => $this->onSignal($srv, (string) $data));

        // Plain HTTP on the same port: a liveness probe for `gc rt status`.
        $server->on('Request', function ($req, $res) {
            $res->header('Content-Type', 'application/json');
            $res->end(\json_encode(['ok' => true, 'clients' => \count($this->clients)]));
        });

        $server->start();
    }

    /** Handshake: a valid, unexpired, untampered ticket or the socket is closed. */
    private function onOpen($srv, $req): void
    {
        $ticket = (string) ($req->get['ticket'] ?? '');
        $claims = Ticket::verify($ticket);
        if ($claims === null) {
            $this->log("reject fd={$req->fd} (bad ticket)");
            $srv->disconnect($req->fd, 4401, 'unauthorized');
            return;
        }

        $this->clients[$req->fd] = ['u' => $claims['u'], 'tn' => $claims['tn'], 'tables' => []];
        $srv->push($req->fd, (string) \json_encode(['op' => 'ready']));
    }

    private function onMessage($srv, $frame): void
    {
        $fd = $frame->fd;
        if (!isset($this->clients[$fd])) {
            return;
        }

        $msg = \json_decode((string) $frame->data, true);
        if (!\is_array($msg)) {
            return;
        }

        switch ($msg['op'] ?? '') {
            case 'sub':
                foreach ($this->tableList($msg) as $t) {
                    $this->clients[$fd]['tables'][$t] = true;
                }
                break;
            case 'unsub':
                foreach ($this->tableList($msg) as $t) {
                    unset($this->clients[$fd]['tables'][$t]);
                }
                break;
            case 'ping':
                $srv->push($fd, (string) \json_encode(['op' => 'pong']));
                break;
        }
    }

    /** @return list<string> */
    private function tableList(array $msg): array
    {
        $raw = $msg['tables'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (\is_string($t) && $t !== '' && \strlen($t) <= 128) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** A change signal arrived from FPM: fan it out to matching subscribers. */
    private function onSignal($srv, string $data): void
    {
        $sig = \json_decode($data, true);
        if (!\is_array($sig) || !isset($sig['t'])) {
            return;
        }
        $table  = (string) $sig['t'];
        $tenant = (string) ($sig['tn'] ?? 'all');
        $frame  = (string) \json_encode(['op' => 'change', 't' => $table, 'g' => (string) ($sig['g'] ?? '')]);

        $sent = 0;
        foreach ($this->clients as $fd => $c) {
            if (!isset($c['tables'][$table])) {
                continue;
            }
            // 'all' on the signal = a root / unscoped write, visible to everyone.
            // 'all' on the client = a root / single-tenant viewer, who sees everything.
            if ($tenant !== 'all' && $c['tn'] !== 'all' && $c['tn'] !== $tenant) {
                continue;
            }
            if ($srv->isEstablished($fd)) {
                $srv->push($fd, $frame);
                $sent++;
            } else {
                unset($this->clients[$fd]);
            }
        }
        $this->log("signal {$table} tenant={$tenant} -> {$sent} client(s)");
    }

    private function log(string $line): void
    {
        @\file_put_contents($this->logPath, '[' . \date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND);
    }
}
