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
        $this->assertSame('', $r['auth_results'], 'no Authentication-Results header → empty, never null');
    }

    public function testAuthResultsIsCarriedOnOneLine(): void
    {
        $r = HeaderRecord::normalise(['auth_results' => "mx.google.com;\r\n       dkim=pass header.i=@id.apple.com;\r\n\tspf=pass"]);
        $this->assertSame('mx.google.com; dkim=pass header.i=@id.apple.com; spf=pass', $r['auth_results']);
    }

    public function testTopmostHeaderReturnsTheFirstOccurrenceUnfolded(): void
    {
        $raw = "Return-Path: <bounce@id.apple.com>\r\n"
            . "Authentication-Results: mail.apigoat.com;\r\n"
            . "\tdkim=pass (2048-bit key) header.d=id.apple.com header.i=@id.apple.com\r\n"
            . "Received: from mx by mail.apigoat.com\r\n"
            . "authentication-results: forged.example; dkim=pass header.d=apple.com\r\n"
            . "Subject: Hi\r\n"
            . "\r\n"
            . "Authentication-Results: in the body, never read\r\n";
        $this->assertSame(
            'mail.apigoat.com; dkim=pass (2048-bit key) header.d=id.apple.com header.i=@id.apple.com',
            HeaderRecord::topmostHeader($raw, HeaderRecord::AUTH_RESULTS_HEADER),
            'the receiving server\'s header wins; the lower (sender-supplied) one is ignored'
        );
        $this->assertSame('', HeaderRecord::topmostHeader("Subject: x\n\nAuthentication-Results: body", 'Authentication-Results'));
        $this->assertSame('Hi', HeaderRecord::topmostHeader("subject: Hi\nX: y", 'Subject'), 'LF-only and case-insensitive');
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

    public function testBulkHeadersReadTheTopmostOccurrenceOfEach(): void
    {
        $raw = "List-Id: Weekly news <news.example.com>\r\n"
             . "List-Unsubscribe: <mailto:u@example.com>,\r\n <https://example.com/u?x=1>\r\n"
             . "Precedence: bulk\r\n"
             . "Auto-Submitted: auto-generated\r\n"
             . "List-Id: forged <other.example>\r\n"
             . "\r\n"
             . "List-Id: in the body <body.example>\r\n";
        $this->assertSame([
            'list_id'          => 'Weekly news <news.example.com>',
            'list_unsubscribe' => '<mailto:u@example.com>, <https://example.com/u?x=1>',
            'precedence'       => 'bulk',
            'auto_submitted'   => 'auto-generated',
        ], HeaderRecord::bulkHeaders($raw));
        $this->assertSame(['list_id' => '', 'list_unsubscribe' => '', 'precedence' => '', 'auto_submitted' => ''],
            HeaderRecord::bulkHeaders("From: a@b.c\r\n\r\nbody"));
    }

    public function testNormaliseCarriesTheBulkKeysWithTypedDefaults(): void
    {
        $r = HeaderRecord::normalise(['list_id' => "  a\r\n\t<b.example>  ", 'precedence' => 'list']);
        $this->assertSame('a <b.example>', $r['list_id']);
        $this->assertSame('list', $r['precedence']);
        $this->assertSame('', $r['list_unsubscribe']);
        $this->assertSame('', $r['auto_submitted']);
        foreach (['list_id', 'list_unsubscribe', 'precedence', 'auto_submitted'] as $k) {
            $this->assertContains($k, HeaderRecord::KEYS);
        }
        $this->assertSame(500, mb_strlen(HeaderRecord::normalise(['list_unsubscribe' => str_repeat('x', 900)])['list_unsubscribe']));
    }

    public function testReferencesKeepsMessageIdsOnlyAndTrimsTheMiddle(): void
    {
        $this->assertSame('<a@x> <b@y>', HeaderRecord::references("<a@x>\r\n\t<b@y> <a@x>"));
        $this->assertSame('', HeaderRecord::references('garbage without ids'));
        $this->assertSame('', HeaderRecord::references(null));
        $ids = implode(' ', array_map(static fn ($i) => "<m{$i}@x>", range(1, 30)));
        $out = explode(' ', HeaderRecord::references($ids));
        $this->assertCount(HeaderRecord::REFERENCES_MAX, $out);
        $this->assertSame('<m1@x>', $out[0], 'the root is kept');
        $this->assertSame('<m30@x>', end($out), 'the newest ancestors are kept');
    }

    public function testReplyToIsAParsedAddressList(): void
    {
        $r = HeaderRecord::normalise(['reply_to' => 'List <list@x.org>, other@y.org', 'references' => '<a@x>']);
        $this->assertSame([['addr' => 'list@x.org', 'name' => 'List'], ['addr' => 'other@y.org', 'name' => '']], $r['reply_to']);
        $this->assertSame('<a@x>', $r['references']);
        $empty = HeaderRecord::normalise([]);
        $this->assertSame([], $empty['reply_to']);
        $this->assertSame('', $empty['references']);
        $this->assertSame(HeaderRecord::KEYS, array_keys($empty));
    }

    public function testAllHeadersUnfoldsKeepsRepeatsAndDecodesWords(): void
    {
        $raw = "Received: from a\r\n\tby b;\r\n  Mon\r\nReceived: from c by d\r\nX-Odd: \r\nbroken line without colon\r\n"
            . "Subject: =?UTF-8?B?w6l0w6k=?=\r\nX-Tag:   spaced  \r\n\r\nBody: not-a-header\r\n";
        $this->assertSame([
            ['Received', 'from a by b; Mon'],
            ['Received', 'from c by d'],
            ['X-Odd', ''],
            ['Subject', 'été'],
            ['X-Tag', 'spaced'],
        ], HeaderRecord::allHeaders($raw));
        $this->assertSame([['A', '1'], ['b', '2']], HeaderRecord::allHeaders("A: 1\nb: 2\n\nC: 3"));
        $this->assertSame([], HeaderRecord::allHeaders(''));
    }

    public function testHeaderMapLowercasesAndKeepsOrderAndRepeats(): void
    {
        $m = HeaderRecord::headerMap([['Received', 'one'], ['X-Foo', 'a'], ['RECEIVED', 'two'], ['x-foo', 'b']]);
        $this->assertSame(['received' => ['one', 'two'], 'x-foo' => ['a', 'b']], $m);
        $this->assertSame(['received', 'x-foo'], array_keys($m));
        $this->assertSame([], HeaderRecord::headerMap([]));
    }

    public function testHeaderMapCapTruncatesDeterministicallyAndMarks(): void
    {
        $pairs = [['Subject', 'short'], ['X-Big', str_repeat('é', 200000)], ['X-Mid', str_repeat('m', 30000)]];
        $a = HeaderRecord::headerMap($pairs);
        $b = HeaderRecord::headerMap($pairs);
        $this->assertSame($a, $b);
        $this->assertSame(['1'], $a['x-apigmail-truncated']);
        $this->assertSame(['short'], $a['subject']);
        $this->assertLessThanOrEqual(HeaderRecord::HEADERS_MAX, strlen((string) json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->assertNotFalse(mb_check_encoding($a['x-big'][0], 'UTF-8'));
        $this->assertLessThan(200000, mb_strlen($a['x-big'][0]));
        $small = HeaderRecord::headerMap([['A', 'b']]);
        $this->assertArrayNotHasKey('x-apigmail-truncated', $small);
    }

    public function testNormalisePassesHeadersMapElseEmpty(): void
    {
        $this->assertContains('headers', HeaderRecord::KEYS);
        $map = ['x-a' => ['1', '2'], 'b' => ['3']];
        $this->assertSame($map, HeaderRecord::normalise(['headers' => $map])['headers']);
        $this->assertSame([], HeaderRecord::normalise([])['headers'], 'empty = the connector gave none');
        $this->assertSame([], HeaderRecord::normalise(['headers' => 'nope'])['headers']);
        $this->assertSame([], HeaderRecord::normalise(['headers' => ['x' => 'string']])['headers']);
        $this->assertSame([], HeaderRecord::normalise(['headers' => [0 => ['a']]])['headers']);
        $this->assertSame([], HeaderRecord::normalise(['headers' => ['x' => [['nested']]]])['headers']);
    }

    public function testCapAppliesToInvalidUtf8AndResultEncodes(): void
    {
        $m = HeaderRecord::headerMap([['X-Latin', str_repeat('a', 100000) . "\xE9" . str_repeat('b', 100000)]]);
        $json = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json);
        $this->assertLessThanOrEqual(HeaderRecord::HEADERS_MAX, strlen($json));
        $this->assertSame(['1'], $m['x-apigmail-truncated']);
    }

    public function testManyTinyHeadersAreBoundedAndFast(): void
    {
        $raw = str_repeat("a: b\r\n", 50000) . "\r\n";
        $t = microtime(true);
        $m = HeaderRecord::headerMap(HeaderRecord::allHeaders($raw));
        $this->assertLessThan(1.0, microtime(true) - $t);
        $this->assertCount(HeaderRecord::HEADERS_COUNT_MAX, $m['a']);
        $this->assertCount(HeaderRecord::HEADERS_COUNT_MAX, HeaderRecord::allHeaders($raw));
    }

    public function testNamesAloneOverTheCapAreDropped(): void
    {
        $pairs = [];
        for ($i = 0; $i < 1000; $i++) $pairs[] = [str_pad((string) $i, 200, 'n'), ''];
        $m = HeaderRecord::headerMap($pairs);
        $this->assertLessThanOrEqual(HeaderRecord::HEADERS_MAX, strlen((string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->assertSame(['1'], $m['x-apigmail-truncated']);
        $this->assertArrayHasKey(strtolower(str_pad('0', 200, 'n')), $m, 'first-seen names survive');
    }

    public function testContinuationAfterEmptyValueHasNoLeadingSpace(): void
    {
        $this->assertSame([['X', 'foo']], HeaderRecord::allHeaders("X: \r\n foo\r\n"));
    }
}
