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

    /**
     * R18 (controller ruling, fix round 2): the symlink check further down
     * compares `realpath -e`'s (always absolute) result against the literal
     * path given -- a relative path can never equal that even with no
     * symlink involved, so it used to be refused with the wrong, misleading
     * "a symlink ... is involved" message. A relative path must instead be
     * refused up front, before the symlink check runs, with its own
     * message and a distinct exit code (2).
     */
    public function test_relative_output_path_is_refused_with_a_distinct_message_and_exit_code(): void
    {
        if (!$this->bashAvailable()) {
            $this->markTestSkipped('bash unavailable on this host');
        }

        $cwd = \sys_get_temp_dir() . '/ops-collect-sh-relpath-test-' . \uniqid();
        \mkdir($cwd, 0775, true);

        try {
            $relative = 'tmp/out2.json';
            $cmd = 'cd ' . \escapeshellarg($cwd) . ' && bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($relative) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);

            $this->assertSame(2, $exitCode, 'a relative output path must exit 2, not just non-zero');
            $joined = \implode("\n", $output);
            $this->assertStringContainsString(
                'ops-collect.sh: output path must be absolute: ' . $relative,
                $joined
            );
            $this->assertFileDoesNotExist($cwd . '/' . $relative);
            $this->assertDirectoryDoesNotExist($cwd . '/tmp', 'no directory should have been created either');
        } finally {
            foreach (\glob($cwd . '/tmp/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($cwd . '/tmp');
            @\rmdir($cwd);
        }
    }

    // ── R16 fix round 1, item 2: refuse to write through a symlinked ────
    // directory or over a non-regular-file output path. The script runs as
    // root but writes into a directory the jailed web user controls, so a
    // symlink swapped in there could otherwise redirect a root-owned write
    // anywhere on the filesystem.

    public function test_symlinked_out_dir_is_refused_and_nothing_is_written_at_the_target(): void
    {
        if (!$this->bashAvailable()) {
            $this->markTestSkipped('bash unavailable on this host');
        }

        $base = \sys_get_temp_dir() . '/ops-collect-sh-symlink-test-' . \uniqid();
        $realDir = $base . '/real';
        $linkDir = $base . '/link';
        \mkdir($realDir, 0775, true);
        \symlink($realDir, $linkDir);

        try {
            $target = $linkDir . '/out.json';
            $cmd = 'bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($target) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);

            $this->assertNotSame(0, $exitCode, 'ops-collect.sh must refuse a symlinked output directory');
            $this->assertNotEmpty($output, 'ops-collect.sh should explain the refusal on stderr');

            // Nothing must have been written through the symlink, at the
            // real target directory it points to, or at the literal
            // (symlinked) path either.
            $this->assertSame([], \array_diff(\scandir($realDir) ?: [], ['.', '..']), 'a file was written at the symlink target despite the refusal');
            $this->assertFileDoesNotExist($target);
        } finally {
            @\unlink($linkDir);
            foreach (\glob($realDir . '/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($realDir);
            @\rmdir($base);
        }
    }

    public function test_out_path_pre_existing_as_a_directory_is_refused(): void
    {
        if (!$this->bashAvailable()) {
            $this->markTestSkipped('bash unavailable on this host');
        }

        $dir = \sys_get_temp_dir() . '/ops-collect-sh-dirout-test-' . \uniqid();
        $target = $dir . '/out.json';
        \mkdir($target, 0775, true); // $target itself is a directory, not a file

        try {
            $cmd = 'bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($target) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);

            $this->assertNotSame(0, $exitCode, 'ops-collect.sh must refuse an output path that already exists as a directory');
            $this->assertNotEmpty($output, 'ops-collect.sh should explain the refusal on stderr');
            $this->assertTrue(\is_dir($target), 'the pre-existing directory must be left untouched');
        } finally {
            @\rmdir($target);
            @\rmdir($dir);
        }
    }
}
