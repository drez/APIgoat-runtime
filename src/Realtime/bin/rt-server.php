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

// Where the .env lives depends on the layout:
//   local  — project root, one level ABOVE .admin (p/<name>/.env)
//   prod   — inside .admin (gc deploy's getRemoteAdminEnvPath())
// Check both and take the first non-empty one, so the same entry point works
// on a dev box and on a deployed site without being told which it is.
$envFile = '';
foreach ([\dirname($adminDir) . '/.env', $adminDir . '/.env'] as $candidate) {
    if (\is_file($candidate) && \filesize($candidate) > 0) {
        $envFile = $candidate;
        break;
    }
}
if ($envFile === '') {
    \fwrite(STDERR, 'rt-server: no .env found at ' . \dirname($adminDir) . '/.env or ' . $adminDir . "/.env\n");
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

// The FPM user must be able to write the signal socket. Default to the GROUP OF
// THE PROJECT .env — gc deliberately keeps that file owned <deploy-user>:<web
// group> with mode 0640 (see Project\SecretProvisioner), so it is already this
// codebase's marker for "the group the web server runs as", and it stays correct
// if the deployment's users change. GC_RT_SOCK_GROUP overrides.
$sockGroup = (string) (env('GC_RT_SOCK_GROUP') ?: '');
if ($sockGroup === '') {
    $gid = @\filegroup($envFile);
    if ($gid !== false && \function_exists('posix_getgrgid')) {
        $grp = @\posix_getgrgid($gid);
        $sockGroup = \is_array($grp) ? (string) $grp['name'] : '';
    }
}

(new \ApiGoat\Realtime\Server($host, $port, $sock, $log, $sockGroup))->run();
