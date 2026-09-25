<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops\Server;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage of RT/bin/ops-collect.sh, the root-cron collector for
 * the "snapshot" server_source (Task 0 ruling): actually RUNS the script on
 * this host (as the current, non-root user — every probe must tolerate
 * missing privilege/tools, not just missing tools) and asserts the file it
 * writes decodes to the shape SnapshotFileSource::normalize() expects.
 *
 * Skipped outright when this host has neither `bash` nor a readable
 * /proc/loadavg (R4's brief: the probes are Linux-specific and the test
 * environment may not be Linux at all).
 */
final class OpsCollectShTest extends TestCase
{
    private string $script;

    private string $outPath;

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__, 3) . '/bin/ops-collect.sh';
        $this->outPath = \sys_get_temp_dir() . '/ops-collect-sh-test-' . \uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @\unlink($this->outPath);
    }

    private function bashAvailable(): bool
    {
        $out = [];
        $code = 0;
        @\exec('command -v bash 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }

    private function procLoadavgAvailable(): bool
    {
        return \is_readable('/proc/loadavg');
    }

    public function test_script_exists_and_is_executable_or_at_least_readable(): void
    {
        $this->assertFileExists($this->script);
    }

    public function test_running_the_script_produces_a_valid_normalized_snapshot(): void
    {
        if (!$this->bashAvailable() || !$this->procLoadavgAvailable()) {
            $this->markTestSkipped('bash or /proc/loadavg unavailable on this host');
        }

        $cmd = 'bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($this->outPath) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        \exec($cmd, $output, $exitCode);

        $this->assertSame(0, $exitCode, "ops-collect.sh exited non-zero. Output:\n" . \implode("\n", $output));
        $this->assertFileExists($this->outPath, 'ops-collect.sh did not write the output file');

        $raw = \file_get_contents($this->outPath);
        $decoded = \json_decode($raw, true);

        $this->assertIsArray($decoded, "ops-collect.sh did not write valid JSON:\n{$raw}");

        foreach (['load1', 'mem_pct', 'disk_pct', 'services', 'f2b_banned', 'f2b', 'auth', 'at'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "missing key '{$key}' in: {$raw}");
        }

        $this->assertIsNumeric($decoded['load1']);
        $this->assertIsNumeric($decoded['mem_pct']);
        $this->assertIsNumeric($decoded['disk_pct']);
        $this->assertIsArray($decoded['services']);
        foreach ($decoded['services'] as $name => $up) {
            $this->assertIsString($name);
            $this->assertIsBool($up, "service '{$name}' value is not a JSON boolean");
        }
        $this->assertIsInt($decoded['f2b_banned']);
        $this->assertIsArray($decoded['f2b']);
        foreach ($decoded['f2b'] as $jail => $n) {
            $this->assertIsString($jail);
            $this->assertIsInt($n, "f2b jail '{$jail}' value is not a JSON integer");
        }
        $this->assertIsArray($decoded['auth']);
        foreach (['ssh_failed', 'ssh_accepted', 'window_h'] as $key) {
            $this->assertArrayHasKey($key, $decoded['auth']);
            $this->assertIsInt($decoded['auth'][$key], "auth.{$key} is not a JSON integer");
        }
        $this->assertIsInt($decoded['at']);
        $this->assertGreaterThan(0, $decoded['at']);
        $this->assertLessThanOrEqual(\time() + 5, $decoded['at']);

        $perms = \substr(\sprintf('%o', \fileperms($this->outPath)), -4);
        $this->assertSame('0644', $perms, 'ops-collect.sh must chmod the output file 0644');
    }

    public function test_missing_output_path_argument_fails_fast(): void
    {
        if (!$this->bashAvailable()) {
            $this->markTestSkipped('bash unavailable on this host');
        }

        $cmd = 'bash ' . \escapeshellarg($this->script) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        \exec($cmd, $output, $exitCode);

        $this->assertNotSame(0, $exitCode, 'ops-collect.sh should fail fast without an output-path argument');
    }
}
