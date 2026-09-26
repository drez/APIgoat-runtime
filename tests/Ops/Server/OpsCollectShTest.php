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

    // ── Final review M2: the auth-file fallback counts only the last 24h
    // and counts "Failed password" only (an invalid-user attempt logs both
    // "Invalid user" and "Failed password" — counting both doubled it).

    public function test_auth_log_fallback_counts_only_the_last_24h_and_never_double_counts(): void
    {
        if (!$this->bashAvailable() || !$this->procLoadavgAvailable()) {
            $this->markTestSkipped('bash or /proc/loadavg unavailable on this host');
        }

        $log = \tempnam(\sys_get_temp_dir(), 'authlog');
        // Written in UTC and the script run with TZ=UTC: syslog stamps local
        // time, and the script compares against its own local clock.
        $bsd = static fn (int $ts): string => \gmdate('M ', $ts) . \sprintf('%2d', (int) \gmdate('j', $ts)) . \gmdate(' H:i:s', $ts);
        $iso = static fn (int $ts): string => \gmdate('Y-m-d\TH:i:s.000000+00:00', $ts);
        $now = \time();
        $old = $now - 3 * 86400;
        $recent = $now - 3600;
        \file_put_contents($log, \implode("\n", [
            // 3 days old: excluded (both formats)
            $bsd($old) . ' host sshd[1]: Failed password for root from 1.2.3.4 port 1 ssh2',
            $iso($old) . ' host sshd[1]: Accepted publickey for fred from 1.2.3.4 port 1 ssh2',
            // last hour, traditional: one invalid-user attempt = 2 lines, counted once
            $bsd($recent) . ' host sshd[2]: Invalid user admin from 5.6.7.8 port 2',
            $bsd($recent) . ' host sshd[2]: Failed password for invalid user admin from 5.6.7.8 port 2 ssh2',
            // last hour, RFC 3339
            $iso($recent) . ' host sshd[3]: Failed password for root from 5.6.7.8 port 3 ssh2',
            $iso($recent) . ' host sshd[4]: Accepted publickey for fred from 9.9.9.9 port 4 ssh2',
            // not sshd: ignored
            $iso($recent) . ' host sudo[5]: Failed password something',
        ]) . "\n");

        try {
            $cmd = 'TZ=UTC OPS_COLLECT_AUTH_LOG=' . \escapeshellarg($log) . ' bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($this->outPath) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);
            $this->assertSame(0, $exitCode, \implode("\n", $output));

            $auth = \json_decode((string) \file_get_contents($this->outPath), true)['auth'];
            $this->assertSame(2, $auth['ssh_failed']);
            $this->assertSame(1, $auth['ssh_accepted']);
        } finally {
            @\unlink($log);
        }
    }

    // ── Final review M1: running as root into a directory someone else
    // owns, the write itself runs AS the directory owner via runuser, so a
    // symlink swapped in after the pre-flight check can't redirect a root
    // write. Simulated with PATH stubs for `id` (reports uid 0) and
    // `runuser` (records its arguments, then runs the command unprivileged).

    public function test_root_run_into_a_non_root_directory_writes_via_runuser_as_the_owner(): void
    {
        if (!$this->bashAvailable() || !$this->procLoadavgAvailable()) {
            $this->markTestSkipped('bash or /proc/loadavg unavailable on this host');
        }
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('needs a non-root test user owning the output directory');
        }

        $stubs = \sys_get_temp_dir() . '/ops-collect-sh-stubs-' . \uniqid();
        \mkdir($stubs, 0775, true);
        // The output directory must be owned by this (non-root) test user —
        // sys_get_temp_dir() itself is root-owned.
        $this->outPath = $stubs . '/out.json';
        $argLog = $stubs . '/runuser.args';
        \file_put_contents($stubs . '/id', "#!/bin/sh\necho 0\n");
        \file_put_contents($stubs . '/runuser', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . \escapeshellarg($argLog) . "\nshift 3\nexec \"\$@\"\n");
        \chmod($stubs . '/id', 0755);
        \chmod($stubs . '/runuser', 0755);

        try {
            $cmd = 'PATH=' . \escapeshellarg($stubs) . ':"$PATH" bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($this->outPath) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);

            $this->assertSame(0, $exitCode, \implode("\n", $output));
            $this->assertFileExists($argLog, 'the write did not go through runuser');
            $args = \file($argLog, \FILE_IGNORE_NEW_LINES);
            $owner = \posix_getpwuid(\fileowner(\dirname($this->outPath)))['name'];
            $this->assertSame(['-u', $owner, '--', 'sh', '-c'], \array_slice($args, 0, 5));
            $this->assertIsArray(\json_decode((string) \file_get_contents($this->outPath), true));
            $this->assertSame('0644', \substr(\sprintf('%o', \fileperms($this->outPath)), -4));
        } finally {
            @\unlink($argLog);
            @\unlink($this->outPath);
            @\unlink($stubs . '/id');
            @\unlink($stubs . '/runuser');
            @\rmdir($stubs);
        }
    }

    public function test_non_root_run_writes_in_process_without_runuser(): void
    {
        if (!$this->bashAvailable() || !$this->procLoadavgAvailable()) {
            $this->markTestSkipped('bash or /proc/loadavg unavailable on this host');
        }
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('must run as a non-root user');
        }

        $stubs = \sys_get_temp_dir() . '/ops-collect-sh-stubs-' . \uniqid();
        \mkdir($stubs, 0775, true);
        $argLog = $stubs . '/runuser.args';
        \file_put_contents($stubs . '/runuser', "#!/bin/sh\ntouch " . \escapeshellarg($argLog) . "\nexit 1\n");
        \chmod($stubs . '/runuser', 0755);

        try {
            $cmd = 'PATH=' . \escapeshellarg($stubs) . ':"$PATH" bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($this->outPath) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            \exec($cmd, $output, $exitCode);

            $this->assertSame(0, $exitCode, \implode("\n", $output));
            $this->assertFileDoesNotExist($argLog, 'a non-root run must not go through runuser');
            $this->assertFileExists($this->outPath);
        } finally {
            @\unlink($argLog);
            @\unlink($stubs . '/runuser');
            @\rmdir($stubs);
        }
    }

    /**
     * `gc fail2ban` coverage: the collector records which logs the gc-probe
     * jail watches (the deploy advisory reads it — a jailed deploy user cannot
     * ask fail2ban). Hostile path characters never reach the JSON.
     */
    public function test_f2b_logs_lists_the_gc_probe_jail_logs(): void
    {
        if (!$this->bashAvailable() || !$this->procLoadavgAvailable()) {
            $this->markTestSkipped('needs bash + /proc/loadavg');
        }
        $stubs = \sys_get_temp_dir() . '/ops-collect-f2b-' . \uniqid();
        \mkdir($stubs, 0775, true);
        $this->outPath = $stubs . '/out.json';
        \file_put_contents($stubs . '/fail2ban-client', <<<'SH'
#!/bin/sh
case "$*" in
  "status") printf 'Status\n|- Number of jail:\t2\n`- Jail list:\tsshd, gc-probe\n' ;;
  "status sshd") printf '`- Currently banned:\t1\n' ;;
  "status gc-probe") printf '`- Currently banned:\t3\n' ;;
  "get gc-probe logpath") printf 'Current monitored log file(s):\n|- /var/www/clients/client1/web134/log/access.log\n`- /var/www/clients/client1/web78/log/access.log"],"x":["\n' ;;
esac
SH);
        \chmod($stubs . '/fail2ban-client', 0755);
        try {
            $cmd = 'PATH=' . \escapeshellarg($stubs) . ':"$PATH" bash ' . \escapeshellarg($this->script) . ' ' . \escapeshellarg($this->outPath) . ' 2>&1';
            \exec($cmd, $out, $code);
            $this->assertSame(0, $code, \implode("\n", $out));
            $d = \json_decode((string) \file_get_contents($this->outPath), true);
            $this->assertIsArray($d, 'still valid JSON with a hostile path in the jail');
            $this->assertSame(['sshd' => 1, 'gc-probe' => 3], $d['f2b']);
            $this->assertSame([
                '/var/www/clients/client1/web134/log/access.log',
                '/var/www/clients/client1/web78/log/access.logx',
            ], $d['f2b_logs']['gc-probe']);
        } finally {
            @\unlink($stubs . '/fail2ban-client');
            @\unlink($this->outPath);
            @\rmdir($stubs);
        }
    }
}
