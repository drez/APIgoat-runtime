<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * Amber / red marking for the Performance dashboard's numbers: a value at
 * or above its amber threshold gets a cl-status-normal pill, at or above
 * red a cl-status-high pill; a normal value stays plain text so problems
 * stand out. Existing pill variants only — no new colors.
 *
 * Server thresholds (memory, disk, load) follow the project's capacity
 * alert config (`config` rows alert_mem_pct / alert_disk_pct / alert_load1,
 * apigoatacc) when present: red = the alert, amber = 10 points below it
 * (half of it for load). Elsewhere the same defaults apply.
 */
final class Level
{
    /** metric => [amber, red], both inclusive */
    public const DEFAULTS = [
        'avg_ms'       => [250, 1000],
        'p95_ms'       => [1000, 2500],
        'rate_5xx_pct' => [0.11, 1.01], // > 0.1% / > 1% on a 2-decimal value
        'count_5xx'    => [1, 1],
        'slow_req_ms'  => [1000, 2500],
        'queries'      => [50, 200],
        'query_ms'     => [250, 1000],
        'mcp_ms'       => [1000, \INF],
        'denied'       => [1, \INF],
    ];

    public const SERVER_DEFAULTS = ['mem_pct' => 85.0, 'disk_pct' => 80.0, 'load1' => 4.0];

    /** @var array<string,array{0:float,1:float}> */
    private array $t;

    /** @param array<string,float> $server red thresholds for mem_pct / disk_pct / load1 */
    public function __construct(array $server = [])
    {
        $this->t = self::DEFAULTS;
        foreach (self::SERVER_DEFAULTS as $k => $red) {
            $red = (float) ($server[$k] ?? $red);
            $this->t[$k] = [$k === 'load1' ? $red / 2 : $red - 10, $red];
        }
    }

    /** Server thresholds from the `config` table's alert_* rows; defaults when absent. */
    public static function fromConfig(\PDO $pdo): self
    {
        $server = [];
        try {
            $rows = $pdo->query("SELECT config, value FROM config WHERE config IN ('alert_mem_pct','alert_disk_pct','alert_load1')")->fetchAll(\PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                if (\is_numeric(\trim((string) $v))) {
                    $server[\substr($k, 6)] = (float) $v;
                }
            }
        } catch (\Throwable $e) {
            // no config table: defaults
        }
        return new self($server);
    }

    public function level(string $metric, mixed $value): ?string
    {
        [$amber, $red] = $this->t[$metric];
        return self::of($value, $amber, $red);
    }

    public static function metric(string $metric, mixed $value): ?string
    {
        return (new self())->level($metric, $value);
    }

    /** 'high' (red), 'normal' (amber) or null; a non-numeric value is never marked. */
    public static function of(mixed $value, float $amber, float $red): ?string
    {
        if (!\is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        return $v >= $red ? 'high' : ($v >= $amber ? 'normal' : null);
    }

    /** The escaped text, wrapped in a pill when $level is set. */
    public static function pill(string $text, ?string $level): string
    {
        $t = \htmlspecialchars($text, \ENT_QUOTES);
        return $level === null ? $t : '<span class="cl-status cl-status-' . $level . '">' . $t . '</span>';
    }
}
