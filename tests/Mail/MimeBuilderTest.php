<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Smtp\MimeBuilder;
use ApiGoat\Mail\Smtp\OutgoingMessage;
use PHPUnit\Framework\TestCase;

final class MimeBuilderTest extends TestCase
{
    private function msg(array $o = []): OutgoingMessage
    {
        return new OutgoingMessage(
            $o['from'] ?? 'fred@fx.example', $o['name'] ?? 'Fred',
            $o['to'] ?? [['addr' => 'ada@fx.example', 'name' => 'Ada']],
            $o['cc'] ?? [['addr' => 'bob@fx.example', 'name' => '']],
            $o['bcc'] ?? [['addr' => 'secret@fx.example', 'name' => '']],
            $o['subject'] ?? 'Re: Déjà vu',
            $o['text'] ?? "Hello\n\n> quoted",
            $o['html'] ?? null,
            $o['mid'] ?? '<abc123@fx.example>',
            $o['irt'] ?? '<p@x.org>',
            $o['refs'] ?? '<root@x.org> <p@x.org>',
            $o['att'] ?? [],
            new \DateTimeImmutable('2026-09-30 12:00:00 +0000')
        );
    }

    private static function head(string $raw): string
    {
        // LF-normalised: PCRE's /m `$` matches before "\n" only, never before "\r\n" (CRLF itself is asserted on $raw).
        return str_replace("\r\n", "\n", preg_split('/\r\n\r\n/', $raw, 2)[0]);
    }

    public function testHeadersCarryOurMessageIdThreadingAndNoBccOnTheWire(): void
    {
        $raw  = MimeBuilder::build($this->msg());
        $head = self::head($raw);
        $this->assertStringContainsString("\r\n", $raw, 'CRLF line ends');
        $this->assertMatchesRegularExpression('/^Message-ID: <abc123@fx\.example>$/mi', $head);
        $this->assertMatchesRegularExpression('/^In-Reply-To: <p@x\.org>$/mi', $head);
        $this->assertMatchesRegularExpression('/^References: <root@x\.org> <p@x\.org>$/mi', $head);
        $this->assertMatchesRegularExpression('/^Date: Wed, 30 Sep 2026 12:00:00 \+0000$/mi', $head);
        $this->assertMatchesRegularExpression('/^Cc: bob@fx\.example$/mi', $head);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $head, 'Bcc never travels in the message');
        $this->assertDoesNotMatchRegularExpression('/^X-Mailer:/mi', $head);
        $this->assertStringContainsString('=?utf-8?', strtolower($head), 'the UTF-8 subject is encoded');
    }

    public function testTextOnlyIsOnePartAndHtmlMakesAlternative(): void
    {
        $this->assertStringNotContainsString('multipart/alternative', MimeBuilder::build($this->msg()));
        $raw = MimeBuilder::build($this->msg(['html' => '<p>Hello</p>']));
        $this->assertStringContainsString('multipart/alternative', $raw);
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertStringContainsString('Hello', (string) $parsed->getTextContent(), 'the text part is always present');
        $this->assertStringContainsString('<p>Hello</p>', (string) $parsed->getHtmlContent());
    }

    public function testAttachmentsBecomeParts(): void
    {
        $raw = MimeBuilder::build($this->msg(['att' => [
            ['filename' => 'invoice.pdf', 'mime' => 'application/pdf', 'content' => "%PDF-1.4\x00\x01binary"],
        ]]));
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame(1, $parsed->getAttachmentCount());
        $part = $parsed->getAttachmentPart(0);
        $this->assertSame('invoice.pdf', $part->getFilename());
        $this->assertSame("%PDF-1.4\x00\x01binary", $part->getBinaryContentStream()->getContents());
    }

    public function testBccHeaderOnlyForADraftCopy(): void
    {
        $this->assertMatchesRegularExpression('/^Bcc: secret@fx\.example$/mi', self::head(MimeBuilder::build($this->msg(), true)));
    }

    public function testADraftWithoutRecipientsStillBuilds(): void
    {
        $raw = MimeBuilder::build($this->msg(['to' => [], 'cc' => [], 'bcc' => []]), true, true);
        $this->assertMatchesRegularExpression('/^Message-ID: <abc123@fx\.example>$/mi', self::head($raw));
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', self::head($raw), 'the placeholder envelope address never reaches the MIME');
    }

    public function testASendWithoutRecipientsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MimeBuilder::build($this->msg(['to' => [], 'cc' => [], 'bcc' => []]));
    }

    public function testAMalformedMessageIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MimeBuilder::build($this->msg(['mid' => 'no-brackets@fx.example']));
    }

    public function testTheEnvelopeIsToCcBccLowercasedAndDeduped(): void
    {
        $m = $this->msg(['to' => [['addr' => 'Ada@FX.example', 'name' => '']], 'cc' => [['addr' => 'ada@fx.example', 'name' => '']]]);
        $this->assertSame(['ada@fx.example', 'secret@fx.example'], $m->envelope());
    }

    public function testHeaderInjectionThroughSubjectOrNameIsNeutralised(): void
    {
        $head = self::head(MimeBuilder::build($this->msg([
            'subject' => "Hi\r\nBcc: evil@x.example",
            'name'    => "Fred\r\nX-Evil: 1",
            'to'      => [['addr' => 'ada@fx.example', 'name' => "Ada\nBcc: evil@x.example"]],
        ])));
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $head);
        $this->assertDoesNotMatchRegularExpression('/^X-Evil:/mi', $head);
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function injected(): iterable
    {
        yield 'references' => [['refs' => "<a@x.org>\r\nBcc: evil@x.example"]];
        yield 'in-reply-to' => [['irt' => "<a@x.org>\r\nBcc: evil@x.example"]];
        yield 'in-reply-to not a msg-id' => [['irt' => 'a@x.org']];
        yield 'references junk' => [['refs' => '<a@x.org> junk']];
        yield 'message-id' => [['mid' => "<a@x.org>\r\nBcc: evil@x.example"]];
        yield 'references CTL byte' => [['refs' => "<a\x01b@x.org> <c@y.org>"]];
        yield 'in-reply-to DEL byte' => [['irt' => "<a\x7fb@x.org>"]];
        yield 'message-id 8-bit' => [['mid' => "<d\xc3\xa9j\xc3\xa0@fx.example>"]];
        yield 'content-id bracket' => [['att' => [['filename' => 'a.png', 'mime' => 'image/png', 'content' => 'x', 'content_id' => "img1>X-Evil: 1"]]]];
        yield 'from address' => [['from' => "fred@fx.example\r\nBcc: evil@x.example"]];
        yield 'recipient address' => [['to' => [['addr' => "ada@fx.example\r\nBcc: evil@x.example", 'name' => '']]]];
    }

    /** @dataProvider injected */
    public function testInjectedHeaderValuesAreRefused(array $o): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MimeBuilder::build($this->msg($o));
    }

    public function testALongReferencesChainIsFoldedNeverEncoded(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = '<CAJ' . str_repeat(chr(65 + $i), 50) . "+{$i}@mail.gmail.com>";
        }
        $refs = implode(' ', $ids);
        $raw  = MimeBuilder::build($this->msg(['refs' => $refs]));
        $head = preg_split('/\r\n\r\n/', $raw, 2)[0];
        $this->assertStringNotContainsString('=?', preg_replace('/^Subject:.*(\r\n[ \t].*)*/mi', '', $head), 'only the subject is encoded');
        foreach (explode("\r\n", $head) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame($refs, preg_replace('/\s+/', ' ', trim((string) $parsed->getHeader('References')?->getRawValue())));
    }

    public function testAHundredRecipientsFoldIntoLegalLines(): void
    {
        $to = $bcc = [];
        for ($i = 0; $i < 100; $i++) {
            $to[]  = ['addr' => "person{$i}@fx.example", 'name' => "Doe, Person {$i}"];
            $bcc[] = ['addr' => "hidden{$i}@fx.example", 'name' => "Zoë Ünïcode {$i}"];
        }
        $raw  = MimeBuilder::build($this->msg(['to' => $to, 'bcc' => $bcc]), true);
        $head = preg_split('/\r\n\r\n/', $raw, 2)[0];
        foreach (explode("\r\n", $head) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertCount(100, $parsed->getHeader('To')->getAddresses());
        $this->assertSame('Doe, Person 42', $parsed->getHeader('To')->getAddresses()[42]->getName());
        $this->assertCount(100, $parsed->getHeader('Bcc')->getAddresses(), 'a draft copy keeps every Bcc, folded');
        $this->assertSame('Zoë Ünïcode 7', $parsed->getHeader('Bcc')->getAddresses()[7]->getName());
    }

    public function testAHostileAttachmentTypeFallsBackToOctetStream(): void
    {
        $raw = MimeBuilder::build($this->msg(['att' => [
            ['filename' => 'a.png', 'mime' => "image/png\r\nX-Evil: 1", 'content' => 'png'],
            ['filename' => 'b.pdf', 'mime' => 'application/pdf', 'content' => 'pdf'],
        ]]));
        $this->assertDoesNotMatchRegularExpression('/^X-Evil:/mi', $raw);
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame('application/octet-stream', $parsed->getAttachmentPart(0)->getContentType());
        $this->assertSame('application/pdf', $parsed->getAttachmentPart(1)->getContentType(), 'a well-formed type is kept');
    }

    public function testBareLineBreaksBecomeCrlfNotEncodedLf(): void
    {
        $raw = MimeBuilder::build($this->msg(['text' => "line1\nline2\r\n-- \nsig\rend", 'html' => "<p>a</p>\n<p>b</p>"]));
        $this->assertStringNotContainsStringIgnoringCase('=0A', $raw);
        $this->assertStringNotContainsStringIgnoringCase('=0D', $raw);
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertStringContainsString("line1\r\nline2\r\n-- \r\nsig\r\nend", (string) $parsed->getTextContent());
        $this->assertStringContainsString("<p>a</p>\r\n<p>b</p>", (string) $parsed->getHtmlContent());
    }

    public function testABlankDraftBuildsWithAnEmptyTextPart(): void
    {
        $raw = MimeBuilder::build($this->msg(['to' => [], 'cc' => [], 'bcc' => [], 'text' => '', 'subject' => '']), true, true);
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame('text/plain', $parsed->getContentType());
        $this->assertSame('', trim((string) $parsed->getTextContent()));
    }

    public function testHtmlWithoutTextStillCarriesATextPart(): void
    {
        $raw = MimeBuilder::build($this->msg(['text' => '', 'html' => '<style>p{}</style><p>Hello <b>there</b> &amp; you</p>']));
        $this->assertStringContainsString('multipart/alternative', $raw);
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame('Hello there & you', trim((string) $parsed->getTextContent()), 'derived from the HTML');

        $raw = MimeBuilder::build($this->msg(['text' => '', 'html' => '<img src="cid:x">']));
        $this->assertStringContainsString('multipart/alternative', $raw, 'even an HTML with no text keeps a text part');
    }

    public function testVeryLongSubjectsAndNamesStayUnder998(): void
    {
        $subject = str_repeat('word ', 150) . str_repeat('x', 1200) . ' Déjà';
        $name    = str_repeat('N', 1500);
        $raw  = MimeBuilder::build($this->msg(['subject' => $subject, 'name' => $name, 'to' => [['addr' => 'ada@fx.example', 'name' => str_repeat('Ü', 900)]]]));
        $head = preg_split('/\r\n\r\n/', $raw, 2)[0];
        foreach (explode("\r\n", $head) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line), substr($line, 0, 40));
        }
        $parsed = \ZBateson\MailMimeParser\Message::from($raw, false);
        $this->assertSame($subject, $parsed->getHeaderValue('Subject'), 'the subject survives intact');
        $this->assertSame('ada@fx.example', $parsed->getHeader('To')->getAddresses()[0]->getEmail());
        $this->assertSame('fred@fx.example', $parsed->getHeader('From')->getAddresses()[0]->getEmail());
    }
}
