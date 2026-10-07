<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\BodyStructure;
use ApiGoat\Mail\MimeStructure;
use ApiGoat\Mail\PartDecoder;
use PHPUnit\Framework\TestCase;

final class MimeStructureTest extends TestCase
{
    private function pdfEml(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/graph/with-pdf.eml');
    }

    public function test_mixed_message_leaves(): void
    {
        $l = MimeStructure::leaves($this->pdfEml());
        $this->assertSame(['1', '2'], array_column($l, 'section'));
        $this->assertSame(['text', 'plain', 'mixed'], [$l[0]['type'], $l[0]['subtype'], $l[0]['parent']]);
        $this->assertSame('base64', $l[1]['encoding']);
        $this->assertSame('attachment', $l[1]['disposition']);
        $this->assertSame('a.pdf', $l[1]['params']['name']);
        $this->assertSame('a.pdf', $l[1]['disposition_params']['filename']);
        $this->assertGreaterThan(1000, $l[1]['size']);
    }

    public function test_part_returns_encoded_content_that_decodes_to_the_pdf(): void
    {
        $enc = MimeStructure::part($this->pdfEml(), '2');
        $this->assertNotNull($enc);
        $this->assertStringStartsWith('JVBERi0x', $enc);
        $out = '';
        $d   = new PartDecoder('base64', 1 << 20, function (string $b) use (&$out) { $out .= $b; });
        $d->write($enc);
        $d->finish();
        $this->assertStringStartsWith('%PDF-1.4', $out);
        $this->assertNull(MimeStructure::part($this->pdfEml(), '3'));
        $this->assertNull(MimeStructure::part($this->pdfEml(), '2.1'));
    }

    public function test_single_part_is_section_1(): void
    {
        $l = MimeStructure::leaves("Content-Type: text/plain; charset=us-ascii\r\n\r\nhello");
        $this->assertSame(['1'], array_column($l, 'section'));
        $this->assertNull($l[0]['parent']);
        $this->assertSame('hello', MimeStructure::part("Content-Type: text/plain\r\n\r\nhello", '1'));
    }

    public function test_nested_alternative_sections(): void
    {
        $raw = "Content-Type: multipart/mixed; boundary=O\r\n\r\n--O\r\nContent-Type: multipart/alternative; boundary=I\r\n\r\n"
            . "--I\r\nContent-Type: text/plain\r\n\r\nplain\r\n--I\r\nContent-Type: text/html\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<b>h=C3=A9</b>\r\n--I--\r\n"
            . "--O\r\nContent-Type: image/png\r\nContent-ID: <logo@x>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\nAAAA\r\n--O--\r\n";
        $l = MimeStructure::leaves($raw);
        $this->assertSame(['1.1', '1.2', '2'], array_column($l, 'section'));
        $this->assertSame('alternative', $l[0]['parent']);
        $this->assertSame('quoted-printable', $l[1]['encoding']);
        $this->assertSame('logo@x', $l[2]['id']);
        $this->assertSame('<b>h=C3=A9</b>', MimeStructure::part($raw, '1.2'));
    }

    public function test_leaf_keys_match_the_imap_shape(): void
    {
        $imap = BodyStructure::parse('* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "us-ascii") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL) UID 9)');
        $this->assertSame(array_keys($imap[0]), array_keys(MimeStructure::leaves($this->pdfEml())[0]));
    }
}
