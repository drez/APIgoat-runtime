<?php

namespace ApiGoat\Tests\Utility;

use ApiGoat\Utility\BuilderMenus;
use PHPUnit\Framework\TestCase;

/** The topbar never shows a raw route key ("Dashboard/finance"). */
final class BuilderMenusReadableRouteTest extends TestCase
{
    public function test_route_is_made_readable(): void
    {
        $this->assertSame('Dashboard › YearEnd', BuilderMenus::readableRoute('Dashboard/yearEnd'));
        $this->assertSame('Billing', BuilderMenus::readableRoute('Billing'));
        $this->assertSame('Performance › Dashboard', BuilderMenus::readableRoute('/Performance/dashboard/'));
    }
}
