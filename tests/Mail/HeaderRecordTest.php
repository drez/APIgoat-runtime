<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\HeaderRecord;
use PHPUnit\Framework\TestCase;

final class HeaderRecordTest extends TestCase
{
    public function testNormaliseFillsEveryKeyWithTypedDefaults(): void
    {
        $r = HeaderRecord::normalise(['provider_message_id' => 'x']);
        $this->assertSame(HeaderRecord::KEYS, array_keys($r));
        $this->assertSame('', $r['from_addr']);
        $this->assertSame([], $r['to']);
        $this->assertSame([], $r['labels']);
        $this->assertNull($r['thread_id']);
        $this->assertNull($r['date_sent']);
        $this->assertFalse($r['has_attachments']);
        $this->assertFalse($r['was_read_at_fetch']);
        $this->assertSame(0, $r['size_bytes']);
    }

    public function testSnippetIsWhitespaceCollapsedAndClampedTo255(): void
    {
        $this->assertSame('a b c', HeaderRecord::snippet("a \n\t b   c "));
        $long = str_repeat('é', 300);
        $s = HeaderRecord::snippet($long);
        $this->assertSame(255, mb_strlen($s));
        $this->assertStringEndsWith('…', $s);
        $this->assertSame('a &amp; b', HeaderRecord::snippet('a &amp;amp; b'));
    }

    /** @dataProvider addresses */
    public function testParseAddress(string $raw, string $addr, string $name): void
    {
        $this->assertSame(['addr' => $addr, 'name' => $name], HeaderRecord::parseAddress($raw));
    }

    public static function addresses(): array
    {
        return [
            ['Ada Lovelace <Ada@Example.com>', 'ada@example.com', 'Ada Lovelace'],
            ['"Lovelace, Ada" <ada@example.com>', 'ada@example.com', 'Lovelace, Ada'],
            ['ada@example.com', 'ada@example.com', ''],
            ['<ada@example.com>', 'ada@example.com', ''],
            ['ada@example.com (Ada)', 'ada@example.com', 'Ada'],
            ['=?UTF-8?B?w4lsw6lvbm9yZQ==?= <e@x.fr>', 'e@x.fr', 'Éléonore'],
            ['', '', ''],
            ['undisclosed-recipients:;', '', 'undisclosed-recipients:;'],
        ];
    }

    public function testParseAddressListSplitsOnlyOutsideQuotesAndBrackets(): void
    {
        $list = HeaderRecord::parseAddressList('"Doe, John" <john@x.com>, jane@x.com, Bob <bob@x.com>');
        $this->assertSame([
            ['addr' => 'john@x.com', 'name' => 'Doe, John'],
            ['addr' => 'jane@x.com', 'name' => ''],
            ['addr' => 'bob@x.com', 'name' => 'Bob'],
        ], $list);
        $this->assertSame([], HeaderRecord::parseAddressList(''));
    }

    public function testMessageIdStripsAngleBracketsAndKeepsFirst(): void
    {
        $this->assertSame('a@b', HeaderRecord::messageId('<a@b>'));
        $this->assertSame('a@b', HeaderRecord::messageId(' <a@b> <c@d>'));
        $this->assertSame('bare', HeaderRecord::messageId('bare'));
        $this->assertNull(HeaderRecord::messageId(''));
        $this->assertNull(HeaderRecord::messageId(null));
    }

    public function testDateSentNormalisesToUtc(): void
    {
        $this->assertSame('2026-08-31 22:15:00', HeaderRecord::dateTime('Mon, 31 Aug 2026 18:15:00 -0400'));
        $this->assertSame('2026-08-31 22:15:00', HeaderRecord::dateTime('Mon, 31 Aug 2026 18:15:00 -0400 (EDT)'));
        $this->assertSame('2026-01-01 00:00:00', HeaderRecord::dateTime(1767225600));
        $this->assertSame('2026-01-01 00:00:00', HeaderRecord::dateTime('1767225600'));
        $this->assertSame('2026-01-01 00:00:00', HeaderRecord::dateTime(new \DateTimeImmutable('2026-01-01T01:00:00+01:00')));
        $this->assertNull(HeaderRecord::dateTime('garbage date'));
        $this->assertNull(HeaderRecord::dateTime(null));
    }

    public function testStringRecipientsAreParsedAndArraysPassThrough(): void
    {
        $r = HeaderRecord::normalise(['to' => 'a@x.com, B <b@x.com>', 'cc' => [['addr' => 'c@x.com', 'name' => '']]]);
        $this->assertSame(['a@x.com', 'b@x.com'], array_column($r['to'], 'addr'));
        $this->assertSame([['addr' => 'c@x.com', 'name' => '']], $r['cc']);
    }
}
