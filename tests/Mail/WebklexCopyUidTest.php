<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\WebklexTransport;
use PHPUnit\Framework\TestCase;

final class WebklexCopyUidTest extends TestCase
{
    public function testUntaggedCopyUidOfAMove(): void
    {
        $this->assertSame(45, WebklexTransport::copyUid(['* OK [COPYUID 1765182910 11727 45] Moved', '* 3 EXPUNGE', 'A5 OK Move completed.'], 11727));
    }

    public function testSetsAreMatchedByPosition(): void
    {
        $this->assertSame(22, WebklexTransport::copyUid([['A5 OK [COPYUID 9 10:12 20:22] Done']], 12));
        $this->assertSame(31, WebklexTransport::copyUid('OK [COPYUID 9 4,7 30,31] Done', 7));
    }

    public function testNoCopyUidIsZeroNeverAGuess(): void
    {
        $this->assertSame(0, WebklexTransport::copyUid(['A5 OK Move completed.'], 11727));
        $this->assertSame(0, WebklexTransport::copyUid(['OK [COPYUID 9 4 30] Done'], 5), 'another uid moved: not ours');
    }
}
