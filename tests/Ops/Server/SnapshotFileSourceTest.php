<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops\Server;

use ApiGoat\Ops\Server\SnapshotFileSource;
use ApiGoat\Ops\Server\Source;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Ops/Server/Source.php';
require_once __DIR__ . '/../../../src/Ops/Server/SnapshotFileSource.php';

/**
 * SnapshotFileSource reads the JSON a root cron job (RT/bin/ops-collect.sh)
 * writes inside the site's open_basedir ("snapshot" server_source — Task 0
 * ruling: prod is ISPConfig-jailed, no MySQL/root for the app user). This
 * is pure filesystem + json_decode, no Propel/PDO, so it needs no database
 * (R1) and is fully covered here.
 */
final class SnapshotFileSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/snapshot_file_source_test_' . \uniqid();
        \mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->dir);
    }

    private function validPayload(array $overrides = []): array
    {
        return \array_merge([
            'load1'      => 0.42,
            'mem_pct'    => 55.5,
            'disk_pct'   => 61.0,
            'services'   => ['nginx' => true, 'mariadb' => false],
            'f2b_banned' => 3,
            'f2b'        => ['sshd' => 3],
            'auth'       => ['ssh_failed' => 12, 'ssh_accepted' => 4, 'window_h' => 24],
            'at'         => \time(),
        ], $overrides);
    }

    private function writeSnapshot(array $payload): string
    {
        $path = $this->dir . '/snap.json';
        \file_put_contents($path, \json_encode($payload));

        return $path;
    }

    public function test_implements_source(): void
    {
        $source = new SnapshotFileSource($this->dir . '/does-not-exist.json');
        $this->assertInstanceOf(Source::class, $source);
    }

    public function test_missing_file_returns_null(): void
    {
        $source = new SnapshotFileSource($this->dir . '/nope.json');
        $this->assertNull($source->snapshot());
    }

    public function test_invalid_json_returns_null(): void
    {
        $path = $this->dir . '/bad.json';
        \file_put_contents($path, '{not valid json');

        $source = new SnapshotFileSource($path);
        $this->assertNull($source->snapshot());
    }

    public function test_json_that_is_not_an_object_or_array_returns_null(): void
    {
        $path = $this->dir . '/scalar.json';
        \file_put_contents($path, '"just a string"');

        $source = new SnapshotFileSource($path);
        $this->assertNull($source->snapshot());
    }

    public function test_missing_required_key_returns_null(): void
    {
        foreach (['load1', 'mem_pct', 'disk_pct', 'services', 'f2b_banned', 'f2b', 'auth', 'at'] as $missing) {
            $payload = $this->validPayload();
            unset($payload[$missing]);
            $path = $this->writeSnapshot($payload);

            $source = new SnapshotFileSource($path);
            $this->assertNull($source->snapshot(), "expected null when '{$missing}' is missing");
        }
    }

    public function test_missing_auth_subkey_returns_null(): void
    {
        foreach (['ssh_failed', 'ssh_accepted', 'window_h'] as $missing) {
            $payload = $this->validPayload();
            unset($payload['auth'][$missing]);
            $path = $this->writeSnapshot($payload);

            $source = new SnapshotFileSource($path);
            $this->assertNull($source->snapshot(), "expected null when auth.{$missing} is missing");
        }
    }

    public function test_non_array_services_or_f2b_or_auth_returns_null(): void
    {
        foreach (['services', 'f2b', 'auth'] as $key) {
            $payload = $this->validPayload([$key => 'not-an-array']);
            $path = $this->writeSnapshot($payload);

            $source = new SnapshotFileSource($path);
            $this->assertNull($source->snapshot(), "expected null when '{$key}' is not an array");
        }
    }

    public function test_valid_snapshot_is_normalized_with_expected_types(): void
    {
        $at = \time() - 60;
        $path = $this->writeSnapshot($this->validPayload([
            'load1'    => '0.75', // string in the file (bash-authored JSON quirk) must still cast
            'mem_pct'  => 42,
            'disk_pct' => '61',
            'at'       => $at,
        ]));

        $source = new SnapshotFileSource($path);
        $snap = $source->snapshot();

        $this->assertIsArray($snap);
        $this->assertSame(
            ['load1', 'mem_pct', 'disk_pct', 'services', 'f2b_banned', 'f2b', 'auth', 'at'],
            \array_keys($snap)
        );
        $this->assertIsFloat($snap['load1']);
        $this->assertSame(0.75, $snap['load1']);
        $this->assertIsFloat($snap['mem_pct']);
        $this->assertSame(42.0, $snap['mem_pct']);
        $this->assertIsFloat($snap['disk_pct']);
        $this->assertSame(61.0, $snap['disk_pct']);
        $this->assertSame(['nginx' => true, 'mariadb' => false], $snap['services']);
        $this->assertIsInt($snap['f2b_banned']);
        $this->assertSame(3, $snap['f2b_banned']);
        $this->assertSame(['sshd' => 3], $snap['f2b']);
        $this->assertIsInt($snap['f2b']['sshd']);
        $this->assertSame(['ssh_failed' => 12, 'ssh_accepted' => 4, 'window_h' => 24], $snap['auth']);
        $this->assertIsInt($snap['auth']['ssh_failed']);
        $this->assertIsInt($snap['at']);
        $this->assertSame($at, $snap['at']);
    }

    public function test_stale_at_is_still_returned_not_dropped(): void
    {
        // R4 (controller ruling): staleness is a dashboard concern, not a
        // validity gate — a snapshot whose `at` is older than 2 hours (the
        // collector cron stopped running) must still come back so the
        // caller can show the age, rather than silently disappearing.
        $stale = \time() - 3 * 3600;
        $path = $this->writeSnapshot($this->validPayload(['at' => $stale]));

        $source = new SnapshotFileSource($path);
        $snap = $source->snapshot();

        $this->assertIsArray($snap);
        $this->assertSame($stale, $snap['at']);
    }

    public function test_normalize_is_the_same_pure_static_helper_snapshot_uses(): void
    {
        $payload = $this->validPayload();
        $normalized = SnapshotFileSource::normalize($payload);

        $this->assertIsArray($normalized);
        $this->assertSame(0.42, $normalized['load1']);
    }

    public function test_normalize_of_empty_array_returns_null(): void
    {
        $this->assertNull(SnapshotFileSource::normalize([]));
    }
}
