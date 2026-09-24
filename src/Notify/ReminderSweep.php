<?php

namespace ApiGoat\Notify;

/**
 * Scan a date window, skip rows already reminded, send, stamp, return a count.
 *
 * Four projects wrote this skeleton independently:
 *
 *   vidifye     Domains\Cron\ExpiryNotifier — exact-day windows, no
 *               idempotency at all (a second run the same day re-sends)
 *   apichatbot  scripts/key_expiry_reminders.php — a stamp column
 *               (expiry_reminded_at) and a --dry-run flag
 *   apicrm      Domains\Invoice\{ReminderScanner,ReminderSchedule} — an
 *               offset list and a log table for idempotency
 *   aexpert     the followup_state/state columns driving ClientServiceWrapper
 *
 * Both idempotency mechanisms are supported because both are legitimate: a
 * stamp column is one bit ("reminded"), a log table is one row per offset and
 * is what a multi-offset schedule needs.
 *
 * Every send fails soft — one bad address must never abort the batch.
 */
final class ReminderSweep
{
    /**
     * @param array<string,mixed> $spec see NotifyManifest / config/Built/notify.php:
     *   table          string  model base name, e.g. 'Invoice'
     *   date_column    string  PhpName of the anchor date, e.g. 'DueDate'
     *   offsets        int[]   days relative to the anchor (negative = before)
     *   stamp_column   string  PhpName of a "reminded at" column (optional)
     *   log_table      string  model base name of a per-offset log (optional)
     *   recipient      callable(object $row): string[]
     *   compose        callable(object $row, int $offset): array{subject:string, html:string}
     *   filter         callable(object $query): void  (optional extra criteria)
     *   send           callable(string $to, string $subject, string $html, array $opts): bool
     *                  (optional; default Notify\Mailer::send)
     * @param bool $dryRun report what would be sent, send nothing
     * @return array{scanned:int, sent:int, mails:int, locked?:bool}
     *         locked=true: another run of the same sweep holds the claim; nothing was done.
     */
    public static function run(array $spec, ?\DateTimeInterface $today = null, bool $dryRun = false): array
    {
        $today = $today ?: new \DateTimeImmutable('today');
        $stats = ['scanned' => 0, 'sent' => 0, 'mails' => 0];

        $queryClass = '\\App\\' . self::modelName($spec) . 'Query';
        if (!\class_exists($queryClass)) {
            \error_log('ReminderSweep: no model for ' . self::modelName($spec));

            return $stats;
        }

        // Claim the sweep: two overlapping cron runs would both read "not yet
        // sent" and both mail. A non-blocking exclusive lock per sweep makes
        // the second run a no-op. A dry run sends nothing and needs no claim.
        $lock = null;
        if (!$dryRun) {
            $lock = self::claim($spec);
            if ($lock === false) {
                $stats['locked'] = true;

                return $stats;
            }
        }
        try {
            return self::sweep($spec, $queryClass, $today, $dryRun, $stats);
        } finally {
            if (\is_resource($lock)) {
                @\flock($lock, LOCK_UN);
                @\fclose($lock);
            }
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @param array{scanned:int, sent:int, mails:int} $stats
     * @return array{scanned:int, sent:int, mails:int}
     */
    private static function sweep(array $spec, string $queryClass, \DateTimeInterface $today, bool $dryRun, array $stats): array
    {
        $send = (isset($spec['send']) && \is_callable($spec['send']))
            ? $spec['send']
            : [Mailer::class, 'send'];

        $query = $queryClass::create();
        if (isset($spec['filter']) && \is_callable($spec['filter'])) {
            ($spec['filter'])($query);
        }

        $offsets     = \array_map('intval', (array) ($spec['offsets'] ?? [0]));
        $stampColumn = (string) ($spec['stamp_php'] ?? $spec['stamp_column'] ?? '');
        $dateGetter  = 'get' . (string) ($spec['date_php'] ?? $spec['date_column']);

        foreach ($query->find() as $row) {
            $stats['scanned']++;

            if (!\method_exists($row, $dateGetter)) {
                continue;
            }
            $anchor = $row->{$dateGetter}('Y-m-d');
            if (!$anchor) {
                continue;
            }

            // A stamp column is a single bit: once set, this row is done.
            if ($stampColumn !== '' && self::isStamped($row, $stampColumn)) {
                continue;
            }

            $due = ReminderSchedule::due($anchor, $offsets, $today, self::sentOffsets($spec, $row));
            if ($due === []) {
                continue;
            }

            $recipients = self::recipients($spec, $row);
            if ($recipients === []) {
                continue;
            }

            $anySent = false;
            foreach ($due as $offset) {
                // Per offset: a success on an EARLIER offset must not log a
                // later offset whose sends all failed as delivered.
                $offsetSent = false;
                $msg = ($spec['compose'])($row, $offset);
                foreach ($recipients as $to) {
                    if ($dryRun) {
                        $stats['mails']++;
                        $offsetSent = true;
                        continue;
                    }
                    if ($send($to, (string) $msg['subject'], (string) $msg['html'], $msg['opts'] ?? [])) {
                        $stats['mails']++;
                        $offsetSent = true;
                    }
                }
                // Log the offset only when something actually went out, so a
                // total send failure is retried on the next run instead of
                // being recorded as delivered.
                if ($offsetSent && !$dryRun) {
                    self::logOffset($spec, $row, $offset);
                }
                $anySent = $anySent || $offsetSent;
            }

            if ($anySent) {
                $stats['sent']++;
                if ($stampColumn !== '' && !$dryRun) {
                    $setter = 'set' . $stampColumn;
                    if (\method_exists($row, $setter)) {
                        $row->{$setter}(\date('Y-m-d H:i:s'));
                        $row->save();
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Propel PhpName of the swept table.
     *
     * with_notify emits table_php alongside the snake_case table name because
     * Propel's PhpName generation is authoritative and a project can override
     * it per table — deriving it here by re-implementing ucwords() would be a
     * second, silently-diverging source of truth. The snake_case fallback is
     * for a hand-written spec that predates the emitted field.
     *
     * @param array<string,mixed> $spec
     */
    public static function modelName(array $spec): string
    {
        return (string) ($spec['table_php'] ?? $spec['table'] ?? '');
    }

    /** @param array<string,mixed> $spec */
    public static function logModelName(array $spec): string
    {
        return (string) ($spec['log_php'] ?? $spec['log_table'] ?? '');
    }

    /**
     * Exclusive, non-blocking lock for one sweep (model + log/stamp target).
     *
     * @param array<string,mixed> $spec
     * @return resource|false|null  resource = held; false = someone else holds
     *         it; null = no lock dir/flock available (run unclaimed, as before)
     */
    private static function claim(array $spec)
    {
        $base = \defined('_BASE_DIR') ? \rtrim(\_BASE_DIR, '/\\') . DIRECTORY_SEPARATOR . 'tmp' : \sys_get_temp_dir();
        if (!\is_dir($base) || !\is_writable($base)) {
            $base = \sys_get_temp_dir();
        }
        $name = \preg_replace('/[^A-Za-z0-9_]/', '_', self::modelName($spec) . '_'
            . self::logModelName($spec) . '_' . (string) ($spec['stamp_php'] ?? $spec['stamp_column'] ?? ''));
        $path = $base . DIRECTORY_SEPARATOR . 'reminder-sweep-' . $name . '.lock';
        $fh = @\fopen($path, 'c');
        if ($fh === false) {
            return null;
        }
        if (!@\flock($fh, LOCK_EX | LOCK_NB)) {
            @\fclose($fh);

            return false;
        }

        return $fh;
    }

    private static function isStamped(object $row, string $stampColumn): bool
    {
        $getter = 'get' . $stampColumn;

        return \method_exists($row, $getter) && $row->{$getter}() !== null;
    }

    /** @return string[] */
    private static function recipients(array $spec, object $row): array
    {
        if (!isset($spec['recipient']) || !\is_callable($spec['recipient'])) {
            return [];
        }
        $out = [];
        foreach ((array) ($spec['recipient'])($row) as $addr) {
            $addr = \strtolower(\trim((string) $addr));
            if ($addr !== '' && \filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[$addr] = $addr;   // dedupe: a contact address and a
                                       // per-user reminder address are often
                                       // the same person
            }
        }

        return \array_values($out);
    }

    /** @return int[] offsets already logged for this row */
    private static function sentOffsets(array $spec, object $row): array
    {
        $logTable = self::logModelName($spec);
        if ($logTable === '') {
            return [];
        }
        $queryClass = '\\App\\' . $logTable . 'Query';
        if (!\class_exists($queryClass)) {
            return [];
        }
        try {
            $pkGetter = 'getId' . self::modelName($spec);
            $filter   = 'filterById' . self::modelName($spec);
            $rows = $queryClass::create()->{$filter}($row->{$pkGetter}())->find();
            $out = [];
            foreach ($rows as $r) {
                $out[] = (int) $r->getOffsetDays();
            }

            return $out;
        } catch (\Throwable $e) {
            // Fail CLOSED here, unlike the quota: if the already-sent set
            // cannot be read, treating it as empty would re-send every
            // reminder on every run. Reporting "all offsets sent" instead
            // skips this row until the log is readable again.
            \error_log('ReminderSweep: cannot read ' . $logTable . ' — ' . $e->getMessage());

            return \array_map('intval', (array) ($spec['offsets'] ?? []));
        }
    }

    private static function logOffset(array $spec, object $row, int $offset): void
    {
        $logTable = self::logModelName($spec);
        if ($logTable === '') {
            return;
        }
        $class = '\\App\\' . $logTable;
        if (!\class_exists($class)) {
            return;
        }
        try {
            $log      = new $class();
            $setter   = 'setId' . self::modelName($spec);
            $pkGetter = 'getId' . self::modelName($spec);
            $log->{$setter}($row->{$pkGetter}());
            $log->setOffsetDays($offset);
            if (\method_exists($log, 'setStatus')) {
                $log->setStatus('Sent');
            }
            $log->save();
        } catch (\Throwable $e) {
            \error_log('ReminderSweep: cannot log offset — ' . $e->getMessage());
        }
    }
}
