<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Utility;

use ApiGoat\Utility\EnumIndex;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Utility/EnumIndex.php';

/**
 * A Peer stand-in shaped exactly like an emitted one: $enumValueSets keyed by
 * the QUALIFIED column constant, values in declaration order.
 */
final class FakeMailMessagePeer
{
    /** @return array<string,array<int,string>> */
    public static function getValueSets(): array
    {
        return [
            'mail_message.gone'         => ['Yes', 'No'],
            'mail_message.triage_state' => ['Pending', 'Queued', 'Done', 'Failed', 'Skipped'],
        ];
    }
}

final class FakeBarePeer
{
    /** @return array<string,array<int,string>> */
    public static function getValueSets(): array
    {
        return ['active' => ['Yes', 'No']];
    }
}

final class FakeNoValueSetsPeer
{
}

final class EnumIndexTest extends TestCase
{
    /** The trap this class exists for: enum(Yes,No) is Yes=0, No=1. */
    public function testYesIsZeroAndNoIsOne(): void
    {
        self::assertSame(0, EnumIndex::of(FakeMailMessagePeer::class, 'gone', 'Yes'));
        self::assertSame(1, EnumIndex::of(FakeMailMessagePeer::class, 'gone', 'No'));
    }

    public function testEveryLabelMapsToItsDeclarationPosition(): void
    {
        $labels = ['Pending', 'Queued', 'Done', 'Failed', 'Skipped'];
        foreach ($labels as $i => $label) {
            self::assertSame($i, EnumIndex::of(FakeMailMessagePeer::class, 'triage_state', $label), $label);
        }
        self::assertSame($labels, EnumIndex::labels(FakeMailMessagePeer::class, 'triage_state'));
    }

    public function testQualifiedAndBareColumnNamesBothResolve(): void
    {
        self::assertSame(2, EnumIndex::of(FakeMailMessagePeer::class, 'mail_message.triage_state', 'Done'));
        self::assertSame(2, EnumIndex::of(FakeMailMessagePeer::class, 'triage_state', 'Done'));
        // A Peer that keys by the bare name works too.
        self::assertSame(1, EnumIndex::of(FakeBarePeer::class, 'active', 'No'));
    }

    public function testAllReturnsAnInBindListInTheOrderGiven(): void
    {
        self::assertSame(
            [4, 0, 2],
            EnumIndex::all(FakeMailMessagePeer::class, 'triage_state', ['Skipped', 'Pending', 'Done'])
        );
        self::assertSame([], EnumIndex::all(FakeMailMessagePeer::class, 'gone', []));
    }

    public function testUnknownLabelThrowsAndNamesWhatIsDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no value "Nope".*Pending, Queued/s');
        EnumIndex::of(FakeMailMessagePeer::class, 'triage_state', 'Nope');
    }

    /** A near-miss of case is still a miss — 'yes' is not 'Yes'. */
    public function testLabelComparisonIsStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnumIndex::of(FakeMailMessagePeer::class, 'gone', 'yes');
    }

    public function testUnknownColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no enum column "nope"/');
        EnumIndex::of(FakeMailMessagePeer::class, 'nope', 'Yes');
    }

    public function testAllPropagatesTheThrowRatherThanSkipping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnumIndex::all(FakeMailMessagePeer::class, 'gone', ['Yes', 'Maybe']);
    }

    public function testNonPeerClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnumIndex::of(FakeNoValueSetsPeer::class, 'gone', 'Yes');
    }

    public function testMissingClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnumIndex::of('\\App\\NoSuchPeerAtAll', 'gone', 'Yes');
    }
}
