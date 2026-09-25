<?php

namespace ApiGoat\Ops\Server;

/**
 * Reads the JSON snapshot a root cron job (RT/bin/ops-collect.sh) writes
 * inside the site's own open_basedir — the "snapshot" server_source (Task 0
 * ruling: prod is ISPConfig-jailed with no MySQL/root access for the app
 * user, but the server owner has root; a root cron writing into the app's
 * own open_basedir is the only channel available to hand it host data).
 *
 * snapshot() validates + casts the decoded JSON defensively: a half-written
 * file (in principle a reader could race the collector's atomic mv, though
 * mv within one filesystem is effectively instantaneous), a stale process
 * substituting garbage, or a hand-edited file with the wrong shape must
 * never throw into a request or cron job — only return null.
 *
 * Staleness is explicitly NOT a validity check (R4 controller ruling): a
 * snapshot whose `at` is old (the collector cron stopped running) is still
 * returned as-is. Showing the age is the dashboard's job, not this class's.
 */
final class SnapshotFileSource implements Source
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function snapshot(): ?array
    {
        if (!\is_file($this->path) || !\is_readable($this->path)) {
            return null;
        }

        $raw = @\file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return self::normalize($decoded);
    }

    /**
     * Validate + cast a decoded snapshot payload. Pure (no filesystem), so
     * it's unit-tested directly against fixture arrays. Returns null when a
     * required top-level or `auth` sub-key is missing, or when `services` /
     * `f2b` / `auth` are present but not arrays.
     *
     * @param array<mixed,mixed> $d
     * @return array{load1:float,mem_pct:float,disk_pct:float,services:array<string,bool>,f2b_banned:int,f2b:array<string,int>,auth:array{ssh_failed:int,ssh_accepted:int,window_h:int},at:int}|null
     */
    public static function normalize(array $d): ?array
    {
        foreach (['load1', 'mem_pct', 'disk_pct', 'services', 'f2b_banned', 'f2b', 'auth', 'at'] as $key) {
            if (!\array_key_exists($key, $d)) {
                return null;
            }
        }
        if (!\is_array($d['services']) || !\is_array($d['f2b']) || !\is_array($d['auth'])) {
            return null;
        }
        foreach (['ssh_failed', 'ssh_accepted', 'window_h'] as $key) {
            if (!\array_key_exists($key, $d['auth'])) {
                return null;
            }
        }

        $services = [];
        foreach ($d['services'] as $name => $up) {
            $services[(string) $name] = (bool) $up;
        }

        $f2b = [];
        foreach ($d['f2b'] as $jail => $n) {
            $f2b[(string) $jail] = (int) $n;
        }

        return [
            'load1'      => (float) $d['load1'],
            'mem_pct'    => (float) $d['mem_pct'],
            'disk_pct'   => (float) $d['disk_pct'],
            'services'   => $services,
            'f2b_banned' => (int) $d['f2b_banned'],
            'f2b'        => $f2b,
            'auth'       => [
                'ssh_failed'   => (int) $d['auth']['ssh_failed'],
                'ssh_accepted' => (int) $d['auth']['ssh_accepted'],
                'window_h'     => (int) $d['auth']['window_h'],
            ],
            'at'         => (int) $d['at'],
        ];
    }
}
