<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MimeBodyParser;
use PHPUnit\Framework\TestCase;

final class MimeBodyParserTest extends TestCase
{
    public function testSinglePartQuotedPrintableLatin1(): void
    {
        $raw = "From: a@b\r\nSubject: =?UTF-8?Q?Caf=C3=A9?=\r\nContent-Type: text/plain; charset=\"iso-8859-1\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 au lait\r\n";
        $b = MimeBodyParser::builtin($raw, '1:INBOX');
        $this->assertSame("Café au lait\r\n", $b->text);
        $this->assertSame('', $b->html);
        $this->assertSame([], $b->attachments);
        $this->assertSame('1:INBOX', $b->providerMessageId);
        $this->assertSame(strlen($raw), $b->sizeBytes);
        $this->assertSame('a@b', $b->headers['from']);
    }

    public function testNestedMultipartWithAttachment(): void
    {
        $raw = implode("\r\n", [
            'Subject: folded', ' subject line',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            'preamble',
            '--outer',
            'Content-Type: multipart/alternative; boundary=inner',
            '',
            '--inner',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'plain body',
            '--inner',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode('<p>html <b>body</b></p>'), 76, "\r\n"),
            '--inner--',
            '--outer',
            'Content-Type: application/pdf; name="q.pdf"',
            'Content-Disposition: attachment; filename="q.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 fake'),
            '--outer--',
            'epilogue',
        ]);
        $b = MimeBodyParser::builtin($raw);
        $this->assertSame('plain body', trim($b->text));
        $this->assertSame('<p>html <b>body</b></p>', trim($b->html));
        $this->assertSame([['filename' => 'q.pdf', 'mime' => 'application/pdf', 'size' => strlen('%PDF-1.4 fake')]], $b->attachments);
        $this->assertSame('folded subject line', $b->headers['subject']);
        $this->assertTrue($b->hasAttachments());
        $this->assertSame('plain body', $b->plainText());
    }

    public function testHtmlOnlyFallsBackToFlattenedText(): void
    {
        $b = new MailBody('x', '', "<div>Hello<br>there &amp; <script>x()</script>friends</div>");
        $this->assertSame("Hello\nthere & friends", $b->plainText());
    }

    public function testRfc2231FilenameAndInlineImageCountAsAttachments(): void
    {
        $raw = "Content-Type: multipart/related; boundary=b\r\n\r\n--b\r\nContent-Type: text/html\r\n\r\n<img src=cid:1>\r\n--b\r\nContent-Type: image/png\r\nContent-Disposition: inline; filename*=UTF-8''r%C3%A9sum%C3%A9.png\r\n\r\nPNG\r\n--b--\r\n";
        $b = MimeBodyParser::builtin($raw);
        $this->assertSame('résumé.png', $b->attachments[0]['filename']);
        $this->assertSame('image/png', $b->attachments[0]['mime']);
        $this->assertSame('<img src=cid:1>', trim($b->html));
    }

    public function testParseChoosesTheBuiltinWhenZbatesonIsAbsent(): void
    {
        if (MimeBodyParser::zbatesonAvailable()) {
            $this->markTestSkipped('zbateson present in this vendor tree');
        }
        $this->assertSame('hi', trim(MimeBodyParser::parse("Content-Type: text/plain\r\n\r\nhi")->text));
    }
}
