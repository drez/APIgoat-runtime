<?php

namespace ApiGoat\Tests\Utility;

use ApiGoat\Utility\Timing;
use PHPUnit\Framework\TestCase;

final class TimingTest extends TestCase
{
    protected function setUp(): void
    {
        Timing::reset();
    }

    protected function tearDown(): void
    {
        Timing::reset();
    }

    public function testSumsSpansPerNameAndFormatsHeader(): void
    {
        Timing::add('openai', 300.25);
        Timing::add('openai', 200.0);
        Timing::add('db', 12.5);
        self::assertSame(['openai' => 500.25, 'db' => 12.5], Timing::spans());
        self::assertSame('app;dur=750.0, openai;dur=500.3, db;dur=12.5', Timing::header(750.0));
    }

    public function testHeaderWithNoSpans(): void
    {
        self::assertSame('app;dur=42.0', Timing::header(42));
    }

    public function testResetClearsEverything(): void
    {
        Timing::add('openai', 1.0);
        Timing::reset();
        self::assertSame([], Timing::spans());
    }

    /**
     * Span names reach the header verbatim, so a name carrying a comma,
     * semicolon or newline could forge extra metrics — or, with CRLF, a whole
     * extra response header. Names are app-authored today, but the sanitiser
     * is what keeps that true if one ever comes from config or a route param.
     */
    public function testSanitisesSpanNames(): void
    {
        Timing::add("ev;dur=1, injected", 5.0);
        Timing::add("crlf\r\nX-Evil: 1", 5.0);
        // `-` is a legal header token character and survives; `,`, `;`, `=`,
        // space and CRLF — the ones that could forge a metric or a header —
        // do not.
        self::assertSame('app;dur=1.0, evdur1injected;dur=5.0, crlfX-Evil1;dur=5.0', Timing::header(1));
    }

    /** A name that sanitises down to nothing must not emit a bare ";dur=". */
    public function testDropsSpansWhoseNameSanitisesAway(): void
    {
        Timing::add(';;;', 5.0);
        self::assertSame('app;dur=1.0', Timing::header(1));
    }
}
