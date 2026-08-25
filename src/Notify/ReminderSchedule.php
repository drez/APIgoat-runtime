<?php

namespace ApiGoat\Notify;

/**
 * Pure scheduling decision, generalized from p/apicrm's
 * App\Domains\Invoice\ReminderSchedule.
 *
 * Given an anchor date (a due date, an expiry, a start date), the configured
 * offsets in days, today, and the offsets already sent for that row, return
 * the offsets whose send date has arrived and which have not fired yet.
 *
 * Catching up after a missed cron day deliberately returns several at once —
 * the caller dedups on the already-sent set, so each fires exactly once rather
 * than being skipped forever because its exact day was missed. That is the
 * behaviour the three hand-written sweeps differed on: apicrm caught up,
 * vidifye and apichatbot matched only an exact day window and silently lost a
 * reminder whenever cron did not run that day.
 */
final class ReminderSchedule
{
    /**
     * @param int[] $offsets       days relative to the anchor; negative = before
     * @param int[] $sentOffsets   offsets already fired for this row
     * @return int[] offsets that are due now, ascending
     */
    public static function due(
        string $anchorDate,
        array $offsets,
        \DateTimeInterface $today,
        array $sentOffsets = []
    ): array {
        $todayTs = \strtotime($today->format('Y-m-d'));
        if ($todayTs === false) {
            return [];
        }

        $out = [];
        foreach ($offsets as $offset) {
            $offset = (int) $offset;
            if (\in_array($offset, $sentOffsets, true)) {
                continue;
            }
            $sign   = $offset >= 0 ? '+' . $offset : (string) $offset;
            $sendTs = \strtotime($anchorDate . ' ' . $sign . ' days');
            if ($sendTs !== false && $sendTs <= $todayTs) {
                $out[] = $offset;
            }
        }
        \sort($out);

        return $out;
    }

    /**
     * Inclusive [start, end] unix bounds of the day $offset days from today,
     * for the "exact window" style of sweep (vidifye's expiring-in-7-days).
     *
     * @return array{0:int,1:int}
     */
    public static function dayWindow(int $offset, ?\DateTimeInterface $today = null): array
    {
        $base = $today ? $today->format('Y-m-d') : 'today';
        $sign = $offset >= 0 ? '+' . $offset : (string) $offset;

        return [
            (int) \strtotime($base . ' ' . $sign . ' days 00:00:00'),
            (int) \strtotime($base . ' ' . $sign . ' days 23:59:59'),
        ];
    }
}
