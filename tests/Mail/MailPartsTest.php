<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';

use ApiGoat\Mail\BodyStructure;
use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\Imap\ImapFetchReader;
use ApiGoat\Mail\Imap\ImapPartTransport;
use ApiGoat\Mail\PartDecoder;
use ApiGoat\Mail\PartTooLarge;
use ApiGoat\Sync\Exceptions\ValidationRejected;
use PHPUnit\Framework\TestCase;

/** BodyStructure, PartDecoder, ImapFetchReader and ImapConnector's PartReader (attachments, 2026-10-05). */
final class MailPartsTest extends TestCase
{
    // A Thunderbird-style mixed/related message: text+html alternative, a cid image, a PDF with an
    // RFC 2231 filename, a forwarded .eml, and a literal-carried name.
    private const MIXED = '* 12 FETCH (UID 345 BODYSTRUCTURE (((("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 12 1 NIL NIL NIL NIL)'
        . '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 120 3 NIL NIL NIL NIL) "ALTERNATIVE" ("BOUNDARY" "b3") NIL NIL NIL)'
        . '("IMAGE" "PNG" ("NAME" "logo.png") "<logo@x>" NIL "BASE64" 1000 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL) "RELATED" ("BOUNDARY" "b2") NIL NIL NIL)'
        . '("APPLICATION" "PDF" ("NAME" "x.pdf") NIL NIL "BASE64" 4096 NIL ("ATTACHMENT" ("FILENAME*" "utf-8\'\'r%C3%A9sum%C3%A9.pdf")) NIL NIL)'
        . '("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 500 ("date" "subj" NIL NIL NIL NIL NIL NIL NIL NIL) ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 1 NIL NIL NIL NIL) 20 NIL ("ATTACHMENT" ("FILENAME" "fwd.eml")) NIL NIL)'
        . '("APPLICATION" "OCTET-STREAM" NIL NIL NIL "BASE64" 8 NIL ("ATTACHMENT" ("FILENAME" {11}' . "\r\n" . 'a "b) c.bin)) NIL NIL) "MIXED" ("BOUNDARY" "b1") NIL NIL NIL))' . "\r\n";

    public function test_leaves_and_sections_of_a_nested_message(): void
    {
        $l = BodyStructure::parse(self::MIXED);
        $this->assertSame(['1.1.1', '1.1.2', '1.2', '2', '3', '4'], array_column($l, 'section'));
        $this->assertSame('image', $l[2]['type']);
        $this->assertSame('logo@x', $l[2]['id']);
        $this->assertSame('inline', $l[2]['disposition']);
        $this->assertSame('related', $l[2]['parent']);
        $this->assertSame('base64', $l[2]['encoding']);
        $this->assertSame(1000, $l[2]['size']);
        $this->assertSame('résumé.pdf', $l[3]['disposition_params']['filename'], 'RFC 2231 filename*');
        $this->assertSame('mixed', $l[3]['parent']);
        $this->assertSame(['message', 'rfc822', 'attachment', 'fwd.eml'], [$l[4]['type'], $l[4]['subtype'], $l[4]['disposition'], $l[4]['disposition_params']['filename']], 'an attached email is one leaf');
        $this->assertSame('a "b) c.bin', $l[5]['disposition_params']['filename'], 'a literal filename with quote and paren');
        $this->assertSame('quoted-printable', $l[1]['encoding']);
    }

    public function test_a_single_part_message_is_section_1(): void
    {
        $l = BodyStructure::parse('* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "us-ascii") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL) UID 9)');
        $this->assertCount(1, $l);
        $this->assertSame('1', $l[0]['section']);
        $this->assertNull($l[0]['parent']);
    }

    public function test_rfc2231_continuations_and_rfc2047_words(): void
    {
        $p = BodyStructure::decodeParams(['FILENAME*0*', "utf-8''%E2%82%AC", 'FILENAME*1', ' part.txt', 'NAME', '=?UTF-8?B?w6l0w6kucGRm?=']);
        $this->assertSame('€ part.txt', $p['filename']);
        $this->assertSame('été.pdf', $p['name']);
    }

    public function test_garbage_is_refused_whole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BodyStructure::parse('* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN"');
    }

    public function test_decoder_streams_base64_and_qp_across_chunk_boundaries(): void
    {
        $data = random_bytes(5000);
        $enc  = chunk_split(base64_encode($data), 76, "\r\n");
        $out  = '';
        $d    = new PartDecoder('BASE64', 10000, static function (string $b) use (&$out): void { $out .= $b; });
        foreach (str_split($enc, 7) as $c) {
            $d->write($c);
        }
        $this->assertSame(5000, $d->finish());
        $this->assertSame($data, $out);

        $qp  = quoted_printable_encode(str_repeat("héllo wörld = ok\r\n", 50));
        $out = '';
        $d   = new PartDecoder('quoted-printable', 100000, static function (string $b) use (&$out): void { $out .= $b; });
        foreach (str_split($qp, 5) as $c) {
            $d->write($c);
        }
        $d->finish();
        $this->assertSame(str_repeat("héllo wörld = ok\r\n", 50), $out);
    }

    public function test_decoder_stops_the_moment_the_cap_is_passed(): void
    {
        $seen = 0;
        $d = new PartDecoder('7bit', 100, static function (string $b) use (&$seen): void { $seen += strlen($b); });
        $d->write(str_repeat('a', 60));
        try {
            $d->write(str_repeat('a', 60));
            $this->fail('no PartTooLarge');
        } catch (PartTooLarge $e) {
            $this->assertSame(100, $e->limit);
        }
        $this->assertSame(60, $seen, 'the chunk that passes the cap never reaches the sink');
    }

    private static function stream(string $s)
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, $s);
        rewind($h);
        return $h;
    }

    public function test_reader_streams_a_literal_and_reads_to_the_tag(): void
    {
        $body = str_repeat("0123456789\r\n", 20000);
        $h    = self::stream("* 3 EXISTS\r\n* 12 FETCH (UID 345 BODY[2]<0> {" . strlen($body) . "}\r\n" . $body . ")\r\nTAG7 OK done\r\nNEXT");
        $out  = '';
        $n    = ImapFetchReader::streamLiteral($h, 'TAG7', static function (string $c) use (&$out): void { $out .= $c; });
        $this->assertSame(strlen($body), $n);
        $this->assertSame($body, $out);
        $this->assertSame('NEXT', stream_get_contents($h), 'stopped exactly after the tagged line');
    }

    public function test_reader_drains_when_the_sink_throws_and_rethrows(): void
    {
        $h = self::stream("* 1 FETCH (BODY[1]<0> {200000}\r\n" . str_repeat('x', 200000) . " UID 5)\r\nT1 OK\r\nNEXT");
        try {
            ImapFetchReader::streamLiteral($h, 'T1', static function (): void { throw new PartTooLarge(10); });
            $this->fail('no rethrow');
        } catch (PartTooLarge) {
        }
        $this->assertSame('NEXT', stream_get_contents($h), 'the connection stays in sync');
    }

    public function test_reader_nil_is_no_such_section_and_no_is_an_error(): void
    {
        $this->assertNull(ImapFetchReader::streamLiteral(self::stream("* 1 FETCH (UID 5 BODY[9]<0> NIL)\r\nT2 OK\r\n"), 'T2', static function (): void {}));
        $this->assertNull(ImapFetchReader::streamLiteral(self::stream("T3 OK no such uid\r\n"), 'T3', static function (): void {}));
        $this->expectException(\RuntimeException::class);
        ImapFetchReader::streamLiteral(self::stream("T4 NO bad\r\n"), 'T4', static function (): void {});
    }

    public function test_collect_keeps_literals_and_parses(): void
    {
        $h = self::stream(str_replace("\r\n", "\r\n", self::MIXED) . "T5 OK\r\n");
        $text = ImapFetchReader::collect($h, 'T5', 1 << 20);
        $this->assertCount(6, BodyStructure::parse($text));
    }

    private function connector(array $sections, array $structure = []): ImapConnector
    {
        $t = new class($sections, $structure) extends FakeImapTransport implements ImapPartTransport {
            public array $asked = [];
            public function __construct(private array $sections, private array $structure) {}
            public function bodyStructure(string $folder, int $uid): ?string
            {
                return $this->structure[$uid] ?? null;
            }
            public function streamSection(string $folder, int $uid, string $section, int $maxOctets, callable $sink): ?int
            {
                $this->asked[] = [$section, $maxOctets];
                if (!isset($this->sections[$section])) {
                    return null;
                }
                $bytes = substr($this->sections[$section], 0, $maxOctets);
                foreach (str_split($bytes, 1000) ?: [] as $c) {
                    $sink($c);
                }
                return strlen($bytes);
            }
        };
        $this->transport = $t;
        return new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p'], $t);
    }

    private object $transport;

    public function test_connector_fetches_a_bounded_prefix_and_refuses_a_truncated_part(): void
    {
        $small = base64_encode(str_repeat('a', 1000));
        $big   = chunk_split(base64_encode(random_bytes(3000)), 76, "\r\n");
        $c     = $this->connector(['2' => $small, '3' => $big], [7 => self::MIXED]);

        $out = '';
        $this->assertSame(1000, $c->fetchPart('7:INBOX', '2', 'base64', 2000, static function (string $b) use (&$out): void { $out .= $b; }));
        $this->assertSame(str_repeat('a', 1000), $out);
        $this->assertSame(ImapConnector::encodedBound('base64', 2000), $this->transport->asked[0][1]);

        $this->expectException(PartTooLarge::class);
        $c->fetchPart('7:INBOX', '3', 'base64', 2000, static function (): void {});
    }

    public function test_connector_missing_section_and_message_are_404(): void
    {
        $c = $this->connector([], [7 => self::MIXED]);
        $this->assertCount(6, $c->fetchStructure('7:INBOX'));
        try {
            $c->fetchPart('7:INBOX', '9', '7bit', 10, static function (): void {});
            $this->fail('no 404');
        } catch (ValidationRejected $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->expectException(ValidationRejected::class);
        $c->fetchStructure('8:INBOX');
    }

    public function test_an_unresolved_id_never_reaches_the_server(): void
    {
        $c = $this->connector([], []);
        $this->expectException(\InvalidArgumentException::class);
        $c->fetchStructure('unresolved:abc');
    }
}
