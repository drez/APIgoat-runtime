<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops\Dashboard;

use ApiGoat\Ops\Dashboard\Kpi;
use PHPUnit\Framework\TestCase;

/** The one KPI tile every admin dashboard uses (audit 2026-10-01). */
final class KpiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('_')) {
            eval('function _($s) { return $s; }');
        }
    }

    public function test_plain_value_has_no_level_or_tone(): void
    {
        $h = Kpi::tile('Requests', 445);
        $this->assertStringContainsString('<div class="ops-kpi">', $h);
        $this->assertStringContainsString('<div class="ops-kpi-v">445</div>', $h);
        $this->assertStringNotContainsString('cl-status', $h);
    }

    public function test_level_colors_the_tile_instead_of_shrinking_the_number_into_a_pill(): void
    {
        $h = Kpi::tile('p95 latency', '1000 ms', null, 'high');
        $this->assertStringContainsString('class="ops-kpi ops-kpi--high"', $h);
        $this->assertStringContainsString('<div class="ops-kpi-v">1000 ms</div>', $h);
        $this->assertStringNotContainsString('cl-status', $h);
        $this->assertStringContainsString('ops-kpi--normal', Kpi::tile('x', 1, null, 'normal'));
    }

    public function test_missing_value_is_a_dash_with_caption(): void
    {
        $h = Kpi::tile('Memory', null, null, null, 'no server data');
        $this->assertStringContainsString('<div class="ops-kpi-v">—</div>', $h);
        $this->assertStringContainsString('<div class="ops-kpi-c">no server data</div>', $h);
    }

    public function test_good_tone_and_escaping(): void
    {
        $h = Kpi::tile('Collected <b>', '$5,000.00', null, null, '1 <i>overdue</i>', 'good');
        $this->assertStringContainsString('ops-kpi--good', $h);
        $this->assertStringContainsString('Collected &lt;b&gt;', $h);
        $this->assertStringContainsString('1 &lt;i&gt;overdue&lt;/i&gt;', $h);
    }
}
