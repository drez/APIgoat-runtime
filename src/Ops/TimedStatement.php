<?php

namespace ApiGoat\Ops;

/**
 * PDOStatement subclass that times every execute() and feeds
 * Security & Performance dashboards Task 3 (slow-query capture).
 *
 * Attached via `PDO::ATTR_STATEMENT_CLASS => [TimedStatement::class]` (a
 * ONE-element array — TimedStatement declares no constructor of its own, and
 * PDO's `pdo_stmt_instantiate()` throws "User-supplied statement does not
 * accept constructor arguments" if a constructor-args element is present at
 * all in that case, even an empty array; that two-element form is only for a
 * class that itself declares __construct(), as DebugPDOStatement does)
 * on the Propel connection — see ServerTimingMiddleware::process(), which is
 * the one place in this codebase that runs exactly once per web request
 * after the connection already exists (config/Built/propel.php, required
 * from config/legacy.php, opens it before the Slim middleware stack runs).
 * That set-up only runs when Config::enabled(), so a project that never
 * declared with_ops_monitor never installs this class at all — no per-query
 * overhead there.
 *
 * Spike finding (R1): PropelPDO ships its OWN configureStatementClass()
 * (DebugPDOStatement), but only calls it from useDebug($value) — and nothing
 * in this codebase ever calls PropelPDO::useDebug(true) (the 'debug' keys
 * grepped in config/settings.defaults.php are an unrelated app-level flag).
 * So the statement class PDO hands back is the built-in \PDOStatement
 * unless/until this class replaces it — extending \PDOStatement directly is
 * correct here, not \DebugPDOStatement.
 */
class TimedStatement extends \PDOStatement
{
    /**
     * Times parent::execute(), records it on QueryCounter, and queues a
     * slow-query row when the duration crosses Config::get('slow_query_ms').
     * Per the controller ruling, this never throws anything the parent
     * execute() wouldn't: only the instrumentation is try/caught, never the
     * parent call itself.
     */
    public function execute(?array $params = null): bool
    {
        // Re-entrancy guard: SlowQueryBuffer::flush()'s own INSERTs run on
        // this same connection. Without this check they would be timed and
        // (being INSERTs, not necessarily slow) merely counted here — but a
        // slow flush INSERT would re-queue itself into the very buffer it is
        // draining. Skip all instrumentation while a flush is in progress.
        if (SlowQueryBuffer::isFlushing()) {
            return parent::execute($params);
        }

        $start = \microtime(true);
        $result = parent::execute($params);
        $ms = (\microtime(true) - $start) * 1000;

        try {
            QueryCounter::inc($ms);

            if ($ms >= (float) Config::get('slow_query_ms')) {
                SlowQueryBuffer::queue((int) \round($ms), (string) $this->queryString);
            }
        } catch (\Throwable $e) {
            \error_log('[ops] TimedStatement instrumentation failed: ' . $e->getMessage());
        }

        return $result;
    }
}
