<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops\Dashboard;

use ApiGoat\Ops\Dashboard\Level;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    public function test_of_maps_value_to_amber_red_or_nothing(): void
    {
        $this->assertNull(Level::of(249, 250, 1000));
        $this->assertSame('normal', Level::of(250, 250, 1000), 'amber from the threshold up');
        $this->assertSame('high', Level::of(1000, 250, 1000));
        $this->assertNull(Level::of(null, 250, 1000));
        $this->assertNull(Level::of('', 250, 1000));
    }

    public function test_named_metric_uses_the_defaults(): void
    {
        $this->assertSame('high', Level::metric('p95_ms', 2500));
        $this->assertSame('normal', Level::metric('rate_5xx_pct', 0.2));
        $this->assertNull(Level::metric('rate_5xx_pct', 0.1));
        $this->assertSame('high', Level::metric('count_5xx', 1), 'any 5xx is red');
        $this->assertNull(Level::metric('count_5xx', 0));
    }

    public function test_server_thresholds_follow_alert_config_when_given(): void
    {
        $lv = new Level(['mem_pct' => 90.0, 'disk_pct' => 95.0, 'load1' => 8.0]);
        $this->assertNull($lv->level('mem_pct', 79));
        $this->assertSame('normal', $lv->level('mem_pct', 80), 'amber = 10 points below the alert');
        $this->assertSame('high', $lv->level('mem_pct', 90));
        $this->assertSame('normal', $lv->level('load1', 4), 'load amber = half the alert');
        $this->assertSame('high', $lv->level('disk_pct', 95));
    }

    public function test_pill_wraps_only_problem_values_and_escapes(): void
    {
        $this->assertSame('120', Level::pill('120', null));
        $this->assertSame('<span class="cl-status cl-status-high">&lt;b&gt;</span>', Level::pill('<b>', 'high'));
        $this->assertSame('<span class="cl-status cl-status-normal">300 ms</span>', Level::pill('300 ms', 'normal'));
    }
}
