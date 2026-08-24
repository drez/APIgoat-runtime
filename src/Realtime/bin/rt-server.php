<?php

/**
 * Realtime sidecar entry point.
 *
 *   php rt-server.php /path/to/project/.admin [port]
 *
 * Boots the MINIMUM needed to sign/verify tickets and bind two sockets: the
 * project's composer autoloader and its .env. It deliberately does NOT include
 * config/Built/config.php or config/legacy.php — those start a session, load
 * Propel and pull the whole application in, none of which may exist inside a
 * process that outlives a request.
 *
 * Started/stopped by `gc rt <project> start|stop`; see Project\RealtimeServer.
 */

declare(strict_types=1);

$adminDir = $argv[1] ?? '';
if ($adminDir === '' || !\is_dir($adminDir)) {
    \fwrite(STDERR, "usage: rt-server.php <project>/.admin\n");
    exit(1);
}
$adminDir = \rtrim(\realpath($adminDir) ?: $adminDir, DIRECTORY_SEPARATOR);

$autoload = $adminDir . '/vendor/autoload.php';
if (!\is_file($autoload)) {
    \fwrite(STDERR, "rt-server: no autoloader at {$autoload}\n");
    exit(1);
}
require $autoload;

// The project .env sits at the project ROOT, one level above .admin.
$envFile = \dirname($adminDir) . '/.env';
if (!\is_file($envFile)) {
    \fwrite(STDERR, "rt-server: no .env at {$envFile}\n");
    exit(1);
}
(new \Ahc\Env\Loader())->load($envFile);

// Signal::socketPath() resolves a relative GC_RT_SOCK against _BASE_DIR, and
// the FPM side defines it the same way (config/Built/config.php) — so both
// sides agree on the path without either knowing about the other.
if (!\defined('_BASE_DIR')) {
    \define('_BASE_DIR', $adminDir . DIRECTORY_SEPARATOR);
}

if (\ApiGoat\Realtime\Ticket::secret() === '') {
    \fwrite(STDERR, "rt-server: JWT_SECRET is not set in {$envFile}\n");
    exit(1);
}

// Port precedence: argv[2] (what `gc rt` resolved and what the Apache ws-tunnel
// in the vhost points at) > GC_RT_PORT > the built-in default. argv wins because
// Project\RealtimeServer derives a per-project port from the project NAME, which
// this process has no way to know — defaulting here instead bound the wrong port.
$port = (int) ($argv[2] ?? 0);
if ($port <= 0) {
    $port = (int) (env('GC_RT_PORT') ?: 9501);
}
$host = (string) (env('GC_RT_HOST') ?: '127.0.0.1');
$sock = \ApiGoat\Realtime\Signal::socketPath();
$log  = $adminDir . '/tmp/rt.log';

(new \ApiGoat\Realtime\Server($host, $port, $sock, $log))->run();
